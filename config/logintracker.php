<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tabelas
    |--------------------------------------------------------------------------
    */

    // Histórico: o que aconteceu (logins, logouts, tentativas falhadas).
    'table' => env('LOGINTRACKER_TABLE', 'auth_logins'),

    // Estado atual: quem está online agora, e quem está bloqueado.
    'sessions_table' => env('LOGINTRACKER_SESSIONS_TABLE', 'auth_sessions'),

    // Ligação de base de dados. null = a padrão da aplicação.
    'connection' => env('LOGINTRACKER_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | O que monitorizar
    |--------------------------------------------------------------------------
    */

    // Guards vigiados. Use ['*'] para todos.
    'guards' => ['web', 'api'],

    'events' => [
        'login'  => true,
        'logout' => true,
        'failed' => true,
    ],

    'capture' => [
        'ip_address' => true,
        'user_agent' => true,
    ],

    // Coluna polimórfica que liga ao seu modelo de utilizador.
    'morph_name' => 'authenticatable',

    // Apagar histórico com mais de N dias (null = nunca apagar).
    'retention_days' => env('LOGINTRACKER_RETENTION_DAYS'),

    /*
    |--------------------------------------------------------------------------
    | Estado online (heartbeat)
    |--------------------------------------------------------------------------
    |
    | O browser envia um sinal de vida periódico. É assim que sabemos quem
    | está online agora, e é também assim que detetamos que uma sessão
    | expirou enquanto a página estava aberta.
    |
    | Regra ao ajustar os tempos, do menor para o maior:
    |   ping_interval_seconds < online_threshold_seconds < stale_after_minutes×60
    |
    */

    'heartbeat' => [
        'enabled' => true,

        // Além do sinal por JavaScript, qualquer pedido normal do
        // utilizador também conta como sinal de vida.
        'middleware_enabled' => true,
        'middleware_throttle_seconds' => 30,

        'route_path' => 'logintracker/heartbeat',

        // De quanto em quanto tempo o browser envia sinal.
        'ping_interval_seconds' => env('LOGINTRACKER_PING_INTERVAL', 60),

        // Sem sinal durante mais do que isto = offline.
        'online_threshold_seconds' => env('LOGINTRACKER_ONLINE_THRESHOLD', 120),

        // Sem sinal durante mais do que isto = sessão morta, fechada
        // pelo comando logintracker:purge.
        'stale_after_minutes' => env('LOGINTRACKER_STALE_MINUTES', 30),

        // Quando a sessão expirar, terminar sessão e levar o utilizador
        // para o ecrã de login automaticamente.
        'auto_logout_on_expiry' => env('LOGINTRACKER_AUTO_LOGOUT', true),

        // Para onde redirecionar depois disso. null = usa a rota de
        // login definida em lockscreen.login_route_name.
        'expired_redirect_route_name' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ecrã de bloqueio (lockscreen)
    |--------------------------------------------------------------------------
    |
    | Bloqueia o ecrã após um período de inatividade. O estado do bloqueio
    | é guardado no servidor, por isso sobrevive a refresh (F5) e aplica-se
    | a todas as abas da mesma sessão.
    |
    | Não confundir com o logout automático acima: aqui a sessão continua
    | válida e basta a password para continuar onde estava; ali a sessão
    | morreu e é preciso entrar de novo.
    |
    */

    'lockscreen' => [
        'enabled' => env('LOGINTRACKER_LOCKSCREEN', false),

        // Segundos de inatividade até bloquear. 0 desliga o bloqueio
        // automático mas mantém o bloqueio manual a funcionar.
        'idle_seconds' => env('LOGINTRACKER_IDLE_SECONDS', 900),

        // View do ecrã de bloqueio. Publique-a para personalizar, ou
        // aponte para uma view sua (ex.: 'partials.meu-lockscreen').
        'view' => 'logintracker::lockscreen',

        'unlock_route_path' => 'logintracker/unlock',

        // Mostrar o botão "Terminar sessão" no ecrã de bloqueio.
        'allow_logout_from_lockscreen' => true,

        // Nome da rota de login da sua aplicação.
        'login_route_name' => env('LOGINTRACKER_LOGIN_ROUTE', 'login'),

        // Proteção contra tentativas repetidas de password no ecrã de
        // bloqueio.
        'max_unlock_attempts' => 5,
        'unlock_throttle_seconds' => 60,
    ],

];
