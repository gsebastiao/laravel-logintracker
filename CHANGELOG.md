# Changelog

Todas as alterações relevantes deste pacote são registadas neste ficheiro.

O formato segue [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/)
e as versões seguem [Versionamento Semântico](https://semver.org/lang/pt-BR/).

---

## [Não lançado]

### Alterado

- **As migrations já não precisam de ser publicadas.** O pacote passa a
  carregá-las dele (`php artisan migrate` basta) e a pasta `database/migrations`
  do projeto fica limpa. `vendor:publish --tag=logintracker-migrations`
  continua a existir, para quem quer editá-las: os ficheiros mantêm o nome
  original, e é por esse nome que o Laravel as identifica — a cópia do projeto
  substitui a do pacote e as tabelas nunca são criadas duas vezes. Quem
  publicou continua a atualizar com `--force`.

### Corrigido

- **`migrate` falhava em MySQL com "Identifier name is too long" (erro 1059).**
  O índice composto de `auth_sessions` ficava com o nome gerado pelo Laravel,
  `auth_sessions_authenticatable_id_authenticatable_type_ended_at_index` — 68
  caracteres, quando o MySQL só aceita 64. O erro acontecia **depois** de as
  tabelas serem criadas, pelo que a instalação ficava a meio: as tabelas
  existiam, o índice não, e a migration não chegava a ser registada. O índice
  passa a ter um nome explícito (`auth_sessions_morph_ended_index`), e a
  migration incremental `add_login_tracker_table` ganhou um bloco idempotente
  que o cria nas instalações que apanharam o erro — basta correr
  `php artisan migrate` outra vez.

- **As migrations do pacote passam a ser registadas sempre.** Estavam dentro
  do bloco `runningInConsole()` e só eram carregadas depois de um `glob()` em
  `database/migrations` não encontrar uma cópia publicada. Resultado: quem
  dispara o `migrate` fora da consola (`Artisan::call('migrate')` num
  instalador, num webhook de deploy ou nos testes do projeto) nunca via as
  migrations, e a tabela `auth_logins` não era criada. O `loadMigrationsFrom()`
  passou para fora do `runningInConsole()` e a verificação por `glob()` foi
  removida — é o Laravel que já descarta a cópia duplicada, porque indexa as
  migrations pelo nome do ficheiro.
- `LOGINTRACKER_GUARD` aceita agora valores separados por vírgulas
  (`web,api`); antes, um valor no `.env` chegava como texto e não como lista.
- Os tempos configuráveis por `.env` (heartbeat, ecrã de bloqueio) chegam ao
  código como números inteiros.
- O README documentava variáveis do `.env` com nomes que o pacote não lia; a
  tabela de variáveis agora corresponde ao `config/logintracker.php`.

## [2.0.0] — 2026-09-19

Correções de segurança importantes no ecrã de bloqueio e no logout
automático. **Contém alterações incompatíveis** — ver o guia de
atualização no fim desta secção.

### Corrigido

- **O ecrã de bloqueio desaparecia ao recarregar a página (F5) ou ao
  abrir uma aba nova**, deixando o sistema acessível a quem estivesse ao
  computador. O estado do bloqueio existia apenas numa variável
  JavaScript, pelo que qualquer recarregamento o apagava. Passa a ser
  guardado no servidor (sessão do Laravel + `auth_sessions.locked_at`),
  sobrevivendo a refresh e aplicando-se a todas as abas da mesma sessão.
  Só a password correta ou terminar sessão desbloqueiam.

- **A sessão expirava sem redirecionar para o ecrã de login**, ao
  contrário do que o pacote anunciava. A rota de heartbeat usava o
  middleware `auth`, que em certas configurações responde com um
  redirecionamento 302 para o login; o `fetch()` do browser segue esse
  redirecionamento em silêncio e recebe 200 com HTML, pelo que o
  JavaScript nunca percebia que a sessão tinha morrido. As rotas deixam
  de usar `auth`, verificam a autenticação internamente e respondem
  sempre em JSON.

- **O sinal de vida (heartbeat) só era enviado com a aba visível**, pelo
  que uma sessão expirada nunca era detetada em janelas minimizadas ou
  em segundo plano. Passa a correr continuamente.

- **O desbloqueio não tinha limite de tentativas.** Como o ecrã de
  bloqueio está acessível em qualquer página, era possível tentar
  passwords à velocidade da máquina. Adicionado *rate limiting*
  configurável (`max_unlock_attempts`, `unlock_throttle_seconds`).

### Adicionado

- Diretiva única `@logintracker`, que inclui o overlay, a configuração
  JavaScript e ambos os scripts. Deixa de ser necessário colar blocos de
  `<script>` à mão — a origem mais comum de instalações parcialmente
  funcionais.
- Coluna `auth_sessions.locked_at`, com o estado real do bloqueio.
- Scope `AuthSession::locked()`.
- Endpoint `POST /logintracker/lock`, usado pelo bloqueio manual e pela
  deteção de inatividade.

### Alterado

- **Migrations reduzidas a dois ficheiros**, publicados como *stubs*:
  `create_login_tracker_table` (cria as tabelas) e
  `add_login_tracker_table` (todas as alterações futuras). O segundo
  verifica o estado da base de dados antes de cada alteração, pelo que é
  idempotente e pode ser republicado com `--force` sem criar duplicados.
  Substitui o modelo anterior, que acrescentava uma migration nova por
  cada alteração do esquema.
- README reescrito de raiz, mais curto e orientado a quem instala o
  pacote pela primeira vez.
- Ficheiro de configuração reorganizado e comentado em linguagem simples.

### Alterações incompatíveis

| Antes | Agora |
|---|---|
| `config/login-tracker.php` | `config/logintracker.php` |
| `config('login-tracker.*')` | `config('logintracker.*')` |
| `php artisan login-tracker:purge` | `php artisan logintracker:purge` |
| `php artisan login-tracker:lock` | `php artisan logintracker:lock` |
| `--tag=login-tracker-*` | `--tag=logintracker-*` |
| `@loginTrackerLockscreen` | `@logintracker` (o nome antigo continua a funcionar) |
| Variáveis `LOGIN_TRACKER_*` | `LOGINTRACKER_*` |

### Como atualizar a partir da v1

```bash
composer update gsebastiao/laravel-logintracker

php artisan vendor:publish --tag=logintracker-config
php artisan vendor:publish --tag=logintracker-migrations --force
php artisan vendor:publish --tag=logintracker-assets --force
php artisan migrate

php artisan config:clear
php artisan view:clear
```

Depois, no seu layout:

1. Substitua `@loginTrackerLockscreen` por `@logintracker`.
2. **Remova os blocos `<script>` do LoginTracker que tenha colado à mão**
   (`window.LoginTrackerConfig`, `heartbeat.js`, `idle.js`). A diretiva
   trata disso sozinha — mantê-los causa configuração duplicada.

A configuração antiga em `config/login-tracker.php` pode ser apagada
depois de transpor os valores que tenha alterado.

---

## [1.1.0] — 2026-09-19

### Adicionado

- Bloqueio manual do ecrã, através de um botão na própria página
  (`window.LoginTrackerLockscreen.lock()`).
- Bloqueio remoto, a partir do servidor: `LoginTracker::forceLock($user)`
  e o comando `php artisan login-tracker:lock {user_id}`. Bloqueia todas
  as sessões ativas do utilizador, em todos os dispositivos.
- Coluna `auth_sessions.lock_requested_at`, que transporta o pedido de
  bloqueio remoto até ao próximo sinal de vida da sessão.

### Corrigido

- O bloqueio manual ficava indisponível quando a deteção automática por
  inatividade estava desligada (`idle_seconds = 0`): a verificação estava
  no nível errado do ficheiro e impedia a criação de
  `window.LoginTrackerLockscreen`.
- Chamar `window.LoginTrackerLockscreen.lock()` numa página sem a
  diretiva no layout provocava `Cannot read properties of undefined`.
  Passa a avisar na consola com a causa provável e a correção.

---

## [1.0.0] — 2026-09-19

Primeira versão pública.

### Adicionado

- Registo de entradas, saídas e tentativas falhadas de autenticação, em
  tabela configurável (por omissão `auth_logins`), através dos eventos
  nativos do Laravel.
- Deteção de estado online em tempo real e duração de sessão, com tabela
  própria (`auth_sessions`) alimentada por sinais de vida periódicos.
- Ecrã de bloqueio por inatividade, com view incluída, publicável e
  personalizável.
- Logout automático quando a sessão expira com a página aberta.
- Registo da causa de cada saída (`logout_reason`): confirmada pelo
  utilizador, confirmada pelo browser, ou deduzida posteriormente.
- Comando `login-tracker:purge`, para fechar sessões mortas e aplicar
  retenção ao histórico.
- Suporte a várias sessões simultâneas do mesmo utilizador, a vários
  guards, e a qualquer modelo autenticável.

---

[2.0.0]: https://github.com/gsebastiao/laravel-logintracker/releases/tag/v2.0.0
[1.1.0]: https://github.com/gsebastiao/laravel-logintracker/releases/tag/v1.1.0
[1.0.0]: https://github.com/gsebastiao/laravel-logintracker/releases/tag/v1.0.0
