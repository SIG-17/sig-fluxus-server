<?php

namespace SIG\Server\Protocol\Request\File;

use SIG\Server\Fluxus;
use SIG\Server\Protocol\Data\File;
use SIG\Server\Protocol\Request\Action;
use SIG\Server\Protocol\Request\Base;
use SIG\Server\Protocol\Request\RequestHandlerInterface;
use Swoole\Coroutine;

class SendFile extends Base implements RequestHandlerInterface
{
    protected(set) string $request_id;
    protected(set) string $channel;
    protected(set) File $file {
        set(array|File $file) {
            $file = $file instanceof File ? $file : new File($file);
            $this->file = $file;
        }
    }
    protected(set) array $metadata;

    public function __construct(
        ?array $values = [],
        Action $protocol = new FileDefinition()
    )
    {
        if (empty($values)) {
            $values = [];
        }
        $values['action'] = $protocol->get('sendFile');
        parent::__construct($values, $protocol);
    }

    public function handle(int $fd, Fluxus $server): void
    {
        Coroutine::create(function () use ($fd, $server) {
            try {
                $result = $server->getProtocolManager('file')?->uploadFile($fd, $this->toArray());
                $server->sendProtocolResponse(protocol: $this->protocol::getProtocolName(), protocolResponse: 'fileUploaded', fd: $fd, data: $result);
            } catch (\Throwable $e) {
                $server->logger?->error("File upload error: {$e->getMessage()}");
                $server->sendError($fd, 'File upload failed');
            }
        });
    }

}