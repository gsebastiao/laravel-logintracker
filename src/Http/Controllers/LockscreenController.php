<?php

namespace Gsebastiao\LoginTracker\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Gsebastiao\LoginTracker\LockState;

/**
 * Endpoints do lockscreen: bloquear e desbloquear.
 *
 * Este controller nao sabe nada sobre o VISUAL do lockscreen - so
 * gere o estado. E por isso que se pode trocar a view inteira sem
 * nunca tocar aqui.
 */
class LockscreenController extends Controller
{
    /**
     * Bloqueia a sessao atual. Chamado pelo idle.js quando deteta
     * inatividade, ou por um botao "Bloquear agora" da aplicacao.
     *
     * O bloqueio fica gravado no SERVIDOR (sessao + base de dados), e e
     * isso que o faz sobreviver a um refresh ou a uma aba nova.
     */
    public function lock(Request $request): JsonResponse
    {
        if (! Auth::check()) {
            return response()->json(['locked' => false, 'reason' => 'unauthenticated'], 401);
        }

        LockState::lock();

        return response()->json(['locked' => true]);
    }

    /**
     * Valida a password e desbloqueia.
     *
     * Protegido por rate limiting: sem isso, o ecra de bloqueio seria
     * um alvo comodo para tentar passwords a velocidade de maquina,
     * ja que esta sempre acessivel em qualquer pagina.
     */
    public function unlock(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        $user = Auth::user();

        if (! $user) {
            return response()->json(['unlocked' => false, 'reason' => 'unauthenticated'], 401);
        }

        $key = 'logintracker-unlock:' . $user->getAuthIdentifier();
        $maxAttempts = (int) config('logintracker.lockscreen.max_unlock_attempts', 5);
        $decaySeconds = (int) config('logintracker.lockscreen.unlock_throttle_seconds', 60);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return response()->json([
                'unlocked' => false,
                'message'  => 'Demasiadas tentativas. Tente novamente dentro de ' . RateLimiter::availableIn($key) . ' segundos.',
            ], 429);
        }

        if (! Hash::check($request->input('password'), $user->getAuthPassword())) {
            RateLimiter::hit($key, $decaySeconds);

            return response()->json([
                'unlocked' => false,
                'message'  => 'Password incorreta.',
            ], 422);
        }

        RateLimiter::clear($key);
        LockState::unlock();

        return response()->json(['unlocked' => true]);
    }

    /**
     * Logout a partir do ecra de bloqueio. Limpa o estado de bloqueio
     * antes de terminar a sessao, para nao deixar lixo na base de dados.
     */
    public function logout(Request $request)
    {
        LockState::unlock();

        Auth::guard(config('auth.defaults.guard'))->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $route = config('logintracker.lockscreen.login_route_name', 'login');
        $url = \Illuminate\Support\Facades\Route::has($route) ? route($route) : url('/login');

        return $request->expectsJson()
            ? response()->json(['logged_out' => true, 'redirect_url' => $url])
            : redirect()->to($url);
    }
}
