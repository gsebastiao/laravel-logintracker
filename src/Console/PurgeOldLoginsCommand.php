<?php

namespace Gsebastiao\LoginTracker\Console;

use Illuminate\Console\Command;
use Gsebastiao\LoginTracker\Models\AuthLogin;
use Gsebastiao\LoginTracker\Models\AuthSession;

class PurgeOldLoginsCommand extends Command
{
    protected $signature = 'logintracker:purge
                            {--days= : Sobrepoe o numero de dias de retencao do historico}
                            {--sessions : So fecha sessoes mortas (staleness), nao apaga historico}
                            {--history : So apaga historico antigo, nao mexe em sessoes}';

    protected $description = 'Fecha sessoes "mortas" (sem heartbeat) e/ou remove historico antigo de login/logout';

    public function handle(): int
    {
        $onlySessions = (bool) $this->option('sessions');
        $onlyHistory = (bool) $this->option('history');

        if (! $onlySessions) {
            $this->purgeHistory();
        }

        if (! $onlyHistory) {
            $this->closeStaleSessions();
        }

        return self::SUCCESS;
    }

    /**
     * Remove registos de HISTORICO mais antigos que o periodo de retencao.
     */
    protected function purgeHistory(): void
    {
        $days = $this->option('days') ?? config('logintracker.retention_days');

        if (empty($days)) {
            $this->line('Retencao de historico nao configurada (logintracker.retention_days) - a saltar.');
            return;
        }

        $count = AuthLogin::where('created_at', '<', now()->subDays((int) $days))->delete();

        $this->info("Historico: removidos {$count} registo(s) com mais de {$days} dia(s).");
    }

    /**
     * Encontra sessoes sem heartbeat ha mais de "stale_after_minutes" e:
     *   1. Marca a sessao como encerrada (ended_at = ultimo last_seen_at
     *      conhecido, NAO o momento agora - para nao inflar a duracao).
     *   2. Fecha o registo correspondente no HISTORICO, marcando
     *      logout_reason = AuthLogin::LOGOUT_INFERRED_STALE, para
     *      deixar claro que foi uma deducao (ninguem - nem o
     *      utilizador, nem o proprio navegador dele - confirmou a
     *      saida) e nao um logout confirmado (LOGOUT_MANUAL ou
     *      LOGOUT_EXPIRED_CLIENT).
     *
     * Isto e o que resolve o problema de "sessoes fantasma" que ficariam
     * para sempre marcadas como abertas/online sem isto rodar.
     */
    protected function closeStaleSessions(): void
    {
        if (! config('logintracker.heartbeat.enabled', true)) {
            $this->line('Heartbeat desativado (logintracker.heartbeat.enabled) - a saltar sessoes.');
            return;
        }

        $morphName = config('logintracker.morph_name', 'authenticatable');
        $staleSessions = AuthSession::query()->stale()->get();

        if ($staleSessions->isEmpty()) {
            $this->line('Sessoes: nenhuma sessao morta encontrada.');
            return;
        }

        $closedSessions = 0;
        $closedHistoryRows = 0;

        foreach ($staleSessions as $session) {
            $estimatedLogoutAt = $session->last_seen_at ?? $session->started_at;

            $session->update(['ended_at' => $estimatedLogoutAt]);
            $closedSessions++;

            $lastLogin = AuthLogin::query()
                ->where($morphName . '_id', $session->{$morphName . '_id'})
                ->where($morphName . '_type', $session->{$morphName . '_type'})
                ->where('guard', $session->guard)
                ->where('event', 'login')
                ->whereNull('logout_at')
                ->where('login_at', '<=', $session->started_at->addMinute())
                ->latest('login_at')
                ->first();

            if ($lastLogin) {
                $lastLogin->update([
                    'logout_at'     => $estimatedLogoutAt,
                    'logout_reason' => AuthLogin::LOGOUT_INFERRED_STALE,
                ]);
                $closedHistoryRows++;
            }
        }

        $this->info("Sessoes: {$closedSessions} sessao(oes) morta(s) encerrada(s), {$closedHistoryRows} registo(s) de historico fechado(s) por inferencia.");
    }
}
