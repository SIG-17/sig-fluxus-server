<?php

namespace SIG\Server\Protocol\Request\Rpc;

use SIG\Server\Fluxus;
use SIG\Server\Protocol\Request\Action;
use SIG\Server\Protocol\Request\Base;
use SIG\Server\Protocol\Request\RequestHandlerInterface;
use SIG\Server\Protocol\Status;
use SIG\Server\RPC\RpcManager;
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
        ?array                  $values = [],
        private readonly Action $protocol = new RpcDefinition()
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
        $server->logger?->debug('Request received for '.$this->protocol::getProtocolName());
        if (!$server->hasProtocol($this->protocol::getProtocolName())) {
            $server->sendError($fd, 'Protocolo de RPC no disponible');
            return;
        }
        /**
         * @var RpcManager $rpc
         */
        $rpc = $server->getProtocolManager($this->protocol::getProtocolName());
        $requestId = $this->id ?? $rpc->generateRpcId();
        $method = $this->method ?? '';

        $server->logger?->debug('Received PARAMS ' . __FILE__ . ' ' . __LINE__ . ' ' . print_r($this->params, true));
        $params = $this->params instanceof AbstractDescriptor ? $this->params?->getInitialized() : $this->params;
        $timeout = isset($this->timeout) ? (int)$this->timeout : 30;
        $workerId = $server->getWorkerId();
        while ($server->isRunning() === false) {
            $server->logger?->info('Worker #' . $workerId . ' initializing, waiting...');
            //usleep(100000);
            $server->safeSleep(0.5);
        }
        try {
            if (empty($method)) {
                $rpc->sendRpcError($fd, $requestId, 'Method not specified');
                return;
            }
            if (!is_string($method)) {
                $rpc->sendRpcError($fd, $requestId, 'Method must be a string');
                return;
            }
            $server->logger?->debug("📨 RPC recibido en worker #{$workerId}: $method (ID: $requestId)");
            $server->logger?->debug("📋 Handlers en worker #{$workerId}: " . count($server->rpcHandlers));

            // Si el método no está en handlers, fallar inmediatamente
            if (!isset($rpc->rpcHandlers[$method])) {
                $server->logger?->error("❌ Método $method no disponible en worker #{$workerId}");
                $rpc->sendRpcError($fd, $requestId, "Servicio no disponible temporalmente", 503);
                return;
            }

            // Verificar en tabla para metadata
            $requiresAuth = false;
            if ($rpc->getRpcMethods()->exist($method)) {
                $methodInfo = $rpc->getRpcMethods()->get($method);
                $requiresAuth = (bool)$methodInfo['requires_auth'];
            }

            if ($requiresAuth && !$server->isAuthenticated($fd)) {
                $rpc->sendRpcError($fd, $requestId, 'No autenticado', 401);
                return;
            }
            $roles = !empty($methodInfo['allowed_roles']) ? explode('|', $methodInfo['allowed_roles']) : ['ws:general', 'ws:user'];

            $server->logger?->debug("Roles permitidos: " . print_r($roles, true));
            if (!empty($roles) && !in_array('ws:general', $roles, false) && empty(array_intersect($roles, $server->userRoles($fd)))) {
                $rpc->sendRpcError($fd, $requestId, 'No autorizado, roles necesarios: ' . $methodInfo['allowed_roles'], 403);
                return;
            }
           // $responses = $server->getResponseTypes($this->rpcProtocol::getProtocolName());

            $server->sendProtocolResponse($this->protocol::getProtocolName(), 'rpcResponse', $fd, [
                'id' => $requestId,
                'status' => Status::accepted->value,
                '_metadata' => [
                    'worker_id' => $workerId,
                    'timestamp' => time()
                ]
            ]);
            // Confirmación de aceptación de RPC
            //$server->sendToClient($fd, $status);
            // Guardar solicitud
            $rpc->rpcRequests->set($requestId, [
                'request_id' => $requestId,
                'fd' => $fd,
                'method' => $method,
                'params' => json_encode($params),
                'created_at' => time(),
                'status' => 'pending',
                'worker_id' => $workerId
            ]);

            $server->logger?->debug("🚀 Ejecutando RPC: $method en worker #{$workerId} con ID: $requestId y timeout de: $timeout");

            $rpc->executeRpcMethod($fd, $requestId, $method, $params, $timeout, $workerId);


        } catch (\Exception $e) {
            $server->logger?->error("❌ Error procesando RPC: " . $e->getMessage());
            $rpc->sendRpcError($fd, $requestId, '❌ Error interno del servidor: ' . $e->getMessage());
        }
    }
}