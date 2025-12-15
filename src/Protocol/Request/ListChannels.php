<?php

namespace SIG\Server\Protocol\Request;

use SIG\Server\Exception\UnexpectedValueException;
use SIG\Server\Fluxus;
use SIG\Server\Protocol\Data\Channel;
use SIG\Server\Protocol\Request\Base;
use SIG\Server\Protocol\Request\RequestHandlerInterface;
use SIG\Server\Protocol\Response\ResponseInterface;

class ListChannels extends Base implements RequestHandlerInterface
{

    public function __construct(
        ?array $values = [],
        Action $protocol = new Action()
    )
    {
        if (empty($values)) {
            $values = [];
        }
        $values['action'] = $protocol->get('list_channels');
        parent::__construct($values, $protocol);
    }

    /**
     * @throws UnexpectedValueException
     */
    public function handle(int $fd, Fluxus $server): void
    {
        $channels = [];
        foreach ($server->channels as $channel) {
            $channels[] = new Channel([
                'name' => $channel['name'],
                'subscribers' => $channel['subscriber_count'],
                'created_at' => $channel['created_at']
            ]);
        }

        $response = $server->responseProtocol->getProtocolFor([
            'type' => $server->responseProtocol->get('channelsList'),
            'channels' => $channels
        ]);
        if ($response instanceof ResponseInterface && $response->isValid()) {
            $server->sendToClient($fd, $response);
        } else {
            $server->logger?->error('Error sending list channels response');
            $server->sendError($fd, 'Error sending list channels response');
        }


    }
}