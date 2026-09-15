<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tabela de historico de logins
    |--------------------------------------------------------------------------
    |
    | Guarda o historico de entradas/saidas/falhas. Fallback: "auth_logins"
    |
    */
    'table' => env('LOGIN_TRACKER_TABLE', 'auth_logins'),

    /*
    |--------------------------------------------------------------------------
    | Tabela de sessoes ativas (heartbeat)
    |--------------------------------------------------------------------------
    |
    | Guarda o ESTADO atual de cada sessao (last_seen_at), usada para
    | responder "quem esta online agora" e "ha quanto tempo". Fallback:
    | "auth_sessions"
    |
    */
    'sessions_table' => env('LOGIN_TRACKER_SESSIONS_TABLE', 'auth_sessions'),

    /*
    |--------------------------------------------------------------------------
    | Conexao de base de dados
    |--------------------------------------------------------------------------
    |
    | Deixe null para usar a conexao padrao da aplicacao (config/database.php).
    |
    */
    'connection' => env('LOGIN_TRACKER_CONNECTION', null),

    /*
    |--------------------------------------------------------------------------
    | Guards monitorizados
    |--------------------------------------------------------------------------
    |
    | Lista de guards de autenticacao (config/auth.php) cujos eventos de
    | login/logout/falha devem ser registados. Use ['*'] para todos.
    |
    */
    'guards' => ['web', 'api'],

    /*
    |--------------------------------------------------------------------------
    | Eventos a registar (tabela de historico)
    |--------------------------------------------------------------------------
    */
    'events' => [
        'login'  => true,
        'logout' => true,
        'failed' => true, // tentativas de login falhadas
    ],

    /*
    |--------------------------------------------------------------------------
    | Dados a capturar
    |--------------------------------------------------------------------------
    */
    'capture' => [
        'ip_address' => true,
        'user_agent' => true,
        'location'   => false, // requer resolver de geolocalizacao proprio (ver docs)
    ],

    /*
    |--------------------------------------------------------------------------
    | Coluna de identificacao do utilizador
    |--------------------------------------------------------------------------
    |
    | Nome da coluna morfica usada para relacionar com o modelo de utilizador
    | (suporta multiplos modelos autenticaveis via morphTo).
    |
    */
    'morph_name' => 'authenticatable',

    /*
    |--------------------------------------------------------------------------
    | Status Online / Heartbeat
    |--------------------------------------------------------------------------
    |
    | ATENCAO: isto e o que responde "quem esta online" com precisao.
    | Sem isto, so temos o momento do login e do logout EXPLICITO
    | (que nao acontece quando a sessao expira sozinha, ex: aba fechada,
    | timeout, token expirado). O heartbeat resolve isso: o
    | last_seen_at avanca enquanto a sessao/aba estiver ativa, e
    | simplesmente para de avancar quando o utilizador some - sem
    | precisar de nenhum evento de "saida".
    |
    */
    'heartbeat' => [

        // Liga/desliga toda a funcionalidade de heartbeat/online.
        'enabled' => env('LOGIN_TRACKER_HEARTBEAT_ENABLED', true),

        // Middleware automatico: atualiza last_seen_at em toda requisicao
        // autenticada normal (paginas, chamadas AJAX, API). Cobre a maior
        // parte dos casos sem precisar de nenhum JS extra.
        'middleware_enabled' => env('LOGIN_TRACKER_HEARTBEAT_MIDDLEWARE', true),

        // Intervalo minimo (segundos) entre updates de last_seen_at pelo
        // middleware, para nao bater na BD a cada requisicao. Ex: 30 =
        // so grava de novo se passaram 30s desde o ultimo registo.
        'middleware_throttle_seconds' => env('LOGIN_TRACKER_HEARTBEAT_THROTTLE', 30),

        // Endpoint JS de heartbeat ativo: cobre o caso de o utilizador
        // deixar a aba aberta SEM clicar em nada (nenhuma requisicao
        // normal e feita). O pacote publica um pequeno JS que faz ping
        // neste endpoint em intervalos regulares.
        'route_enabled' => env('LOGIN_TRACKER_HEARTBEAT_ROUTE', true),
        'route_path'    => env('LOGIN_TRACKER_HEARTBEAT_PATH', 'login-tracker/heartbeat'),
        'route_middleware' => ['web', 'auth'],

        // Intervalo (segundos) que o JS de heartbeat usa para fazer ping.
        // Deve ser MENOR que "online_threshold_seconds" abaixo.
        'ping_interval_seconds' => env('LOGIN_TRACKER_PING_INTERVAL', 60),

        // Quanto tempo (segundos) sem sinal ate considerarmos o
        // utilizador OFFLINE. Deve ser MAIOR que ping_interval_seconds
        // e maior que middleware_throttle_seconds, para tolerar
        // pings perdidos por rede instavel.
        'online_threshold_seconds' => env('LOGIN_TRACKER_ONLINE_THRESHOLD', 120),

        // Depois de quanto tempo (minutos) sem heartbeat uma sessao e
        // considerada "morta" e pode ser limpa/fechada pelo comando de
        // purga (login-tracker:purge --sessions). Isto tambem fecha o
        // registo correspondente na tabela de historico (login_at/logout_at)
        // preenchendo o logout_at estimado = ultimo last_seen_at, com
        // logout_reason = 'inferred_stale'.
        'stale_after_minutes' => env('LOGIN_TRACKER_STALE_MINUTES', 30),

        // LOGOUT AUTOMATICO NO CLIENT QUANDO A SESSAO EXPIRA.
        //
        // Quando true (padrao), se o heartbeat.js (a correr no
        // navegador do proprio utilizador) receber HTTP 401 do endpoint
        // de heartbeat - ou seja, descobrir que a sessao no servidor ja
        // nao e valida - ele deixa de so mostrar um aviso: chama
        // Auth::logout() de verdade no servidor (via o endpoint de
        // logout forcado, ver rota 'login-tracker.force-logout'),
        // limpando o cookie de sessao, e SO DEPOIS redireciona para a
        // pagina de login. O resultado e exatamente "a sessao expira e
        // a tela do utilizador fica sozinha na pagina de login", sem
        // ele precisar de clicar em nada.
        //
        // Este logout automatico E AUDITADO como qualquer outro: fica
        // gravado em auth_logins com logout_reason='expired_client', e
        // a sessao correspondente em auth_sessions e fechada (ended_at
        // preenchido) no mesmo instante.
        //
        // Se definir como false, o comportamento volta a ser so
        // mostrar um aviso (ver defaultOnSessionExpired em
        // heartbeat.js) sem fazer logout nenhum no servidor - a sessao
        // so e formalmente fechada mais tarde, quando
        // login-tracker:purge correr (logout_reason='inferred_stale').
        'auto_logout_on_expiry' => env('LOGIN_TRACKER_AUTO_LOGOUT_ON_EXPIRY', true),

        // Depois de fazer o logout automatico (acima), para onde
        // redirecionar. Deixe null para usar a rota de login padrao da
        // aplicacao (config('login-tracker.lockscreen.login_route_name'),
        // que por sua vez usa a rota chamada "login" por defeito).
        'expired_redirect_route_name' => env('LOGIN_TRACKER_EXPIRED_REDIRECT_ROUTE', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Purga automatica (retencao)
    |--------------------------------------------------------------------------
    |
    | Numero de dias que os registos de HISTORICO devem ser mantidos.
    | null desativa a purga. Execute via: php artisan login-tracker:purge
    |
    */
    'retention_days' => env('LOGIN_TRACKER_RETENTION_DAYS', null),

    /*
    |--------------------------------------------------------------------------
    | Lockscreen (ecra de bloqueio por inatividade)
    |--------------------------------------------------------------------------
    |
    | Quando ativo, se o utilizador ficar parado (sem mover o rato/teclado)
    | por mais tempo que "idle_seconds", um ecra de bloqueio aparece POR
    | CIMA da pagina atual (sem recarregar, sem perder o que estava a
    | fazer). Para desbloquear, o utilizador confirma a propria password.
    |
    | Isto reaproveita o mesmo heartbeat que ja usamos para "esta online":
    | a deteccao de inatividade acontece no NAVEGADOR (JS), nao no servidor
    | - o servidor nao sabe se o rato do utilizador mexeu, so sabe se
    | chegaram pedidos. Por isso o "idle_seconds" aqui e independente do
    | "online_threshold_seconds" la em cima: pode (e normalmente deve) ser
    | bem menor - por exemplo, bloquear ao fim de 5 minutos parado, mesmo
    | que o pacote so considere "offline de vez" ao fim de 2 minutos SEM
    | heartbeat nenhum.
    |
    | NAO CONFUNDIR com 'heartbeat.auto_logout_on_expiry' (acima): o
    | lockscreen bloqueia a tela por INATIVIDADE (rato/teclado parados)
    | com a sessao no servidor continuando perfeitamente valida por
    | baixo - o utilizador desbloqueia com a propria password e
    | continua exatamente onde estava. Ja o logout automatico e sobre a
    | SESSAO EM SI ter expirado no servidor (ex: passaram-se X horas
    | desde o login) - nesse caso nao ha nada para desbloquear, o
    | utilizador tem mesmo de voltar a fazer login. Os dois podem
    | acontecer de forma totalmente independente um do outro.
    |
    */
    'lockscreen' => [

        // Liga/desliga a funcionalidade toda. Por padrao desligado -
        // e uma decisao de UX da sua aplicacao, nao algo que deva vir
        // ativo por defeito num pacote generico.
        'enabled' => env('LOGIN_TRACKER_LOCKSCREEN_ENABLED', false),

        // Segundos parado (sem mover rato/teclado/tocar no ecra) ate o
        // ecra de bloqueio aparecer. 0 = nunca bloqueia mesmo com
        // enabled=true (util para desligar so nalguns ambientes via .env
        // sem mexer no valor de "enabled").
        'idle_seconds' => env('LOGIN_TRACKER_LOCKSCREEN_IDLE_SECONDS', 900), // 15 minutos

        // Caminho da view Blade a usar como ecra de bloqueio.
        //
        // - Deixe como esta ('login-tracker::lockscreen') para usar a
        //   view PADRAO que vem embutida no pacote - funciona out-of-the-
        //   -box, sem voce criar nada.
        //
        // - Para personalizar o visual, publique a view padrao para o
        //   seu projeto:
        //       php artisan vendor:publish --tag=login-tracker-views
        //   Isto copia o ficheiro para
        //       resources/views/vendor/login-tracker/lockscreen.blade.php
        //   e o Laravel passa a usar automaticamente essa copia (o
        //   caminho 'login-tracker::lockscreen' continua igual, o
        //   publish e que faz o Laravel preferir a copia local).
        //
        // - Para usar uma view TOTALMENTE SUA, nalgum outro sitio da sua
        //   app (ex: uma que segue o layout do resto do sistema), mude
        //   este valor para o nome dessa view, ex:
        //   'lockscreen_view' => 'partials.meu-lockscreen',
        //   Essa view recebe os MESMOS dados/helpers que a view padrao -
        //   ver a seccao "Personalizar o ecra de bloqueio" no README.
        //
        'view' => env('LOGIN_TRACKER_LOCKSCREEN_VIEW', 'login-tracker::lockscreen'),

        // Rota (endpoint) que valida a password para desbloquear.
        'unlock_route_path' => env('LOGIN_TRACKER_LOCKSCREEN_UNLOCK_PATH', 'login-tracker/unlock'),
        'unlock_route_middleware' => ['web', 'auth'],

        // O que fazer se o utilizador clicar em "Sair" a partir do
        // ecra de bloqueio, em vez de desbloquear. true = faz logout
        // normal (Auth::logout) e manda para a pagina de login. false =
        // esconde esse botao, so permite desbloquear com a password.
        'allow_logout_from_lockscreen' => true,

        // Nome da rota de login da SUA aplicacao, para onde o botao
        // "Sair" do lockscreen (acima) redireciona depois do logout.
        // Ajuste se a sua rota de login tiver outro nome.
        'login_route_name' => env('LOGIN_TRACKER_LOGIN_ROUTE_NAME', 'login'),
    ],

];
