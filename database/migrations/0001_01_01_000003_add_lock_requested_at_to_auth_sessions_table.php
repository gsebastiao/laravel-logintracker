<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona 'lock_requested_at' a tabela de sessoes ativas.
 *
 * Ficheiro SEPARADO das migrations anteriores, pelo mesmo motivo de
 * sempre: editar uma migration ja corrida nao tem efeito nenhum em
 * quem ja instalou o pacote antes desta versao - uma migration nova e
 * a forma correta de alterar uma tabela que ja pode existir por ai.
 *
 * COMO ISTO RESOLVE "FORCAR O LOCKSCREEN MANUALMENTE" (a partir do
 * servidor, nao so localmente via JS):
 *
 * O overlay de lockscreen e puramente do lado do CLIENTE (ver
 * resources/js/idle.js) - nao ha nenhum estado "bloqueado" guardado no
 * servidor para uma sessao normal, de proposito (ver o comentario em
 * LockscreenController::unlock() sobre esta decisao de design). Isto
 * cria um problema real quando se quer forcar o bloqueio a partir de
 * FORA da propria aba - por exemplo, um admin a bloquear a sessao de
 * outro utilizador, ou um comando artisan disparado por algum evento
 * da aplicacao. Nesses casos nao ha nenhuma aba "local" para chamar
 * window.LoginTrackerLockscreen.lock() diretamente - o pedido tem de
 * vir do servidor, e so o proprio navegador do utilizador, correndo
 * heartbeat.js, tem como reagir a isso.
 *
 * A solucao, coerente com o resto do pacote (que ja usa polling via
 * heartbeat em vez de WebSockets, de proposito, para manter simples):
 * 'lock_requested_at' funciona como uma "caixa de correio" de UM
 * pedido de bloqueio pendente por sessao. Quando preenchida (ver
 * LoginTrackerManager::forceLock()), o proximo heartbeat.js daquela
 * sessao especifica (no maximo "ping_interval_seconds" depois - a
 * mesma janela de atraso que ja existe para deteccao de expiracao de
 * sessao) recebe a instrucao de mostrar o lockscreen, e o proprio
 * heartbeat limpa esta coluna de volta a NULL nesse momento - e por
 * isso "caixa de correio de UM pedido", nao uma flag booleana
 * permanente: uma vez entregue, o pedido esta consumido.
 */
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
        Schema::connection($this->connectionName())->table($this->tableName(), function (Blueprint $table) {
            $table->timestamp('lock_requested_at')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connectionName())->table($this->tableName(), function (Blueprint $table) {
            $table->dropColumn('lock_requested_at');
        });
    }
};
