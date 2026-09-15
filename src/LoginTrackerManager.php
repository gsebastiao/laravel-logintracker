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
