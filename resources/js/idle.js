/**
 * LoginTracker - Lockscreen (inatividade + bloqueio persistente)
 * gsebastiao/laravel-logintracker
 *
 * Este ficheiro e carregado automaticamente pela diretiva @logintracker.
 * Nao precisa de o incluir a mao.
 *
 * O QUE MUDOU EM RELACAO A v1.x (e porque):
 * Antes, o bloqueio existia apenas numa variavel JavaScript. Bastava
 * carregar F5 ou abrir uma aba nova para o ecra desaparecer e o sistema
 * ficar acessivel - era um efeito visual, nao uma protecao.
 *
 * Agora o estado vive no SERVIDOR:
 *   - ao bloquear, avisamos o servidor (POST /logintracker/lock);
 *   - ao recarregar a pagina, o servidor ja manda o overlay visivel
 *     (config.startLocked), por isso nao ha sequer um piscar de conteudo;
 *   - abrir uma aba nova mostra o bloqueio tambem, porque partilham a
 *     mesma sessao;
 *   - so a password correta (ou o logout) desbloqueia.
 */
(function () {
    'use strict';

    var cfg = window.LoginTrackerConfig || {};

    if (!cfg.lockscreenEnabled) {
        return;
    }

    var overlay = document.getElementById('logintracker-lockscreen');

    if (!overlay) {
        var msg =
            '[LoginTracker] O elemento #logintracker-lockscreen nao existe nesta pagina.\n' +
            'Causa mais provavel: falta a diretiva @logintracker no layout Blade desta pagina.\n' +
            'Adicione @logintracker antes de </body> e corra: php artisan view:clear';

        // Stub seguro: evita "Cannot read properties of undefined" em
        // botoes que chamem window.LoginTrackerLockscreen.lock().
        window.LoginTrackerLockscreen = window.LoginTrackerLockscreen || {
            lock: function () { console.warn(msg); },
            unlock: function () { console.warn(msg); },
            isLocked: function () { return false; }
        };

        console.warn(msg);
        return;
    }

    var idleSeconds = cfg.idleSeconds || 0;
    var idleTimer = null;
    var locked = false;

    function post(url) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': cfg.csrfToken,
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        });
    }

    function showOverlay() {
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        var input = document.getElementById('logintracker-password');
        if (input) {
            setTimeout(function () { input.focus(); }, 50);
        }
    }

    function hideOverlay() {
        overlay.style.display = 'none';
        document.body.style.overflow = '';
    }

    /**
     * Bloqueia. Mostra o overlay imediatamente (resposta visual
     * instantanea) e avisa o servidor em paralelo, para o bloqueio
     * persistir num refresh ou noutra aba.
     */
    function lock() {
        if (locked) return;
        locked = true;

        showOverlay();
        clearTimeout(idleTimer);

        if (cfg.lockUrl) {
            post(cfg.lockUrl)['catch'](function () {
                // Se a rede falhar, o overlay continua visivel nesta
                // aba. O bloqueio pode nao sobreviver a um refresh,
                // mas e preferivel a nao bloquear de todo.
                console.warn('[LoginTracker] Nao foi possivel registar o bloqueio no servidor.');
            });
        }
    }

    /**
     * Desbloqueia. Chamado apenas depois de o servidor confirmar a
     * password - nunca diretamente pelo formulario.
     */
    function unlock() {
        locked = false;
        hideOverlay();
        resetIdleTimer();
    }

    function resetIdleTimer() {
        if (locked || !idleSeconds || idleSeconds <= 0) {
            return;
        }

        clearTimeout(idleTimer);
        idleTimer = setTimeout(lock, idleSeconds * 1000);
    }

    // --- Estado inicial vindo do servidor -------------------------
    // Se a sessao ja estava bloqueada, o overlay aparece de imediato,
    // antes de qualquer interacao. E isto que faz o F5 manter o ecra.
    if (cfg.startLocked) {
        locked = true;
        showOverlay();
    }

    // --- Formulario de desbloqueio --------------------------------
    var form = document.getElementById('logintracker-unlock-form');
    var input = document.getElementById('logintracker-password');
    var errorBox = document.getElementById('logintracker-error');

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            if (errorBox) errorBox.style.display = 'none';

            fetch(cfg.unlockUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': cfg.csrfToken,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: JSON.stringify({ password: input ? input.value : '' })
            })
                .then(function (r) {
                    return r.json().then(function (d) { return { status: r.status, data: d }; });
                })
                .then(function (res) {
                    if (res.status === 200 && res.data.unlocked) {
                        if (input) input.value = '';
                        unlock();
                        return;
                    }

                    var text = res.data.message
                        || (res.data.errors && res.data.errors.password && res.data.errors.password[0])
                        || 'Password incorreta.';

                    if (errorBox) {
                        errorBox.textContent = text;
                        errorBox.style.display = 'block';
                    }

                    if (input) { input.value = ''; input.focus(); }
                })
                ['catch'](function () {
                    if (errorBox) {
                        errorBox.textContent = 'Nao foi possivel contactar o servidor.';
                        errorBox.style.display = 'block';
                    }
                });
        });
    }

    // --- Deteccao de inatividade ----------------------------------
    ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'].forEach(function (evt) {
        document.addEventListener(evt, resetIdleTimer, { passive: true });
    });

    // Sair da aba e voltar mais tarde tambem conta como inatividade:
    // os timers do browser sao suspensos em abas escondidas, por isso
    // medimos o tempo real de ausencia em vez de confiar no timer.
    var hiddenAt = null;

    document.addEventListener('visibilitychange', function () {
        if (!idleSeconds || idleSeconds <= 0) return;

        if (document.visibilityState === 'hidden') {
            hiddenAt = Date.now();
            return;
        }

        if (hiddenAt) {
            var away = (Date.now() - hiddenAt) / 1000;
            hiddenAt = null;

            if (away >= idleSeconds) {
                lock();
            } else {
                resetIdleTimer();
            }
        }
    });

    // API publica, para botoes "Bloquear agora" da aplicacao e para o
    // heartbeat.js entregar bloqueios remotos.
    window.LoginTrackerLockscreen = {
        lock: lock,
        unlock: unlock,
        isLocked: function () { return locked; }
    };

    resetIdleTimer();
})();
