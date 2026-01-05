<?php

namespace SIG\Server\Protocol\Response\Rpc;

use SIG\Server\Protocol\Response\Base;
use SIG\Server\Protocol\Response\ResponseInterface;
use SIG\Server\Protocol\Response\Type;

class RpcMethodsList extends Base implements ResponseInterface
{
    protected(set) array $methods = [];
    protected int $total = 0;

    public function __construct(
        ?array                $values = [],
        private readonly Type $responseTypes = new RpcType()
    )
    {
        if (empty($values)) {
            $values = [];
        }
        $values['type'] = $responseTypes->get('rpcMethodsList');
        parent::__construct($values, $responseTypes);
    }

    public function isValid(): bool
    {
        return $this->type && $this->type === $this->responseTypes->get('rpcMethodsList');
    }
}