<?php

namespace SIG\Server\Protocol\Request;
use SIG\Server\Exception\UnexpectedValueException;
use SIG\Server\Fluxus;
class Subscribe extends Base implements RequestHandlerInterface
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
        $values['action'] = $protocol->get('subscribe');
        parent::__construct($values, $protocol);
    }

    public function handle(int $fd, Fluxus $server): void
    {
        if (empty($this->channel)) {
            $server->sendError($fd, 'Nombre de canal requerido');
            return;
        }
        try {
            $server->subscribeToChannel($fd, $this->channel);
            $server->sendSuccess($fd, "Suscrito al canal: $this->channel");
        } catch (\Throwable $e) {
            $server->logger?->error("Error en conexión #$fd suscribiendo al canal $this->channel: {$e->getMessage()}");
            $server->sendError($fd, $e->getMessage());
        }
    }
}