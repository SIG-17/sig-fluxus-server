<?php

namespace SIG\Server\Protocol\Response\PubSub;

use SIG\Server\Protocol\Response\Type;

class PubSubType extends Type
{
    CONST string PROTOCOL = 'file';
    protected(set) string $channelsList = 'channel_list';
}