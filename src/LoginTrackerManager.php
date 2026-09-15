<?php

namespace Gsebastiao\LoginTracker;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Gsebastiao\LoginTracker\Models\AuthLogin;
use Gsebastiao\LoginTracker\Models\AuthSession;

class LoginTrackerManager
{
    /**
     * O utilizador esta online agora (em QUALQUER sessao/dispositivo)?
     */
    public function isOnline(Authenticatable $user): bool
    {
        return $this->sessionsFor($user)->online()->exists();
    }

    /**
     * Todas as sessoes ativas (online agora) deste utilizador. Um mesmo
     * utilizador pode ter mais que uma (ex: browser + telemovel).
     */
    public function activeSessionsFor(Authenticatable $user): Collection
    {
        return $this->sessionsFor($user)->online()->get();
    }

    /**
     * Todos os utilizadores online agora (query base - encadeie ->get()
     * ou filtre mais antes disso).
     */
    public function onlineSessions()
    {
        return AuthSession::query()->online();
    }

    /**
     * Ha quanto tempo este utilizador esta online NESTA sessao. Se tiver
     * multiplas sessoes ativas, use activeSessionsFor() e chame
     * ->duration() em cada uma individualmente.
     */
    public function onlineDuration(Authenticatable $user): ?\DateInterval
    {
        $session = $this->sessionsFor($user)->online()->latest('started_at')->first();

        return $session?->duration();
    }

    public function onlineDurationForHumans(Authenticatable $user): ?string
    {
        $session = $this->sessionsFor($user)->online()->latest('started_at')->first();

        return $session?->durationForHumans();
    }

    /**
     * Forca a exibicao do lockscreen em TODAS as sessoes ativas deste
     * utilizador (todos os dispositivos), no proximo heartbeat de cada
     * uma - no maximo config('login-tracker.heartbeat.ping_interval_seconds')
     * depois (60s por defeito), a mesma janela de atraso que ja existe
     * para deteccao de expiracao de sessao.
     *
     * Bloqueia SEMPRE todas as sessoes do utilizador, nunca so uma -
     * util nomeadamente para um admin a reagir a uma sessao suspeita:
     * bloquear so um dispositivo e deixar outros abertos seria uma
     * falha de seguranca. Se precisar de bloquear so uma sessao
     * especifica, chame ->requestLock() diretamente numa instancia de
     * AuthSession obtida via activeSessionsFor($user).
     *
     * Requisitos para isto ter efeito pratico:
     *   - config('login-tracker.lockscreen.enabled') tem de ser true;
     *   - o layout da aplicacao tem de incluir @loginTrackerLockscreen
     *     (ver README) - sem isso nao ha overlay nenhum na pagina do
     *     lado do utilizador para reagir a este pedido;
     *   - a sessao tem de estar online (a correr heartbeat.js) - uma
     *     sessao ja offline nunca vai fazer outro ping para receber isto,
     *     entao nao ha nada "pendurado" a espera quando ela reconectar
     *     (o pedido so e escrito nas sessoes que estao online AGORA).
     *
     * @return int Numero de sessoes que receberam o pedido (0 se o
     *             utilizador nao tinha nenhuma sessao online no momento).
     */
    public function forceLock(Authenticatable $user): int
    {
        if (! config('login-tracker.lockscreen.enabled', false)) {
            return 0;
        }

        $sessions = $this->sessionsFor($user)->online()->get();

        foreach ($sessions as $session) {
            $session->requestLock();
        }

        return $sessions->count();
    }

    /**
     * Historico completo de logins/logouts/falhas deste utilizador.
     */
    public function historyFor(Authenticatable $user): Collection
    {
        $morphName = config('login-tracker.morph_name', 'authenticatable');

        return AuthLogin::query()
            ->where($morphName . '_id', $user->getAuthIdentifier())
            ->where($morphName . '_type', get_class($user))
            ->latest('created_at')
            ->get();
    }

    protected function sessionsFor(Authenticatable $user)
    {
        $morphName = config('login-tracker.morph_name', 'authenticatable');

        return AuthSession::query()
            ->where($morphName . '_id', $user->getAuthIdentifier())
            ->where($morphName . '_type', get_class($user));
    }
}
