<?php
// src/Server/HealthManager.php

namespace SIG\Server;

use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Swoole\WebSocket\Server;
use Tabula17\Satelles\Omnia\Roga\Database\Connector;
use Throwable;

/**
 * @deprecated Use Tabula17\Satelles\Omnia\Roga\HealthManager
 */
class HealthManager
{
    private array $checks = [];
    private int $checkInterval;
    private bool $shouldStop = false;
    private bool $isChecking = false;
    private LoggerInterface $logger;
    private array $runningWorkers = []; // Para trackear workers activos

    public function __construct(
        private Connector $connector,
        int $checkIntervalMs = 30000,
        ?LoggerInterface $logger = null
    ) {
        $this->checkInterval = $checkIntervalMs;
        $this->logger = $logger ?? new Logger('health-manager');
    }
    /**
     * Inicia el ciclo de health checks con control de parada
     */
    public function startHealthCheckCycle(Server $server, int $workerId): void
    {
        // Verificar si es worker principal (no task worker)
        if ($workerId >= $server->setting['worker_num']) {
            $this->logger->debug("Worker #{$workerId} es task worker, omitiendo health checks");
            return;
        }

        // Registrar worker como activo
        $this->runningWorkers[$workerId] = [
            'started_at' => time(),
            'last_check' => 0,
            'cycle_count' => 0
        ];

        // Aplicar escalonamiento (distribución temporal)
        $offset = $this->calculateWorkerOffset($workerId, $server->setting['worker_num']);

        go(function () use ($server, $workerId, $offset) {
            $this->logger->info("Worker #{$workerId}: Health checks iniciarán en {$offset}s");

            // Esperar offset para escalonar
            \Swoole\Coroutine::sleep($offset);

            $this->runHealthCheckLoop($server, $workerId);
        });
    }

