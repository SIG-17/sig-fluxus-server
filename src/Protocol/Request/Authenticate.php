<?php

namespace SIG\Server\Protocol\Request;

use SIG\Server\Fluxus;
use SIG\Server\Protocol\Request\Base;
use SIG\Server\Protocol\Response\ResponseInterface;

class Authenticate extends Base implements RequestHandlerInterface
{
    protected(set) string|array|null $token;

    public function __construct(
        ?array $values = [],
        Action $protocol = new Action()
    )
    {
        if (empty($values)) {
            $values = [];
        }
        $values['action'] = $protocol->get('authenticate');
        parent::__construct($values, $protocol);
    }

    public function handle(int $fd, Fluxus $server): void
    {
        $server->logger?->debug('Auth request on connection ' . $fd . ': ' . var_export($this->token, true));
        $success = $server->auth?->authenticate($fd, $this->token) ?? false;
        $response = $server->responseProtocol->getProtocolFor([
            'type' => $server->responseProtocol->get('authResponse'),
            'success' => $success,
            'message' => 'Authentication successful',
            'data' => [
                'roles' => array_unique(array_merge(['ws:user'], $server->auth->getRoles($fd))),
                'permissions' => $server->auth->getPermissions($fd),
            ],
            '_metadata' => [
                'client_fd' => $fd,
                'server_time' => date('Y-m-d H:i:s'),
                'worker_id' => $server->getWorkerId()
            ]
        ]);
        if ($response instanceof ResponseInterface && $response->isValid()) {
            $server->sendToClient($fd, $response);
        } else {
            $server->logger?->error('Error sending authentication response');
            $server->sendError($fd, 'Error sending authentication response');
        }
    }
}