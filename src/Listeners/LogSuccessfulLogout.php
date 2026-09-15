<?php

namespace Gsebastiao\LoginTracker\Listeners;

use Illuminate\Auth\Events\Logout;
use Gsebastiao\LoginTracker\Models\AuthLogin;
use Gsebastiao\LoginTracker\Models\AuthSession;

class LogSuccessfulLogout
{
    public function handle(Logout $event): void
    {
        if (! $this->guardIsTracked($event->guard) || ! $event->user) {
            return;
        }

        $morphName = config('login-tracker.morph_name', 'authenticatable');
        $now = now();

        // 1) Fecha o registo de HISTORICO em aberto.
        //
        //    logout_reason default aqui e LOGOUT_MANUAL, porque este
        //    listener escuta o evento Logout NATIVO do Laravel, que so
        //    dispara quando algo chamou Auth::logout() de forma direta -
        //    o caso classico sendo o utilizador a clicar em "Sair".
        //
        //    HA UMA EXCECAO: o endpoint de logout forcado
        //    (HeartbeatController::forceLogout) TAMBEM chama
        //    Auth::logout() por baixo dos panos (para garantir que o
        //    cookie fica mesmo limpo), o que faz este MESMO listener
        //    disparar a seguir. Mas naquele momento o registo ja foi
        //    fechado, momentos antes, com logout_reason='expired_client' -
        //    e este listener NAO deve sobrescrever isso com 'manual'.
        //    Por isso so preenchemos logout_reason quando o registo
        //    AINDA nao tem um logout_at preenchido (isOpen()) - se ja
        //    tiver sido fechado por outra via nesta mesma requisicao,
        //    respeitamos essa origem e nao mexemos mais nele.
        if (config('login-tracker.events.logout', true)) {
            $lastLogin = AuthLogin::query()
                ->where($morphName . '_id', $event->user->getAuthIdentifier())
                ->where($morphName . '_type', get_class($event->user))
                ->where('guard', $event->guard)
                ->where('event', 'login')
                ->latest('login_at')
                ->first();

            if ($lastLogin && $lastLogin->isOpen()) {
                // Registo ainda estava mesmo em aberto - este e o
                // logout que o esta a fechar. Marca como manual.
                $lastLogin->update([
                    'logout_at'     => $now,
                    'logout_reason' => AuthLogin::LOGOUT_MANUAL,
                ]);
            } elseif (! $lastLogin) {
                // Nao ha nenhum login registado para associar (situacao
                // rara) - cria um registo avulso de logout, tal como
                // antes.
                AuthLogin::create([
                    $morphName . '_id'   => $event->user->getAuthIdentifier(),
                    $morphName . '_type' => get_class($event->user),
                    'guard'         => $event->guard,
                    'event'         => 'logout',
                    'ip_address'    => config('login-tracker.capture.ip_address', true) ? request()?->ip() : null,
                    'user_agent'    => config('login-tracker.capture.user_agent', true) ? request()?->userAgent() : null,
                    'logout_at'     => $now,
                    'logout_reason' => AuthLogin::LOGOUT_MANUAL,
                ]);
            }
            // Caso restante (existe $lastLogin mas NAO esta isOpen()):
            // o registo ja foi fechado momentos antes, nesta mesma
            // requisicao, por outra via (ex: HeartbeatController::
            // forceLogout, com logout_reason='expired_client'). Nao
            // fazemos NADA aqui - nem sobrescrevemos a origem ja
            // registada, nem criamos um segundo registo duplicado.
        }

        // 2) Fecha a SESSAO ATIVA correspondente (para de contar como online
        //    imediatamente, em vez de esperar o threshold de heartbeat expirar).
        //    Isto e seguro fazer sempre, mesmo que ja tenha sido fechada
        //    momentos antes por forceLogout() - um segundo UPDATE com
        //    ended_at/last_seen_at = agora numa sessao ja fechada nao
        //    causa nenhum dado incorreto (so grava um timestamp
        //    ligeiramente mais tardio, na pratica milissegundos).
        if (config('login-tracker.heartbeat.enabled', true)) {
            $sessionId = $this->resolveSessionId(request(), $event->guard);

            $query = AuthSession::query()
                ->where($morphName . '_id', $event->user->getAuthIdentifier())
                ->where($morphName . '_type', get_class($event->user))
                ->where('guard', $event->guard)
                ->whereNull('ended_at');

            // Se conseguirmos identificar a sessao exata, fecha so ela
            // (preserva outras sessoes do mesmo user noutro dispositivo).
            // Caso contrario (ex: logout forcado via admin sem request atual),
            // fecha todas as sessoes abertas deste guard para este user.
            if ($sessionId) {
                $query->where('session_id', $sessionId);
            }

            $query->update(['ended_at' => $now, 'last_seen_at' => $now]);
        }
    }

    protected function resolveSessionId($request, string $guard): ?string
    {
        if (! $request) {
            return null;
        }

        if ($request->hasSession()) {
            return 'sess_' . $request->session()->getId();
        }

        $user = $request->user($guard);
        if ($user && method_exists($user, 'currentAccessToken')) {
            $token = $user->currentAccessToken();
            if ($token) {
                return 'token_' . ($token->id ?? md5((string) $token));
            }
        }

        if ($bearer = $request->bearerToken()) {
            return 'bearer_' . md5($bearer);
        }

        return null;
    }

    protected function guardIsTracked(?string $guard): bool
    {
        $guards = config('login-tracker.guards', ['web']);

        return in_array('*', $guards, true) || in_array($guard, $guards, true);
    }
}
