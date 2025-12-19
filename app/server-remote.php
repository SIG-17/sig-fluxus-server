#!/usr/bin/env php
<?php
// scripts/server-control.php

require __DIR__ . '/../vendor/autoload.php';

$action = $argv[1] ?? 'status';

switch ($action) {
    case 'stop':
        stopServer();
        break;
    case 'kill':
        killServer();
        break;
    case 'reload':
        reloadServer();
        break;
    case 'status':
        checkStatus();
        break;
    default:
        echo "Uso: php server-remote.php [stop|kill|reload|status]\n";
        exit(1);
}

function stopServer(): void
{
    echo "🛑 Deteniendo servidor gracefulmente...\n";

    // Método 1: Via RPC (preferido)
    if (stopViaRpc()) {
        exit(0);
    }

    echo "❌ No se pudo conectar via RPC\n";

    // Método 2: Via señal
    if (stopViaSignal()) {
        exit(0);
    }

    echo "❌ Falló todo, usando kill forzado...\n";
    killServer();
}

function stopViaRpc(): bool
{
    $config = require __DIR__ . '/../config/ws-config.php';
    $instance = $config['ws-default'] ?? null;

    if (!$instance) {
        return false;
    }

    $host = $instance->host ?? '127.0.0.1';
    $port = $instance->port ?? 9501;

    try {
        $client = new \Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);

        if ($client->connect($host, $port, 3)) {
            // Enviar comando shutdown
            $cmd = json_encode([
                'action' => 'rpc',
                'method' => 'ws.shutdown',
                'id' => 'control_' . time()
            ]);

            $client->send($cmd);

            // Leer respuesta
            $response = $client->recv(2);
            if ($response) {
                $data = json_decode($response, true);
                echo "📨 Respuesta: " . ($data['message'] ?? 'OK') . "\n";
            }

            $client->close();

            // Esperar que se cierre
            echo "⏳ Esperando cierre...";
            for ($i = 0; $i < 10; $i++) {
                if (!isServerRunning($host, $port)) {
                    echo "\n✅ Servidor detenido\n";
                    return true;
                }
                sleep(1);
                echo ".";
            }
            echo "\n⚠️  Timeout, pero el shutdown fue iniciado\n";
            return true;
        }
    } catch (\Exception $e) {
        // Silenciar error
    }

    return false;
}

function stopViaSignal(): bool
{
    $pidFile = __DIR__ . '/../runtime/pid';

    if (!file_exists($pidFile)) {
        echo "⚠️  No hay PID file\n";
        return false;
    }

    $pid = (int) trim(file_get_contents($pidFile));

    if ($pid <= 0 || !posix_kill($pid, 0)) {
        echo "⚠️  Proceso $pid no encontrado\n";
        unlink($pidFile);
        return true; // Ya está muerto
    }

    echo "📡 Enviando SIGTERM al proceso $pid...\n";
    posix_kill($pid, SIGTERM);

    // Esperar 5 segundos
    for ($i = 0; $i < 5; $i++) {
        if (!posix_kill($pid, 0)) {
            echo "✅ Proceso terminado via señal\n";
            unlink($pidFile);
            return true;
        }
        sleep(1);
        echo ".";
    }

    return false;
}

function killServer(): void
{
    echo "💀 Matando servidor forzosamente...\n";

    $pidFile = __DIR__ . '/../runtime/pid';

    if (!file_exists($pidFile)) {
        echo "✅ No hay servidor corriendo\n";
        exit(0);
    }

    $pid = (int) trim(file_get_contents($pidFile));

    if ($pid <= 0) {
        unlink($pidFile);
        echo "✅ PID inválido, limpiando\n";
        exit(0);
    }

    // Matar proceso y todos sus hijos
    exec("pkill -9 -P $pid 2>/dev/null");
    posix_kill($pid, SIGKILL);

    sleep(1);

    if (!posix_kill($pid, 0)) {
        echo "✅ Proceso $pid eliminado\n";
        unlink($pidFile);
        exit(0);
    }

    echo "❌ No se pudo matar el proceso\n";
    exit(1);
}

function reloadServer(): void
{
    $pidFile = __DIR__ . '/../runtime/pid';

    if (!file_exists($pidFile)) {
        echo "❌ Servidor no está corriendo\n";
        exit(1);
    }

    $pid = (int) trim(file_get_contents($pidFile));

    if ($pid <= 0 || !posix_kill($pid, 0)) {
        echo "⚠️  Proceso $pid no encontrado\n";
        unlink($pidFile);
        exit(0);
    }

    echo "🔄 Enviando SIGUSR1 para reload al proceso $pid...\n";
    posix_kill($pid, SIGUSR1);
    echo "✅ Comando de reload enviado\n";
}

function checkStatus(): void
{
    $config = require __DIR__ . '/../config/ws-config.php';
    $instance = $config['ws-default'] ?? null;

    if (!$instance) {
        echo "❌ Configuración no encontrada\n";
        exit(1);
    }

    $host = $instance->host ?? '127.0.0.1';
    $port = $instance->port ?? 9501;

    if (isServerRunning($host, $port)) {
        echo "✅ Servidor WebSocket corriendo en $host:$port\n";

        $pidFile = __DIR__ . '/../runtime/pid';
        if (file_exists($pidFile)) {
            $pid = file_get_contents($pidFile);
            echo "📝 PID: $pid\n";

            // Verificar si el proceso existe
            if (posix_kill((int)$pid, 0)) {
                echo "🟢 Proceso activo\n";
            } else {
                echo "🔴 Proceso no encontrado (PID file obsoleto)\n";
            }
        }
    } else {
        echo "❌ Servidor WebSocket no está corriendo en $host:$port\n";
    }
}

function isServerRunning(string $host, int $port): bool
{
    $timeout = 1;
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if ($fp) {
        fclose($fp);
        return true;
    }
    return false;
}