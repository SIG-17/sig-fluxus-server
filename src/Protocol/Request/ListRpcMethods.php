<?php

namespace SIG\Server\Protocol\Request;

use SIG\Server\Exception\UnexpectedValueException;
use SIG\Server\Fluxus;
use SIG\Server\Protocol\Data\Method;
use SIG\Server\Protocol\Request\Base;
use SIG\Server\Protocol\Request\RequestHandlerInterface;
use SIG\Server\Protocol\Response\ResponseInterface;

class ListRpcMethods extends Base implements RequestHandlerInterface
{

    /**
     * @throws UnexpectedValueException
     */
    public function handle(int $fd, Fluxus $server): void
    {
        $methods = [];
        $workerId = $server->getWorkerId();
        while ($server->initialized === false) {
            $server->logger?->info('Worker #' . $workerId . ' initializing, waiting...');
            //usleep(100000);
            $server->safeSleep(0.1);
        }

        $server->logger?->debug('🗂️ Propiedad rpcHandlers contiene ' . count($server->rpcHandlers) . ' métodos');
        $server->logger?->debug('📟 Propiedad rpcMethods contiene ' . $server->rpcMethods->count());
        // Listar métodos disponibles en ESTE worker
        foreach ($server->rpcHandlers as $methodName => $handler) {
            $requiresAuth = false;
            $description = $server->getRpcMethodDescription($methodName);
            $registeredAt = 0;
            $auth_roles = [];

            // Verificar en tabla si existe metadata
            if ($server->rpcMethods->exist($methodName)) {
                $methodInfo = $server->rpcMethods->get($methodName);
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
        $response = $server->responseProtocol->getProtocolFor([
            'type' => $server->responseProtocol->get('rpcMethodsList'),
            'methods' => $methods,
            'total' => count($methods),
            'stats' => [
                'worker_id' => $workerId,
                'timestamp' => time()
            ]
        ]);
        if ($response instanceof ResponseInterface && $response->isValid()) {
            $server->sendToClient($fd, $response);
        } else {
            $server->logger?->error('Error sending rpc methods list response');
            $server->sendError($fd, 'Error sending rpc methods list response');
        }
    }
}