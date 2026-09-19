<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tabelas
    |--------------------------------------------------------------------------
    */

    // Ligação de base de dados. null = a padrão da aplicação.
    'connection' => env('LOGINTRACKER_CONNECTION'),

    // Histórico: o que aconteceu (logins, logouts, tentativas falhadas).
    'table' => env('LOGINTRACKER_TABLE', 'auth_logins'),

    // Estado atual: quem está online agora, e quem está bloqueado.
    'sessions_table' => env('LOGINTRACKER_SESSIONS_TABLE', 'auth_sessions'),

    /*
    |--------------------------------------------------------------------------
    | O que monitorizar
    |--------------------------------------------------------------------------
    */

    // Guards vigiados. Use ['*'] para todos.
    // No .env, separe por vírgulas: LOGINTRACKER_GUARD=web,api (ou *).
    'guards' => array_values(array_filter(array_map('trim', explode(',', (string) env('LOGINTRACKER_GUARD', 'web,api'))))),

    'events' => [
        'login'  => env('LOGINTRACKER_EVENT_LOGIN', true),
        'logout' => env('LOGINTRACKER_EVENT_LOGOUT', true),
        'failed' => env('LOGINTRACKER_EVENT_FAILED', true),
    ],

    'capture' => [
        'ip_address' => env('LOGINTRACKER_CAPTURE_IPADDRESS', true),
        'user_agent' => env('LOGINTRACKER_CAPTURE_USERAGENT', true),
    ],

    // Coluna polimórfica que liga ao seu modelo de utilizador.
    'morph_name' => env('LOGINTRACKER_MORPH_NAME', 'authenticatable'),

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
        'enabled' => env('LOGINTRACKER_HEARTBEAT_ENABLE', true),

        // Além do sinal por JavaScript, qualquer pedido normal do
        // utilizador também conta como sinal de vida.
        'middleware_enabled' => env('LOGINTRACKER_HEARTBEAT_MIDDLEWARE_ENABLE', true),
        'middleware_throttle_seconds' => (int) env('LOGINTRACKER_HEARTBEAT_MIDDLEWARE_THROTTLE', 30),

        'route_path' => env('LOGINTRACKER_HEARTBEAT_ROUTE_PATH', 'logintracker/heartbeat'),

        // De quanto em quanto tempo o browser envia sinal.
        'ping_interval_seconds' => (int) env('LOGINTRACKER_HEARTBEAT_PING_INTERVAL', 60),

        // Sem sinal durante mais do que isto = offline.
        'online_threshold_seconds' => (int) env('LOGINTRACKER_HEARTBEAT_ONLINE_THRESHOLD', 120),

        // Sem sinal durante mais do que isto = sessão morta, fechada
        // pelo comando logintracker:purge.
        'stale_after_minutes' => (int) env('LOGINTRACKER_HEARTBEAT_STALE_MINUTES', 30),

        // Quando a sessão expirar, terminar sessão e levar o utilizador
        // para o ecrã de login automaticamente.
        'auto_logout_on_expiry' => env('LOGINTRACKER_HEARTBEAT_AUTO_LOGOUT', true),

        // Para onde redirecionar depois disso. null = usa a rota de
        // login definida em lockscreen.login_route_name.
        'expired_redirect_route_name' => env('LOGINTRACKER_HEARTBEAT_EXPIRED_REDIRECT', null),
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
        'enabled' => env('LOGINTRACKER_LOCKSCREEN_ENABLE', false),

        // Segundos de inatividade até bloquear. 0 desliga o bloqueio
        // automático mas mantém o bloqueio manual a funcionar.
        'idle_seconds' => (int) env('LOGINTRACKER_LOCKSCREEN_IDLE_SECONDS', 900),

        // View do ecrã de bloqueio. Publique-a para personalizar, ou
        // aponte para uma view sua (ex.: 'partials.meu-lockscreen').
        'view' => env('LOGINTRACKER_LOCKSCREEN_VIEW', 'logintracker::lockscreen'),

        'unlock_route_path' => env('LOGINTRACKER_LOCKSCREEN_UNLOCK_ROUTE_PATH', 'logintracker/unlock'),

        // Mostrar o botão "Terminar sessão" no ecrã de bloqueio.
        'allow_logout_from_lockscreen' => env('LOGINTRACKER_LOCKSCREEN_ALLOW_LOGOUT', true),

        // Nome da rota de login da sua aplicação.
        'login_route_name' => env('LOGINTRACKER_LOCKSCREEN_LOGIN_ROUTE', 'login'),

        // Proteção contra tentativas repetidas de password no ecrã de
        // bloqueio.
        'max_unlock_attempts' => env('LOGINTRACKER_LOCKSCREEN_MAX_UNLOCK', 5),
        'unlock_throttle_seconds' => env('LOGINTRACKER_LOCKSCREEN_UNLOCK_THROTTLE', 60),
    ],

];
