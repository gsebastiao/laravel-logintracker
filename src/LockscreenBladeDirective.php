<?php

namespace Gsebastiao\LoginTracker;

use Illuminate\Support\Facades\Blade;

/**
 * Registo da diretiva Blade @loginTrackerLockscreen.
 *
 * Ver LoginTrackerServiceProvider::boot() para onde isto e chamado.
 *
 * O QUE A DIRETIVA FAZ:
 * Ao colocar @loginTrackerLockscreen no seu layout (normalmente mesmo
 * antes de </body>), o pacote imprime, nessa posicao:
 *
 *   1. A view do overlay de lockscreen (a padrao do pacote, ou a sua
 *      customizada - o que estiver em config('login-tracker.lockscreen.view')).
 *   2. Um <script> com a configuracao (idle_seconds) para o idle.js.
 *   3. A tag <script> a carregar o idle.js publicado.
 *
 * Tudo isto SO acontece se:
 *   - config('login-tracker.lockscreen.enabled') for true, E
 *   - o utilizador estiver autenticado (Auth::check()) - nao faz
 *     sentido mostrar lockscreen numa pagina publica/de login.
 *
 * Se qualquer uma destas condicoes for falsa, a diretiva nao imprime
 * absolutamente nada - zero overhead, zero HTML extra no <body>.
 */
class LockscreenBladeDirective
{
    public static function register(): void
    {
        Blade::directive('loginTrackerLockscreen', function () {
            return "<?php echo \\Gsebastiao\\LoginTracker\\LockscreenBladeDirective::render(); ?>";
        });
    }

    public static function render(): string
    {
        if (! config('login-tracker.lockscreen.enabled', false)) {
            return '';
        }

        if (! auth()->check()) {
            return '';
        }

        $view = view(config('login-tracker.lockscreen.view', 'login-tracker::lockscreen'))->render();

        $idleSeconds = (int) config('login-tracker.lockscreen.idle_seconds', 900);
        $idleJsUrl = asset('vendor/login-tracker/idle.js');

        return $view . <<<HTML
            <script>
                window.LoginTrackerLockscreenConfig = {
                    idleSeconds: {$idleSeconds}
                };
            </script>
            <script src="{$idleJsUrl}"></script>
            HTML;
    }
}
