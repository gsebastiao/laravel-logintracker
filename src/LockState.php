<?php

namespace Gsebastiao\LoginTracker;

use Illuminate\Support\Facades\Auth;
use Gsebastiao\LoginTracker\Models\AuthSession;

/**
 * Fonte de verdade do estado "bloqueado" do lockscreen.
 *
 * PORQUE ISTO EXISTE:
 * Ate a v1.x, o bloqueio vivia apenas numa variavel JavaScript no
 * browser. Isso significava que bastava carregar F5, ou abrir uma aba
 * nova, para o ecra de bloqueio desaparecer e o sistema ficar acessivel
 * a qualquer pessoa - o lockscreen era um efeito visual, nao uma
 * protecao. Era um bug de seguranca.
 *
 * A correcao e guardar o estado no SERVIDOR, em dois sitios que se
 * complementam:
 *
 *   1. SESSAO do Laravel (chave "logintracker.locked_at")
 *      E a fonte rapida, lida a cada render de pagina sem tocar na
 *      base de dados. Como o cookie de sessao e partilhado por todas
 *      as abas do mesmo browser, bloquear numa aba bloqueia em todas -
 *      e sobrevive a refresh, porque vive no servidor e nao na memoria
 *      do JavaScript.
 *
 *   2. Coluna auth_sessions.locked_at
 *      Permite bloquear remotamente (um admin a bloquear a sessao de
 *      outra pessoa, que nao tem sessao PHP partilhada connosco) e
 *      deixa rasto auditavel de quando o bloqueio aconteceu.
 *
 * Qualquer uma das duas a indicar bloqueio basta para considerar a
 * sessao bloqueada.
 */
class LockState
{
    public const SESSION_KEY = 'logintracker.locked_at';

    /**
     * A sessao atual esta bloqueada?
     */
    public static function isLocked(): bool
    {
        if (session()->has(self::SESSION_KEY)) {
            return true;
        }

        $session = self::currentSessionRow();

        if ($session && $session->locked_at !== null) {
            // Bloqueio remoto encontrado: espelha na sessao PHP para os
            // proximos pedidos nao precisarem de ir a base de dados.
            session()->put(self::SESSION_KEY, $session->locked_at->toIso8601String());

            return true;
        }

        return false;
    }

    /**
     * Bloqueia a sessao atual (botao manual ou inatividade detetada).
     */
    public static function lock(): void
    {
        $now = now();

        session()->put(self::SESSION_KEY, $now->toIso8601String());

        if ($row = self::currentSessionRow()) {
            $row->update(['locked_at' => $now, 'lock_requested_at' => null]);
        }
    }

    /**
     * Desbloqueia a sessao atual. So deve ser chamado depois de a
     * password ter sido validada com sucesso.
     */
    public static function unlock(): void
    {
        session()->forget(self::SESSION_KEY);

        if ($row = self::currentSessionRow()) {
            $row->update(['locked_at' => null, 'lock_requested_at' => null]);
        }
    }

    /**
     * Linha de auth_sessions correspondente a sessao atual, ou null.
     */
    protected static function currentSessionRow(): ?AuthSession
    {
        $user = Auth::user();

        if (! $user || ! request()->hasSession()) {
            return null;
        }

        return AuthSession::query()
            ->where('guard', config('auth.defaults.guard'))
            ->where('session_id', 'sess_' . session()->getId())
            ->whereNull('ended_at')
            ->first();
    }
}
