<?php

namespace SIG\Server\Protocol\Response\PubSub;

use SIG\Server\Protocol\Response\Base;
use SIG\Server\Protocol\Response\ResponseInterface;
use SIG\Server\Protocol\Response\Type;

class ChannelsList extends Base implements ResponseInterface
{
    protected(set) array $channels = [];

    public function __construct(
        ?array                $values = [],
        private readonly Type $responseTypes = new PubSubType()
    )
    {
        if (empty($values)) {
            $values = [];
        }
        $values['type'] = $responseTypes->get('channelList');;
        parent::__construct($values, $responseTypes);
    }

    public function isValid(): bool
    {
        return $this->type && $this->type === $this->responseTypes->get('channelList');
    }
}