/**
 * Login Tracker - Lockscreen (deteccao de inatividade)
 * gsebastiao/laravel-logintracker
 *
 * O QUE ISTO FAZ:
 * Conta ha quanto tempo o utilizador nao mexe no rato, teclado, ou toca
 * no ecra (mobile). Ao passar de "idle_seconds", mostra o overlay de
 * lockscreen (a view Blade que ja vem incluida no HTML da pagina - ver
 * resources/views/lockscreen.blade.php) por CIMA da pagina atual, sem
 * recarregar nada. O utilizador confirma a password para o overlay
 * desaparecer e a contagem reiniciar.
 *
 * DIFERENCA IMPORTANTE em relacao ao heartbeat.js:
 * O heartbeat.js (ficheiro separado) detecta se a SESSAO NO SERVIDOR
 * expirou (ex: passaram-se X horas desde o login, o cookie morreu).
 * Este ficheiro (idle.js) so olha para eventos do RATO/TECLADO no
 * NAVEGADOR - nao faz nenhum pedido ao servidor para decidir quando
 * mostrar o overlay (so faz o pedido quando o utilizador tenta
 * desbloquear, validando a password). Ou seja: o lockscreen pode
 * aparecer mesmo que a sessao no servidor continue perfeitamente
 * valida - o proposito aqui e "o utilizador saiu de frente do
 * computador", nao "a sessao morreu".
 *
 * Os dois ficheiros sao independentes e podem ser usados um sem o
 * outro. Se usar os dois, o heartbeat.js continua a correr por baixo
 * do overlay normalmente (o overlay e so visual).
 *
 * INSTALACAO:
 * Este ficheiro so precisa de ser incluido se
 * config('login-tracker.lockscreen.enabled') for true - nesse caso,
 * o pacote inclui-o automaticamente atraves da diretiva Blade
 * @loginTrackerLockscreen (ver README, seccao de instalacao do
 * lockscreen). Normalmente NAO precisa de o incluir manualmente.
 */
(function () {
    'use strict';

    var config = window.LoginTrackerLockscreenConfig || {};
    var idleSeconds = config.idleSeconds || 900;
    var idleTimer = null;
    var locked = false;

    var overlay = document.getElementById('login-tracker-lockscreen');

    if (!overlay) {
        console.warn('[LoginTracker] Overlay de lockscreen nao encontrado na pagina - idle.js desativado.');
        return;
    }

    function showLockscreen() {
        if (locked) return;
        locked = true;
        overlay.style.display = 'flex';

        // Foca automaticamente no campo de password, para o utilizador
        // so precisar de comecar a escrever.
        var passwordInput = document.getElementById('login-tracker-password');
        if (passwordInput) {
            setTimeout(function () { passwordInput.focus(); }, 50);
        }
    }

    function hideLockscreen() {
        locked = false;
        overlay.style.display = 'none';
        resetTimer();
    }

    function resetTimer() {
        // idle_seconds = 0 (ou nao definido) desliga a deteccao AUTOMATICA
        // por inatividade, mas nao desativa o ficheiro inteiro - um
        // bloqueio manual (via botao, chamando .lock() diretamente, ou
        // via bloqueio remoto disparado pelo servidor - ver heartbeat.js
        // e README, seccao "Forcar o lockscreen manualmente") continua a
        // funcionar mesmo assim. Por isso este early-return esta aqui
        // dentro, e nao la em cima logo a seguir ao "if (!overlay)": se
        // estivesse la em cima, a funcao inteira parava antes de expor
        // window.LoginTrackerLockscreen, e um botao manual de "Bloquear
        // agora" deixaria de ter o que chamar.
        if (!idleSeconds || idleSeconds <= 0) {
            return;
        }

        if (locked) {
            // Enquanto o overlay estiver visivel, nao reinicia o timer
            // com base em atividade DENTRO do proprio overlay (ex: o
            // utilizador a escrever a password conta como "atividade",
            // mas nao deve desbloquear sozinho - so o submit correto
            // desbloqueia, via hideLockscreen() chamado pelo unlock()
            // abaixo).
            return;
        }

        clearTimeout(idleTimer);
        idleTimer = setTimeout(showLockscreen, idleSeconds * 1000);
    }

    // Eventos que contam como "atividade" e reiniciam a contagem.
    // passive:true melhora performance de scroll em mobile. So tem
    // efeito pratico quando idle_seconds > 0 (ver early-return dentro
    // de resetTimer), mas registamos sempre - custo irrelevante.
    ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'].forEach(function (eventName) {
        document.addEventListener(eventName, resetTimer, { passive: true });
    });

    // Se a aba ficar invisivel (utilizador foi para outra aba/app) e
    // voltar depois de mais tempo que idle_seconds, bloqueia
    // imediatamente ao voltar, em vez de esperar o timer (que fica
    // pausado por navegadores em abas nao visiveis, entao sozinho nao
    // seria fiavel para este caso). So se aplica com idle_seconds > 0.
    var hiddenAt = null;
    document.addEventListener('visibilitychange', function () {
        if (!idleSeconds || idleSeconds <= 0) {
            return;
        }

        if (document.visibilityState === 'hidden') {
            hiddenAt = Date.now();
        } else if (document.visibilityState === 'visible' && hiddenAt) {
            var awaySeconds = (Date.now() - hiddenAt) / 1000;
            hiddenAt = null;
            if (awaySeconds >= idleSeconds) {
                showLockscreen();
            } else {
                resetTimer();
            }
        }
    });

    // API publica: o formulario dentro da view de lockscreen (padrao ou
    // customizada) chama window.LoginTrackerLockscreen.unlock() apos
    // confirmar a password com sucesso no servidor. Tambem exposta para
    // permitir bloquear manualmente - ver README, seccao "Forcar o
    // lockscreen manualmente" - seja atraves de um botao na propria
    // pagina (chamando .lock() diretamente), seja atraves de um bloqueio
    // remoto disparado do lado do servidor (LoginTracker::forceLock($user)
    // ou o comando "php artisan login-tracker:lock"), que e entregue por
    // heartbeat.js (ficheiro separado) na resposta ao proximo ping desta
    // sessao, e que entao chama .lock() aqui.
    window.LoginTrackerLockscreen = {
        lock: showLockscreen,
        unlock: hideLockscreen,
        isLocked: function () { return locked; },
    };

    resetTimer();
})();
