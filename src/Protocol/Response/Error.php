<?php

namespace SIG\Server\Protocol\Response;

use SIG\Server\Protocol\Response\Base;

class Error extends Base implements ResponseInterface
{
    protected(set) ?string $message;
    protected(set) ?string $code;

    public function __construct(
        ?array                $values = [],
        private readonly Type $responseTypes = new Type()
    )
    {
        if (empty($values)) {
            $values = [];
        }
        $values['type'] = $responseTypes->get('error');
        parent::__construct($values, $responseTypes);
    }

    public function isValid(): bool
    {
        return $this->type && $this->type === $this->responseTypes->get('error');
    }
}