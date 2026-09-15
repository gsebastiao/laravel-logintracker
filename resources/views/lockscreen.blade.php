{{--
    Ecra de bloqueio PADRAO do pacote gsebastiao/laravel-logintracker.

    Esta view e usada automaticamente quando
    config('login-tracker.lockscreen.view') aponta para
    'login-tracker::lockscreen' (o valor por defeito).

    COMO PERSONALIZAR:

    Opcao A - editar esta mesma view no seu projeto (mais simples):
        php artisan vendor:publish --tag=login-tracker-views
    Isto copia este ficheiro para
        resources/views/vendor/login-tracker/lockscreen.blade.php
    e o Laravel passa a usar essa copia automaticamente. Edite o HTML/
    Tailwind/CSS a vontade a partir dai.

    Opcao B - usar uma view sua, noutro sitio qualquer (ex: para
    reaproveitar o layout do resto da sua app):
    No config/login-tracker.php, mude:
        'view' => 'partials.meu-lockscreen',
    E crie essa view onde preferir. Todas as variaveis usadas aqui em
    baixo ($ltUserName, $ltUnlockUrl, etc.) chegam automaticamente
    tambem a sua view custom - nao precisa de escrever nenhum PHP para
    as obter. Ver a lista completa e o que cada uma faz em:
        src/View/Composers/LockscreenComposer.php
    (ou a seccao "Personalizar o ecra de bloqueio" no README do pacote).

    Esta view nao depende de nenhum framework CSS (Tailwind, Bootstrap,
    etc.) - o estilo esta todo inline/num <style> proprio, para
    funcionar em qualquer projeto sem conflitos. Se o seu projeto ja usa
    Tailwind, sinta-se livre para reescrever tudo com classes utility ao
    publicar a view.
--}}
<div
    id="login-tracker-lockscreen"
    style="
        display: none;
        position: fixed;
        inset: 0;
        z-index: 999999;
        background: rgba(17, 24, 39, 0.97);
        backdrop-filter: blur(6px);
        align-items: center;
        justify-content: center;
        font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
    "
>
    <div style="width: 100%; max-width: 360px; padding: 32px; text-align: center; color: #f9fafb;">

        @if ($ltUserAvatarUrl)
            <img
                src="{{ $ltUserAvatarUrl }}"
                alt="{{ $ltUserName }}"
                style="width: 88px; height: 88px; border-radius: 9999px; margin: 0 auto 20px; display: block; border: 3px solid rgba(255,255,255,0.15); object-fit: cover;"
            >
        @endif

        <h1 style="font-size: 20px; font-weight: 600; margin: 0 0 4px;">
            {{ $ltUserName }}
        </h1>

        <p style="font-size: 13px; color: #9ca3af; margin: 0 0 28px;">
            Sessao bloqueada por inatividade
            @if ($ltOnlineDuration)
                &middot; online ha {{ $ltOnlineDuration }}
            @endif
        </p>

        <form id="login-tracker-unlock-form" autocomplete="off" style="text-align: left;">
            @csrf

            <label for="login-tracker-password" style="display: block; font-size: 13px; margin-bottom: 6px; color: #d1d5db;">
                Confirme a sua password para continuar
            </label>

            <input
                type="password"
                id="login-tracker-password"
                name="password"
                autocomplete="current-password"
                required
                autofocus
                style="
                    width: 100%;
                    box-sizing: border-box;
                    padding: 10px 12px;
                    border-radius: 8px;
                    border: 1px solid #374151;
                    background: #1f2937;
                    color: #f9fafb;
                    font-size: 14px;
                    margin-bottom: 8px;
                "
            >

            <p
                id="login-tracker-error"
                style="display:none; color: #f87171; font-size: 13px; margin: 0 0 12px;"
            ></p>

            <button
                type="submit"
                style="
                    width: 100%;
                    padding: 10px 12px;
                    border-radius: 8px;
                    border: none;
                    background: #2563eb;
                    color: #fff;
                    font-size: 14px;
                    font-weight: 600;
                    cursor: pointer;
                    margin-top: 4px;
                "
            >
                Desbloquear
            </button>
        </form>

        @if ($ltLogoutUrl)
            <form action="{{ $ltLogoutUrl }}" method="POST" style="margin-top: 16px;">
                @csrf
                <button
                    type="submit"
                    style="
                        background: none;
                        border: none;
                        color: #9ca3af;
                        font-size: 13px;
                        cursor: pointer;
                        text-decoration: underline;
                    "
                >
                    Nao e {{ $ltUserName }}? Sair
                </button>
            </form>
        @endif
    </div>
</div>

<script>
(function () {
    var overlay = document.getElementById('login-tracker-lockscreen');
    var form = document.getElementById('login-tracker-unlock-form');
    var passwordInput = document.getElementById('login-tracker-password');
    var errorMessage = document.getElementById('login-tracker-error');

    if (!overlay || !form) {
        return;
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        errorMessage.style.display = 'none';

        fetch('{{ $ltUnlockUrl }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ $ltCsrfToken }}',
                'Accept': 'application/json',
            },
            body: JSON.stringify({ password: passwordInput.value }),
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { status: response.status, data: data };
                });
            })
            .then(function (result) {
                if (result.status === 200 && result.data.unlocked) {
                    passwordInput.value = '';
                    // Avisa o script de deteccao de inatividade
                    // (idle.js) que pode esconder o overlay e reiniciar
                    // a contagem.
                    if (window.LoginTrackerLockscreen && window.LoginTrackerLockscreen.unlock) {
                        window.LoginTrackerLockscreen.unlock();
                    }
                } else {
                    var message = (result.data.errors && result.data.errors.password && result.data.errors.password[0])
                        || result.data.message
                        || 'Password incorreta.';
                    errorMessage.textContent = message;
                    errorMessage.style.display = 'block';
                    passwordInput.value = '';
                    passwordInput.focus();
                }
            })
            .catch(function () {
                errorMessage.textContent = 'Nao foi possivel verificar a password. Tente novamente.';
                errorMessage.style.display = 'block';
            });
    });
})();
</script>
