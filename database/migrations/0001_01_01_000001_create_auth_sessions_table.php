<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected function tableName(): string
    {
        return config('login-tracker.sessions_table', 'auth_sessions');
    }

    protected function connectionName(): ?string
    {
        return config('login-tracker.connection');
    }

    public function up(): void
    {
        Schema::connection($this->connectionName())->create($this->tableName(), function (Blueprint $table) {
            $table->id();

            $morphName = config('login-tracker.morph_name', 'authenticatable');
            $table->morphs($morphName);

            $table->string('guard')->index();

            // ID da sessao do Laravel (session()->getId()) OU, para APIs
            // stateless com token (Sanctum/Passport), o ID do token.
            // Permite distinguir multiplas sessoes simultaneas do MESMO
            // utilizador (ex: logado no browser E no telemovel ao mesmo tempo).
            $table->string('session_id')->nullable()->index();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Quando esta sessao especifica comecou (= login_at correspondente
            // na tabela de historico).
            $table->timestamp('started_at');

            // Atualizado a cada requisicao (via middleware) ou a cada ping
            // (via JS de heartbeat). ISTO e o campo que responde "esta online".
            $table->timestamp('last_seen_at')->index();

            // Preenchido quando a sessao e encerrada (logout explicito OU
            // detectada como morta pela purga). NULL = ainda esta "aberta".
            $table->timestamp('ended_at')->nullable();

            $table->timestamps();

            // Uma sessao (session_id) so pode ter UMA linha ativa.
            $table->unique(['guard', 'session_id']);

            $table->index([$morphName . '_id', $morphName . '_type', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connectionName())->dropIfExists($this->tableName());
    }
};
