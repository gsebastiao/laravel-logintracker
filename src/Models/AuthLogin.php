<?php

namespace Gsebastiao\LoginTracker\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuthLogin extends Model
{
    /**
     * Logout confirmado: o utilizador (ou um admin em nome dele) chamou
     * Auth::logout() diretamente - por exemplo, clicou em "Sair".
     */
    public const LOGOUT_MANUAL = 'manual';

    /**
     * O NAVEGADOR do proprio utilizador detetou, via heartbeat.js, que a
     * sessao no servidor tinha expirado, e reagiu fazendo logout sozinho
     * e redirecionando para o login - sem nenhum clique do utilizador.
     */
    public const LOGOUT_EXPIRED_CLIENT = 'expired_client';

    /**
     * Ninguem confirmou o logout - nem o utilizador, nem o proprio
     * navegador dele reagiu (ex: computador desligado a meio, sem
     * oportunidade de o heartbeat.js correr). O comando
     * login-tracker:purge, correndo no servidor, reparou que a sessao
     * nao dava sinal de vida ha mais tempo que o configurado, e fechou-a
     * por inferencia.
     */
    public const LOGOUT_INFERRED_STALE = 'inferred_stale';

    protected $guarded = [];

    protected $casts = [
        'meta'            => 'array',
        'login_at'        => 'datetime',
        'logout_at'       => 'datetime',
        'logout_inferred' => 'boolean',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('logintracker.table', 'auth_logins'));

        if ($connection = config('logintracker.connection')) {
            $this->setConnection($connection);
        }
    }

    protected static function booted(): void
    {
        // Mantem o campo antigo 'logout_inferred' (boolean) sincronizado
        // automaticamente a partir do novo 'logout_reason', para quem ja
        // tinha codigo a usar logout_inferred continuar a funcionar sem
        // precisar de mudar nada. So precisa de correr quando
        // logout_reason e de facto alterado nesta gravacao.
        static::saving(function (AuthLogin $log) {
            if ($log->isDirty('logout_reason')) {
                $log->logout_inferred = $log->logout_reason === self::LOGOUT_INFERRED_STALE;
            }
        });
    }

    public function authenticatable(): MorphTo
    {
        return $this->morphTo(config('logintracker.morph_name', 'authenticatable'));
    }

    /**
     * Este registo de login ainda esta "em aberto" (sem logout registado)?
     * Atencao: "em aberto" na tabela de HISTORICO nao significa
     * necessariamente "online agora" - use AuthSession::online() para isso.
     * Este metodo so diz que nenhum evento/inferencia de saida aconteceu.
     */
    public function isOpen(): bool
    {
        return $this->event === 'login' && $this->logout_at === null;
    }

    /**
     * O logout deste registo foi confirmado com certeza (por um clique
     * do utilizador OU pelo proprio navegador dele detetando a
     * expiracao)? Distingue-se de uma saida apenas DEDUZIDA pela purga,
     * onde ninguem - nem o utilizador nem o navegador - confirmou nada.
     */
    public function isLogoutConfirmed(): bool
    {
        return in_array($this->logout_reason, [self::LOGOUT_MANUAL, self::LOGOUT_EXPIRED_CLIENT], true);
    }
}

