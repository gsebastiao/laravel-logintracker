<?php

namespace Gsebastiao\LoginTracker\Listeners;

use Illuminate\Auth\Events\Login;
use Gsebastiao\LoginTracker\Models\AuthLogin;
use Gsebastiao\LoginTracker\Models\AuthSession;

class LogSuccessfulLogin
{
    public function handle(Login $event): void
    {
        if (! $this->guardIsTracked($event->guard)) {
            return;
        }

        $request = request();
        $morphName = config('login-tracker.morph_name', 'authenticatable');
        $now = now();

        $ip = config('login-tracker.capture.ip_address', true) ? $request?->ip() : null;
        $userAgent = config('login-tracker.capture.user_agent', true) ? $request?->userAgent() : null;
        $sessionId = $this->resolveSessionId($request, $event->guard);

        // 1) Tabela de HISTORICO (append-only, nunca editada depois - excepto
        //    para preencher logout_at quando a saida acontece/e inferida).
        if (config('login-tracker.events.login', true)) {
            AuthLogin::create([
                $morphName . '_id'   => $event->user->getAuthIdentifier(),
                $morphName . '_type' => get_class($event->user),
                'guard'      => $event->guard,
                'event'      => 'login',
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'login_at'   => $now,
            ]);
        }

        // 2) Tabela de SESSAO ATIVA (mutavel, responde "esta online").
        //    Se ja existir uma linha para este session_id (ex: login duplicado
        //    na mesma sessao), reabre/atualiza em vez de duplicar.
        if (config('login-tracker.heartbeat.enabled', true) && $sessionId) {
            AuthSession::updateOrCreate(
                [
                    'guard'      => $event->guard,
                    'session_id' => $sessionId,
                ],
                [
                    $morphName . '_id'   => $event->user->getAuthIdentifier(),
                    $morphName . '_type' => get_class($event->user),
                    'ip_address'   => $ip,
                    'user_agent'   => $userAgent,
                    'started_at'   => $now,
                    'last_seen_at' => $now,
                    'ended_at'     => null,
                ]
            );
        }
    }

    /**
     * Resolve um identificador unico para esta sessao especifica.
     * Para guards "web" (sessao de cookie), usa o ID de sessao do Laravel.
     * Para guards stateless (api/sanctum/passport), usa o token atual.
     */
    protected function resolveSessionId($request, string $guard): ?string
    {
        if (! $request) {
            return null;
        }

        // Guard baseado em sessao de cookie
        if ($request->hasSession()) {
            return 'sess_' . $request->session()->getId();
        }

        // Guard baseado em token (Sanctum Personal Access Token, Passport, etc)
        $user = $request->user($guard);
        if ($user && method_exists($user, 'currentAccessToken')) {
            $token = $user->currentAccessToken();
            if ($token) {
                return 'token_' . ($token->id ?? md5((string) $token));
            }
        }

        // Fallback: bearer token cru (menos ideal, mas evita perder o evento)
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
