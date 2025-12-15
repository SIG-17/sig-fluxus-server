<?php
// Crea un archivo: src/Utils/BroadcastCollector.php
namespace SIG\Server\Utils;

use Psr\Log\LoggerInterface;
use SIG\Server\Fluxus;
use Swoole\Table;
use Swoole\Coroutine\Channel;
use Swoole\WebSocket\Server;

class BroadcastCollector
{
    private Table $responsesTable;
    private Channel $responseChannel;
    private string $requestId;
    private int $timeout;

    public function __construct(int $maxWorkers = 16, int $timeout = 2000, private readonly ?LoggerInterface $logger = null)
    {
        $this->requestId = uniqid('broadcast_', true);
        $this->timeout = $timeout;

        // Tabla para respuestas
        $this->responsesTable = new Table($maxWorkers);
        $this->responsesTable->column('worker_id', Table::TYPE_INT);
        $this->responsesTable->column('data', Table::TYPE_STRING, 8192); // JSON
        $this->responsesTable->column('received', Table::TYPE_INT, 1);
        $this->responsesTable->create();

        // Canal para notificar nuevas respuestas
        $this->responseChannel = new Channel($maxWorkers);
    }

    public function collect(Fluxus $server, string $action, array $params = []): array
    {
        $currentWorkerId = $server->getWorkerId();
        $totalWorkers = $server->setting['worker_num'] ?? 1;

        // 1. Registrar worker actual
        $this->addResponse($currentWorkerId, [
            'worker_id' => $currentWorkerId,
            'status' => 'pending',
            '_source' => 'local'
        ]);

        // 2. Enviar broadcast
        $message = json_encode([
            'action' => 'broadcast_collect',
            'collect_action' => $action,
            'request_id' => $this->requestId,
            'params' => $params,
            'response_to_worker' => $currentWorkerId,
            'timestamp' => time()
        ]);

        $sent = 0;
        for ($i = 0; $i < $totalWorkers; $i++) {
            if (($i !== $currentWorkerId) && $server->sendMessage($message, $i)) {
                $sent++;
            }
        }


        // 4. Procesar worker local
        if (isset($server->rpcHandlers[$action])) {
            try {
                $localResult = $server->rpcHandlers[$action]($params, 0);
                $this->updateResponse($currentWorkerId, $localResult, true);
            } catch (\Exception $e) {
                $this->updateResponse($currentWorkerId, [
                    'error' => $e->getMessage(),
                    'status' => 'error'
                ], true);
            }
        }

        // 3. Esperar respuestas
        // +1 por el local
        return $this->waitForResponses($sent + 1);
    }

    private function waitForResponses(int $expected): array
    {
        $start = microtime(true);
        $received = 1; // Ya tenemos el local

        while ($received < $expected) {
            if ((microtime(true) - $start) * 1000 > $this->timeout) {
                break;
            }

            // Esperar nueva respuesta con timeout
            $data = $this->responseChannel->pop(0.1); // 100ms timeout
            if ($data !== false) {
                $this->logger?->debug("🌀☢️ Response received from worker {$data}");
                $received++;
            }
        }

        return $this->getAllResponses();
    }

    public function addResponse(int $workerId, array $data): void
    {
        $this->responsesTable->set("worker_{$workerId}", [
            'worker_id' => $workerId,
            'data' => json_encode($data),
            'received' => 0
        ]);
    }

    public function updateResponse(int $workerId, array $data, bool $received = true): void
    {
        $this->responsesTable->set("worker_{$workerId}", [
            'worker_id' => $workerId,
            'data' => json_encode($data),
            'received' => $received ? 1 : 0
        ]);

        if ($received) {
            $this->responseChannel->push($workerId);
        }
    }

    public function getAllResponses(): array
    {
        $responses = [];
        foreach ($this->responsesTable as $key => $row) {
            $responses[] = [
                'worker_id' => $row['worker_id'],
                'data' => json_decode($row['data'], true),
                'received' => (bool)$row['received']
            ];
        }
        return $responses;
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }
}