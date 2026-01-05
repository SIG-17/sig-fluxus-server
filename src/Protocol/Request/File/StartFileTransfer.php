<?php

namespace SIG\Server\Protocol\Request\File;

use SIG\Server\Fluxus;
use SIG\Server\Protocol\Request\Action;
use SIG\Server\Protocol\Request\Base;
use SIG\Server\Protocol\Request\RequestHandlerInterface;
use Swoole\Coroutine;

class StartFileTransfer extends Base implements RequestHandlerInterface
{
    protected(set) string $file_id;
    protected(set) string $file_name;
    protected(set) string $file_type;
    protected(set) int $file_size;
    protected(set) int $total_chunks;
    protected(set) string $channel;
    protected(set) array $metadata;

    public function __construct(
        ?array $values = [],
        Action $protocol = new FileDefinition()
    )
    {
        if (empty($values)) {
            $values = [];
        }
        $values['action'] = $protocol->get('startFileTransfer');
        parent::__construct($values, $protocol);
    }

    public function handle(int $fd, Fluxus $server): void
    {
        Coroutine::create(function () use ($fd, $server) {
            try {
                $result = $server->getProtocolManager('file')?->initiateTransfer($fd, $this->toArray());
                $server->sendProtocolResponse(protocol: $this->protocol::getProtocolName(), protocolResponse: 'transferStarted', fd: $fd, data: $result);
            } catch (\Throwable $e) {
                $server->logger?->error("File transfer error: {$e->getMessage()}");
                $server->sendError($fd, 'File transfer failed');
            }
        });
    }
}