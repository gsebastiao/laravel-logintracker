<?php

use Illuminate\Support\Facades\Route;
use Gsebastiao\LoginTracker\Http\Controllers\HeartbeatController;
use Gsebastiao\LoginTracker\Http\Controllers\LockscreenController;

/*
 * Nenhuma destas rotas usa o middleware "auth" - todas verificam a
 * autenticacao por dentro e respondem sempre em JSON. Isto e
 * deliberado: com "auth", um pedido feito depois de a sessao expirar
 * podia receber um redirect 302 para o login, que o fetch() segue em
 * silencio, impedindo o JavaScript de perceber que a sessao morreu.
 */
Route::middleware('web')->group(function () {

    Route::post(
        config('logintracker.heartbeat.route_path', 'logintracker/heartbeat'),
        HeartbeatController::class
    )->name('logintracker.heartbeat');

    Route::post(
        'logintracker/force-logout',
        [HeartbeatController::class, 'forceLogout']
    )->name('logintracker.force-logout');

    Route::post(
        'logintracker/lock',
        [LockscreenController::class, 'lock']
    )->name('logintracker.lock');

    Route::post(
        config('logintracker.lockscreen.unlock_route_path', 'logintracker/unlock'),
        [LockscreenController::class, 'unlock']
    )->name('logintracker.unlock');

    Route::post(
        'logintracker/lockscreen-logout',
        [LockscreenController::class, 'logout']
    )->name('logintracker.lockscreen-logout');
});
