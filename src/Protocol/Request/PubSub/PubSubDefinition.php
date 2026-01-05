<?php

namespace SIG\Server\Protocol\Request\PubSub;

use SIG\Server\Protocol\Request\Action;

class PubSubDefinition extends Action
{
    const string PROTOCOL = 'pubsub';
    protected(set) string $subscribe = 'subscribe';
    protected(set) string $unsubscribe = 'unsubscribe';
    protected(set) string $publish = 'publish';
    protected(set) string $listChannels = 'list_channels';
}