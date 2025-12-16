<?php

namespace SIG\Server\Protocol\Request;

use SIG\Server\Fluxus;
use SIG\Server\Protocol\Status;
use Swoole\Coroutine;
use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;

class Rpc extends Base implements RequestHandlerInterface
{
    protected(set) string $id;
    protected(set) string $method;
    protected(set) array|AbstractDescriptor $params {
        set(string|array|AbstractDescriptor $params) {
            $this->params = $params instanceof AbstractDescriptor ? $params->toArray() : $params;
        }
    }
    protected(set) float|int|null $timeout;
    public function __construct(
        ?array $values = [],
        Action $protocol = new Action()
    )
    {
        if (empty($values)) {
            $values = [];
        }
        $values['action'] = $protocol->get('rpc');
        parent::__construct($values, $protocol);
    }
    public function handle(int $fd, Fluxus $server): void
    {

        $requestId = $this->id ?? $server->generateRpcId();
        $method = $this->method ?? '';
        $params = $this->params instanceof AbstractDescriptor ? $this->params?->getInitialized() : [];
        $timeout = isset($this->timeout) ? (int)$this->timeout : 30;
        $workerId = $server->getWorkerId();
        while ($server->initialized === false) {
            $server->logger?->info('Worker #' . $workerId . ' initializing, waiting...');
            //usleep(100000);
            $server->safeSleep(0.1);
        }
        try {
            if (empty($method)) {
                $server->sendRpcError($fd, $requestId, 'Method not specified');
                return;
            }
            if (!is_string($method)) {
                $server->sendRpcError($fd, $requestId, 'Method must be a string');
                return;
            }
            $server->logger?->debug("📨 RPC recibido en worker #{$workerId}: $method (ID: $requestId)");
            $server->logger?->debug("📋 Handlers en worker #{$workerId}: " . count($server->rpcHandlers));

            // Si el método no está en handlers, fallar inmediatamente
            if (!isset($server->rpcHandlers[$method])) {
                $server->logger?->error("❌ Método $method no disponible en worker #{$workerId}");
                $server->sendRpcError($fd, $requestId, "Servicio no disponible temporalmente", 503);
                return;
            }

            // Verificar en tabla para metadata
            $requiresAuth = false;
            if ($server->rpcMethods->exist($method)) {
                $methodInfo = $server->rpcMethods->get($method);
                $requiresAuth = (bool)$methodInfo['requires_auth'];
            }

            if ($requiresAuth && !$server->isAuthenticated($fd)) {
                $server->sendRpcError($fd, $requestId, 'No autenticado', 401);
                return;
            }
            $roles = isset($methodInfo['allowed_roles']) ? explode('|', $methodInfo['allowed_roles']) : ['ws:general', 'ws:user'];
            if (!empty($roles) && !in_array('ws:general', $roles, false) && empty(array_intersect($roles, $server->userRoles($fd)))) {
                $server->sendRpcError($fd, $requestId, 'No autorizado', 403);
                return;
            }

            $status = $server->responseProtocol->getProtocolFor([
                'type' => $server->responseProtocol->get('rpcResponse'),
                'id' => $requestId,
                'status' => Status::accepted->value,
                '_metadata' => [
                    'worker_id' => $workerId,
                    'timestamp' => time()
                ]
            ]);

            // Confirmación de aceptación de RPC
            $server->sendToClient($fd, $status);

            // Guardar solicitud
            $server->rpcRequests->set($requestId, [
                'request_id' => $requestId,
                'fd' => $fd,
                'method' => $method,
                'params' => json_encode($params),
                'created_at' => time(),
                'status' => 'pending',
                'worker_id' => $workerId
            ]);

            $server->logger?->debug("🚀 Ejecutando RPC: $method en worker #{$workerId}");

            // Ejecutar en corutina
            Coroutine::create(function () use ($fd, $requestId, $method, $params, $timeout, $workerId, $server) {
                $server->executeRpcMethod($fd, $requestId, $method, $params, $timeout, $workerId);
            });


        } catch (\Exception $e) {
            $server->logger?->error("❌ Error procesando RPC: " . $e->getMessage());
            $server->sendRpcError($fd, $requestId, 'Error interno del servidor');
        }
    }
}