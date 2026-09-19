/**
 * LoginTracker - Heartbeat
 * gsebastiao/laravel-logintracker
 *
 * Carregado automaticamente pela diretiva @logintracker.
 *
 * FAZ DUAS COISAS:
 *   1. Mantem o estado "online" atualizado no servidor, mesmo quando o
 *      utilizador nao clica em nada.
 *   2. Deteta que a sessao expirou e, por defeito, termina a sessao e
 *      leva o utilizador para o ecra de login automaticamente.
 *
 * DIFERENCA IMPORTANTE EM RELACAO A v1.x:
 * Antes, o ping so acontecia com a aba visivel. Se o utilizador
 * deixasse a janela minimizada ou noutra aba, a expiracao nunca era
 * detetada e o redirecionamento prometido nunca acontecia. Agora o
 * ping corre sempre; o servidor e que decide o que responder.
 */
(function () {
    'use strict';

    var cfg = window.LoginTrackerConfig || {};

    if (!cfg.heartbeatUrl) {
        return;
    }

    var intervalMs = (cfg.pingIntervalSeconds || 60) * 1000;
    var timer = null;
    var finished = false;

    function post(url) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': cfg.csrfToken,
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            // "manual" impede o browser de seguir em silencio um
            // eventual redirect 302 para a pagina de login: se isso
            // acontecesse, receberiamos 200 com HTML e nunca
            // perceberiamos que a sessao tinha expirado.
            redirect: 'manual'
        });
    }

    function banner(text) {
        var el = document.createElement('div');
        el.setAttribute('role', 'alert');
        el.style.cssText = [
            'position:fixed', 'top:0', 'left:0', 'right:0', 'z-index:2147483647',
            'background:#b91c1c', 'color:#fff', 'padding:12px 16px',
            'font:14px system-ui,-apple-system,sans-serif', 'text-align:center'
        ].join(';');
        el.textContent = text;
        document.body.appendChild(el);
    }

    function goToLogin(url) {
        window.location.href = url || cfg.loginUrl || '/login';
    }

    /**
     * Sessao expirada. Termina-a formalmente no servidor (para ficar
     * auditada como "expired_client") e so depois redireciona.
     */
    function handleExpired(redirectUrl) {
        if (finished) return;
        finished = true;

        stop();

        if (window.LoginTracker && typeof window.LoginTracker.onSessionExpired === 'function') {
            window.LoginTracker.onSessionExpired();
            return;
        }

        if (!cfg.autoLogoutOnExpiry) {
            banner('A sua sessao expirou.');
            setTimeout(function () { goToLogin(redirectUrl); }, 2500);
            return;
        }

        banner('A sua sessao expirou. A terminar sessao...');

        post(cfg.forceLogoutUrl)
            .then(function (r) { return r.json(); })
            .then(function (d) { goToLogin(d && d.redirect_url); })
            ['catch'](function () { goToLogin(redirectUrl); });
    }

    function ping() {
        if (finished) return;

        post(cfg.heartbeatUrl)
            .then(function (r) {
                // Qualquer resposta que nao seja JSON valido indica que
                // fomos parar a outro lado (tipicamente a pagina de
                // login) - tratamos como sessao expirada.
                var type = r.headers.get('content-type') || '';

                if (r.status === 401 || r.status === 419 || r.type === 'opaqueredirect' || type.indexOf('json') === -1) {
                    handleExpired();
                    return null;
                }

                return r.json();
            })
            .then(function (data) {
                if (!data) return;

                if (data.authenticated === false) {
                    handleExpired(data.redirect_url);
                    return;
                }

                // Bloqueio pedido remotamente por um administrador.
                if (data.locked && window.LoginTrackerLockscreen) {
                    window.LoginTrackerLockscreen.lock();
                }
            })
            ['catch'](function () {
                // Falha de rede: nao assumimos que a sessao morreu,
                // tentamos outra vez no proximo intervalo.
            });
    }

    function start() {
        if (timer) return;
        ping();
        timer = setInterval(ping, intervalMs);
    }

    function stop() {
        clearInterval(timer);
        timer = null;
    }

    // Voltar a aba deve verificar de imediato, sem esperar o intervalo.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') ping();
    });

    window.LoginTracker = window.LoginTracker || {};
    window.LoginTracker.start = start;
    window.LoginTracker.stop = stop;

    start();
})();
