/**
 * Login Tracker - Heartbeat
 * gsebastiao/laravel-logintracker
 *
 * O QUE ISTO FAZ:
 * Envia um "ping" periodico ao servidor enquanto esta aba estiver aberta
 * e visivel, mantendo o registo de "ultima vez visto" (last_seen_at) do
 * utilizador atualizado. Isto e o que permite responder "quem esta
 * online" com precisao, mesmo quando o utilizador nao clica em nada.
 *
 * COMPORTAMENTO QUANDO A SESSAO EXPIRA COM A ABA ABERTA:
 * O servidor NAO tem como avisar uma aba ja aberta sozinho - HTTP e um
 * protocolo de pergunta/resposta, o servidor nao "empurra" nada sem
 * WebSockets/SSE (que este pacote nao usa, para manter simples).
 * Entao o que acontece, passo a passo:
 *
 *   1. A sessao expira no servidor (timeout do session.lifetime, ou
 *      logout feito noutro dispositivo, ou token revogado).
 *   2. A TELA CONTINUA EXATAMENTE COMO ESTAVA. Nao ha nenhuma mudanca
 *      visual ate que algo aconteca.
 *   3. No proximo ping (no maximo "ping_interval_seconds" depois), este
 *      script recebe HTTP 401 do endpoint de heartbeat.
 *   4. Se "autoLogoutOnExpiry" estiver ativo (padrao - controlado por
 *      config('login-tracker.heartbeat.auto_logout_on_expiry') no
 *      lado do servidor), este script chama de imediato o endpoint de
 *      logout forcado, que faz Auth::logout() de verdade e limpa a
 *      sessao no servidor, e SO DEPOIS redireciona para o login. O
 *      resultado: a sessao expira e a tela do utilizador fica sozinha
 *      na pagina de login, sem ele precisar de clicar em nada - e este
 *      logout automatico fica AUDITADO nas tabelas do pacote, com
 *      logout_reason='expired_client'.
 *      Se "autoLogoutOnExpiry" estiver desativado, o comportamento e o
 *      antigo: so mostra um aviso (ver defaultOnSessionExpired) sem
 *      limpar nada no servidor - a sessao so fica formalmente fechada
 *      mais tarde, quando o comando login-tracker:purge correr.
 *
 * Ou seja: existe uma JANELA DE ATRASO entre a sessao expirar de verdade
 * e o utilizador ser avisado/deslogado, do tamanho de
 * "ping_interval_seconds" (60s por padrao). Isto e uma limitacao
 * inerente a abordagem de polling, nao um bug. Se precisar de deteccao
 * instantanea, teria de migrar para WebSockets/Laravel Echo - fora do
 * escopo deste pacote.
 *
 * BLOQUEIO REMOTO (LoginTracker::forceLock($user) no lado PHP):
 * A mesma janela de atraso (ping_interval_seconds) aplica-se a um
 * bloqueio forcado remotamente (ver README, "Forcar o lockscreen
 * manualmente") - quando o servidor tem um pedido de bloqueio pendente
 * para esta sessao, a resposta a este ping inclui "should_lock: true",
 * e este ficheiro chama window.LoginTrackerLockscreen.lock().
 *
 * IMPORTANTE: isto so tem efeito se o idle.js (resources/js/idle.js)
 * TAMBEM estiver carregado na mesma pagina - e ele quem cria o objeto
 * window.LoginTrackerLockscreen. Os dois ficheiros continuam
 * logicamente independentes (heartbeat.js nunca falha nem avisa nada
 * se idle.js nao estiver presente - o "if" e silencioso), mas para o
 * bloqueio remoto funcionar de facto, ambos precisam de estar incluidos
 * no layout. Isto normalmente ja acontece sozinho se voce seguiu a
 * instalacao do lockscreen no README (a diretiva @loginTrackerLockscreen
 * inclui idle.js automaticamente).
 *
 * INSTALACAO:
 * Inclua este ficheiro no seu layout autenticado, depois de definir
 * window.LoginTrackerConfig antes dele:
 *
 *   <script>
 *     window.LoginTrackerConfig = {
 *       heartbeatUrl: '{{ route("login-tracker.heartbeat") }}',
 *       pingIntervalSeconds: {{ config('login-tracker.heartbeat.ping_interval_seconds', 60) }},
 *       csrfToken: '{{ csrf_token() }}',
 *       loginUrl: '{{ route('login') }}',
 *       autoLogoutOnExpiry: {{ config('login-tracker.heartbeat.auto_logout_on_expiry', true) ? 'true' : 'false' }},
 *       forceLogoutUrl: @if(config('login-tracker.heartbeat.auto_logout_on_expiry', true)) '{{ route("login-tracker.force-logout") }}' @else null @endif,
 *     };
 *   </script>
 *   <script src="{{ asset('vendor/login-tracker/heartbeat.js') }}"></script>
 */
