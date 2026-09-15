<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
        Schema::connection($this->connectionName())->create($this->tableName(), function (Blueprint $table) {
            $table->id();

            $morphName = config('login-tracker.morph_name', 'authenticatable');
            $table->nullableMorphs($morphName);

            $table->string('guard')->nullable()->index();
            $table->string('event')->index(); // login | logout | failed
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('location')->nullable();

            // Guarda credencial usada em tentativas falhadas (ex: email), sem senha
            $table->string('identifier')->nullable();

            $table->timestamp('login_at')->nullable();
            $table->timestamp('logout_at')->nullable();

            // Preenchido pelo comando de purga quando o logout e INFERIDO
            // (sessao expirou sozinha) em vez de um evento Logout real.
            $table->boolean('logout_inferred')->default(false);

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['guard', 'event']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connectionName())->dropIfExists($this->tableName());
    }
};
