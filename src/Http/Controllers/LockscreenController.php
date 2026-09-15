<?php

namespace Gsebastiao\LoginTracker\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Responde ao formulario da tela de bloqueio (padrao ou customizada).
 *
 * Este controller NAO sabe nada sobre o VISUAL do lockscreen - so sabe
 * validar a password e fazer logout. Isto e o que permite ao dev trocar
 * a view inteira (config('login-tracker.lockscreen.view')) sem nunca
 * precisar de tocar neste ficheiro: a view so precisa de fazer um POST
 * para $ltUnlockUrl com o campo "password", e um POST para $ltLogoutUrl
 * quando quiser sair.
 */
class LockscreenController extends Controller
{
    /**
     * Valida a password do utilizador autenticado e "desbloqueia".
     * Nao ha nenhum estado de "bloqueado" guardado no servidor - o
     * bloqueio e um overlay do lado do CLIENTE (JS). Este endpoint so
     * confirma que quem esta a tentar desbloquear sabe a password da
     * conta atualmente autenticada, e informa ao JS que pode remover
     * o overlay.
     */
    public function unlock(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = Auth::user();

        if (! $user) {
            return response()->json(['unlocked' => false, 'message' => 'Sessao nao encontrada.'], 401);
        }

        if (! Hash::check($request->input('password'), $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'password' => [__('A password esta incorreta.')],
            ]);
        }

        return response()->json(['unlocked' => true]);
    }

    /**
     * Logout a partir do ecra de bloqueio (botao "Sair", quando
     * 'allow_logout_from_lockscreen' esta ativo). Faz logout REAL
     * (dispara o evento Logout do Laravel, que por sua vez fecha o
     * historico/sessao deste pacote normalmente - reaproveita a
     * logica que ja existe, nao ha nada especial aqui).
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::guard(config('auth.defaults.guard'))->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $loginRouteName = config('login-tracker.lockscreen.login_route_name', 'login');

        return redirect()->route($loginRouteName);
    }
}
