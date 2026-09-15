<?php

namespace Gsebastiao\LoginTracker\Listeners;

use Illuminate\Auth\Events\Failed;
use Gsebastiao\LoginTracker\Models\AuthLogin;

class LogFailedLogin
{
    public function handle(Failed $event): void
    {
        if (! config('login-tracker.events.failed', true)) {
            return;
        }

        if (! $this->guardIsTracked($event->guard)) {
            return;
        }

        $morphName = config('login-tracker.morph_name', 'authenticatable');
        $request = request();

        AuthLogin::create([
            $morphName . '_id'   => $event->user?->getAuthIdentifier(),
            $morphName . '_type' => $event->user ? get_class($event->user) : null,
            'guard'      => $event->guard,
            'event'      => 'failed',
            'identifier' => $event->credentials['email'] ?? $event->credentials['username'] ?? null,
            'ip_address' => config('login-tracker.capture.ip_address', true) ? $request?->ip() : null,
            'user_agent' => config('login-tracker.capture.user_agent', true) ? $request?->userAgent() : null,
        ]);
    }

    protected function guardIsTracked(?string $guard): bool
    {
        $guards = config('login-tracker.guards', ['web']);

        return in_array('*', $guards, true) || in_array($guard, $guards, true);
    }
}
