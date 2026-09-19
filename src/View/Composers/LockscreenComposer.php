<?php

namespace Gsebastiao\LoginTracker\View\Composers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Gsebastiao\LoginTracker\LockState;

/**
 * Injeta automaticamente as variaveis do lockscreen em QUALQUER view de
 * bloqueio - a padrao do pacote ou uma totalmente sua, apontada em
 * config('logintracker.lockscreen.view').
 *
 * Nao precisa de escrever nenhum controller ou PHP para as obter.
 */
class LockscreenComposer
{
    public function compose(View $view): void
    {
        $user = Auth::user();

        $view->with([
            'ltUser'          => $user,
            'ltUserName'      => $this->name($user),
            'ltUserAvatarUrl' => $this->avatar($user),
            'ltIsLocked'      => LockState::isLocked(),
            'ltUnlockUrl'     => route('logintracker.unlock'),
            'ltLockUrl'       => route('logintracker.lock'),
            'ltLogoutUrl'     => config('logintracker.lockscreen.allow_logout_from_lockscreen', true)
                ? route('logintracker.lockscreen-logout')
                : null,
            'ltCsrfToken'     => csrf_token(),
            'ltConfig'        => config('logintracker.lockscreen', []),
        ]);
    }

    protected function name($user): string
    {
        if (! $user) {
            return 'Utilizador';
        }

        return $user->name ?? $user->email ?? 'Utilizador';
    }

    protected function avatar($user): ?string
    {
        if (! $user) {
            return null;
        }

        if (! empty($user->avatar_url)) {
            return $user->avatar_url;
        }

        if (! empty($user->avatar)) {
            return $user->avatar;
        }

        return 'https://ui-avatars.com/api/?name=' . urlencode($this->name($user)) . '&background=1f2937&color=fff&size=128';
    }
}
