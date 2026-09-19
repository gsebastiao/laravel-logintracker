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
     * As migrations sao publicadas como STUBS, com timestamp gerado no
     * momento da publicacao - nao sao carregadas automaticamente de
     * dentro do pacote. Isto da ao programador controlo total sobre o
     * esquema (pode editar antes de correr) e mantem a pasta
     * database/migrations limpa: apenas 2 ficheiros, hoje e no futuro.
     */
    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__ . '/../config/logintracker.php' => config_path('logintracker.php'),
        ], 'logintracker-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/create_login_tracker_table.php.stub'
                => $this->migrationPath('create_login_tracker_table'),
            __DIR__ . '/../database/migrations/add_login_tracker_table.php.stub'
                => $this->migrationPath('add_login_tracker_table'),
        ], 'logintracker-migrations');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/logintracker'),
        ], 'logintracker-views');

        $this->publishes([
            __DIR__ . '/../resources/js' => public_path('vendor/logintracker'),
        ], 'logintracker-assets');
    }

    /**
     * Resolve o caminho de destino de uma migration publicada.
     *
     * Se ja existir uma migration com este nome (publicada antes),
     * devolve o MESMO caminho - assim, republicar com --force atualiza
     * o ficheiro existente em vez de criar um duplicado com timestamp
     * novo, que causaria confusao e erros de migration repetida.
     */
    protected function migrationPath(string $name): string
    {
        $existing = glob(database_path('migrations/*_' . $name . '.php'));

        if (! empty($existing)) {
            return $existing[0];
        }

        return database_path('migrations/' . date('Y_m_d_His') . '_' . $name . '.php');
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
