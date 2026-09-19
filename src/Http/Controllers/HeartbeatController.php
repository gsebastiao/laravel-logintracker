<?php

namespace Gsebastiao\LoginTracker\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route as RouteFacade;
use Gsebastiao\LoginTracker\LockState;
use Gsebastiao\LoginTracker\Models\AuthLogin;
use Gsebastiao\LoginTracker\Models\AuthSession;

/**
 * Heartbeat: mantem o estado "online" atualizado e informa o browser
 * sobre mudancas que exigem reacao (sessao expirada, bloqueio remoto).
 *
 * IMPORTANTE - porque esta rota NAO usa o middleware "auth":
 * Se usasse, o Laravel intercetaria o pedido antes de chegar aqui
 * quando a sessao expirasse, e - dependendo da configuracao da
 * aplicacao - poderia responder com um redirect 302 para a pagina de
 * login em vez de um 401. O fetch() do browser segue redirects
 * automaticamente, recebendo 200 com o HTML do login, e o JavaScript
 * nunca perceberia que a sessao tinha morrido. Era esta a causa de o
 * logout automatico nao acontecer.
 *
 * Ao dispensar o middleware e verificar a autenticacao aqui dentro,
 * garantimos uma resposta JSON previsivel em todos os casos.
 */
class HeartbeatController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $guard = config('auth.defaults.guard');
        $user = $request->user($guard) ?? Auth::guard($guard)->user();

        // Sessao expirada / nao autenticado.
        if (! $user) {
            return response()->json([
                'authenticated'   => false,
                'auto_logout'     => (bool) config('logintracker.heartbeat.auto_logout_on_expiry', true),
                'redirect_url'    => $this->loginUrl(),
                'force_logout_url' => route('logintracker.force-logout'),
            ]);
        }

        $sessionId = $this->sessionId($request, $guard);

        if (! $sessionId) {
            return response()->json(['authenticated' => true, 'tracked' => false]);
        }

        $now = now();
        $morph = config('logintracker.morph_name', 'authenticatable');

        // Le o pedido de bloqueio remoto ANTES de gravar o heartbeat, e
        // nao inclui essa coluna no update abaixo - caso contrario um
        // forceLock() concorrente seria apagado por este proprio ping
        // antes de chegar a ser entregue ao browser.
        $existing = AuthSession::query()
            ->where('guard', $guard)
            ->where('session_id', $sessionId)
            ->first();

        $remoteLockPending = $existing && $existing->lock_requested_at !== null;

        $session = AuthSession::updateOrCreate(
            ['guard' => $guard, 'session_id' => $sessionId],
            [
                $morph . '_id'   => $user->getAuthIdentifier(),
                $morph . '_type' => get_class($user),
                'ip_address'     => config('logintracker.capture.ip_address', true) ? $request->ip() : null,
                'user_agent'     => config('logintracker.capture.user_agent', true) ? $request->userAgent() : null,
                'last_seen_at'   => $now,
                'ended_at'       => null,
            ]
        );

        if ($session->wasRecentlyCreated) {
            $session->update(['started_at' => $now]);
        }

        if ($remoteLockPending) {
            $session->update(['lock_requested_at' => null]);
            LockState::lock();
        }

        return response()->json([
            'authenticated' => true,
            'tracked'       => true,
            'locked'        => config('logintracker.lockscreen.enabled', false) && LockState::isLocked(),
            'next_ping_in'  => (int) config('logintracker.heartbeat.ping_interval_seconds', 60),
        ]);
    }

    /**
     * Encerra formalmente a sessao expirada e devolve o destino do
     * redirecionamento. Chamado pelo heartbeat.js assim que deteta que
     * a sessao ja nao e valida.
     *
     * Nao exige autenticacao valida - e chamado exatamente porque ela
     * ja nao existe. Identificamos a sessao pelo cookie, que ainda
     * carrega o session_id mesmo depois de o utilizador deixar de estar
     * autenticado.
     */
    public function forceLogout(Request $request): JsonResponse
    {
        $guard = config('auth.defaults.guard');

        // A ordem importa: ler o session_id antes de invalidar a sessao.
        $sessionId = $this->sessionId($request, $guard);

        if ($sessionId) {
            $this->closeExpiredSession($guard, $sessionId);
        }

        if (Auth::guard($guard)->check()) {
            Auth::guard($guard)->logout();
        }

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'logged_out'   => true,
            'redirect_url' => $this->loginUrl(),
        ]);
    }

    protected function closeExpiredSession(string $guard, string $sessionId): void
    {
        $morph = config('logintracker.morph_name', 'authenticatable');
        $now = now();

        $session = AuthSession::query()
            ->where('guard', $guard)
            ->where('session_id', $sessionId)
            ->whereNull('ended_at')
            ->first();

        if (! $session) {
            return;
        }

        $session->update(['ended_at' => $now, 'locked_at' => null]);

        $log = AuthLogin::query()
            ->where($morph . '_id', $session->{$morph . '_id'})
            ->where($morph . '_type', $session->{$morph . '_type'})
            ->where('guard', $guard)
            ->where('event', 'login')
            ->whereNull('logout_at')
            ->latest('login_at')
            ->first();

        $log?->update([
            'logout_at'     => $now,
            'logout_reason' => AuthLogin::LOGOUT_EXPIRED_CLIENT,
        ]);
    }

    protected function sessionId(Request $request, ?string $guard): ?string
    {
        if ($request->hasSession()) {
            return 'sess_' . $request->session()->getId();
        }

        $user = $request->user($guard);

        if ($user && method_exists($user, 'currentAccessToken') && ($token = $user->currentAccessToken())) {
            return 'token_' . ($token->id ?? md5((string) $token));
        }

        if ($bearer = $request->bearerToken()) {
            return 'bearer_' . md5($bearer);
        }

        return null;
    }

    protected function loginUrl(): string
    {
        $name = config('logintracker.heartbeat.expired_redirect_route_name')
            ?? config('logintracker.lockscreen.login_route_name', 'login');

        return RouteFacade::has($name) ? route($name) : url('/login');
    }
}
