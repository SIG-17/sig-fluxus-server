<?php

namespace SIG\Server\Protocol\Response;

use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;

class Message extends Base implements ResponseInterface
{
    protected(set) string $channel;
    protected(set) array|AbstractDescriptor $data = [] {
        set(array|AbstractDescriptor $data) {
            $this->data = $data instanceof AbstractDescriptor ? $data->toArray() : $data;
        }
    }

    public function __construct(
        ?array                $values = [],
        private readonly Type $responseTypes = new Type()
    )
    {
        if (empty($values)) {
            $values = [];
        }
        $values['type'] = $responseTypes->get('message');
        parent::__construct($values, $responseTypes);
    }

    public function isValid(): bool
    {
        return $this->type &&
            $this->type === $this->responseTypes->get('message') &&
            $this->channel &&
            $this->data && !empty($this->data instanceof AbstractDescriptor ? $this->data->getInitialized() : $this->data);
    }
}