<?php

namespace Gsebastiao\LoginTracker\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool isOnline(\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static \Illuminate\Database\Eloquent\Collection activeSessionsFor(\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static \Illuminate\Database\Eloquent\Builder onlineSessions()
 * @method static \DateInterval|null onlineDuration(\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static string|null onlineDurationForHumans(\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static int forceLock(\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static \Illuminate\Database\Eloquent\Collection historyFor(\Illuminate\Contracts\Auth\Authenticatable $user)
 *
 * @see \Gsebastiao\LoginTracker\LoginTrackerManager
 */
class LoginTracker extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'login-tracker';
    }
}
