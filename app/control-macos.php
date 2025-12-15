#!/usr/bin/env php
<?php
declare(strict_types=1);

use SIG\Server\Fluxus;

/**
 * Script de control para servidor WebSocket en macOS
 * Uso: php control-macos.php [start|stop|restart|status|kill] [opciones]
 */

$options = getopt('', ['run:', 'ws-config:',  'host:', 'port:', 'verbose::']);
$command = $argv[1] ?? 'status';

// Configuración por defecto
$wsConfig = 'default';
$wsRedis = 'channel';
$host = null;
$port = null;
$verbose = '';

// Procesar opciones
if (isset($options['ws-config'])) {
    $wsConfig = $options['ws-config'];
  //  if (isset($options['ws-channel'])) $wsRedis = $options['ws-channel'];
    $wsRedis = $options['ws-config'];
}
if (isset($options['host'])) $host = $options['host'];
if (isset($options['port'])) $port = $options['port'];
if (isset($options['verbose'])) $verbose = $options['verbose'] === false ? 'v' : $options['verbose'];

$program = $options['run'] ?? 'router.php';

$serverScript = __DIR__ . DIRECTORY_SEPARATOR . $program;
$pidFile = __DIR__ . '/../logs/websocket-server.pid';
$logFile = __DIR__ . '/../logs/websocket-control.log';

// Función para escribir logs
function log_message($message)
{
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $formatted = "[$timestamp] $message\n";
    echo $formatted;
    file_put_contents($logFile, $formatted, FILE_APPEND | LOCK_EX);
}

// Función para construir comando
function build_command($wsConfig, $wsRedis, $host, $port, $verbose)
{
    global $program;
    $cmd = __DIR__ . DIRECTORY_SEPARATOR . $program;
    $args = [];

    if ($wsConfig) $args[] = "--ws-config=$wsConfig";
    if ($host) $args[] = "-h $host";
    if ($port) $args[] = "-p $port";
    if ($verbose) $args[] = "-" . str_repeat('v', strlen($verbose));

    return $cmd . ' ' . implode(' ', $args) . ' > /dev/null 2>&1 & echo $!';
}

// Comandos
switch ($command) {
    case 'start':
        if (file_exists($pidFile)) {
            $pid = (int)file_get_contents($pidFile);
            if (posix_kill($pid, 0)) {
                log_message("❌ Servidor ya está ejecutándose (PID: $pid)");
                exit(1);
            }

            unlink($pidFile);
        }

        $cmd = build_command($wsConfig, $wsRedis, $host, $port, $verbose);
        log_message("🚀 Iniciando servidor WebSocket...");
        log_message("📝 Comando: $cmd");

        $output = [];
        exec($cmd, $output);
        $pid = (int)$output[0];

        if ($pid > 0) {
            file_put_contents($pidFile, $pid);
            log_message("✅ Servidor iniciado con PID: $pid");
            log_message("🔧 Configuración: ws-config=$wsConfig");

            // Esperar y verificar
            sleep(2);
            if (posix_kill($pid, 0)) {
                log_message("📡 Servidor ejecutándose correctamente");
            } else {
                log_message("❌ Error: Servidor no se pudo iniciar");
                unlink($pidFile);
                exit(1);
            }
        } else {
            log_message("❌ Error: No se pudo obtener PID del servidor");
            exit(1);
        }
        break;

    case 'stop':
        if (!file_exists($pidFile)) {
            log_message("❌ No se encontró archivo PID");
            exit(1);
        }

        $pid = (int)file_get_contents($pidFile);
        log_message("🛑 Deteniendo servidor (PID: $pid)...");

        // Shutdown graceful
        posix_kill($pid, SIGTERM);

        // Esperar máximo 10 segundos
        $stopped = false;
        for ($i = 0; $i < 10; $i++) {
            if (!posix_kill($pid, 0)) {
                $stopped = true;
                break;
            }
            sleep(1);
        }

        if ($stopped) {
            unlink($pidFile);
            log_message("✅ Servidor detenido gracefulmente");

            // Limpiar workers huérfanos
            cleanup_workers();
        } else {
            log_message("⚠️  Forzando terminación...");
            posix_kill($pid, SIGKILL);
            unlink($pidFile);
            cleanup_workers();
            log_message("✅ Servidor forzado a detenerse");
        }
        break;

    case 'restart':
        log_message("🔄 Reiniciando servidor...");
        $output = [];
        exec(__FILE__ . ' stop', $output);
        foreach ($output as $line) log_message($line);

        sleep(2);

        $output = [];
        exec(__FILE__ . ' start', $output);
        foreach ($output as $line) log_message($line);
        break;

    case 'status':
        if (file_exists($pidFile)) {
            $pid = (int)file_get_contents($pidFile);
            if (posix_kill($pid, 0)) {
                log_message("✅ Servidor ejecutándose (PID: $pid)");

                // Mostrar workers
                $workers = get_workers($pid);
                if (!empty($workers)) {
                    log_message("👷 Workers activos: " . count($workers));
                    foreach ($workers as $workerPid) {
                        log_message("   - PID: $workerPid");
                    }
                } else {
                    log_message("⚠️  No se detectaron workers activos");
                }

                // Mostrar configuración
                log_message("🔧 Configuración: ws-config=$wsConfig");
            } else {
                log_message("❌ PID file existe pero proceso no está ejecutándose");
                unlink($pidFile);
            }
        } else {
            log_message("❌ Servidor no está ejecutándose");
        }
        break;

    case 'kill':
        log_message("💥 Matando todos los procesos relacionados...");

        if (file_exists($pidFile)) {
            $pid = (int)file_get_contents($pidFile);
            posix_kill($pid, SIGKILL);
            unlink($pidFile);
        }

        cleanup_workers();
        log_message("✅ Todos los procesos eliminados");
        break;

    default:
        log_message("Uso: php " . basename(__FILE__) . " {start|stop|restart|status|kill} [opciones]");
        log_message("");
        log_message("Opciones:");
        log_message("  --ws-config=NAME   Configuración del servidor (default: default)");
       // log_message("  --ws-channel=NAME    Configuración Redis (default: channel)");
        log_message("  --host=IP          IP del servidor (sobrescribe configuración)");
        log_message("  --port=PORT        Puerto del servidor (sobrescribe configuración)");
        log_message("  --verbose[=LEVEL]  Nivel de verbosidad (v, vv)");
        log_message("");
        log_message("Ejemplos:");
        log_message("  php " . basename(__FILE__) . " start --ws-config=production --verbose");
        log_message("  php " . basename(__FILE__) . " stop");
        log_message("  php " . basename(__FILE__) . " status");
        break;
}

// Función para obtener workers
function get_workers($masterPid)
{
    $workers = [];
    $output = [];
    exec("pgrep -P $masterPid", $output);
    foreach ($output as $pid) {
        if ($pid != $masterPid) {
            $workers[] = (int)$pid;
        }
    }
    return $workers;
}

// Función para limpiar workers huérfanos
function cleanup_workers()
{
    global $program;
    $output = [];
    exec("pkill -f 'php.*$program' 2>/dev/null", $output, $result);
    exec("pkill -f 'swoole' 2>/dev/null", $output, $result);

    // Esperar un poco
    sleep(1);

    // Verificar que no quedan procesos
    $output = [];
    exec("pgrep -f 'php.*$program'", $output);
    if (empty($output)) {
        log_message("✅ Todos los procesos limpiados");
    } else {
        log_message("⚠️  Quedaron procesos: " . implode(', ', $output));
    }
}