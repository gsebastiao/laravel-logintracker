<?php

namespace Gsebastiao\LoginTracker\Console;

use Illuminate\Console\Command;
use Gsebastiao\LoginTracker\Facades\LoginTracker;

/**
 * Permite forcar o lockscreen de um utilizador a partir do terminal, ou
 * de qualquer lugar que consiga disparar um comando artisan (um Job
 * agendado, um webhook, um script externo, etc.) - sem precisar de
 * escrever nenhum PHP customizado para isso.
 *
 * Para forcar a partir de DENTRO do codigo da sua aplicacao (ex: num
 * controller de admin, ao reagir a um evento suspeito), prefira chamar
 * diretamente LoginTracker::forceLock($user) em vez deste comando - e
 * mais direto e evita o overhead de around um processo artisan novo.
 * Este comando existe para os casos em que voce PRECISA de disparar
 * isto de fora do ciclo de vida normal do Laravel.
 */
class ForceLockCommand extends Command
{
    protected $signature = 'login-tracker:lock
                            {user_id : O ID do utilizador (o mesmo valor devolvido por getAuthIdentifier())}
                            {--model= : Classe do Model, se a sua aplicacao tiver mais que um tipo de utilizador autenticavel (ex: --model="App\\Models\\Admin"). Por defeito usa config(\'auth.providers.users.model\')}';

    protected $description = 'Forca a exibicao do lockscreen em todas as sessoes ativas de um utilizador';

    public function handle(): int
    {
        $modelClass = $this->option('model') ?? config('auth.providers.users.model');

        if (! $modelClass || ! class_exists($modelClass)) {
            $this->error("Nao foi possivel determinar a classe do Model de utilizador. Use --model=\"App\\Models\\SeuModel\".");
            return self::FAILURE;
        }

        $user = $modelClass::find($this->argument('user_id'));

        if (! $user) {
            $this->error("Nenhum utilizador encontrado com id '{$this->argument('user_id')}' em {$modelClass}.");
            return self::FAILURE;
        }

        if (! config('login-tracker.lockscreen.enabled', false)) {
            $this->warn('login-tracker.lockscreen.enabled esta desativado na configuracao - LoginTracker::forceLock() nao grava nenhum pedido nesse caso (ver LoginTrackerManager::forceLock()). Ative o lockscreen primeiro (LOGIN_TRACKER_LOCKSCREEN_ENABLED=true) para este comando ter efeito.');
            return self::SUCCESS;
        }

        $affected = LoginTracker::forceLock($user);

        if ($affected === 0) {
            $this->line("Utilizador encontrado, mas nao tinha nenhuma sessao online neste momento - nada para bloquear.");
            return self::SUCCESS;
        }

        $this->info("Pedido de bloqueio enviado para {$affected} sessao(oes) ativa(s). Sera entregue no proximo heartbeat de cada uma (ate " . config('login-tracker.heartbeat.ping_interval_seconds', 60) . "s).");

        return self::SUCCESS;
    }
}
