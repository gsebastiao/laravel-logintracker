<?php

namespace Gsebastiao\LoginTracker;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Gsebastiao\LoginTracker\Console\PurgeOldLoginsCommand;
use Gsebastiao\LoginTracker\Http\Middleware\UpdateLastSeen;
use Gsebastiao\LoginTracker\Listeners\LogFailedLogin;
use Gsebastiao\LoginTracker\Listeners\LogSuccessfulLogin;
use Gsebastiao\LoginTracker\Listeners\LogSuccessfulLogout;
use Gsebastiao\LoginTracker\View\Composers\LockscreenComposer;

class LoginTrackerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/login-tracker.php',
            'login-tracker'
        );

        $this->app->singleton('login-tracker', function () {
            return new LoginTrackerManager();
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Regista o namespace 'login-tracker::' para as views do pacote.
        // Isto e o que permite view('login-tracker::lockscreen') funcionar,
        // e o que faz o Laravel PREFERIR automaticamente uma copia
        // publicada em resources/views/vendor/login-tracker/ (via
        // php artisan vendor:publish --tag=login-tracker-views) em vez
        // da view original do pacote, sem precisar de mudar nenhuma
        // config - e o comportamento padrao do loadViewsFrom().
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'login-tracker');

        if (config('login-tracker.heartbeat.route_enabled', true) || config('login-tracker.lockscreen.enabled', false)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/login-tracker.php');
        }

        $this->registerMiddleware();
        $this->registerLockscreen();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/login-tracker.php' => config_path('login-tracker.php'),
            ], 'login-tracker-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'login-tracker-migrations');

            $this->publishes([
                __DIR__ . '/../resources/js/heartbeat.js' => public_path('vendor/login-tracker/heartbeat.js'),
                __DIR__ . '/../resources/js/idle.js'      => public_path('vendor/login-tracker/idle.js'),
            ], 'login-tracker-assets');

            // Tag separada especificamente para a view Blade do
            // lockscreen, para o dev poder publicar SO isto (sem trazer
            // config/migrations junto) quando so quer personalizar o
            // visual do ecra de bloqueio.
            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/login-tracker'),
            ], 'login-tracker-views');

            $this->commands([
                PurgeOldLoginsCommand::class,
            ]);
        }

        $this->app['events']->listen(Login::class, LogSuccessfulLogin::class);
        $this->app['events']->listen(Logout::class, LogSuccessfulLogout::class);
        $this->app['events']->listen(Failed::class, LogFailedLogin::class);
    }

    /**
     * Regista tudo o que o lockscreen precisa: a diretiva Blade
     * @loginTrackerLockscreen, e o View Composer que injeta $ltUserName,
     * $ltUnlockUrl, etc. na view configurada - seja ela a padrao do
     * pacote OU uma view customizada apontada em
     * config('login-tracker.lockscreen.view').
     *
     * O View::composer() e registado com o NOME DA VIEW ATUAL da config
     * (nao um valor fixo), entao se o dev customizar
     * 'lockscreen.view' => 'partials.meu-lockscreen', e essa view que
     * passa a receber as variaveis automaticamente - nao precisa de
     * registar composer nenhum manualmente.
     */
    protected function registerLockscreen(): void
    {
        if (! config('login-tracker.lockscreen.enabled', false)) {
            return;
        }

        LockscreenBladeDirective::register();

        $viewName = config('login-tracker.lockscreen.view', 'login-tracker::lockscreen');
        View::composer($viewName, LockscreenComposer::class);
    }

    /**
     * Regista o middleware globalmente APENAS no grupo 'web' e 'api',
     * e so quando o heartbeat via middleware estiver ativado. Isto evita
     * bater na BD para rotas que nao precisam (ex: rotas publicas).
     * O middleware em si so age quando ha um utilizador autenticado.
     */
    protected function registerMiddleware(): void
    {
        if (! config('login-tracker.heartbeat.middleware_enabled', true)) {
            return;
        }

        $router = $this->app['router'];

        $router->pushMiddlewareToGroup('web', UpdateLastSeen::class);
        $router->pushMiddlewareToGroup('api', UpdateLastSeen::class);

        // Tambem disponivel para uso manual em rotas especificas:
        // Route::middleware('login-tracker.heartbeat')->group(...)
        $router->aliasMiddleware('login-tracker.heartbeat', UpdateLastSeen::class);
    }
}
