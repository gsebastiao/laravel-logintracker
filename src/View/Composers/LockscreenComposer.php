<?php

namespace Gsebastiao\LoginTracker\View\Composers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Gsebastiao\LoginTracker\Facades\LoginTracker;

/**
 * Este Composer injeta AUTOMATICAMENTE um conjunto de variaveis prontas
 * a usar em QUALQUER view de lockscreen - seja a view padrao que vem
 * com o pacote, seja uma view 100% sua que voce aponte em
 * config('login-tracker.lockscreen.view').
 *
 * Ou seja: se voce criar a sua propria view de bloqueio, NAO precisa de
 * escrever nenhum PHP/controller para obter o nome do utilizador, o
 * avatar, etc. - estas variaveis ja chegam prontas porque este Composer
 * e registado (ver LoginTrackerServiceProvider::boot()) para correr
 * sempre que a view configurada em 'lockscreen.view' for renderizada,
 * seja qual for o caminho dessa view.
 *
 * VARIAVEIS DISPONIVEIS NA VIEW:
 *
 *   $ltUser              -> o utilizador autenticado (Model completo,
 *                            Auth::user()). null se por algum motivo a
 *                            view for renderizada sem sessao (nao deve
 *                            acontecer em uso normal, mas fica seguro).
 *
 *   $ltUserName           -> string pronta para mostrar: tenta
 *                            $user->name, depois $user->email, depois
 *                            "Utilizador" como ultimo recurso - poupa
 *                            voce de escrever esse if/else na view.
 *
 *   $ltUserAvatarUrl      -> string|null. Tenta $user->avatar_url,
 *                            depois $user->avatar, depois gera um
 *                            avatar automatico via ui-avatars.com com
 *                            as iniciais do nome (nunca fica sem imagem).
 *
 *   $ltOnlineDuration     -> string|null, ex: "2 horas e 15 minutos".
 *                            Ha quanto tempo esta sessao esta ativa.
 *
 *   $ltUnlockUrl          -> string. URL absoluto do endpoint que
 *                            valida a password para desbloquear. Use
 *                            isto na sua view custom no atributo
 *                            action/fetch do formulario.
 *
 *   $ltLogoutUrl          -> string|null. URL para fazer logout a
 *                            partir do lockscreen (null se
 *                            'allow_logout_from_lockscreen' estiver
 *                            false na config).
 *
 *   $ltCsrfToken          -> string. Token CSRF pronto, ja resolvido
 *                            (equivalente a csrf_token()).
 *
 *   $ltConfig             -> array. A config('login-tracker.lockscreen')
 *                            completa, para o caso de precisar de algum
 *                            valor que nao tenha uma variavel dedicada
 *                            acima (ex: $ltConfig['idle_seconds']).
 *
 * Se o SEU modelo de utilizador tiver campos com nomes diferentes (ex:
 * "full_name" em vez de "name"), a forma mais simples de ajustar e
 * publicar este ficheiro:
 *
 *   php artisan vendor:publish --tag=login-tracker-views
 *
 * ...e editar a logica de $ltUserName / $ltUserAvatarUrl nas funcoes
 * protected abaixo, OU simplesmente ignorar $ltUserName na sua view
 * customizada e escrever {{ $ltUser->full_name }} diretamente - o
 * $ltUser (Model completo) esta sempre disponivel para isso.
 */
class LockscreenComposer
{
    public function compose(View $view): void
    {
        $user = Auth::user();

        $view->with([
            'ltUser'             => $user,
            'ltUserName'         => $this->resolveUserName($user),
            'ltUserAvatarUrl'    => $this->resolveAvatarUrl($user),
            'ltOnlineDuration'   => $user ? LoginTracker::onlineDurationForHumans($user) : null,
            'ltUnlockUrl'        => route('login-tracker.unlock'),
            'ltLogoutUrl'        => config('login-tracker.lockscreen.allow_logout_from_lockscreen', true)
                                        ? route('login-tracker.lockscreen-logout')
                                        : null,
            'ltCsrfToken'        => csrf_token(),
            'ltConfig'           => config('login-tracker.lockscreen', []),
        ]);
    }

    /**
     * Resolve um nome de exibicao razoavel independentemente de como o
     * Model de utilizador da aplicacao esta estruturado.
     */
    protected function resolveUserName($user): string
    {
        if (! $user) {
            return 'Utilizador';
        }

        return $user->name ?? $user->email ?? 'Utilizador';
    }

    /**
     * Resolve uma URL de avatar. Se a aplicacao nao tiver nenhum campo
     * de avatar definido, gera um automaticamente com as iniciais do
     * nome via ui-avatars.com (servico publico gratuito, sem
     * necessidade de API key) - assim a view padrao nunca fica com uma
     * imagem quebrada.
     */
    protected function resolveAvatarUrl($user): ?string
    {
        if (! $user) {
            return null;
        }

        if (! empty($user->avatar_url)) {
            return $user->avatar_url;
        }

        if (! empty($user->avatar)) {
            return $user->avatar;
        }

        $name = urlencode($this->resolveUserName($user));

        return "https://ui-avatars.com/api/?name={$name}&background=1f2937&color=fff&size=128";
    }
}
