# Laravel Login Tracker

**`gsebastiao/laravel-logintracker`**

Pacote Laravel que:

1. Regista automaticamente **entradas (login)**, **saídas (logout)** e **tentativas falhadas** de autenticação de cada utilizador.
2. Responde à pergunta **"quem está online agora"** e **"há quanto tempo"**, em tempo real.
3. Mostra um **ecrã de bloqueio automático** quando o utilizador fica um tempo sem usar o sistema (como o lockscreen do Windows/Mac), com uma tela padrão pronta a usar e totalmente personalizável.

Tudo isto guardado em tabelas cujo **nome você escolhe** (com um valor padrão que já funciona sem configurar nada).

Este README foi escrito para ser seguido do início ao fim **mesmo que você nunca tenha usado um pacote Laravel antes**. Se em algum passo faltar alguma informação, isso é uma falha do README — abra uma issue.

---

## Índice

1. [Instalação passo a passo](#1-instalação-passo-a-passo)
2. [Como funciona (leia isto antes de configurar)](#2-como-funciona-leia-isto-antes-de-configurar)
3. [Consultar quem está online](#3-consultar-quem-está-online)
4. [Logout automático quando a sessão expira](#4-logout-automático-quando-a-sessão-expira)
5. [Ativar o ecrã de bloqueio (lockscreen)](#5-ativar-o-ecrã-de-bloqueio-lockscreen)
6. [Personalizar o ecrã de bloqueio](#6-personalizar-o-ecrã-de-bloqueio)
7. [Forçar o lockscreen manualmente](#7-forçar-o-lockscreen-manualmente)
8. [Todas as opções de configuração explicadas](#8-todas-as-opções-de-configuração-explicadas)
9. [Manutenção: purgar dados antigos](#9-manutenção-purgar-dados-antigos)
10. [Perguntas frequentes / problemas comuns](#10-perguntas-frequentes--problemas-comuns)
11. [Compatibilidade com Fortify, Jetstream, Breeze e Sanctum](#11-compatibilidade-com-fortify-jetstream-breeze-e-sanctum)
12. [Estrutura das tabelas](#12-estrutura-das-tabelas)

---

## 1. Instalação passo a passo

### Passo 1 — Instalar via Composer

No terminal, na raiz do seu projeto Laravel:

```bash
composer require gsebastiao/laravel-logintracker
```

Isto baixa o pacote. O Laravel deteta automaticamente o pacote (não precisa registar nada manualmente em `config/app.php` — isto é feito via "package discovery" do próprio Laravel).

### Passo 2 — Publicar o ficheiro de configuração

```bash
php artisan vendor:publish --tag=login-tracker-config
```

Isto cria o ficheiro `config/login-tracker.php` no **seu** projeto, com todas as opções documentadas (comentadas em português). É aqui que você vai mexer para configurar tudo mais tarde.

> **O que significa "publicar"?** O pacote vem com um ficheiro de configuração padrão escondido dentro da pasta `vendor/`. Este comando faz uma cópia desse ficheiro para dentro do seu projeto, num sítio onde você pode editar à vontade sem essa edição se perder quando atualizar o pacote no futuro.

### Passo 3 — Rodar as migrations (criar as tabelas na base de dados)

```bash
php artisan migrate
```

Isto cria duas tabelas na sua base de dados:
- `auth_logins` — histórico de entradas/saídas/tentativas falhadas.
- `auth_sessions` — estado atual (quem está online agora).

> Não precisa de publicar as migrations para elas funcionarem — o pacote já as carrega automaticamente. Só publique-as (passo opcional abaixo) se quiser alterar a estrutura das tabelas.

**(Opcional)** Se quiser editar a estrutura das tabelas antes de migrar (por exemplo, adicionar uma coluna extra):

```bash
php artisan vendor:publish --tag=login-tracker-migrations
```

Isto copia os ficheiros de migration para `database/migrations/` no seu projeto. Edite-os, depois rode `php artisan migrate`.

### Passo 4 — Pronto! Já está a funcionar

**Não precisa de escrever nenhum código.** A partir de agora, sempre que alguém fizer login ou logout na sua aplicação (usando `Auth::login()`, `Auth::attempt()`, Laravel Breeze, Jetstream, Fortify, Sanctum — qualquer forma padrão do Laravel), o pacote regista isso automaticamente.

Para confirmar que está a funcionar: faça login na sua aplicação normalmente, depois abra o **Tinker** (`php artisan tinker`) e rode:

```php
\Gsebastiao\LoginTracker\Models\AuthLogin::latest()->first();
```

Se aparecer um registo com o seu login recente, está tudo a funcionar.

---

## 2. Como funciona (leia isto antes de configurar)

Este pacote responde a **duas perguntas diferentes**, guardadas em **duas tabelas diferentes**. Entender isto evita muita confusão mais à frente:

| Pergunta | Onde fica guardado | Como pensar nisto |
|---|---|---|
| "Quando é que o José entrou e saiu, historicamente?" | tabela `auth_logins` | Um livro de registo. Nunca se apaga uma linha antiga (a não ser pela limpeza automática configurável). |
| "O José está online **agora**? Há quanto tempo?" | tabela `auth_sessions` | Um placar que se atualiza a cada instante. A linha do José muda constantemente enquanto ele estiver a usar o sistema. |

### Por que precisamos de uma segunda tabela para "está online"?

Parece que dava para responder "está online" só a olhar para o histórico (`auth_logins`) e ver se existe uma entrada sem saída registada. **Isto não funciona**, e é importante perceber porquê:

A sessão de um utilizador pode "morrer" de várias formas **sem que ninguém clique em "Sair"**:
- Ele fecha a aba do navegador.
- Ele fica muito tempo sem usar o sistema e a sessão expira sozinha (timeout).
- O token de acesso de uma API expira.

Em nenhum destes casos o Laravel dispara um evento de "logout" — porque tecnicamente **não houve** nenhum logout, a sessão simplesmente deixou de ser válida. Se você tentasse responder "está online" olhando só para o histórico, teria utilizadores marcados como "ainda logados" para sempre, mesmo tendo saído há dias.

A solução: uma segunda tabela (`auth_sessions`) que recebe um **"sinal de vida"** (chamado de *heartbeat*) em intervalos regulares, enquanto o utilizador estiver mesmo a usar o sistema. Quando o sinal para de chegar, sabemos que ele saiu — sem precisar de nenhum evento explícito de logout.

Isto é feito de duas formas, que trabalham em conjunto:

1. **Automática (middleware):** sempre que o utilizador autenticado faz qualquer ação normal no sistema (abrir uma página, clicar num botão que faz um pedido ao servidor, usar a API), o pacote regista isso como "sinal de vida". **Não precisa de configurar nada para isto funcionar** — já vem ativo por padrão.

2. **Ativa (JavaScript opcional):** cobre o caso de o utilizador deixar a aba aberta, parado, sem clicar em nada. Um pequeno script no navegador avisa o servidor em intervalos regulares ("continuo aqui"), mesmo sem nenhuma ação do utilizador. Isto é opcional — ver secção 3, "Instalar o heartbeat ativo".

---

## 3. Consultar quem está online

### Perguntar sobre um utilizador específico

```php
use Gsebastiao\LoginTracker\Facades\LoginTracker;

// $user é uma instância do seu Model de utilizador, ex: Auth::user()
// ou User::find(5)

LoginTracker::isOnline($user); // true ou false

LoginTracker::onlineDurationForHumans($user); // "2 horas e 15 minutos", ou null se offline
```

### Listar todos os utilizadores online agora

```php
use Gsebastiao\LoginTracker\Models\AuthSession;

$sessoesOnline = AuthSession::online()->with('authenticatable')->get();

foreach ($sessoesOnline as $sessao) {
    echo $sessao->authenticatable->name; // o utilizador (via relação polimórfica)
    echo $sessao->durationForHumans();   // "45 minutos"
}
```

### Ver o histórico completo de um utilizador

```php
LoginTracker::historyFor($user); // Collection de AuthLogin, mais recente primeiro
```

### Instalar o heartbeat ativo (recomendado)

Sem este passo, "está online" já funciona razoavelmente bem (via middleware automático), mas só se atualiza quando o utilizador **interage** com o sistema. Se ele ficar com a aba aberta sem clicar em nada, o sistema vai achar que ele "parou" no momento exato do último clique — não no momento real em que ele realmente saiu de frente do ecrã.

Para uma deteção mais fiel, publique e inclua o script de heartbeat:

```bash
php artisan vendor:publish --tag=login-tracker-assets
```

E no seu layout principal (o ficheiro Blade que envolve as páginas autenticadas, por exemplo `resources/views/layouts/app.blade.php`), adicione **antes do fecho `</body>`**:

```blade
<script>
  window.LoginTrackerConfig = {
    heartbeatUrl: '{{ route("login-tracker.heartbeat") }}',
    pingIntervalSeconds: {{ config('login-tracker.heartbeat.ping_interval_seconds', 60) }},
    csrfToken: '{{ csrf_token() }}',
    loginUrl: '{{ route('login') }}',
    autoLogoutOnExpiry: {{ config('login-tracker.heartbeat.auto_logout_on_expiry', true) ? 'true' : 'false' }},
    forceLogoutUrl: @if(config('login-tracker.heartbeat.auto_logout_on_expiry', true)) '{{ route("login-tracker.force-logout") }}' @else null @endif,
  };
</script>
<script src="{{ asset('vendor/login-tracker/heartbeat.js') }}"></script>
```

> **Nota sobre `route('login')`:** ajuste este nome se a sua rota de login tiver outro nome no seu projeto. Este valor só é usado para onde redirecionar o utilizador se o script detetar que a sessão expirou enquanto ele estava com a aba aberta.

---

## 4. Logout automático quando a sessão expira

Isto já vem **ativado por padrão** assim que você instala o heartbeat (secção 3 acima) — não precisa de nenhum passo extra além do que já foi feito. Esta secção explica o que acontece e como desativar/ajustar, caso precise.

### O comportamento

Quando a sessão de um utilizador expira no servidor (por exemplo, o tempo configurado em `session.lifetime` no `config/session.php` do seu projeto passou) **enquanto ele continua com a aba aberta**, o pacote:

1. Deteta isto através do heartbeat (o próximo ping recebe HTTP 401 — ver secção 3).
2. Chama, sozinho, um `Auth::logout()` de verdade no servidor — limpando o cookie de sessão como se o utilizador tivesse clicado em "Sair".
3. Regista este logout automático no histórico, com a origem marcada como `expired_client` (ver secção 12, "Estrutura das tabelas" — isto é auditável, não é um logout "invisível").
4. Redireciona o navegador para a página de login.

O resultado final é exatamente o que costuma ser esperado deste tipo de sistema: **a sessão expira, e o utilizador simplesmente aparece na tela de login**, sem precisar de clicar em nada, e sem ficar preso numa página com uma sessão morta por trás.

### Isto é diferente do lockscreen (secção 5)

Vale repetir isto, porque é fácil confundir os dois:

- **Lockscreen:** a sessão no servidor continua **perfeitamente válida**. O que aconteceu foi só o utilizador ter ficado parado (rato/teclado) tempo demais. Ele desbloqueia com a própria password e volta exatamente para onde estava.
- **Logout automático (esta secção):** a sessão em si **deixou de ser válida** no servidor. Não há nada para "desbloquear" — o utilizador realmente precisa de fazer login de novo.

Os dois mecanismos são independentes e podem estar ambos ativos ao mesmo tempo, sem conflito.

### Desativar (voltar ao comportamento antigo, só de aviso)

Se preferir que o pacote apenas **avise** o utilizador da expiração sem fazer logout automático no servidor (deixando isso para o comando de purga tratar mais tarde, de forma inferida), desligue no `.env`:

```env
LOGIN_TRACKER_AUTO_LOGOUT_ON_EXPIRY=false
```

Com isto desativado, o comportamento passa a ser o mesmo de antes desta funcionalidade existir: um aviso na tela, sem chamada nenhuma ao servidor — e a sessão só fica formalmente fechada quando `login-tracker:purge` rodar (secção 9), com origem `inferred_stale` em vez de `expired_client`.

### Personalizar o que acontece ao expirar

Se quiser substituir completamente o comportamento (por exemplo, mostrar um modal em vez de um banner simples, ou fazer alguma limpeza extra no lado do cliente antes de redirecionar), defina `window.LoginTracker.onSessionExpired` **antes** do `heartbeat.js` carregar:

```blade
<script>
  window.LoginTracker = window.LoginTracker || {};
  window.LoginTracker.onSessionExpired = function () {
    // A sua lógica customizada aqui. Isto SUBSTITUI totalmente o
    // comportamento automático (incluindo o logout no servidor) - se
    // quiser manter o logout automático mas só mudar o visual do
    // aviso, chame você mesmo o endpoint de logout forçado dentro
    // desta função, usando fetch() para a URL que o pacote calculou em
    // window.LoginTrackerConfig.forceLogoutUrl.
    alert('A sua sessão expirou!');
    window.location.href = '/login';
  };
</script>
<script src="{{ asset('vendor/login-tracker/heartbeat.js') }}"></script>
```

> **Importante:** ao definir `onSessionExpired`, você assume o controlo total — o pacote deixa de chamar o logout automático sozinho. Se quiser manter a auditoria (`expired_client`) e só mudar a aparência do aviso, a sua função customizada precisa de fazer o `fetch()` para `window.LoginTrackerConfig.forceLogoutUrl` ela própria, tal como o comportamento padrão faz internamente.

---

## 5. Ativar o ecrã de bloqueio (lockscreen)

Isto é uma funcionalidade **separada e opcional** — vem desligada por padrão. Quando ativada, se o utilizador ficar um tempo sem mexer no rato/teclado, um ecrã de bloqueio aparece por cima da página atual (sem recarregar nada, sem perder o que ele estava a fazer), pedindo a password para continuar.

### Ativar

No seu ficheiro `.env`:

```env
LOGIN_TRACKER_LOCKSCREEN_ENABLED=true
LOGIN_TRACKER_LOCKSCREEN_IDLE_SECONDS=900
```

`900` segundos = 15 minutos parado até o ecrã aparecer. Ajuste como preferir.

### Publicar o script de deteção de inatividade

```bash
php artisan vendor:publish --tag=login-tracker-assets
```

(O mesmo comando do passo do heartbeat — se já correu antes, o `idle.js` já foi copiado também.)

### Adicionar a diretiva no seu layout

No mesmo ficheiro de layout onde colocou o script de heartbeat (ou, se não usa heartbeat, em qualquer layout usado pelas páginas autenticadas), adicione, **imediatamente antes do fecho `</body>`**:

```blade
@loginTrackerLockscreen
```

**É só isto.** Esta única linha:
- Verifica se o lockscreen está ativado na configuração (se não estiver, não faz nada).
- Verifica se há um utilizador autenticado (se não houver, não faz nada — não faz sentido bloquear a página de login).
- Imprime o HTML do ecrã de bloqueio (a versão padrão do pacote, pronta a usar).
- Carrega o script que deteta a inatividade.

Pronto. Faça login na aplicação, fique os segundos configurados sem mexer no rato/teclado, e o ecrã de bloqueio deve aparecer.

### O que acontece exatamente

1. O ecrã de bloqueio **não é uma página nova nem um redirecionamento** — é uma camada visual (overlay) por cima da página atual. O que o utilizador estava a fazer continua exatamente como estava por baixo.
2. Para desbloquear, o utilizador escreve a **própria password** (a mesma da conta com que está autenticado) e confirma.
3. Se preferir, existe também um botão "Sair", para o caso de não ser a mesma pessoa que estava a usar o computador antes.
4. Este ecrã de bloqueio **não é o mesmo que a sessão expirar**. São coisas diferentes: o lockscreen só olha para o rato/teclado no navegador; a expiração de sessão (secção 3, heartbeat) olha para se o servidor ainda considera a sessão válida. Pode perfeitamente aparecer o lockscreen com a sessão no servidor continuando 100% válida por trás — o objetivo do lockscreen é proteger o ecrã de alguém que passe por ali, não é um mecanismo de segurança de sessão.

---

## 6. Personalizar o ecrã de bloqueio

O pacote foi feito para ter **três níveis de personalização**, do mais simples ao mais avançado. Escolha o que precisar — não precisa de saber os três, o primeiro já resolve a maioria dos casos.

### Nível 1 — Publicar e editar a view padrão (mais comum)

```bash
php artisan vendor:publish --tag=login-tracker-views
```

Isto copia a view do ecrã de bloqueio para dentro do **seu** projeto, em:

```
resources/views/vendor/login-tracker/lockscreen.blade.php
```

A partir deste momento, o Laravel usa **automaticamente** esta cópia em vez da que vem dentro do pacote — não precisa de mudar nenhuma configuração. Edite este ficheiro à vontade: mude cores, adicione o logótipo da sua empresa, troque para Tailwind/Bootstrap se o seu projeto já usar, etc.

Dentro desse ficheiro já publicado, você tem acesso a um conjunto de variáveis prontas — não precisa de escrever nenhum PHP para as obter (ver lista completa mais abaixo).

### Nível 2 — Apontar para uma view sua, noutro sítio qualquer

Se preferir manter a sua própria view organizada junto com o resto das views da sua aplicação (em vez de dentro de `resources/views/vendor/`), no `config/login-tracker.php`:

```php
'lockscreen' => [
    // ...
    'view' => 'partials.meu-lockscreen', // resources/views/partials/meu-lockscreen.blade.php
],
```

Crie essa view onde quiser. **As mesmas variáveis do Nível 1 continuam disponíveis automaticamente** nesta view, seja qual for o caminho que você escolher — o pacote deteta o valor desta configuração e injeta os dados na view certa.

### Nível 3 — HTML completamente do zero

Combine com o Nível 2: crie a sua view do zero, sem copiar nada do pacote, usando só as variáveis abaixo. O único requisito técnico é que a sua view faça:
- um `POST` para `$ltUnlockUrl` com um campo `password`, esperando de volta `{"unlocked": true}` em caso de sucesso;
- (opcional) um formulário `POST` para `$ltLogoutUrl` se quiser um botão de logout.

Veja o ficheiro `resources/views/lockscreen.blade.php` dentro do pacote como referência de como isto é feito (é exatamente o HTML/JS que o Nível 1 lhe dá para editar).

### Variáveis e helpers disponíveis em qualquer view de lockscreen

Estas variáveis chegam **automaticamente** à view configurada (seja a padrão, seja a sua customizada) — não precisa de nenhum controller ou código extra para as obter:

| Variável | Tipo | Descrição |
|---|---|---|
| `$ltUser` | Model ou null | O utilizador autenticado, igual a `Auth::user()`. Use isto se precisar de aceder a algum campo específico do seu Model que não tenha uma variável dedicada abaixo. |
| `$ltUserName` | string | Nome pronto a mostrar. Tenta `name`, depois `email`, depois usa `"Utilizador"` como último recurso. |
| `$ltUserAvatarUrl` | string ou null | URL de uma imagem de avatar. Tenta `avatar_url`, depois `avatar`; se o seu utilizador não tiver nenhum dos dois, gera automaticamente um avatar com as iniciais do nome (nunca fica com uma imagem quebrada). |
| `$ltOnlineDuration` | string ou null | Há quanto tempo esta sessão está ativa, ex: `"2 horas e 15 minutos"`. |
| `$ltUnlockUrl` | string | URL completo para onde a sua view deve fazer o `POST` com a password, para desbloquear. |
| `$ltLogoutUrl` | string ou null | URL para fazer logout a partir do ecrã de bloqueio. `null` se esta opção estiver desativada na configuração. |
| `$ltCsrfToken` | string | Token de segurança CSRF, já pronto (equivalente a chamar `csrf_token()`). |
| `$ltConfig` | array | A configuração `login-tracker.lockscreen` inteira, para o caso de precisar de algum valor sem variável dedicada (ex: `$ltConfig['idle_seconds']`). |

> **O meu Model de utilizador usa nomes de campos diferentes (ex: `full_name` em vez de `name`)?** Nesse caso, `$ltUserName` pode não vir como você espera. A forma mais simples de resolver: na sua view customizada, ignore `$ltUserName` e escreva diretamente `{{ $ltUser->full_name }}` — `$ltUser` é sempre o Model completo, com todos os seus campos disponíveis.

---

## 7. Forçar o lockscreen manualmente

Além do bloqueio automático por inatividade (secção 5), o pacote suporta forçar o lockscreen de duas formas: um **botão na própria página** (o utilizador bloqueia a sua própria sessão de propósito) e um **bloqueio remoto** (você força o bloqueio da sessão de outro utilizador, a partir do servidor — por exemplo, um painel de administração, ou um comando de terminal).

As duas formas exigem que o lockscreen esteja ativado (`LOGIN_TRACKER_LOCKSCREEN_ENABLED=true`, secção 5) e que o layout inclua `@loginTrackerLockscreen` — sem isso não existe overlay nenhum na página do utilizador para reagir a nenhum destes pedidos. Se tentar usar `forceLock()` ou `login-tracker:lock` com o lockscreen desativado, nada é gravado — a chamada devolve `0` sessões afetadas sem tocar na base de dados.

### Botão "Bloquear agora" na própria página

Isto é só JavaScript — não precisa de nenhuma chamada ao servidor, porque o utilizador já está na própria aba que quer bloquear. Em qualquer ponto do seu layout (por exemplo, num menu de utilizador):

```html
<button onclick="window.LoginTrackerLockscreen.lock()">Bloquear agora</button>
```

`window.LoginTrackerLockscreen` é criado automaticamente pelo `idle.js` (o mesmo script que já detecta inatividade) assim que a página carrega — não precisa de incluir mais nada além do que a instalação do lockscreen (secção 5) já pede.

### Bloqueio remoto (a partir do servidor)

Isto cobre o caso em que **não há nenhuma aba local para chamar `.lock()` diretamente** — por exemplo, um administrador quer bloquear a sessão de outro utilizador, ou você quer disparar isto a partir de algum evento da sua aplicação (uma deteção de atividade suspeita, uma automação, etc.).

Como o servidor não tem uma ligação aberta permanente com o navegador do utilizador (o pacote usa heartbeat/polling em vez de WebSockets, de propósito, para se manter simples — ver secção 4), o pedido de bloqueio é entregue no **próximo heartbeat** daquela sessão, no máximo `ping_interval_seconds` depois (60 segundos por padrão).

**A partir do código PHP da sua aplicação** (a forma recomendada — por exemplo, dentro de um controller de administração):

```php
use Gsebastiao\LoginTracker\Facades\LoginTracker;

// $user é qualquer instância do seu Model de utilizador, ex: User::find($id)

$sessoesAfetadas = LoginTracker::forceLock($user);

// $sessoesAfetadas é o número de sessões ativas que receberam o pedido.
// Bloqueia SEMPRE todas as sessões ativas daquele utilizador (todos os
// dispositivos) — nunca só uma, porque bloquear só um dispositivo e
// deixar outros abertos seria uma falha de segurança na maioria dos
// casos de uso deste método.
```

**A partir do terminal** (útil para automações, cron, ou testar manualmente):

```bash
php artisan login-tracker:lock 5
```

Onde `5` é o ID do utilizador (o mesmo valor que `$user->getAuthIdentifier()` devolveria). Se a sua aplicação tiver mais que um Model autenticável (por exemplo, `User` e `Admin`), especifique qual usar:

```bash
php artisan login-tracker:lock 5 --model="App\Models\Admin"
```

### O que acontece do lado do utilizador

Nada muda visualmente até o próximo heartbeat correr. Nesse momento, o `heartbeat.js` recebe a instrução do servidor e chama `window.LoginTrackerLockscreen.lock()` sozinho — exatamente o mesmo overlay que apareceria por inatividade normal, incluindo a mesma exigência de confirmar a password para desbloquear.

**Duas limitações a conhecer:**

- **Janela de atraso:** tal como a deteção de expiração de sessão (secção 4), existe um atraso de até `ping_interval_seconds` entre você disparar o bloqueio e ele aparecer de facto no ecrã do utilizador. Isto é uma limitação inerente à abordagem de polling, não um bug.
- **Só funciona com a aba visível:** o heartbeat só faz ping enquanto a aba está em primeiro plano (visível). Se o utilizador estiver noutra aba/aplicação, o bloqueio só é entregue quando ele voltar a esta aba.

---

## 8. Todas as opções de configuração explicadas

Depois de publicar a configuração (passo 2 da instalação), o ficheiro `config/login-tracker.php` no seu projeto terá comentários detalhados em cada opção. Aqui vai um resumo rápido de referência:

### Tabelas e ligação à base de dados

```php
'table' => env('LOGIN_TRACKER_TABLE', 'auth_logins'),
'sessions_table' => env('LOGIN_TRACKER_SESSIONS_TABLE', 'auth_sessions'),
'connection' => env('LOGIN_TRACKER_CONNECTION', null),
```

Para mudar o nome de qualquer tabela, ou usar uma ligação de base de dados diferente (por exemplo, uma base de dados separada só para auditoria), defina no `.env`:

```env
LOGIN_TRACKER_TABLE=historico_de_acessos
LOGIN_TRACKER_SESSIONS_TABLE=sessoes_ativas
LOGIN_TRACKER_CONNECTION=auditoria
```

**Importante:** se já rodou `php artisan migrate` antes de mudar estes valores, vai precisar de fazer `php artisan migrate:rollback` e migrar de novo (ou renomear as tabelas manualmente na base de dados), porque o nome da tabela só é lido no momento em que a migration corre.

### Quais guards são monitorizados

```php
'guards' => ['web', 'api'],
```

Se a sua aplicação usa outro nome de guard (definido em `config/auth.php`), adicione-o aqui. Use `['*']` para monitorizar todos.

### Heartbeat (deteção de "está online")

```php
'heartbeat' => [
    'enabled' => true,
    'middleware_enabled' => true,
    'middleware_throttle_seconds' => 30,
    'route_enabled' => true,
    'ping_interval_seconds' => 60,
    'online_threshold_seconds' => 120,
    'stale_after_minutes' => 30,
    'auto_logout_on_expiry' => true,
    'expired_redirect_route_name' => null,
],
```

**Regra importante ao ajustar estes números:** têm de respeitar esta ordem, do menor para o maior:

```
ping_interval_seconds  <  online_threshold_seconds  <  stale_after_minutes x 60
      (60s)                     (120s)                        (30 min = 1800s)
```

Se inverter esta ordem (por exemplo, pôr `online_threshold_seconds` menor que `ping_interval_seconds`), vai ter utilizadores a aparecer como "offline" poucos segundos depois de terem feito login, porque o sistema vai considerar o sinal "expirado" mais depressa do que ele é enviado.

`auto_logout_on_expiry` controla o comportamento explicado em detalhe na secção 4 — quando `true` (padrão), o próprio navegador do utilizador faz logout automaticamente ao detetar que a sessão expirou, deixando a tela no login sozinha. `expired_redirect_route_name` permite escolher uma rota diferente da rota de login padrão para onde redirecionar depois desse logout automático — deixe `null` para usar a mesma rota configurada em `lockscreen.login_route_name`.

### Lockscreen

```php
'lockscreen' => [
    'enabled' => false,
    'idle_seconds' => 900,
    'view' => 'login-tracker::lockscreen',
    'allow_logout_from_lockscreen' => true,
    'login_route_name' => 'login',
],
```

Já explicado em detalhe nas secções 4 e 5 acima.

---

## 9. Manutenção: purgar dados antigos

Com o tempo, a tabela de histórico (`auth_logins`) cresce indefinidamente, e sessões "mortas" (que expiraram sem um logout explícito) ficam com o `last_seen_at` parado, sem serem formalmente fechadas. Um comando artisan trata dos dois problemas:

```bash
php artisan login-tracker:purge
```

Este comando faz duas coisas:
1. **Fecha sessões mortas:** qualquer sessão em `auth_sessions` sem sinal de vida há mais tempo que `stale_after_minutes` é marcada como encerrada, e o registo correspondente no histórico é fechado com `logout_reason = 'inferred_stale'` — para você distinguir isto de um logout confirmado (`manual` ou `expired_client`, ver secção 12).
2. **Remove histórico antigo:** se você definir `LOGIN_TRACKER_RETENTION_DAYS` no `.env`, registos de histórico mais antigos que esse número de dias são apagados.

### Este comando precisa de estar agendado para rodar sozinho

Sem isto, sessões mortas nunca são formalmente encerradas — ninguém vai marcar `ended_at` sozinho.

No Laravel 11 ou mais recente, edite `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('login-tracker:purge')->everyFiveMinutes();
```

No Laravel 10 ou mais antigo, edite `app/Console/Kernel.php`, dentro do método `schedule()`:

```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('login-tracker:purge')->everyFiveMinutes();
}
```

Depois disto, garanta que o **cron do seu servidor** está a chamar o scheduler do Laravel a cada minuto (isto é uma configuração padrão do Laravel, não específica deste pacote — se já tem outras tarefas agendadas na sua aplicação, provavelmente já está configurado):

```
* * * * * cd /caminho/do/seu/projeto && php artisan schedule:run >> /dev/null 2>&1
```

### Rodar manualmente, com opções

```bash
php artisan login-tracker:purge              # faz as duas coisas
php artisan login-tracker:purge --sessions   # só fecha sessões mortas
php artisan login-tracker:purge --history    # só apaga histórico antigo
php artisan login-tracker:purge --days=90    # sobrepõe a retenção configurada, só desta vez
```

---

## 10. Perguntas frequentes / problemas comuns

**"Instalei o pacote mas nada está a ser registado."**
Confirme que rodou `php artisan migrate` (Passo 3). Depois confirme que o guard usado no seu `Auth::login()` está na lista `'guards' => ['web', 'api']` da configuração — se usa um guard com outro nome, adicione-o lá.

**"Quero mudar o nome da tabela mas já tenho dados nela."**
Este pacote só define o nome da tabela no momento da migration. Se já migrou com o nome antigo, terá de renomear a tabela manualmente na base de dados (`RENAME TABLE auth_logins TO novo_nome;` em MySQL, por exemplo) e depois atualizar a configuração para bater com esse novo nome.

**"O ecrã de bloqueio não está a aparecer."**
Confira, por ordem:
1. `LOGIN_TRACKER_LOCKSCREEN_ENABLED=true` está no `.env`?
2. Rodou `php artisan config:clear` depois de mudar o `.env`? (O Laravel guarda a configuração em cache em produção — se mudou o `.env` e nada aconteceu, é quase sempre isto.)
3. A diretiva `@loginTrackerLockscreen` está no layout que a página atual está mesmo a usar? (Se tem vários layouts, confirme que é o certo.)
4. Está autenticado? A diretiva não mostra nada em páginas sem utilizador logado.
5. Publicou os assets (`php artisan vendor:publish --tag=login-tracker-assets`)? Sem isto, o `idle.js` não existe em `public/vendor/login-tracker/` e o navegador não consegue carregá-lo (verifique a consola do navegador — F12 — para confirmar se há um erro 404 nesse ficheiro).

**"Alterei o `config/login-tracker.php` mas nada mudou."**
Em produção (e às vezes em desenvolvimento, dependendo do seu setup), o Laravel guarda a configuração em cache. Rode:

```bash
php artisan config:clear
```

**"Um utilizador consegue estar logado em dois dispositivos ao mesmo tempo?"**
Sim — o pacote foi feito para suportar isto. Cada sessão (navegador, telemóvel, etc.) tem a sua própria linha na tabela `auth_sessions`, identificada separadamente. `LoginTracker::isOnline($user)` retorna `true` se **qualquer uma** delas estiver ativa. Se precisar de ver todas as sessões separadamente, use `LoginTracker::activeSessionsFor($user)`.

**"Isto funciona com Laravel Sanctum/Passport (APIs)?"**
Sim, o pacote deteta automaticamente se está a lidar com uma sessão de navegador (cookie) ou com um token de API, e trata cada uma corretamente. Só é preciso que o guard correspondente (normalmente `api`) esteja na lista `'guards'` da configuração.

**"Funciona com Fortify?"**
Login e logout sim, sempre. Tentativas falhadas (`failed`) podem não ser registadas dependendo de como o pipeline de autenticação do Fortify está configurado no seu projeto — isto é um comportamento do próprio Fortify, não deste pacote. Ver a secção 11 para uma explicação completa e como confirmar se o seu projeto é afetado.

**"Como sei se um logout foi mesmo o utilizador a sair, ou se foi automático?"**
Consulte o campo `logout_reason` na tabela `auth_logins` — `manual` significa que alguém clicou em "Sair", `expired_client` significa que o navegador do utilizador detetou a expiração e agiu sozinho, e `inferred_stale` significa que ninguém confirmou nada e o comando de purga deduziu isso mais tarde. Ver a tabela completa na secção 12.

**"Não quero o logout automático, só quero um aviso."**
Defina `LOGIN_TRACKER_AUTO_LOGOUT_ON_EXPIRY=false` no `.env`. Ver secção 4 para detalhes.

**"Chamei `LoginTracker::forceLock($user)` e não aconteceu nada."**
Confirme três coisas: (1) `LOGIN_TRACKER_LOCKSCREEN_ENABLED=true` no `.env` — sem isto, `forceLock()` devolve `0` sem gravar nada; (2) o utilizador tinha mesmo uma sessão online no momento da chamada — sessões offline nunca recebem o pedido; (3) já passou tempo suficiente (`ping_interval_seconds`, 60s por padrão) para o próximo heartbeat correr. Ver secção 7 para detalhes.

---

## 11. Compatibilidade com Fortify, Jetstream, Breeze e Sanctum

O pacote **não integra diretamente** com nenhum destes pacotes — ele nunca importa nenhuma classe do Fortify, Jetstream, Breeze ou Sanctum. Em vez disso, escuta os eventos **nativos do Laravel Auth** (`Illuminate\Auth\Events\Login`, `Logout`, `Failed`). Isto é uma vantagem na maioria dos casos (funciona com qualquer um destes pacotes, e com qualquer versão deles, sem precisar de atualizações específicas), mas tem uma exceção importante que vale a pena conhecer antes de confiar cegamente na auditoria de tentativas falhadas.

### Breeze e Jetstream — funciona sem ressalvas

Ambos usam o `Auth::attempt()` do próprio Laravel por baixo (diretamente, no caso do Breeze; através do Fortify, no caso do Jetstream quando configurado com Fortify como backend). Login, logout e tentativas falhadas são todos registados normalmente, sem nenhuma configuração extra.

### Fortify — Login e Logout funcionam sempre; Failed pode não disparar, dependendo da configuração

Isto é uma característica **do próprio Fortify**, não uma limitação deste pacote — mas precisa de ser dita com clareza, porque afeta diretamente um dos três tipos de evento que este pacote audita.

- **Login**: a action interna do Fortify que finaliza uma autenticação bem-sucedida (`PrepareAuthenticatedSession`) chama o mecanismo nativo de login do Laravel — o mesmo que dispara o evento `Login` que este pacote escuta. Funciona sempre, em qualquer configuração de Fortify.

- **Logout**: mesma lógica, funciona sempre.

- **Failed (tentativa de password errada)**: o Fortify **pode ou não** disparar o evento `Illuminate\Auth\Events\Failed`, dependendo da ordem das actions dentro do pipeline `Fortify::authenticateThrough()`, definido no `FortifyServiceProvider` do seu projeto. Se a action `RedirectIfTwoFactorAuthenticatable` estiver posicionada **antes** de `AttemptToAuthenticate` nessa pipeline — o que já foi reportado como comportamento observado nalgumas instalações — a validação de credenciais acontece dentro dessa outra action, e o evento `Failed` nunca chega a ser despoletado.

**Como confirmar se isto afeta o seu projeto:** abra o método `boot()` do seu `app/Providers/FortifyServiceProvider.php` e procure por uma chamada a `Fortify::authenticateThrough(...)`. Se você não tiver essa chamada lá, o Fortify está a usar a ordem padrão da versão instalada — vale a pena testar na prática (tente uma password errada propositadamente e confirme se aparece um registo com `event = 'failed'` na tabela `auth_logins`). Se tiver essa chamada customizada, confirme que `AttemptToAuthenticate::class` aparece **antes** de `RedirectIfTwoFactorAuthenticatable::class` na lista.

Isto significa, na prática: **não assuma que a auditoria de tentativas falhadas está a funcionar com Fortify só porque instalou o pacote** — teste explicitamente esse caso específico no seu projeto antes de depender dele para deteção de força bruta ou auditoria de segurança.

### Sanctum e Passport (APIs) — funciona sem ressalvas

Guards baseados em token (`api`, ou o nome que você tiver configurado) são detetados automaticamente pelo pacote, tanto para o histórico como para o heartbeat/status online. Não há nenhuma dependência de eventos específicos do Sanctum ou Passport — o pacote só precisa que o guard correspondente esteja listado em `'guards'` na configuração (ver secção 8).

---

## 12. Estrutura das tabelas

### `auth_logins` (histórico)

| Coluna | Descrição |
|---|---|
| `authenticatable_id` / `authenticatable_type` | Relação com o utilizador (suporta qualquer Model, não só `User`) |
| `guard` | Qual guard foi usado (`web`, `api`, ...) |
| `event` | `login`, `logout`, ou `failed` |
| `ip_address` / `user_agent` | Origem do pedido |
| `identifier` | Email/username usado numa tentativa falhada (nunca guarda a password) |
| `login_at` / `logout_at` | Data/hora de entrada e saída |
| `logout_reason` | A **causa** da saída. Ver tabela detalhada abaixo. |
| `logout_inferred` | Campo antigo, mantido por compatibilidade. Continua a ser preenchido automaticamente (`true` apenas quando `logout_reason = 'inferred_stale'`) — se já tem código a usar este campo, não precisa de mudar nada. Para código novo, prefira `logout_reason`, que distingue três causas em vez de duas. |
| `meta` | Campo livre (JSON) para guardar dados extra, se precisar |

#### Os três valores possíveis de `logout_reason`

Isto é o cerne da auditoria de logout deste pacote — cada saída fica marcada com uma causa clara, nunca ambígua:

| Valor | O que significa | Quem confirmou |
|---|---|---|
| `manual` | O utilizador clicou em "Sair" (ou um administrador fez logout dele) | Confirmado — alguém chamou `Auth::logout()` diretamente |
| `expired_client` | A sessão expirou no servidor, e o **próprio navegador** do utilizador detetou isso (via heartbeat) e reagiu sozinho, fazendo logout e indo para o login | Confirmado — o navegador do utilizador validou que a sessão morreu, mesmo sem clique nenhum |
| `inferred_stale` | Ninguém confirmou nada — nem o utilizador, nem o navegador dele chegou a reagir (por exemplo, o computador foi desligado a meio, sem tempo de o JavaScript sequer correr). O comando `login-tracker:purge`, ao rodar mais tarde, reparou que a sessão estava sem sinal de vida há tempo demais, e fechou-a por dedução | Não confirmado — é uma estimativa, feita bem depois do momento real em que a pessoa saiu |

Esta distinção importa em contextos onde a diferença entre "sabemos com certeza" e "deduzimos" tem peso — por exemplo, para auditoria de segurança ou compliance.

### `auth_sessions` (estado / heartbeat)

| Coluna | Descrição |
|---|---|
| `authenticatable_id` / `authenticatable_type` | Relação com o utilizador |
| `guard` | Qual guard foi usado |
| `session_id` | Identificador único desta sessão específica (permite distinguir dispositivos diferentes do mesmo utilizador) |
| `started_at` | Quando esta sessão começou |
| `last_seen_at` | Última vez que houve sinal de vida — **este é o campo que decide se está "online"** |
| `ended_at` | Preenchido quando a sessão termina (logout explícito, ou detetada como morta pelo comando de purga) |

---

## Licença

MIT — use, modifique e distribua livremente.
