<?php

namespace SIG\Server\Protocol\Response\Rpc;

use SIG\Server\Exception\InvalidArgumentException;
use SIG\Server\Protocol\Response\Base;
use SIG\Server\Protocol\Response\ResponseInterface;
use SIG\Server\Protocol\Response\Type;
use SIG\Server\Protocol\Status;

class RpcSuccess extends Base implements ResponseInterface
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
    protected(set) string $message;

    public function __construct(
        ?array $values = [],
        private readonly Type $responseTypes = new RpcType()
    )
    {
        if (empty($values)) {
            $values = [];
        }
        $values['type'] = $responseTypes->get('rpcSuccess');
        parent::__construct($values, $responseTypes);
    }

    public function isValid(): bool
    {
        return isset($this->id, $this->status) && $this->type && $this->type === $this->responseTypes->get('rpcSuccess');
    }
}