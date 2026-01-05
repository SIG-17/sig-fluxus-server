<?php

namespace SIG\Server\Protocol\Request\File;

use SIG\Server\Fluxus;
use SIG\Server\Protocol\Request\Action;
use SIG\Server\Protocol\Request\Base;
use SIG\Server\Protocol\Request\RequestHandlerInterface;
use Swoole\Coroutine;

class ListFiles extends Base implements RequestHandlerInterface
{

    protected(set) string $request_id;
    protected(set) array $filters;
    public function __construct(
        ?array $values = [],
        Action $protocol = new FileDefinition()
    )
    {
        if (empty($values)) {
            $values = [];
        }
        $values['action'] = $protocol->get('listFiles');
        parent::__construct($values, $protocol);
    }

    public function handle(int $fd, Fluxus $server): void
    {
        Coroutine::create(function () use ($fd, $server) {
            try {
                $result = $server->getProtocolManager('file')?->listFiles($fd, $this->toArray());
                $server->sendProtocolResponse(protocol: $this->protocol::getProtocolName(), protocolResponse: 'filesList', fd: $fd, data: $result);
            }catch (\Throwable $e){
                $server->logger?->error("File list error: {$e->getMessage()}");
                $server->sendError($fd, 'File list failed');
            }
        });
    }
}