<?php

namespace SIG\Server\Protocol\Response;

class Success extends Base implements ResponseInterface
{
    protected(set) ?string $message;

    public function __construct(
        ?array $values = [],
        private readonly Type   $responseTypes = new Type()
    )
    {
        if (empty($values)) {
            $values = [];
        }
        $values['type'] = $responseTypes->get('success');
        parent::__construct($values, $responseTypes);
    }

    public function isValid(): bool
    {
       return $this->type && $this->type === $this->responseTypes->get('success');
    }
}