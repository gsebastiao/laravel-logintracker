<?php

namespace Gsebastiao\LoginTracker\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

class AuthSession extends Model
{
    protected $guarded = [];

    protected $casts = [
        'started_at'  => 'datetime',
        'last_seen_at' => 'datetime',
        'ended_at'    => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('login-tracker.sessions_table', 'auth_sessions'));

        if ($connection = config('login-tracker.connection')) {
            $this->setConnection($connection);
        }
    }

    public function authenticatable(): MorphTo
    {
        return $this->morphTo(config('login-tracker.morph_name', 'authenticatable'));
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Sessoes consideradas ONLINE agora: nao encerradas E com heartbeat
     * recente (dentro do threshold configurado).
     */
    public function scopeOnline(Builder $query): Builder
    {
        $thresholdSeconds = config('login-tracker.heartbeat.online_threshold_seconds', 120);

        return $query->whereNull('ended_at')
            ->where('last_seen_at', '>=', now()->subSeconds($thresholdSeconds));
    }

    /**
     * Sessoes OFFLINE: ja encerradas explicitamente OU heartbeat expirou
     * (mesmo que ninguem tenha chamado logout ainda - deteccao passiva).
     */
    public function scopeOffline(Builder $query): Builder
    {
        $thresholdSeconds = config('login-tracker.heartbeat.online_threshold_seconds', 120);

        return $query->where(function (Builder $q) use ($thresholdSeconds) {
            $q->whereNotNull('ended_at')
              ->orWhere('last_seen_at', '<', now()->subSeconds($thresholdSeconds));
        });
    }

    /**
     * Sessoes "mortas": sem heartbeat ha mais tempo que stale_after_minutes.
     * Usado pelo comando de purga para fechar sessoes penduradas.
     */
    public function scopeStale(Builder $query): Builder
    {
        $staleMinutes = config('login-tracker.heartbeat.stale_after_minutes', 30);

        return $query->whereNull('ended_at')
            ->where('last_seen_at', '<', now()->subMinutes($staleMinutes));
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers de instancia
    |--------------------------------------------------------------------------
    */

    /**
     * Esta sessao especifica esta online neste momento?
     */
    public function isOnline(): bool
    {
        if ($this->ended_at !== null) {
            return false;
        }

        $thresholdSeconds = config('login-tracker.heartbeat.online_threshold_seconds', 120);

        return $this->last_seen_at !== null
            && $this->last_seen_at->greaterThanOrEqualTo(now()->subSeconds($thresholdSeconds));
    }

    /**
     * Duracao da sessao ate agora (se ainda aberta) ou ate ended_at
     * (se ja fechada). Sempre calculada a partir de last_seen_at para
     * sessoes fechadas por timeout, que e o instante REAL em que o
     * utilizador desapareceu - nao o momento em que a purga rodou.
     */
    public function duration(): \DateInterval
    {
        $end = $this->ended_at ?? $this->last_seen_at ?? now();

        return $this->started_at->diff($end);
    }

    public function durationInSeconds(): int
    {
        $end = $this->ended_at ?? $this->last_seen_at ?? now();

        return $this->started_at->diffInSeconds($end);
    }

    public function durationForHumans(): string
    {
        $end = $this->ended_at ?? $this->last_seen_at ?? now();

        return $this->started_at->diffForHumans($end, ['parts' => 2, 'short' => true, 'syntax' => Carbon::DIFF_ABSOLUTE]);
    }
}
