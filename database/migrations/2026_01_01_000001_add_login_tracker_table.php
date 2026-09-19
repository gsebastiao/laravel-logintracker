<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration INCREMENTAL do LoginTracker.
 *
 * ESTE E O UNICO FICHEIRO ONDE SE ADICIONAM COLUNAS OU TABELAS NOVAS.
 *
 * Em vez de criar uma migration nova a cada atualizacao do pacote (o
 * que polui a pasta database/migrations com dezenas de ficheiros), toda
 * a evolucao do esquema passa por aqui.
 *
 * COMO FUNCIONA:
 * Cada bloco abaixo verifica PRIMEIRO no banco se a coluna/tabela ja
 * existe (Schema::hasColumn / hasTable). Se existir, salta. Se nao,
 * cria. Isto torna a migration IDEMPOTENTE: pode ser corrida as vezes
 * que forem precisas, em bancos em qualquer estado, sem nunca dar erro
 * de "coluna ja existe".
 *
 * COMO ATUALIZAR QUANDO SAIR UMA VERSAO NOVA DO PACOTE:
 *   1. php artisan vendor:publish --tag=logintracker-migrations --force
 *   2. php artisan migrate
 *
 * O passo 1 substitui este ficheiro pela versao mais recente (que traz
 * os blocos novos ja escritos). O passo 2 aplica so o que falta - o que
 * ja existe e saltado automaticamente.
 *
 * COMO ADICIONAR ALGO SEU (colunas proprias da sua aplicacao):
 * Acrescente um bloco novo no fim do metodo up(), seguindo o mesmo
 * padrao de verificacao. Mantenha os blocos existentes intactos para
 * as atualizacoes futuras do pacote nao entrarem em conflito.
 */
return new class extends Migration
{
    protected function connectionName(): ?string
    {
        return config('logintracker.connection');
    }

    protected function logsTable(): string
    {
        return config('logintracker.table', 'auth_logins');
    }

    protected function sessionsTable(): string
    {
        return config('logintracker.sessions_table', 'auth_sessions');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->connectionName());

        // ------------------------------------------------------------
        // v1.x -> auth_logins.logout_reason
        // Distingue a causa da saida: manual | expired_client |
        // inferred_stale. Substitui o antigo campo booleano
        // logout_inferred, que e mantido por compatibilidade.
        // ------------------------------------------------------------
        if ($schema->hasTable($this->logsTable()) && ! $schema->hasColumn($this->logsTable(), 'logout_reason')) {
            $schema->table($this->logsTable(), function (Blueprint $table) {
                $table->string('logout_reason')->nullable()->after('logout_at');
            });
        }

        // ------------------------------------------------------------
        // v2.x -> auth_sessions.locked_at
        // Estado REAL do lockscreen, guardado no servidor. E isto que
        // faz o bloqueio sobreviver a um refresh (F5) ou a abrir uma
        // aba nova - sem esta coluna o bloqueio era apenas visual e
        // desaparecia ao recarregar a pagina.
        // ------------------------------------------------------------
        if ($schema->hasTable($this->sessionsTable()) && ! $schema->hasColumn($this->sessionsTable(), 'locked_at')) {
            $schema->table($this->sessionsTable(), function (Blueprint $table) {
                $table->timestamp('locked_at')->nullable()->after('last_seen_at');
            });
        }

        // ------------------------------------------------------------
        // v2.x -> auth_sessions.lock_requested_at
        // Pedido de bloqueio remoto pendente (admin a bloquear a sessao
        // de outro utilizador), entregue no proximo heartbeat.
        // ------------------------------------------------------------
        if ($schema->hasTable($this->sessionsTable()) && ! $schema->hasColumn($this->sessionsTable(), 'lock_requested_at')) {
            $schema->table($this->sessionsTable(), function (Blueprint $table) {
                $table->timestamp('lock_requested_at')->nullable()->after('locked_at');
            });
        }

        // ------------------------------------------------------------
        // Compatibilidade: instalacoes antigas podem ter o campo
        // booleano logout_inferred. Nao e criado em instalacoes novas,
        // mas se existir, continua a ser preenchido pelo Model.
        // ------------------------------------------------------------

        // ------------------------------------------------------------
        // ADICIONE AQUI OS SEUS PROPRIOS BLOCOS
        // Siga sempre o padrao: verificar com hasColumn/hasTable antes
        // de alterar, para a migration continuar idempotente.
        // ------------------------------------------------------------
    }

    /**
     * Reverte apenas o que esta migration adiciona, e so se existir.
     * As tabelas base sao da responsabilidade da migration
     * "create_login_tracker_table".
     */
    public function down(): void
    {
        $schema = Schema::connection($this->connectionName());

        foreach (['lock_requested_at', 'locked_at'] as $column) {
            if ($schema->hasTable($this->sessionsTable()) && $schema->hasColumn($this->sessionsTable(), $column)) {
                $schema->table($this->sessionsTable(), function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }

        if ($schema->hasTable($this->logsTable()) && $schema->hasColumn($this->logsTable(), 'logout_reason')) {
            $schema->table($this->logsTable(), function (Blueprint $table) {
                $table->dropColumn('logout_reason');
            });
        }
    }
};
