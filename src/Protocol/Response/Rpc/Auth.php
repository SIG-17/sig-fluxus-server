<?php

namespace SIG\Server\Protocol\Response\Rpc;

use SIG\Server\Protocol\Data\AuthResponse;
use SIG\Server\Protocol\Response\Base;
use SIG\Server\Protocol\Response\ResponseInterface;
use SIG\Server\Protocol\Response\Type;

class Auth extends Base implements ResponseInterface
{

    protected(set) string $message;
    protected(set) AuthResponse $data {
        set(array|AuthResponse $data) {
            $this->data = is_array($data) ? new AuthResponse($data) : $data;
        }
    }

    public function __construct(
        ?array       $values = [],
        private readonly Type $responseTypes = new RpcType()
    )
    {
        if (empty($values)) {
            $values = [];
        }
        $values['type'] = $responseTypes->get('authResponse');
        parent::__construct($values, $responseTypes);
    }

    /*
     *
    $this->sendToClient($fd, [
                'type' => 'auth_response',
                'success' => $success,
                'message' => 'Authentication successful',
                'data' => [
                    'roles' => array_unique(array_merge(['ws:user'], $this->auth->getRoles($fd))),
                    'permissions' => $this->auth->getPermissions($fd),
                ],
                'stats'=> [
                    'client_fd' => $fd,
                    'server_time' => date('Y-m-d H:i:s'),
                    'worker_id' => $this->getWorkerId()
                ]
            ]);
     */
    public function isValid(): bool
    {
        return $this->type && $this->type === $this->responseTypes->get('authResponse') && !empty($this->data->getInitialized());
    }
}