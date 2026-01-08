<?php

namespace SIG\Server\Protocol\Request\Rpc;

use SIG\Server\Exception\UnexpectedValueException;
use SIG\Server\Fluxus;
use SIG\Server\Protocol\Data\Method;
use SIG\Server\Protocol\Request\Action;
use SIG\Server\Protocol\Request\Base;
use SIG\Server\Protocol\Request\RequestHandlerInterface;
use SIG\Server\Protocol\Response\ResponseInterface;
use SIG\Server\Protocol\Status;
use SIG\Server\RPC\RpcManager;

class ListRpcMethods extends Base implements RequestHandlerInterface
{

    public function __construct(
        ?array                  $values = [],
        private readonly Action $protocol = new RpcDefinition()
    )
    {
        if (empty($values)) {
            $values = [];
        }
        $values['action'] = $protocol->get('listRpcMethods');
        parent::__construct($values, $protocol);
    }

    /**
     * @throws UnexpectedValueException
     */
    public function handle(int $fd, Fluxus $server): void
    {
        if (!$server->hasProtocol($this->protocol::getProtocolName())) {
            $server->sendError($fd, 'Protocolo de RPC no disponible');
            return;
        }
        $methods = [];
        $workerId = $server->getWorkerId();
        while ($server->isRunning() === false) {
            $server->logger?->info('Worker #' . $workerId . ' initializing, waiting...');
            //usleep(100000);
            $server->safeSleep(0.1);
        }
        /**
         * @var RpcManager $rpc
         */
        $rpc = $server->getProtocolManager($this->protocol::getProtocolName());

        $server->logger?->debug('🗂️ Propiedad rpcHandlers contiene ' . count($rpc->rpcHandlers) . ' métodos');
        $server->logger?->debug('📟 Propiedad rpcMethods contiene ' . $rpc->getRpcMethods()->count());
        // Listar métodos disponibles en ESTE worker
        $rpcHandlers =$rpc->rpcHandlers;
        sort($rpcHandlers);
        foreach ($rpcHandlers as $methodName => $handler) {
            $requiresAuth = false;
            $description = 'RPC method ' . $methodName;
            $registeredAt = 0;
            $auth_roles = [];

            // Verificar en tabla si existe metadata
            if ($rpc->getRpcMethods()->exist($methodName)) {
                $methodInfo = $rpc->getRpcMethods()->get($methodName);
                $requiresAuth = (bool)$methodInfo['requires_auth'];
                $auth_roles = !empty($methodInfo['allowed_roles']) ? explode(',', $methodInfo['allowed_roles']) : [];
                $description = $methodInfo['description'];
                $registeredAt = $methodInfo['registered_at'];
                if (array_key_exists('only_internal', $methodInfo) && (bool)$methodInfo['only_internal'] === true) {
                    continue;
                }
            }

            $methods[] = new Method([
                'name' => $methodName,
                'requires_auth' => $requiresAuth,
                'allowed_roles' => $auth_roles,
                'available_in_worker' => $workerId,
                'description' => $description,
                'registered_at' => $registeredAt
            ]);
        }
        $server->sendProtocolResponse($this->protocol::getProtocolName(), 'rpcMethodsList', $fd, [
            'methods' => $methods,
            'total' => count($methods),
            '_metadata' => [
                'worker_id' => $workerId,
                'timestamp' => time()
            ]
        ]);
        /*
                $responses = $server->getResponseTypes($this->rpcProtocol::getProtocolName());
                $response = $responses->getProtocolFor([
                    'type' => $responses->get('rpcMethodsList'),
                    'methods' => $methods,
                    'total' => count($methods),
                    '_metadata' => [
                        'worker_id' => $workerId,
                        'timestamp' => time()
                    ]
                ]);
                if ($response instanceof ResponseInterface && $response->isValid()) {
                    $server->sendToClient($fd, $response);
                } else {
                    $server->logger?->error('Error sending rpc methods list response');
                    $server->sendError($fd, 'Error sending rpc methods list response');
                }*/
    }
}