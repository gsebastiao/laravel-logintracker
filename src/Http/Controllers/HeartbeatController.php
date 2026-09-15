<?php

namespace Gsebastiao\LoginTracker\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Gsebastiao\LoginTracker\Models\AuthLogin;
use Gsebastiao\LoginTracker\Models\AuthSession;

/**
 * Recebe o "ping" do JS de heartbeat (resources/js/heartbeat.js) e, se
 * 'heartbeat.auto_logout_on_expiry' estiver ativo, o pedido de logout
 * forcado disparado por esse mesmo JS quando deteta expiracao.
 *
 * Isto e o que cobre o caso especifico que motivou esta funcionalidade:
 * o utilizador deixa a aba aberta, parado, sem clicar em nada - nenhuma
 * requisicao normal e feita, entao o middleware UpdateLastSeen nunca
 * dispara. O JS chama este endpoint em intervalos regulares enquanto a
 * aba estiver visivel, mantendo o last_seen_at vivo.
 *
 * Quando a sessao expira NO SERVIDOR enquanto a aba continua aberta, este
 * endpoint retorna 401. E responsabilidade do JS do lado do cliente
 * reagir a esse 401 (ver heartbeat.js) - o Laravel/este pacote nao tem
 * como "empurrar" um aviso para uma aba ja aberta sem esse ping.
 */
