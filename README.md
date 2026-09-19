# LoginTracker para Laravel

[![License](https://img.shields.io/packagist/l/gsebastiao/laravel-logintracker.svg)](LICENSE.md)
[![PHP Version](https://img.shields.io/packagist/php-v/gsebastiao/laravel-logintracker.svg)](composer.json)
[![Laravel Framework](https://img.shields.io/packagist/dependency-v/gsebastiao/laravel-logintracker/illuminate/support.svg)](composer.json)
[![Latest Version](https://img.shields.io/packagist/v/gsebastiao/laravel-logintracker.svg)](https://packagist.org/packages/gsebastiao/laravel-logintracker)

Sabe quem entrou, quem saiu, quem está online agora — e bloqueia o ecrã quando alguém se afasta do computador.

> Pronto. O histórico de logins já está a ser gravado, sem escrever uma linha de código.

---

## Índice

- [Começar](#começar)
- [Quem está online](#quem-está-online)
- [Ecrã de bloqueio](#ecrã-de-bloqueio)
- [Logout automático ao expirar](#logout-automático-ao-expirar)
- [Personalizar o ecrã de bloqueio](#personalizar-o-ecrã-de-bloqueio)
- [Bloquear manualmente](#bloquear-manualmente)
- [Configuração](#configuração)
- [Manutenção](#manutenção)
- [Migrations](#migrations)
- [Problemas comuns](#problemas-comuns)
- [Compatibilidade](#compatibilidade)
- [Tabelas](#tabelas)

---

## Começar

### 1. Instalar

```bash
composer require gsebastiao/laravel-logintracker
```

### 2. Criar as tabelas

```bash
php artisan migrate
```

Não precisa de copiar nada para o seu projeto: o pacote traz as próprias migrations e o `migrate` corre-as. A sua pasta `database/migrations` fica limpa. (Só quer editar as migrations antes de correr? Veja [Migrations](#migrations).)

Isto cria duas tabelas:

| Tabela | Para quê |
| --- | --- |
| `auth_logins` | Histórico: quem entrou, quando saiu, tentativas falhadas |
| `auth_sessions` | Estado atual: quem está online agora, quem está bloqueado |

### 3. Confirmar que funciona

Faça login na sua aplicação e depois:

```bash
php artisan tinker
```

```php
\Gsebastiao\LoginTracker\Models\AuthLogin::latest()->first();
```

Se aparecer o seu login, está tudo a funcionar.

### 4. Publicar a configuração (opcional)

Só precisa disto se preferir editar o ficheiro de configuração:

```bash
php artisan vendor:publish --tag=logintracker-config
```

> **Quer mudar nomes de tabelas, tempos ou ativar o ecrã de bloqueio?** Não
> precisa de publicar nada: basta acrescentar variáveis `LOGINTRACKER_*` ao seu
> `.env`. A lista completa, com o padrão de cada uma, está em
> [Configuração](#configuração). Se for mudar o nome das tabelas, faça-o
> **antes** do `migrate`.

---

## Quem está online

```php
use Gsebastiao\LoginTracker\Facades\LoginTracker;

LoginTracker::isOnline($user);                  // true / false
LoginTracker::onlineDurationForHumans($user);   // "2 horas e 15 minutos"
LoginTracker::historyFor($user);                // histórico completo
```

Todos os utilizadores online agora:

```php
use Gsebastiao\LoginTracker\Models\AuthSession;

AuthSession::online()->with('authenticatable')->get();
```

### Como isto funciona

O browser envia um "sinal de vida" ao servidor de minuto a minuto. Enquanto esses sinais chegarem, o utilizador conta como online.

Isto é necessário porque uma sessão pode morrer em silêncio — o utilizador fecha o portátil, o browser fecha, a sessão expira — e nesses casos o Laravel **não** dispara nenhum evento de logout. Se contássemos apenas com os eventos, teríamos pessoas marcadas como "online" durante dias depois de terem saído.

Para os sinais serem enviados, adicione **uma linha** ao seu layout, antes de `</body>`:

```blade
@logintracker
```

Esta linha inclui tudo o que o pacote precisa no browser. Não é preciso colar mais nada.

Depois publique os ficheiros JavaScript:

```bash
php artisan vendor:publish --tag=logintracker-assets
```

---

## Ecrã de bloqueio

Bloqueia o ecrã depois de um período sem o utilizador mexer no rato ou no teclado. Para continuar, é preciso introduzir a password.

No `.env`:

```env
LOGINTRACKER_LOCKSCREEN=true
LOGINTRACKER_IDLE_SECONDS=900
```

E no layout (a mesma linha de antes — se já a tem, não precisa de fazer nada):

```blade
@logintracker
```

Depois:

```bash
php artisan vendor:publish --tag=logintracker-assets
```

```bash
php artisan config:clear
```

```bash
php artisan view:clear
```

### O bloqueio é real, não apenas visual

O estado do bloqueio fica guardado **no servidor**. Isto significa que:

- **Recarregar a página (F5) não desbloqueia.** O ecrã volta a aparecer imediatamente.
- **Abrir uma aba nova não contorna o bloqueio.** Todas as abas da mesma sessão ficam bloqueadas.
- **Fechar e reabrir o browser não desbloqueia**, enquanto a sessão for válida.

Só a password correta — ou terminar sessão — desbloqueia.

---

## Logout automático ao expirar

Quando a sessão do utilizador expira enquanto ele tem a página aberta, o pacote termina a sessão e leva-o para o ecrã de login automaticamente. Vem **ativo por defeito**.

O utilizador não precisa de clicar em nada: até um minuto depois de a sessão morrer, o ecrã dele fica no login.

Isto é diferente do ecrã de bloqueio:

| | Ecrã de bloqueio | Logout automático |
| --- | --- | --- |
| A sessão | continua válida | expirou |
| Para voltar | password | login completo |
| Causa | inatividade (rato/teclado) | tempo de sessão esgotado |

Para desligar e mostrar apenas um aviso:

```env
LOGINTRACKER_AUTO_LOGOUT=false
```

---

## Personalizar o ecrã de bloqueio

### Opção 1 — editar o ecrã que vem incluído

```bash
php artisan vendor:publish --tag=logintracker-views
```

O ficheiro aparece em `resources/views/vendor/logintracker/lockscreen.blade.php`. O Laravel passa a usar a sua cópia automaticamente — edite à vontade.

### Opção 2 — usar uma view totalmente sua

Em `config/logintracker.php`:

```php
'lockscreen' => [
    'view' => 'partials.meu-lockscreen',
],
```

### Variáveis disponíveis na view

Chegam sozinhas, seja qual for a view que usar:

| Variável | O que é |
| --- | --- |
| `$ltUser` | O utilizador autenticado (o modelo completo) |
| `$ltUserName` | Nome pronto a mostrar |
| `$ltUserAvatarUrl` | Avatar (gera um automaticamente se não existir) |
| `$ltIsLocked` | Se a sessão está bloqueada **neste momento** |
| `$ltUnlockUrl` | Para onde enviar a password |
| `$ltLogoutUrl` | Botão de terminar sessão (`null` se desativado) |
| `$ltCsrfToken` | Token CSRF |

### Se criar uma view de raiz

O JavaScript precisa de encontrar os elementos. Mantenha estes IDs:

```html
#logintracker-lockscreen    <!-- o overlay -->
#logintracker-unlock-form   <!-- o formulário -->
#logintracker-password      <!-- o campo de password -->
#logintracker-error         <!-- onde os erros aparecem -->
```

E aplique `display: {{ $ltIsLocked ? 'flex' : 'none' }}` ao overlay — é isto que faz o bloqueio sobreviver a um refresh.

---

## Bloquear manualmente

### Um botão na página

```html
<button onclick="window.LoginTrackerLockscreen.lock()">Bloquear agora</button>
```

### Bloquear a sessão de outra pessoa

```php
use Gsebastiao\LoginTracker\Facades\LoginTracker;

LoginTracker::forceLock($user);   // devolve o nº de sessões bloqueadas
```

Ou a partir do terminal:

```bash
php artisan logintracker:lock 5
```

```bash
php artisan logintracker:lock 5 --model="App\Models\Admin"
```

Bloqueia **todas** as sessões ativas daquela pessoa, em todos os dispositivos. O bloqueio aparece no ecrã dela até um minuto depois.

---

## Configuração

**Não precisa de publicar o ficheiro de configuração.** O pacote carrega a sua
configuração sozinho e tudo se muda pelo `.env`. Só publique
(`--tag=logintracker-config`) se preferir editar o ficheiro.

| Variável | Padrão | Para quê |
| --- | --- | --- |
| `LOGINTRACKER_CONNECTION` | (não definir) | Ligação de base de dados; sem ela, a da aplicação |
| `LOGINTRACKER_TABLE` | `auth_logins` | Tabela do histórico (mude antes do `migrate`) |
| `LOGINTRACKER_SESSIONS_TABLE` | `auth_sessions` | Tabela do estado atual (online/bloqueado) |
| `LOGINTRACKER_GUARD` | `web,api` | Guards vigiados, separados por vírgulas; `*` = todos |
| `LOGINTRACKER_EVENT_LOGIN` | `true` | Registar logins |
| `LOGINTRACKER_EVENT_LOGOUT` | `true` | Registar logouts |
| `LOGINTRACKER_EVENT_FAILED` | `true` | Registar tentativas falhadas |
| `LOGINTRACKER_CAPTURE_IPADDRESS` | `true` | Guardar o IP |
| `LOGINTRACKER_CAPTURE_USERAGENT` | `true` | Guardar o navegador (user agent) |
| `LOGINTRACKER_MORPH_NAME` | `authenticatable` | Nome da coluna polimórfica do utilizador |
| `LOGINTRACKER_RETENTION_DAYS` | (não definir) | Apagar histórico com mais de N dias; sem valor, nunca apaga |
| `LOGINTRACKER_HEARTBEAT_ENABLE` | `true` | Liga o sinal de vida (estado online) |
| `LOGINTRACKER_HEARTBEAT_MIDDLEWARE_ENABLE` | `true` | Cada pedido normal também conta como sinal de vida |
| `LOGINTRACKER_HEARTBEAT_MIDDLEWARE_THROTTLE` | `30` | Segundos mínimos entre duas gravações do middleware |
| `LOGINTRACKER_HEARTBEAT_ROUTE_PATH` | `logintracker/heartbeat` | Rota do sinal de vida |
| `LOGINTRACKER_HEARTBEAT_PING_INTERVAL` | `60` | De quanto em quanto tempo (s) o browser envia sinal |
| `LOGINTRACKER_HEARTBEAT_ONLINE_THRESHOLD` | `120` | Sem sinal há mais de N segundos = offline |
| `LOGINTRACKER_HEARTBEAT_STALE_MINUTES` | `30` | Sem sinal há mais de N minutos = sessão morta |
| `LOGINTRACKER_HEARTBEAT_AUTO_LOGOUT` | `true` | Terminar a sessão no ecrã quando ela expirar |
| `LOGINTRACKER_HEARTBEAT_EXPIRED_REDIRECT` | (não definir) | Rota para onde ir ao expirar; sem valor, a de login |
| `LOGINTRACKER_LOCKSCREEN_ENABLE` | `false` | Liga o ecrã de bloqueio |
| `LOGINTRACKER_LOCKSCREEN_IDLE_SECONDS` | `900` | Segundos de inatividade até bloquear (0 = só manual) |
| `LOGINTRACKER_LOCKSCREEN_VIEW` | `logintracker::lockscreen` | View do ecrã de bloqueio |
| `LOGINTRACKER_LOCKSCREEN_UNLOCK_ROUTE_PATH` | `logintracker/unlock` | Rota de desbloqueio |
| `LOGINTRACKER_LOCKSCREEN_ALLOW_LOGOUT` | `true` | Botão "Terminar sessão" no ecrã de bloqueio |
| `LOGINTRACKER_LOCKSCREEN_LOGIN_ROUTE` | `login` | Nome da rota de login da sua aplicação |
| `LOGINTRACKER_LOCKSCREEN_MAX_UNLOCK` | `5` | Tentativas de password antes de esperar |
| `LOGINTRACKER_LOCKSCREEN_UNLOCK_THROTTLE` | `60` | Segundos de espera após exceder as tentativas |

Exemplo de `.env` — ponha **só as linhas que quiser mudar**:

```env
LOGINTRACKER_GUARD=web,admin
LOGINTRACKER_RETENTION_DAYS=90
LOGINTRACKER_LOCKSCREEN_ENABLE=true
LOGINTRACKER_LOCKSCREEN_IDLE_SECONDS=600
```

> **Não deixe uma variável em branco** (`LOGINTRACKER_CONNECTION=`): o Laravel
> lê isso como texto vazio, não como "sem valor". Para voltar ao padrão, apague
> a linha. Com `php artisan config:cache`, rode-o de novo depois de mudar o
> `.env`.

Ao mexer nos tempos, respeite esta ordem:

```
HEARTBEAT_PING_INTERVAL  <  HEARTBEAT_ONLINE_THRESHOLD  <  HEARTBEAT_STALE_MINUTES × 60
         60s                          120s                          1800s
```

Se inverter, as pessoas aparecem offline segundos depois de entrarem.

---

## Manutenção

Uma tarefa agendada trata de fechar sessões mortas e limpar histórico antigo:

```php
// routes/console.php  (Laravel 11+)
use Illuminate\Support\Facades\Schedule;

Schedule::command('logintracker:purge')->everyFiveMinutes();
```

```php
// app/Console/Kernel.php  (Laravel 10 e anteriores)
$schedule->command('logintracker:purge')->everyFiveMinutes();
```

Sem isto, sessões de pessoas que desligaram o computador ficam por fechar.

Também pode correr à mão:

```bash
php artisan logintracker:purge
```

```bash
php artisan logintracker:purge --sessions   # só fechar sessões mortas
```

```bash
php artisan logintracker:purge --history    # só limpar histórico antigo
```

```bash
php artisan logintracker:purge --days=90
```

---

## Migrations

**Publicar as migrations é opcional.** Por defeito, `php artisan migrate` corre as migrations que vêm dentro do pacote, e a sua pasta `database/migrations` não recebe nada. O pacote usa **dois ficheiros, e só dois**, hoje e no futuro:

| Ficheiro | Para quê |
| --- | --- |
| `2026_01_01_000000_create_login_tracker_table.php` | Cria as tabelas. Corre uma vez. |
| `2026_01_01_000001_add_login_tracker_table.php` | Todas as alterações futuras. |

Quando sair uma versão nova do pacote com colunas novas, basta atualizar o pacote e correr `php artisan migrate`. Cada alteração verifica primeiro se já existe na base de dados, por isso pode correr as vezes que quiser sem dar erro.

### Quero editar as migrations

Só nesse caso copie-as para o seu projeto, **antes** do primeiro `migrate`:

```bash
php artisan vendor:publish --tag=logintracker-migrations
```

Os ficheiros mantêm o nome original, e o Laravel usa a sua cópia em vez da do pacote (nunca corre as duas). Para acrescentar colunas suas, acrescente-as no fim do método `up()` do ficheiro `add_...`, seguindo o padrão de verificação que já lá está.

Com cópia publicada, as versões novas do pacote **não** a atualizam sozinhas. Depois de atualizar o pacote, volte a publicar:

```bash
php artisan vendor:publish --tag=logintracker-migrations --force
```

```bash
php artisan migrate
```

(o `--force` substitui as suas edições nesses ficheiros.)

> **Já tinha publicado as migrations antes?** Continua tudo a funcionar: o pacote deteta as suas cópias (mesmo com outro timestamp) e não corre as dele.

---

## Problemas comuns

**O ecrã de bloqueio não aparece**

Por esta ordem:

1. `LOGINTRACKER_LOCKSCREEN_ENABLE=true` está no `.env`?
2. A linha `@logintracker` está no layout que esta página usa?
3. Correu `php artisan vendor:publish --tag=logintracker-assets`?
4. Correu `php artisan config:clear` e `php artisan view:clear`?
5. Está autenticado? Em páginas públicas não aparece nada.

**A consola diz `#logintracker-lockscreen nao existe nesta pagina`**

Falta `@logintracker` no layout. Ter a variável no `.env` não chega — é a diretiva que coloca o ecrã na página.

**Mudei o `.env` e nada mudou**

```bash
php artisan config:clear
```

```bash
php artisan view:clear
```

**A sessão expira mas não vou para o login**

Confirme que `@logintracker` está no layout e que os ficheiros JavaScript foram publicados. Abra a consola do browser (F12) e veja se há erro 404 em `vendor/logintracker/heartbeat.js`.

**Já uso o pacote e quero atualizar da v1**

A configuração mudou de nome, de `login-tracker.php` para `logintracker.php`:

```bash
php artisan vendor:publish --tag=logintracker-config
```

```bash
php artisan migrate
```

```bash
php artisan config:clear && php artisan view:clear
```

(Se tinha publicado as migrations, volte a publicá-las com `--tag=logintracker-migrations --force` antes do `migrate`.)

Depois substitua `@loginTrackerLockscreen` por `@logintracker` no layout (o nome antigo continua a funcionar) e remova quaisquer blocos `<script>` do LoginTracker que tenha colado à mão — a diretiva trata disso sozinha agora.

---

## Compatibilidade

Funciona com **Breeze**, **Jetstream**, **Fortify**, **Sanctum** e **Passport**, porque escuta os eventos de autenticação do próprio Laravel em vez de depender de qualquer um deles.

**Uma ressalva com o Fortify:** logins e logouts são sempre registados, mas as *tentativas falhadas* podem não ser, consoante a ordem das ações em `Fortify::authenticateThrough()` no seu `FortifyServiceProvider`. É um comportamento do Fortify, não do pacote. Se depende disso para deteção de ataques, teste com uma password errada e confirme que aparece um registo com `event = 'failed'`.

---

## Tabelas

### `auth_logins` — histórico

| Coluna | |
| --- | --- |
| `authenticatable_id` / `_type` | Quem (funciona com qualquer modelo) |
| `guard` | `web`, `api`, … |
| `event` | `login`, `logout`, `failed` |
| `ip_address` / `user_agent` | De onde |
| `identifier` | Email usado numa tentativa falhada |
| `login_at` / `logout_at` | Quando entrou e saiu |
| `logout_reason` | Porque saiu (ver abaixo) |
| `meta` | Campo livre (JSON) |

**`logout_reason`** distingue três situações diferentes:

| Valor | Significa |
| --- | --- |
| `manual` | Clicou em "Sair" |
| `expired_client` | A sessão expirou e o browser dele reagiu sozinho |
| `inferred_stale` | Ninguém confirmou nada — deduzido mais tarde pela limpeza |

A diferença importa em auditoria: os dois primeiros são factos, o terceiro é uma estimativa.

### `auth_sessions` — estado atual

| Coluna | |
| --- | --- |
| `authenticatable_id` / `_type` | Quem |
| `guard` | Qual guard |
| `session_id` | Distingue dispositivos da mesma pessoa |
| `started_at` | Início da sessão |
| `last_seen_at` | Último sinal de vida — decide se está online |
| `locked_at` | Ecrã bloqueado desde quando |
| `lock_requested_at` | Bloqueio remoto por entregar |
| `ended_at` | Fim da sessão |

---

## Licença

MIT
