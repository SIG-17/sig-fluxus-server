### SIG WebSocket Server (sig-ws-server)

WebSocket server component for the SIG platform. It provides a publish/subscribe messaging layer over WebSockets, optional Redis fan‑out for channels, and RPC handlers for stats and database operations. A lightweight JavaScript client (`js/VecturaPubSub.js`) and a `client-example.html` page are included for local testing.

#### Stack
- Language: `PHP >= 8.4`
- Framework/Runtime: [Swoole >= 6.0](https://www.swoole.co.uk/) (PHP extension)
- Logging: `monolog/monolog` (+ colored formatter `bramus/monolog-colored-line-formatter`); optional MongoDB handler support
- Optional messaging broker: `ext-redis` (Redis server required if enabled)
- Optional data integrations:
  - MongoDB via `utopia-php/mongo` (suggests `ext-mongodb`)
  - Database RPC via `xvii/satelles-omnia-roga`
- Package manager: `Composer`

#### Entry point & CLI
- CLI entry: `app/router.php`
  - Loads configuration from `config/ws-config.php` and `config/ws-redis.php`
  - Instantiates `SIG\Server\Fluxus` (see `src/Fluxus.php`) and starts the WebSocket server
  - CLI arguments (run `php app/router.php --help`):
    - `--ws-config=<name>`: selects instance from config files. A `ws-` prefix is added internally (e.g., `default` → `ws-default`).
    - `-h <host>`: override host from config.
    - `-p <port>`: override port from config.
    - `-v | -vv | -vvv`: increase verbosity (Info/Debug). No flag = Error level.

---

### Requirements
- PHP 8.4+ (CLI)
- PHP extensions:
  - `ext-swoole` 6.0+
  - `ext-posix` (required by this project; see `composer.json`)
  - `ext-redis` 5.3+ (optional, only if Redis integration is used)
  - `ext-mongodb` (optional, only if MongoDB integrations/logging are used)
- Composer
- Redis server (optional; required only if you configure Redis channels)
- MongoDB server (optional; only if you enable MongoDB logging/integration)
- OS: Linux/macOS recommended for Swoole. Windows is not officially supported by Swoole for production CLI servers.

Notes
- This repository depends on `xvii/satelles-utilis-proelio` (and uses `xvii/satelles-omnia-roga` for DB RPC in dev) via a private Composer repository (`https://tabula17.repo.repman.io`). If that repository requires authentication in your environment, configure Composer accordingly. See TODOs below.

---

### Installation
1. Ensure required PHP extensions are installed and enabled (Swoole, Posix, Redis if needed).
2. Install PHP dependencies via Composer:
   ```bash
   composer install
   ```

If Composer prompts for credentials to access the private repository, configure them per your organization guidelines.

---

### Configuration
Configuration is file-based.

- `config/ws-config.php`
  - Returns a `TCPServerCollection` with one or more server instances. Examples:
    ```php
    'ws-default' => [
      'host' => '127.0.0.1',
      'port' => 9800,
      'mode' => SWOOLE_PROCESS
    ],
    'ws-sigsj'   => [ 'port' => 9801, 'mode' => SWOOLE_PROCESS ],
    'ws-sigpch'  => [ 'port' => 9802, 'mode' => SWOOLE_PROCESS ],
    ```
  - You can add more instances or change host/port/mode.

- `config/ws-redis.php` (optional)
  - Returns a `ConnectionCollection` with Redis connection(s). Example default:
    ```php
    'ws-default' => [
      'host' => '127.0.0.1',
      'port' => 6379,
      'database' => 7
    ]
    ```

- `app/router.php`
  - Selects which server instance and Redis channel prefix to use based on `--ws-config` (internally prefixed with `ws-`).
  - Host/port can be overridden with flags `-h` and `-p`.
  - Initializes optional MongoDB logging via `Tabula17\Satelles\Utilis\Log\Handler\MongoDbHandler` if configured in your environment.

RPC configuration
- Optional RPC handlers are registered from:
  - `config/rpc/ws-rpc-stats.php` (e.g., `ping`, `server.stats`, `client.info`, `worker.info`, `channels.list`)
  - `config/rpc/ws-rpc-db.php` (database‑related RPC methods like `db.statement.list`, `db.pool.health`, etc.)
  - See `src/RPC` for processors (e.g., `DbProcessor`).

MongoDB logging/integration (optional)
- The codebase includes optional classes like `Monolog\Formatter\MongoDBFormatter` and `Tabula17\Satelles\Utilis\Log\Handler\MongoDbHandler` that can be wired if you need MongoDB‑backed logs. This is not enabled by default; wire a handler in `app/router.php` or your bootstrap if desired.
- SECURITY NOTE: `config/db-mongo.php` currently contains example credentials. Do not commit real secrets. Prefer environment variables or out‑of‑repo secret management. TODO: Replace with env‑driven configuration.

Environment variables
- Core server does not currently read env vars directly; all configuration is via the files above and CLI arguments.
- The systemd wrapper `systemd/start-server.sh` can read `.env` with variables like `WS_CONFIG`, `WS_HOST`, `WS_PORT`, `WS_VERBOSE` (see below).

---

### Running the server
From the project root:
```bash
php app/router.php --ws-config=default -v
```

Examples:
- Override host/port quickly:
  ```bash
  php app/router.php --ws-config=default -h 0.0.0.0 -p 9800 -vv
  ```

On startup, you should see colored Monolog output (to stderr).

Stop the server with Ctrl+C.

Ports
- Default WebSocket port: `9800` (`ws://127.0.0.1:9800`)
- Additional example configs exist at `9801` (`ws-sigsj`) and `9802` (`ws-sigpch`). Select them with `--ws-config=sigsj` or `--ws-config=sigpch`.

---

### Client example (browser)
- A minimal test page is included: `client-example.html`
  - It imports the client from `./js/VecturaPubSub.js`
  - Default URL: `ws://127.0.0.1:9800` (adjust in the HTML as needed)

Usage steps
1. Start the server: `php app/router.php`
2. Open `client-example.html` in a browser (preferably via a static file server to avoid CORS/file URL issues).
3. Use the page UI to connect, subscribe, publish sample messages, and inspect events.

Client library overview (`js/VecturaPubSub.js`)
- ES module exporting `VecturaPubSub extends EventTarget`
- Features: channel subscribe/unsubscribe, reconnect with backoff, file transfer helpers, custom RPC handlers, event dispatching (`ready`, `message`, `error`, `close`).

---

### Scripts
- Composer scripts: none defined in `composer.json`.
- NPM/package.json: only a placeholder `test` script is defined.

Common commands
- Install deps: `composer install`
- Start server (CLI): `php app/router.php [--ws-config=default] [-h host] [-p port] [-v]`

Helpful CLI
- Show help: `php app/router.php --help`

Systemd helpers (Linux)
- Example unit files are provided in `systemd/examples/` (e.g., `sig-ws-env.service`, `sig-ws@.service`).
- A simple wrapper is available: `systemd/start-server.sh`
  - Reads `.env` from project root if present.
  - Builds and executes: `php app/router.php` with the configured options.
- Optional logging tweak for systemd: `systemd/logging.conf` (drop-in example).

---

### Tests
- No automated tests present in this repository.
- TODO: Add tests for server behavior (subscription handling, Redis fan‑out paths, connection lifecycle). Consider using integration tests with Swoole in a CI environment that supports required extensions.

RPC and DB statements
- There is support for RPC handlers and optional database‑backed RPC via `src/RPC/DbProcessor.php`.
- SQL/XML statements and related metadata live under `db/xml/`.
- TODO: Document RPC message formats, authentication (if any), and expected request/response envelopes.

---

### Project structure
```
app/
  router.php               # CLI entry that boots the WebSocket server
config/
  ws-config.php            # WebSocket server instances (host/port/mode)
  ws-redis.php             # Redis connections (optional)
  db-mongo.php             # Example MongoDB logging config (contains example creds!)
  rpc/
    ws-rpc-stats.php       # RPC: ping, stats, client/worker info, channels list
    ws-rpc-db.php          # RPC: DB statements + pool health
db/
  xml/                     # SQL/XML statements used by RPC processors
js/
  VecturaPubSub.js         # Browser client (ES module)
logs/                      # Runtime logs (created at runtime)
src/
  Fluxus.php               # SIG\Server\Fluxus (extends Swoole WebSocket Server)
  RPC/                     # RPC processors (e.g., DbProcessor)
systemd/
  start-server.sh          # Wrapper used by systemd (reads .env)
  logging.conf             # Example systemd drop-in for logging
  examples/
    sig-ws-env.service     # Example systemd unit (set paths/env to your install)
    sig-ws@.service        # Templated systemd unit (example)
client-example.html        # HTML page to try the client against the server
vendor/                    # Composer dependencies
composer.json
composer.lock
package.json               # JS package manifest for the browser client
```

---

### Environment variables
- Core server: currently none. All configuration is file‑based. If you need env‑driven configuration, add a loader layer (e.g., `vlucas/phpdotenv`) and map variables into the collections created in `config/`.
- Wrapper script `systemd/start-server.sh` reads the project `.env` with:
  - `WS_CONFIG` (e.g., `default` → instance `ws-default`)
  - `WS_HOST` (default `0.0.0.0` in wrapper; CLI default from config is 127.0.0.1)
  - `WS_PORT` (default `9501` in wrapper; CLI default from config is 9800)
  - `WS_VERBOSE` (e.g., `v`, `vv`, `vvv`)
  - Note: Adjust paths in the example systemd unit files to your environment.

---

### License
- TODO: Add a license file and specify the license here.

---

### Troubleshooting
- Swoole not found: ensure `ext-swoole` is installed and enabled for the CLI SAPI. Check with `php -m | grep swoole`.
- Redis connection warnings: either configure `config/ws-redis.php` and select the proper `--ws-config` (the server will warn but continue without Redis), or disable Redis usage.
- Private Composer repository access: if `composer install` fails due to auth, configure credentials for `https://tabula17.repo.repman.io` in your Composer auth configuration.
- systemd paths: example units point to generic paths. Adjust `WorkingDirectory` and script paths to your installation. Consider using `systemd/start-server.sh`.
- Port mismatch: the wrapper defaults to `9501`, while the default config is `9800`. Align them via `.env` or CLI flags.

---

### TODOs / Next steps
- Publish clear installation steps for the private Composer repository access (if required in your environment).
- Add Dockerfiles/devcontainer for reproducible local setup.
- Implement automated tests and CI (ensuring runners support Swoole and Redis).
- Document message formats and any expected Pub/Sub protocol between server and clients.
- Align all systemd example unit files to use `router.php` and configurable `--ws-config`.
- Provide examples for enabling MongoDB logging and database‑backed RPC.