class HeartbeatController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $guard = config('auth.defaults.guard');
        $user = $request->user($guard);

        // A sessao expirou no servidor. O middleware de auth ja teria
        // barrado isto antes de chegar aqui se a rota exigir 'auth', mas
        // deixamos explicito para o caso de configuracao customizada.
        if (! $user) {
            return response()->json([
                'online'          => false,
                'reason'          => 'unauthenticated',
                'auto_logout_url' => config('login-tracker.heartbeat.auto_logout_on_expiry', true)
                    ? route('login-tracker.force-logout')
                    : null,
            ], 401);
        }

        $sessionId = $this->resolveSessionId($request, $guard);

        if (! $sessionId) {
            return response()->json(['online' => false, 'reason' => 'no_session'], 200);
        }

        $now = now();
        $morphName = config('login-tracker.morph_name', 'authenticatable');

        // Le o estado de 'lock_requested_at' ANTES do updateOrCreate
        // abaixo, e propositadamente NAO incluimos essa coluna no array
        // de update dele. Isto evita uma condicao de corrida real: se
        // 'lock_requested_at' estivesse no array de update (como
        // 'ended_at' esta, sempre a null), um forceLock() chamado por
        // um admin exatamente durante este pedido seria apagado por
        // este proprio updateOrCreate antes de ser entregue - o pedido
        // desapareceria sem o utilizador nunca ter visto o lockscreen.
        //
        // Nota de honestidade: isto reduz a janela de corrida a
        // milissegundos (o intervalo entre este SELECT e o
        // updateOrCreate abaixo), mas nao a elimina 100% sem uma
        // transacao com lockForUpdate(). Optamos por nao adicionar essa
        // complexidade porque o pior cenario aqui e trivial: o pedido
        // simplesmente sobrevive na coluna e e entregue no PROXIMO
        // ping (no maximo ping_interval_seconds depois) em vez deste -
        // nunca ha perda de dado permanente, so um atraso adicional
        // raro e pequeno.
        $existingSession = AuthSession::query()
            ->where('guard', $guard)
            ->where('session_id', $sessionId)
            ->first();

        $hadPendingLock = $existingSession && $existingSession->lock_requested_at !== null;

        $session = AuthSession::updateOrCreate(
            ['guard' => $guard, 'session_id' => $sessionId],
            [
                $morphName . '_id'   => $user->getAuthIdentifier(),
                $morphName . '_type' => get_class($user),
                'ip_address'   => config('login-tracker.capture.ip_address', true) ? $request->ip() : null,
                'user_agent'   => config('login-tracker.capture.user_agent', true) ? $request->userAgent() : null,
                'last_seen_at' => $now,
                'ended_at'     => null,
            ]
        );

        // started_at so e definido na criacao (updateOrCreate nao sobrescreve
        // em updates subsequentes porque nao esta no array de update acima,
        // mas precisa existir na criacao).
        if ($session->wasRecentlyCreated) {
            $session->update(['started_at' => $now]);
        }

        // Se havia um pedido de bloqueio pendente, consome-o agora
        // (limpa a coluna) e instrui o heartbeat.js a mostrar o
        // lockscreen na resposta abaixo. "Consumir" aqui significa que
        // este e o UNICO ping que recebe a instrucao - se o JS por
        // algum motivo nao conseguir mostrar o overlay (ex: lockscreen
        // desativado na config entretanto), o pedido nao e reenviado.
        $shouldLock = $hadPendingLock && $session->consumePendingLock();

        return response()->json([
            'online'          => true,
            'last_seen_at'    => $now->toIso8601String(),
            'next_ping_in'    => config('login-tracker.heartbeat.ping_interval_seconds', 60),
            'should_lock'     => $shouldLock,
        ]);
    }

    /**
     * Chamado pelo heartbeat.js quando o ping acima devolveu 401 (sessao
     * expirada) e 'auto_logout_on_expiry' esta ativo. Responsabilidades:
     *
     *   1. Fechar FORMALMENTE, com origem AUDITADA, a linha em
     *      auth_sessions e o registo correspondente em auth_logins -
     *      com logout_reason = 'expired_client', para deixar claro que
     *      foi o proprio navegador do utilizador a confirmar isto (em
     *      oposicao a 'inferred_stale', que e quando NINGUEM confirma e
     *      a purga deduz sozinha mais tarde).
     *
     *   2. Limpar o cookie/sessao do lado do servidor de verdade
     *      (Auth::logout() + invalidate + regenerateToken), para que,
     *      quando o navegador redirecionar para o login, nao sobre
     *      nenhum resto de sessao valida por tras.
     *
     * IMPORTANTE: propositadamente NAO exige 'auth' no middleware desta
     * rota (ver routes/login-tracker.php) - e chamado exatamente PORQUE
     * a sessao ja nao e valida, entao $request->user() aqui ja vai
     * retornar null na maioria dos casos. Por isso identificamos QUEM
     * estava logado atraves do session_id (que ainda existe no cookie,
     * mesmo com o utilizador ja "deslogado" pelo Laravel), procurando a
     * sessao correspondente em auth_sessions - nao atraves do utilizador
     * autenticado, que a esta altura ja nao existe.
     */
    public function forceLogout(Request $request): JsonResponse
    {
        $guard = config('auth.defaults.guard');

        // ORDEM IMPORTA: resolveSessionId() tem de ser chamado ANTES de
        // qualquer invalidate()/regenerateToken() na sessao (mais abaixo)
        // - depois disso, o ID de sessao muda, e ja nao encontrariamos a
        // linha correspondente em auth_sessions para fechar/auditar.
        $sessionId = $this->resolveSessionId($request, $guard);

        if ($sessionId) {
            $this->closeSessionAsExpired($guard, $sessionId);
        }

        // Auth::logout() e chamado mesmo que nao tenhamos encontrado a
        // sessao acima (ex: cookie ja tinha sido limpo por outra via) -
        // isto garante que, seja qual for o estado encontrado, o
        // resultado final e sempre "sem sessao valida nenhuma", que e o
        // proposito deste endpoint.
        if (Auth::guard($guard)->check()) {
            Auth::guard($guard)->logout();
        }

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $loginRouteName = config('login-tracker.lockscreen.login_route_name', 'login');
        $redirectRouteName = config('login-tracker.heartbeat.expired_redirect_route_name') ?? $loginRouteName;

        return response()->json([
            'logged_out'   => true,
            'redirect_url' => route($redirectRouteName),
        ]);
    }

    /**
     * Fecha, com logout_reason = 'expired_client', a sessao ativa e o
     * registo de historico correspondentes ao session_id fornecido -
     * identificando o utilizador a partir da PROPRIA sessao guardada em
     * auth_sessions, ja que a esta altura $request->user() ja nao esta
     * disponivel (a sessao no servidor ja expirou).
     */
    protected function closeSessionAsExpired(string $guard, string $sessionId): void
    {
        $morphName = config('login-tracker.morph_name', 'authenticatable');
        $now = now();

        $session = AuthSession::query()
            ->where('guard', $guard)
            ->where('session_id', $sessionId)
            ->whereNull('ended_at')
            ->first();

        // Sessao ja tinha sido fechada antes (ex: por outra aba, ou pela
        // purga entretanto) - nada a fazer, evita sobrescrever um
        // ended_at/logout_reason que ja fazia sentido.
        if (! $session) {
            return;
        }

        $session->update(['ended_at' => $now]);

        $lastLogin = AuthLogin::query()
            ->where($morphName . '_id', $session->{$morphName . '_id'})
            ->where($morphName . '_type', $session->{$morphName . '_type'})
            ->where('guard', $guard)
            ->where('event', 'login')
            ->whereNull('logout_at')
            ->latest('login_at')
            ->first();

        if ($lastLogin) {
            $lastLogin->update([
                'logout_at'       => $now,
                'logout_reason'   => AuthLogin::LOGOUT_EXPIRED_CLIENT,
            ]);
        }
    }

    protected function resolveSessionId(Request $request, ?string $guard): ?string
    {
        if ($request->hasSession()) {
            return 'sess_' . $request->session()->getId();
        }

        $user = $request->user($guard);
        if ($user && method_exists($user, 'currentAccessToken')) {
            $token = $user->currentAccessToken();
            if ($token) {
                return 'token_' . ($token->id ?? md5((string) $token));
            }
        }

        if ($bearer = $request->bearerToken()) {
            return 'bearer_' . md5($bearer);
        }

        return null;
    }
}
