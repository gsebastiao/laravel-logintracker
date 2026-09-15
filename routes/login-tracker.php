<?php

use Illuminate\Support\Facades\Route;
use Gsebastiao\LoginTracker\Http\Controllers\HeartbeatController;
use Gsebastiao\LoginTracker\Http\Controllers\LockscreenController;

Route::post(
    config('login-tracker.heartbeat.route_path', 'login-tracker/heartbeat'),
    HeartbeatController::class
)
    ->middleware(config('login-tracker.heartbeat.route_middleware', ['web', 'auth']))
    ->name('login-tracker.heartbeat');

// Endpoint de LOGOUT FORCADO, chamado pelo heartbeat.js quando deteta
// que a sessao expirou (401 do endpoint acima). Propositadamente FORA
// do middleware 'auth': este endpoint precisa de funcionar mesmo que a
// sessao ja esteja invalida - e chamado exatamente PORQUE ela expirou.
// O middleware 'web' sozinho basta (precisamos so do cookie de sessao e
// do CSRF, nao de autenticacao valida).
if (config('login-tracker.heartbeat.auto_logout_on_expiry', true)) {
    Route::post(
        'login-tracker/force-logout',
        [HeartbeatController::class, 'forceLogout']
    )
        ->middleware(['web'])
        ->name('login-tracker.force-logout');
}

if (config('login-tracker.lockscreen.enabled', false)) {
    Route::post(
        config('login-tracker.lockscreen.unlock_route_path', 'login-tracker/unlock'),
        [LockscreenController::class, 'unlock']
    )
        ->middleware(config('login-tracker.lockscreen.unlock_route_middleware', ['web', 'auth']))
        ->name('login-tracker.unlock');

    Route::post(
        'login-tracker/lockscreen-logout',
        [LockscreenController::class, 'logout']
    )
        ->middleware(config('login-tracker.lockscreen.unlock_route_middleware', ['web', 'auth']))
        ->name('login-tracker.lockscreen-logout');
}
