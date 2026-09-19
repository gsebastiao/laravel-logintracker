<?php

namespace Gsebastiao\LoginTracker;

use Illuminate\Support\Facades\Blade;

/**
 * Regista a diretiva Blade @logintracker.
 *
 * UMA UNICA LINHA NO LAYOUT INCLUI TUDO:
 *   - o overlay do lockscreen (se ativo);
 *   - a configuracao JS (URLs, CSRF, intervalos);
 *   - o heartbeat.js (status online + deteccao de sessao expirada);
 *   - o idle.js (bloqueio por inatividade).
 *
 * Nao e preciso colar blocos de <script> a mao. Versoes anteriores do
 * pacote exigiam isso e era a causa mais comum de "nao funciona":
 * bastava esquecer uma variavel para o logout automatico ou o
 * lockscreen ficarem silenciosamente mortos.
 *
 * O overlay so e impresso se o lockscreen estiver ativo E houver um
 * utilizador autenticado. Os scripts de heartbeat sao impressos sempre
 * que houver utilizador autenticado, mesmo com o lockscreen desligado.
 */
class BladeDirective
{
    public static function register(): void
    {
        Blade::directive('logintracker', fn () => "<?php echo \\Gsebastiao\\LoginTracker\\BladeDirective::render(); ?>");

        // Alias retrocompativel com a versao anterior do pacote.
        Blade::directive('loginTrackerLockscreen', fn () => "<?php echo \\Gsebastiao\\LoginTracker\\BladeDirective::render(); ?>");
    }

    public static function render(): string
    {
        if (! auth()->check()) {
            return self::debugComment('Nenhum utilizador autenticado nesta pagina.');
        }

        $html = '';

        if (config('logintracker.lockscreen.enabled', false)) {
            $html .= view(config('logintracker.lockscreen.view', 'logintracker::lockscreen'))->render();
        }

        return $html . self::scripts();
    }

    protected static function scripts(): string
    {
        $lockscreenOn = (bool) config('logintracker.lockscreen.enabled', false);
        $heartbeatOn  = (bool) config('logintracker.heartbeat.enabled', true);

        $config = [
            'heartbeatUrl'        => route('logintracker.heartbeat'),
            'forceLogoutUrl'      => route('logintracker.force-logout'),
            'lockUrl'             => $lockscreenOn ? route('logintracker.lock') : null,
            'unlockUrl'           => $lockscreenOn ? route('logintracker.unlock') : null,
            'loginUrl'            => self::loginUrl(),
            'csrfToken'           => csrf_token(),
            'pingIntervalSeconds' => (int) config('logintracker.heartbeat.ping_interval_seconds', 60),
            'autoLogoutOnExpiry'  => (bool) config('logintracker.heartbeat.auto_logout_on_expiry', true),
            'lockscreenEnabled'   => $lockscreenOn,
            'idleSeconds'         => (int) config('logintracker.lockscreen.idle_seconds', 900),
            'startLocked'         => $lockscreenOn && LockState::isLocked(),
        ];

        $json = json_encode($config, JSON_UNESCAPED_SLASHES);
        $out  = "<script>window.LoginTrackerConfig = {$json};</script>";

        if ($heartbeatOn) {
            $out .= '<script src="' . asset('vendor/logintracker/heartbeat.js') . '" defer></script>';
        }

        if ($lockscreenOn) {
            $out .= '<script src="' . asset('vendor/logintracker/idle.js') . '" defer></script>';
        }

        return $out;
    }

    /**
     * URL de login da aplicacao. Se a rota configurada nao existir,
     * cai para "/login" em vez de rebentar com RouteNotFoundException
     * no meio do layout.
     */
    protected static function loginUrl(): string
    {
        $name = config('logintracker.lockscreen.login_route_name', 'login');

        return \Illuminate\Support\Facades\Route::has($name) ? route($name) : url('/login');
    }

    /**
     * Comentario de diagnostico, so visivel com APP_DEBUG=true.
     */
    protected static function debugComment(string $reason): string
    {
        if (! config('app.debug', false)) {
            return '';
        }

        return "<!-- [LoginTracker] @logintracker nao imprimiu nada: {$reason} -->";
    }
}
