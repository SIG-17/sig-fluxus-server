### SIG WebSocket Server (`sig-ws-server`)

The `sig-ws-server` is a high-performance WebSocket server component for the SIG platform. Built on Swoole, it provides a robust publish/subscribe messaging layer, RPC handlers for system statistics, and database operations. It includes a lightweight JavaScript client and supports Redis for horizontal scaling and MongoDB for advanced logging.

#### Stack
- **Language**: PHP >= 8.4
- **Framework/Runtime**: [Swoole >= 6.0](https://www.swoole.co.uk/)
- **Messaging Broker**: Redis (via `ext-redis`, optional for channel fan-out)
- **Data Integrations**: 
  - MongoDB (via `utopia-php/mongo` and `ext-mongodb`, optional)
  - Database RPC (via `xvii/satelles-omnia-roga`, optional)
- **Package Manager**: Composer (PHP), npm (JS metadata)

#### Entry Points & CLI
- **Main Entry**: `app/router.php`
- **CLI Usage**: `php app/router.php [options]`
  - `--ws-config=<name>`: Instance selection (e.g., `default` maps to `ws-default`).
  - `-h <host>`: Override host.
  - `-p <port>`: Override port.
  - `-v | -vv | -vvv`: Increase verbosity (Notice/Info/Debug).
  - `--help`: Show usage information.

---

### Requirements
- **PHP 8.4+**
- **PHP Extensions**:
  - `ext-swoole` (>= 6.0)
  - `ext-posix`
  - `ext-redis` (Optional, for Redis integration)
  - `ext-mongodb` (Optional, for MongoDB integration)
  - `ext-pdo` (Optional, for DB RPC)
- **Services**:
  - **Redis Server** (Optional, for multi-worker/multi-server channel sync)
  - **MongoDB Server** (Optional, for logging)
- **OS**: Linux or macOS (Swoole limitation for production)

---

### Installation
1.  **Clone the repository**.
2.  **Install dependencies**:
    ```bash
    composer install
    ```
    *Note: Access to `https://tabula17.repo.repman.io` may require authentication.*

---

### Configuration
The server uses file-based configuration located in the `config/` directory.

-   **`config/ws-config.php`**: Defines server instances (host, port, Swoole mode).
-   **`config/ws-redis.php`**: Redis connection settings for channel synchronization.
-   **`config/rpc/`**: RPC method definitions.
    -   `ws-rpc-stats.php`: System stats and health checks.
    -   `ws-rpc-db.php`: Database-related operations.
-   **`config/channels/`**: Channel-specific configurations.

#### Environment Variables
While the core server is file-configured, the systemd wrapper `systemd/start-server.sh` supports a `.env` file:
-   `WS_CONFIG`: Config instance name (default: `production`).
-   `WS_HOST`: Bind address (default: `0.0.0.0`).
-   `WS_PORT`: Listen port (default: `9501`).
-   `WS_VERBOSE`: Verbosity flags (e.g., `v`, `vv`).

---

### Running the Server
**Standard CLI**:
```bash
php app/router.php --ws-config=default -v
```

**Using Systemd**:
The `systemd/` directory contains helpers for Linux deployments:
-   `systemd/install-systemd.sh`: Installation script.
-   `systemd/start-server.sh`: Wrapper script for execution.

---

### Scripts
-   **PHP**: `app/router.php` (Server lifecycle management).
-   **Systemd**: `systemd/start-server.sh` (Wrapper for environment-aware execution).
-   **Composer**: Dependencies managed via `composer.json`.
-   **npm**: `package.json` defines versioning for the JS client.

---

### Tests
-   **Automated Tests**: Currently not implemented.
-   **Manual Testing**: 
    -   Use `client-example.html` in a browser.
    -   Ensure the server is running on `ws://127.0.0.1:9800` (default).

---

### Project Structure
```text
app/
  router.php               # Main CLI entry point
config/
  ws-config.php            # Server instance configuration
  ws-redis.php             # Redis connection settings
  rpc/                     # RPC handler definitions
db/
  xml/                     # SQL/XML statement descriptors for DbProcessor
js/
  VecturaPubSub.js         # ES Module WebSocket client
src/
  Fluxus.php               # Core Server class (extends Swoole\WebSocket\Server)
  RPC/                     # RPC Processing logic
  Protocol/                # Messaging protocol definitions (Request/Response)
systemd/                   # Systemd service units and scripts
logs/                      # Application logs
runtime/                   # PID and temporary runtime files
```

---

### License
-   TODO: Define license (refer to `js/Vectura/licenses` for related info).

---

### TODOs
- [ ] Implement automated unit and integration tests.
- [ ] Add Docker/Container support.
- [ ] Formalize the Pub/Sub protocol documentation.
- [ ] Securely manage credentials (move from `config/*.php` to `.env`).
- [ ] Add a root `LICENSE` file.
