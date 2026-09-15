<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona 'logout_reason' a tabela de historico.
 *
 * Ficheiro SEPARADO da migration original (nao editamos
 * 0001_01_01_000000_create_auth_logins_table.php diretamente) porque,
 * se alguem ja tiver corrido esse ficheiro em producao, o Laravel nunca
 * mais volta a executa-lo - editar uma migration ja corrida nao tem
 * efeito nenhum na base de dados de quem ja instalou o pacote antes
 * desta versao. Uma migration NOVA e a forma correta de alterar uma
 * tabela que ja pode existir por ai.
 *
 * 'logout_reason' distingue a CAUSA do logout, o que e o cerne da
 * auditoria pedida:
 *
 *   'manual'          -> o utilizador clicou em "Sair" (ou o admin fez
 *                        logout dele) - Auth::logout() foi chamado
 *                        diretamente, sem nenhuma deteccao de
 *                        inatividade/expiracao envolvida.
 *
 *   'expired_client'  -> o NAVEGADOR do proprio utilizador detetou (via
 *                        heartbeat.js) que a sessao no servidor tinha
 *                        expirado, e reagiu chamando logout sozinho,
 *                        deixando o ecra no login automaticamente. E o
 *                        comportamento que respondeu ao pedido de
 *                        "logout automatico ao expirar, com a tela do
 *                        user a ficar no login".
 *
 *   'inferred_stale'  -> ninguem (nem o utilizador, nem o proprio
 *                        navegador dele) confirmou o logout - foi o
 *                        comando login-tracker:purge, correndo no
 *                        servidor, que reparou que a sessao nao dava
 *                        sinal de vida ha muito tempo (ex: o utilizador
 *                        desligou o computador a meio, sem a aba sequer
 *                        ter oportunidade de reagir) e fechou-a por
 *                        inferencia.
 *
 * O campo antigo 'logout_inferred' (boolean) e mantido para nao partir
 * quem ja usa o pacote, mas passa a ser CALCULADO a partir deste novo
 * campo (true apenas quando logout_reason = 'inferred_stale') - ver
 * AuthLogin::booted() no Model.
 */
return new class extends Migration
{
    protected function tableName(): string
    {
        return config('login-tracker.table', 'auth_logins');
    }

    protected function connectionName(): ?string
    {
        return config('login-tracker.connection');
    }

    public function up(): void
    {
        Schema::connection($this->connectionName())->table($this->tableName(), function (Blueprint $table) {
            $table->string('logout_reason')->nullable()->after('logout_inferred');
        });

        // Migra dados existentes: quem ja tinha logout_inferred=true
        // passa a ter logout_reason='inferred_stale'; quem tinha
        // logout_inferred=false mas ja tinha logout_at preenchido
        // (logout confirmado antes desta versao existir) passa a
        // 'manual', que era o unico tipo de logout confirmado possivel
        // ate aqui.
        Schema::connection($this->connectionName())->getConnection()
            ->table($this->tableName())
            ->whereNotNull('logout_at')
            ->where('logout_inferred', true)
            ->update(['logout_reason' => 'inferred_stale']);

        Schema::connection($this->connectionName())->getConnection()
            ->table($this->tableName())
            ->whereNotNull('logout_at')
            ->where('logout_inferred', false)
            ->update(['logout_reason' => 'manual']);
    }

    public function down(): void
    {
        Schema::connection($this->connectionName())->table($this->tableName(), function (Blueprint $table) {
            $table->dropColumn('logout_reason');
        });
    }
};
