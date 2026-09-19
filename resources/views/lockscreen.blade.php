{{--
    Ecrã de bloqueio padrão do LoginTracker.

    PERSONALIZAR:
      php artisan vendor:publish --tag=logintracker-views
    Isto copia este ficheiro para
      resources/views/vendor/logintracker/lockscreen.blade.php
    e o Laravel passa a usar a sua cópia automaticamente.

    Ou aponte para uma view totalmente sua em
      config/logintracker.php → lockscreen.view

    As variáveis $lt* abaixo chegam sozinhas a qualquer view de
    lockscreen. Ver a lista completa no README.

    REQUISITO se criar uma view de raiz: mantenha os IDs
      #logintracker-lockscreen   (o overlay)
      #logintracker-unlock-form  (o formulário)
      #logintracker-password     (o campo)
      #logintracker-error        (onde os erros aparecem)
    É por eles que o JavaScript encontra os elementos.
--}}
<div
    id="logintracker-lockscreen"
    role="dialog"
    aria-modal="true"
    aria-label="Sessão bloqueada"
    style="
        display: {{ $ltIsLocked ? 'flex' : 'none' }};
        position: fixed;
        inset: 0;
        z-index: 2147483646;
        background: rgba(17, 24, 39, 0.98);
        backdrop-filter: blur(8px);
        align-items: center;
        justify-content: center;
        font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
    "
>
    <div style="width: 100%; max-width: 360px; padding: 32px; text-align: center; color: #f9fafb;">

        @if ($ltUserAvatarUrl)
            <img src="{{ $ltUserAvatarUrl }}" alt=""
                 style="width: 88px; height: 88px; border-radius: 9999px; margin: 0 auto 20px; display: block; border: 3px solid rgba(255,255,255,.15); object-fit: cover;">
        @endif

        <h1 style="font-size: 20px; font-weight: 600; margin: 0 0 4px;">{{ $ltUserName }}</h1>

        <p style="font-size: 13px; color: #9ca3af; margin: 0 0 28px;">Sessão bloqueada</p>

        <form id="logintracker-unlock-form" autocomplete="off" style="text-align: left;">
            <label for="logintracker-password" style="display: block; font-size: 13px; margin-bottom: 6px; color: #d1d5db;">
                Introduza a sua password para continuar
            </label>

            <input type="password" id="logintracker-password" name="password"
                   autocomplete="current-password" required
                   style="width: 100%; box-sizing: border-box; padding: 10px 12px; border-radius: 8px; border: 1px solid #374151; background: #1f2937; color: #f9fafb; font-size: 14px; margin-bottom: 8px;">

            <p id="logintracker-error" style="display: none; color: #f87171; font-size: 13px; margin: 0 0 12px;"></p>

            <button type="submit"
                    style="width: 100%; padding: 10px 12px; border-radius: 8px; border: 0; background: #2563eb; color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; margin-top: 4px;">
                Desbloquear
            </button>
        </form>

        @if ($ltLogoutUrl)
            <form action="{{ $ltLogoutUrl }}" method="POST" style="margin-top: 16px;">
                @csrf
                <button type="submit"
                        style="background: none; border: 0; color: #9ca3af; font-size: 13px; cursor: pointer; text-decoration: underline;">
                    Terminar sessão
                </button>
            </form>
        @endif
    </div>
</div>
