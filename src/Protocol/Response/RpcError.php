<?php

namespace SIG\Server\Protocol\Response;

use SIG\Server\Exception\InvalidArgumentException;
use SIG\Server\Protocol\Data\Error;
use SIG\Server\Protocol\Data\Stats;
use SIG\Server\Protocol\Status;

class RpcError extends Base implements ResponseInterface
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
    protected(set) ?Error $error {
        set(Error|array|null $error) {
            if ($error !== null) {
                $this->status = Status::error->value;
            }
            $error = is_array($error) ? new Error($error) : $error;
            $this->error = $error;
        }
    }
   // protected(set) ?Stats $stats;

    public function __construct(
        ?array                $values = [],
        private readonly Type $responseTypes = new Type()
    )
    {
        if (empty($values)) {
            $values = [];
        }
        $values['type'] = $responseTypes->get('rpcError');
        $this->status = Status::error->value;
        if (!isset($values['id'])) {
            $values['id'] = uniqid('rpc_error_', false);
        }
        parent::__construct($values, $responseTypes);
    }

    public function isValid(): bool
    {
        return isset($this->id, $this->status) && $this->type && $this->type === $this->responseTypes->get('rpcError');
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