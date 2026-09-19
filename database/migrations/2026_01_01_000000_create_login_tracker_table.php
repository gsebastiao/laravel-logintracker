<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cria as tabelas base do LoginTracker.
 *
 * Esta migration cria TODAS as tabelas do pacote de uma so vez. Nao a
 * edite para adicionar colunas novas no futuro - use antes a migration
 * "add_login_tracker_table", que existe exatamente para isso.
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
        $morph = config('logintracker.morph_name', 'authenticatable');
        $schema = Schema::connection($this->connectionName());

        // ------------------------------------------------------------
        // auth_logins - HISTORICO (o que aconteceu, e quando)
        // ------------------------------------------------------------
        if (! $schema->hasTable($this->logsTable())) {
            $schema->create($this->logsTable(), function (Blueprint $table) use ($morph) {
                $table->id();
                $table->nullableMorphs($morph);
                $table->string('guard')->nullable()->index();
                $table->string('event')->index();            // login | logout | failed
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('location')->nullable();
                $table->string('identifier')->nullable();     // email usado numa tentativa falhada
                $table->timestamp('login_at')->nullable();
                $table->timestamp('logout_at')->nullable();
                $table->string('logout_reason')->nullable();  // manual | expired_client | inferred_stale
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['guard', 'event']);
            });
        }

        // ------------------------------------------------------------
        // auth_sessions - ESTADO ATUAL (quem esta online / bloqueado)
        // ------------------------------------------------------------
        if (! $schema->hasTable($this->sessionsTable())) {
            $schema->create($this->sessionsTable(), function (Blueprint $table) use ($morph) {
                $table->id();
                $table->morphs($morph);
                $table->string('guard')->index();
                $table->string('session_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamp('started_at');
                $table->timestamp('last_seen_at')->index();
                $table->timestamp('locked_at')->nullable();        // lockscreen ativo nesta sessao
                $table->timestamp('lock_requested_at')->nullable(); // pedido de bloqueio remoto pendente
                $table->timestamp('ended_at')->nullable();
                $table->timestamps();

                $table->unique(['guard', 'session_id']);
                $table->index([$morph . '_id', $morph . '_type', 'ended_at']);
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connectionName());
        $schema->dropIfExists($this->sessionsTable());
        $schema->dropIfExists($this->logsTable());
    }
};
