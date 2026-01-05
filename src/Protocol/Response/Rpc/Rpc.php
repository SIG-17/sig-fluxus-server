<?php

namespace SIG\Server\Protocol\Response\Rpc;

use SIG\Server\Exception\InvalidArgumentException;
use SIG\Server\Protocol\Data\Stats;
use SIG\Server\Protocol\Response\Base;
use SIG\Server\Protocol\Response\ResponseInterface;
use SIG\Server\Protocol\Response\Type;
use SIG\Server\Protocol\Status;

class Rpc extends Base implements ResponseInterface
{
    protected(set) string $status {
        set(string|Status $status) {
            if (is_string($status)) {
                $status = Status::fromString($status);
                if (!$status->isValid()) {
                    throw new InvalidArgumentException('Invalid status: ' . $status->value);
                }
            }
            $this->status = $status->value;
        }
    }
    protected(set) mixed $result;
    protected(set) int $total;
    protected(set) ?Stats $stats;

    public function __construct(
        ?array $values = [],
        private readonly Type $responseTypes = new RpcType()
    )
    {
        if (empty($values)) {
            $values = [];
        }
        $values['type'] = $responseTypes->get('rpcResponse');
        parent::__construct($values, $responseTypes);
    }

    public function isValid(): bool
    {
        return isset($this->id, $this->status) && $this->type && $this->type === $this->responseTypes->get('rpcResponse');
    }
}
/*
 *
// Confirmación de aceptación de RPC
$this->sendToClient($fd, [
                'type' => 'rpc_response',
                'id' => $requestId,
                'status' => 'accepted',
                'worker_id' => $workerId,
                'timestamp' => time()
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
 */