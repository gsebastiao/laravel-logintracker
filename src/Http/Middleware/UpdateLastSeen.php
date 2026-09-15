<?php

namespace Gsebastiao\LoginTracker\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Gsebastiao\LoginTracker\Models\AuthSession;

/**
 * Atualiza last_seen_at da sessao atual em toda requisicao autenticada.
 *
 * Isto cobre o caso comum: o utilizador esta a navegar/clicar/usar a API
 * normalmente. Nao cobre o caso de "aba aberta, parado, sem clicar em
 * nada" - para isso existe o endpoint de heartbeat JS (ver
 * routes/login-tracker.php e resources/js/heartbeat.js).
 *
 * Usa throttle (heartbeat.middleware_throttle_seconds) para nao gravar
 * na BD a cada request - so grava se passou tempo suficiente desde o
 * ultimo registo.
 */
class UpdateLastSeen
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (! config('login-tracker.heartbeat.enabled', true)) {
            return $response;
        }

        if (! config('login-tracker.heartbeat.middleware_enabled', true)) {
            return $response;
        }

        $guard = $request->route()?->getAction('guard') ?? config('auth.defaults.guard');
        $user = $request->user($guard);

        if (! $user) {
            return $response;
        }

        $this->touch($request, $user, $guard);

        return $response;
    }

    protected function touch(Request $request, $user, ?string $guard): void
    {
        $sessionId = $this->resolveSessionId($request, $guard);

        if (! $sessionId) {
            return;
        }

        $throttleSeconds = config('login-tracker.heartbeat.middleware_throttle_seconds', 30);
        $now = now();

        $session = AuthSession::query()
            ->where('guard', $guard)
            ->where('session_id', $sessionId)
            ->whereNull('ended_at')
            ->first();

        if (! $session) {
            // Sessao ainda nao existe no heartbeat (ex: user autenticado por
            // outro meio que nao disparou o evento Login, como um guard
            // customizado). Cria aqui para nao perder o rasto.
            $morphName = config('login-tracker.morph_name', 'authenticatable');

            AuthSession::create([
                $morphName . '_id'   => $user->getAuthIdentifier(),
                $morphName . '_type' => get_class($user),
                'guard'        => $guard,
                'session_id'   => $sessionId,
                'ip_address'   => config('login-tracker.capture.ip_address', true) ? $request->ip() : null,
                'user_agent'   => config('login-tracker.capture.user_agent', true) ? $request->userAgent() : null,
                'started_at'   => $now,
                'last_seen_at' => $now,
            ]);

            return;
        }

        // Throttle: so grava se passou tempo suficiente desde o ultimo
        // registo, para nao fazer UPDATE a cada request.
        if ($session->last_seen_at && $session->last_seen_at->diffInSeconds($now) < $throttleSeconds) {
            return;
        }

        $session->update(['last_seen_at' => $now]);
    }

    protected function resolveSessionId(Request $request, ?string $guard): ?string
    {
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
}