(function () {
    'use strict';

    var config = window.LoginTrackerConfig || {};
    var heartbeatUrl = config.heartbeatUrl;
    var pingIntervalMs = (config.pingIntervalSeconds || 60) * 1000;
    var csrfToken = config.csrfToken;
    var autoLogoutOnExpiry = config.autoLogoutOnExpiry !== false; // true por defeito
    var forceLogoutUrl = config.forceLogoutUrl;
    var timerId = null;
    var expired = false;

    if (!heartbeatUrl) {
        console.warn('[LoginTracker] heartbeatUrl nao configurado - heartbeat desativado.');
        return;
    }

    /**
     * Comportamento padrao ao detetar sessao expirada, quando
     * autoLogoutOnExpiry esta DESATIVADO. So avisa - nao limpa nada no
     * servidor. (Quando autoLogoutOnExpiry esta ativo, o fluxo real e
     * performAutoLogout() abaixo, nao esta funcao.)
     * Pode ser sobrescrito definindo window.LoginTracker.onSessionExpired
     * ANTES deste script carregar.
     */
    function defaultOnSessionExpired() {
        stop();
        showExpiredBanner('A sua sessao expirou. Sera redirecionado para o login em instantes...');

        setTimeout(function () {
            window.location.href = config.loginUrl || '/login';
        }, 3000);
    }

    function showExpiredBanner(message) {
        var banner = document.createElement('div');
        banner.setAttribute('role', 'alert');
        banner.style.cssText = [
            'position:fixed', 'top:0', 'left:0', 'right:0', 'z-index:99999',
            'background:#b91c1c', 'color:#fff', 'padding:12px 16px',
            'font-family:system-ui,-apple-system,sans-serif', 'font-size:14px',
            'text-align:center'
        ].join(';');
        banner.textContent = message;
        document.body.appendChild(banner);
    }

    /**
     * Fluxo REAL quando autoLogoutOnExpiry esta ativo (o padrao). Chama
     * o endpoint de logout forcado - que confirma no servidor que a
     * sessao foi encerrada, regista a auditoria com
     * logout_reason='expired_client', e limpa o cookie de verdade -
     * e SO DEPOIS de essa confirmacao chegar e que redireciona.
     *
     * Isto e deliberadamente diferente de so fazer
     * "window.location.href = loginUrl" direto: se so redirecionassemos
     * sem chamar o servidor, o logout nunca ficaria auditado como
     * 'expired_client' - ficaria pendurado ate a purga o apanhar mais
     * tarde como 'inferred_stale'. Chamar o endpoint e o que torna este
     * logout uma acao CONFIRMADA, nao uma deducao.
     */
    function performAutoLogout() {
        stop();
        showExpiredBanner('A sua sessao expirou. A terminar sessao...');

        fetch(forceLogoutUrl, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                window.location.href = (data && data.redirect_url) || config.loginUrl || '/login';
            })
            .catch(function () {
                // Mesmo que o pedido de logout forcado falhe (ex: rede
                // caiu no pior momento possivel), redireciona na mesma -
                // o utilizador nao deve ficar preso numa aba com sessao
                // morta so porque este ultimo pedido nao chegou. Neste
                // caso raro, a auditoria fica a cargo da purga
                // (logout_reason='inferred_stale') em vez de
                // 'expired_client', o que e uma degradacao aceitavel.
                window.location.href = config.loginUrl || '/login';
            });
    }

    function ping() {
        // Nao faz ping se a aba nao estiver visivel - poupa requisicoes
        // desnecessarias quando o utilizador esta noutra aba/janela.
        // Consequencia direta: um bloqueio remoto (forceLock() - ver
        // README) so e entregue quando o utilizador voltar a esta aba e
        // ela ficar visivel de novo (o que dispara um ping extra por
        // conta do listener de visibilitychange mais abaixo) - nao
        // instantaneamente enquanto ele esta noutro lado. Isto e
        // coerente com o proposito do lockscreen: nao ha nada para
        // "proteger" numa aba que nao esta a ser vista.
        if (document.visibilityState !== 'visible') {
            return;
        }

        fetch(heartbeatUrl, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
            .then(function (response) {
                if (response.status === 401) {
                    handleExpired();
                    return null;
                }
                return response.json();
            })
            .then(function (data) {
                // data e null quando a resposta foi 401 (ja tratado
                // acima por handleExpired) - nada mais a fazer aqui
                // nesse caso.
                if (data && data.should_lock && window.LoginTrackerLockscreen) {
                    window.LoginTrackerLockscreen.lock();
                }
            })
            .catch(function (err) {
                // Erro de rede (offline, etc) - nao trata como sessao
                // expirada, so tenta de novo no proximo intervalo.
                console.warn('[LoginTracker] Falha no heartbeat (rede?):', err);
            });
    }

    function handleExpired() {
        if (expired) {
            return; // ja tratado, evita disparar multiplas vezes
        }
        expired = true;

        // Se o dev definiu um onSessionExpired proprio, este SEMPRE tem
        // prioridade sobre o comportamento automatico - permite
        // personalizar totalmente a UX (ex: um modal em vez de um
        // banner) mantendo o dev no controlo total do que acontece.
        if (window.LoginTracker && window.LoginTracker.onSessionExpired) {
            window.LoginTracker.onSessionExpired();
            return;
        }

        if (autoLogoutOnExpiry && forceLogoutUrl) {
            performAutoLogout();
        } else {
            defaultOnSessionExpired();
        }
    }

    function start() {
        if (timerId) return;
        ping(); // ping imediato ao carregar a pagina
        timerId = setInterval(ping, pingIntervalMs);
    }

    function stop() {
        if (timerId) {
            clearInterval(timerId);
            timerId = null;
        }
    }

    // Ping extra sempre que a aba volta a ficar visivel (ex: utilizador
    // volta de outra aba) - detecta expiracao mais rapido nesse cenario
    // em vez de esperar o proximo intervalo do timer.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && !expired) {
            ping();
        }
    });

    window.LoginTracker = window.LoginTracker || {};
    window.LoginTracker.start = start;
    window.LoginTracker.stop = stop;

    start();
})();