    /**
     * Ciclo principal de health checks con control de parada
     */
    private function runHealthCheckLoop(Server $server, int $workerId): void
    {
        $checkIntervalSec = $this->checkInterval / 1000;
        $lastFullCheck = 0;

        $this->logger->info("Worker #{$workerId}: Ciclo de health checks iniciado ({$checkIntervalSec}s)");

        // Bucle principal controlado por flag de parada
        while (!$this->shouldStop) {
            $cycleStart = microtime(true);

            try {
                // 1. Verificar si el worker sigue activo (heartbeat interno)
                if (!$this->isWorkerAlive($server, $workerId)) {
                    $this->logger->warning("Worker #{$workerId}: Marcado como inactivo, deteniendo checks");
                    break;
                }

                // 2. Ejecutar health checks
                $this->performHealthChecks($workerId, $lastFullCheck);

                // 3. Actualizar estadísticas
                $this->runningWorkers[$workerId]['cycle_count']++;
                $this->runningWorkers[$workerId]['last_check'] = time();

            } catch (\Throwable $e) {
                $this->logger->error("Worker #{$workerId}: Error en ciclo de health check", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }

            // 4. Calcular tiempo de espera dinámico
            $cycleDuration = microtime(true) - $cycleStart;
            $sleepTime = max(0.1, $checkIntervalSec - $cycleDuration);

            // 5. Esperar con posibilidad de parada anticipada
            $this->sleepWithInterrupt($sleepTime, $workerId);
        }

        $this->logger->info("Worker #{$workerId}: Ciclo de health checks finalizado");
        unset($this->runningWorkers[$workerId]);
    }

    /**
     * Sleep que puede ser interrumpido por señal de parada
     */
    private function sleepWithInterrupt(float $seconds, int $workerId): void
    {
        $chunkSize = 1.0; // Verificar cada segundo si hay que parar
        $remaining = $seconds;

        while ($remaining > 0 && !$this->shouldStop) {
            $sleepTime = min($chunkSize, $remaining);
            \Swoole\Coroutine::sleep($sleepTime);
            $remaining -= $sleepTime;

            // Log periódico de actividad
            if ($remaining > 0 && (int)$remaining % 10 == 0) {
                $this->logger->debug("Worker #{$workerId}: Health check durmiendo, {$remaining}s restantes");
            }
        }
    }

    /**
     * Señal para detener todos los health checks ordenadamente
     */
    public function stopGracefully(int $timeoutSec = 30): array
    {
        $this->logger->info("Iniciando parada graceful de health checks (timeout: {$timeoutSec}s)");
        $this->shouldStop = true;

        $startTime = time();
        $activeWorkers = count($this->runningWorkers);

        // Esperar a que todos los workers terminen
        while (count($this->runningWorkers) > 0) {
            if (time() - $startTime > $timeoutSec) {
                $this->logger->warning("Timeout de parada graceful alcanzado, workers pendientes: " .
                    count($this->runningWorkers));
                break;
            }

            \Swoole\Coroutine::sleep(1);

            // Log cada 5 segundos
            if ((time() - $startTime) % 5 == 0) {
                $this->logger->info("Esperando finalización de health checks...", [
                    'workers_restantes' => array_keys($this->runningWorkers),
                    'tiempo_espera' => time() - $startTime
                ]);
            }
        }

        $result = [
            'workers_iniciales' => $activeWorkers,
            'workers_finales' => count($this->runningWorkers),
            'tiempo_total' => time() - $startTime,
            'timeout_alcanzado' => (time() - $startTime) >= $timeoutSec
        ];

        $this->logger->info("Parada graceful completada", $result);
        return $result;
    }

    /**
     * Verifica si un worker sigue activo en el servidor
     */
    private function isWorkerAlive(Server $server, int $workerId): bool
    {
        // Método 1: Verificar estadísticas del servidor
        try {
            $stats = $server->stats();
            return true; // Si podemos obtener stats, el servidor está vivo
        } catch (\Throwable) {
            return false;
        }

        // Método alternativo: Comunicación entre workers (más avanzado)
        // return $this->checkWorkerHeartbeat($workerId);
    }



    public function registerDatabaseCheck(Connector $connector): void
    {
        $this->connector = $connector;
        $this->checks['database'] = [
            'last_check' => 0,
            'status' => 'unknown',
            'details' => []
        ];
    }

    public function setupServerTick(Fluxus $server, int $workerId): void
    {
        $this->logger->notice("Evaluando iniciar tick para health checks en worker #{$workerId} (Worker max: {$server->setting['worker_num']})");
        // Solo ejecutar en workers principales (no task workers)
        if ($workerId < $server->setting['worker_num']) {
            $this->logger->notice("Iniciando tick para health checks en worker #{$workerId}");
            // Iniciar el tick después de 10 segundos (para dar tiempo a la inicialización)
            Coroutine::sleep(10);
            $this->startHealthCheckCycle($server, $workerId);
        }
    }


    public function performHealthChecks(int $workerId): void
    {
        if ($this->isChecking) {
            $this->logger->debug("Worker #{$workerId}: Health check already in progress, skipping");
            return;
        }

        $this->isChecking = true;
        $startTime = microtime(true);

        try {
            $this->logger->debug("Worker #{$workerId}: Starting health checks");

            // 1. Check de base de datos
            if ($this->connector instanceof Connector) {
                $this->performDatabaseHealthCheck($workerId);
            }

            // 2. Check de memoria (opcional)
            $this->performMemoryCheck($workerId);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            // Log resumen solo periódicamente para no saturar
            if (time() % 300 === 0) { // Cada 5 minutos
                $this->logger->info("Worker #{$workerId}: Health checks completed in {$duration}ms", [
                    'checks' => array_keys($this->checks),
                    'statuses' => array_column($this->checks, 'status')
                ]);
            }

        } catch (Throwable $e) {
            $this->logger->error("Worker #{$workerId}: Health check failed: " . $e->getMessage());
        } finally {
            $this->isChecking = false;
        }
    }
    private function performDatabaseHealthCheck(int $workerId): void
    {
        try {
            $connector = $this->connector;
            $currentTime = time();

            // 1. ESTADO ACTUAL (siempre)
            $currentStatus = $connector->getHealthStatus();

            $this->checks['database'] = [
                'last_check' => $currentTime,
                'status' => $this->evaluateDatabaseStatus($currentStatus, $workerId),
                'current_stats' => $currentStatus,
                'timestamp' => $currentTime
            ];

            // 2. CHECK COMPLETO (cada 30 segundos usando comparación)
            $lastFullCheck = $this->checks['database']['last_full_check'] ?? 0;

            if ($currentTime - $lastFullCheck >= 30) {
                $this->logger->debug("Worker #{$workerId}: Iniciando check completo de BD");

                $startTime = microtime(true);
                $connector->healthCheckLoadedConnections();

                // 3. RECONEXIÓN PERIÓDICA (cada 5 minutos usando comparación)
                $lastReconnect = $this->checks['database']['last_reconnect_attempt'] ?? 0;
                $unreachableCount = $connector->getUnreachableCount();
                $this->logger->debug("Last reconnect attempt: {$lastReconnect}, Unreachable connections: {$unreachableCount}, current timestamp: {$currentTime}");
                if ($unreachableCount > 0 && ($currentTime - $lastReconnect) >= 300) {
                    $this->logger->info("Worker #{$workerId}: Reintentando {$unreachableCount} conexiones caídas");
                    $reconnectResult = $connector->reloadUnreachableConnections();

                    $this->checks['database']['last_reconnect_attempt'] = $currentTime;
                    $this->checks['database']['reconnect_result'] = $reconnectResult;
                }

                $duration = round((microtime(true) - $startTime) * 1000, 2);
                $this->checks['database']['full_check'] = [
                    'duration_ms' => $duration,
                    'executed_at' => $currentTime
                ];
                $this->checks['database']['last_full_check'] = $currentTime;
            }


        } catch (Throwable $e) {
            $this->checks['database'] = [
                'last_check' => time(),
                'status' => 'error',
                'error' => $e->getMessage(),
                'timestamp' => time()
            ];
            $this->logger->error("Worker #{$workerId}: Database health check error", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    public function retryPermanentFailures(int $workerId): array
    {
        $this->logger->info("Retrying permanent failures for worker #{$workerId}:");
        return $this->connector->retryFailedConnections();
    }
    private function evaluateDatabaseStatus(array $stats, $workerId): string
    {
        $this->logger->debug("WorkerId #{$workerId}: Evaluando estado de la base de datos: ". var_export($stats, true));
        if ($stats['unreachable_connections'] > 0) {
            return 'degraded';
        }
        if ($stats['loaded_connections'] === 0) {
            return 'critical';
        }
        return 'healthy';
    }

    private function performMemoryCheck(int $workerId): void
    {
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        $memoryLimit = ini_get('memory_limit');

        $this->checks['memory'] = [
            'last_check' => time(),
            'usage_mb' => round($memoryUsage / 1024 / 1024, 2),
            'peak_mb' => round($memoryPeak / 1024 / 1024, 2),
            'limit' => $memoryLimit,
            'status' => $this->evaluateMemoryStatus($memoryUsage, $memoryLimit)
        ];

        // Alertar si uso de memoria es alto
        if ($this->checks['memory']['status'] === 'warning') {
            $this->logger->warning("Worker #{$workerId}: High memory usage detected",
                $this->checks['memory']);
        }
    }

    private function evaluateMemoryStatus(int $usage, string $limit): string
    {
        $limitBytes = $this->convertToBytes($limit);
        $usagePercent = ($limitBytes > 0) ? ($usage / $limitBytes) * 100 : 0;

        if ($usagePercent > 90) {
            return 'critical';
        }

        if ($usagePercent > 70) {
            return 'warning';
        }

        return 'healthy';
    }

    private function convertToBytes(string $value): int
    {
        $value = trim($value);
        $last = strtolower($value[strlen($value)-1]);
        $int = (int) $value;

        switch ($last) {
            case 'g': $int *= 1024 * 1024 * 1024; break;
            case 'm': $int *= 1024 * 1024; break;
            case 'k': $int *= 1024; break;
        }

        return $int;
    }

    public function getHealthStatus(): array
    {
        return [
            'checks' => $this->checks,
            'timestamp' => time(),
            'next_check_in' => $this->checkInterval / 1000 . ' seconds'
        ];
    }

    public function getCheckInterval(): int
    {
        return $this->checkInterval;
    }/**
 * Calcula offset único para cada worker basado en:
 * 1. ID del worker
 * 2. Número total de workers
 * 3. Intervalo de checks
 * 4. Evitar colisiones
 */
    private function calculateWorkerOffset(int $workerId, int $totalWorkers): float
    {
        // Configuración de escalonamiento
        $baseStagger = 7; // Segundos base entre workers
        $maxOffset = 30; // Máximo offset en segundos

        // Fórmula para distribución uniforme
        if ($totalWorkers <= 1) {
            return 0; // Sin escalonamiento si solo hay un worker
        }

        // Opción 1: Distribución lineal simple
        $offset = ($workerId % $totalWorkers) * $baseStagger;

        // Opción 2: Distribución usando número primo para mejor dispersión
        $primeMultiplier = 11; // Número primo para evitar patrones
        $offset = ($workerId * $primeMultiplier) % $maxOffset;

        // Opción 3: Offset basado en hash del worker ID (más aleatorio)
        $hash = crc32((string)$workerId);
        $offset = ($hash % $maxOffset);

        // Asegurar que no sea cero para todos excepto primer worker
        if ($workerId > 0 && $offset == 0) {
            $offset = min($baseStagger, $maxOffset - 1);
        }

        $this->logger->debug("Worker #{$workerId}: Offset calculado = {$offset}s", [
            'total_workers' => $totalWorkers,
            'strategy' => 'hash-based'
        ]);

        return (float)$offset;
    }

    /**
     * Escalonamiento adaptativo basado en carga del sistema
     */
    public function calculateAdaptiveOffset(int $workerId, array $systemLoad): float
    {
        $baseOffset = 5.0;

        // Factor basado en carga de CPU
        $cpuFactor = 1.0;
        if (isset($systemLoad['cpu_percent'])) {
            if ($systemLoad['cpu_percent'] > 80) {
                $cpuFactor = 1.5; // Aumentar offset si CPU alta
            } elseif ($systemLoad['cpu_percent'] < 20) {
                $cpuFactor = 0.7; // Reducir offset si CPU baja
            }
        }

        // Factor basado en memoria
        $memoryFactor = 1.0;
        if (isset($systemLoad['memory_percent'])) {
            if ($systemLoad['memory_percent'] > 85) {
                $memoryFactor = 1.3;
            }
        }

        // Factor basado en hora del día (ej: evitar horas pico)
        $timeFactor = 1.0;
        $hour = date('G');
        if ($hour >= 9 && $hour <= 17) {
            $timeFactor = 1.2; // Horario laboral
        }

        $finalOffset = $baseOffset * $cpuFactor * $memoryFactor * $timeFactor;

        // Aplicar variación por worker ID
        $workerVariation = ($workerId % 10) * 0.3;
        $finalOffset += $workerVariation;

        // Limitar offset máximo
        $finalOffset = min($finalOffset, 60.0); // Máximo 60 segundos

        return round($finalOffset, 1);
    }
}