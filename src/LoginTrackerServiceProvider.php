<?php

namespace Gsebastiao\LoginTracker;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Gsebastiao\LoginTracker\Console\ForceLockCommand;
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
        $this->mergeConfigFrom(__DIR__ . '/../config/logintracker.php', 'logintracker');

        $this->app->singleton('logintracker', fn () => new LoginTrackerManager());
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'logintracker');
        $this->loadRoutesFrom(__DIR__ . '/../routes/logintracker.php');

        // As migrations correm com `php artisan migrate`, mesmo sem serem
        // publicadas. Ficam FORA do runningInConsole(): quem dispara o migrate
        // a partir de HTTP (Artisan::call('migrate') num instalador, num
        // webhook de deploy ou nos testes do projeto) tem de as ver na mesma.
        //
        // Ao publicar, os ficheiros mantem o nome original; o Laravel indexa
        // as migrations pelo nome, por isso a copia do projeto substitui a do
        // pacote e as tabelas nunca sao criadas duas vezes.
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        BladeDirective::register();

        $this->registerMiddleware();
        $this->registerViewComposer();
        $this->registerEventListeners();

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();

            $this->commands([
                PurgeOldLoginsCommand::class,
                ForceLockCommand::class,
            ]);
        }
    }

    /**
     * Publicar e opcional - serve para quem quer editar o esquema antes de
     * correr. Ao publicar, os ficheiros mantem o nome original, para o Laravel
     * as reconhecer como as mesmas e nunca as correr duas vezes.
     */
    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__ . '/../config/logintracker.php' => config_path('logintracker.php'),
        ], 'logintracker-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'logintracker-migrations');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/logintracker'),
        ], 'logintracker-views');

        $this->publishes([
            __DIR__ . '/../resources/js' => public_path('vendor/logintracker'),
        ], 'logintracker-assets');
    }

    protected function registerEventListeners(): void
    {
        $this->app['events']->listen(Login::class, LogSuccessfulLogin::class);
        $this->app['events']->listen(Logout::class, LogSuccessfulLogout::class);
        $this->app['events']->listen(Failed::class, LogFailedLogin::class);
    }

    protected function registerMiddleware(): void
    {
        $router = $this->app['router'];
        $router->aliasMiddleware('logintracker.seen', UpdateLastSeen::class);

        if (! config('logintracker.heartbeat.middleware_enabled', true)) {
            return;
        }

        $router->pushMiddlewareToGroup('web', UpdateLastSeen::class);
        $router->pushMiddlewareToGroup('api', UpdateLastSeen::class);
    }

    /**
     * Injeta as variaveis do lockscreen na view configurada, seja ela a
     * padrao do pacote ou uma view totalmente customizada apontada em
     * config('logintracker.lockscreen.view').
     */
    protected function registerViewComposer(): void
    {
        if (! config('logintracker.lockscreen.enabled', false)) {
            return;
        }

        View::composer(
            config('logintracker.lockscreen.view', 'logintracker::lockscreen'),
            LockscreenComposer::class
        );
    }
}
