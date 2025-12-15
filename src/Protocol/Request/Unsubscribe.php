<?php

namespace SIG\Server\Protocol\Request;

use SIG\Server\Fluxus;

class Unsubscribe extends Base implements RequestHandlerInterface
{
    protected(set) string $channel;

    public function __construct(
        ?array $values = [],
        Action $protocol = new Action()
    )
    {
        if (empty($values)) {
            $values = [];
        }
        $values['action'] = $protocol->get('unsubscribe');
        parent::__construct($values, $protocol);
    }
    public function handle(int $fd, Fluxus $server): void
    {
        if (empty($this->channel)) {
            $server->sendError($fd, 'Nombre de canal requerido');
            return;
        }

        $server->unsubscribeFromChannel($fd, $this->channel);
        $server->sendSuccess($fd, "Desuscrito del canal: $this->channel");
    }
}