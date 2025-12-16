<?php

namespace SIG\Server\Protocol\Response;

use SIG\Server\Exception\UnexpectedValueException;
use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;

class Type extends AbstractDescriptor
{
    protected(set) string $message = 'message';
    protected(set) string $error = 'error';
    protected(set) string $success = 'success';
    protected(set) string $fileAvailable = 'file_available';
    protected(set) string $fileResponse = 'file_response';
    protected(set) string $rpcResponse = 'rpc_response';
    protected(set) string $rpcError = 'rpc_error';
    protected(set) string $rpcMethodsList = 'rpc_methods_list';
    protected(set) string $authResponse = 'auth_response';
    protected(set) string $channelsList = 'channel_list';
    protected(set) string $rpcMethodList = 'rpc_method_list';

    /**
     * @throws UnexpectedValueException
     */
    public function getProtocolFor(array $data): Base|ResponseInterface
    {
        if (isset($data['type']) && in_array($data['type'], $this->toArray())) {
            $className = __NAMESPACE__ . '\\' . str_replace(' ', '', ucwords(str_replace('_', ' ', str_replace('_response', '', $data['type']))));
            if (class_exists($className)) {
                return new $className($data);
            }
            return new Base($data);
        }
        throw new UnexpectedValueException('No response protocol detected. Must be one of: ' . implode(', ', $this->toArray()) . '');
    }
}


/*
 *
    MESSAGE_TYPE_FILE_AVAILABLE = 'file_available';
    MESSAGE_TYPE_FILE_RESPONSE = 'file_response';
    MESSAGE_TYPE_RPC_RESPONSE = 'rpc_response';
    MESSAGE_TYPE_RPC_METHODS = 'rpc_methods_list';
    MESSAGE_TYPE_ERROR = 'error';
    MESSAGE_TYPE_MESSAGE = 'message';
    MESSAGE_TYPE_SUCCESS = 'success';
 *  [
            'type' => 'message',
            'channel' => $channel,
            'data' => $data,
            'timestamp' => time(),
            'publisher' => $fd,
            'origin_server' => $this->getServerId() // Identificar origen
        ]
            // Confirmación de aceptación de RPC
            $this->sendToClient($fd, [
                'type' => 'rpc_response',
                'id' => $requestId,
                'status' => 'accepted',
                'worker_id' => $workerId,
                'timestamp' => time()
            ]);

            // Guardar solicitud
            $this->rpcRequests->set($requestId, [
                'request_id' => $requestId,
                'fd' => $fd,
                'method' => $method,
                'params' => json_encode($params),
                'created_at' => time(),
                'status' => 'pending',
                'worker_id' => $workerId
            ]);
$response = [
            'type' => 'rpc_response',
            'id' => $requestId,
            'status' => 'success',
            'result' => $result,
            'execution_time' => $executionTime,
            'timestamp' => time()
        ];
 $response = [
            'type' => 'rpc_response',
            'id' => $requestId,
            'status' => 'error',
            'error' => [
                'code' => $code,
                'message' => $error
            ],
            'timestamp' => time()
        ]
$this->sendToClient($fd, [
            'type' => 'auth_response',
            'success' => $success,
            'message' => 'Authentication successful',
            'data' => [
                'roles' => array_unique(array_merge(['ws:user'], $this->auth->getRoles($fd))),
                'permissions' => $this->auth->getPermissions($fd),
            ],
            'client_fd' => $fd,
            'server_time' => date('Y-m-d H:i:s'),
            'worker_id' => $this->getWorkerId()
        ]);
 $this->sendToClient($fd, [
            'type' => 'rpc_methods_list',
            'methods' => $methods,
            'total' => count($methods),
            'worker_id' => $workerId,
            'timestamp' => time()
        ]);
 */