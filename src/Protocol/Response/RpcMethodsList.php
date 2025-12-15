<?php

namespace SIG\Server\Protocol\Response;

use SIG\Server\Protocol\Response\Base;
use SIG\Server\Protocol\Response\ResponseInterface;

class RpcMethodsList extends Base implements ResponseInterface
{
    protected(set) array $methods = [];
    protected int $total = 0;

    public function __construct(
        ?array                $values = [],
        private readonly Type $responseTypes = new Type()
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