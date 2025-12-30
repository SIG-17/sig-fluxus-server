<?php

namespace SIG\Server\Protocol\Request;

use SIG\Server\Fluxus;
use SIG\Server\Protocol\Request\Base;
use SIG\Server\Protocol\Request\RequestHandlerInterface;
use Swoole\Coroutine;

class FileChunk extends Base implements RequestHandlerInterface
{
    protected(set) string $file_id;
    protected(set) int $chunk_index;
    protected(set) string $chunk_data;
    protected(set) bool $is_last;
    public function __construct(
        ?array $values = [],
        Action $protocol = new Action()
    )
    {
        if (empty($values)) {
            $values = [];
        }
        $values['action'] = $protocol->get('fileChunk');
        parent::__construct($values, $protocol);
    }

    public function handle(int $fd, Fluxus $server): void
    {
        Coroutine::create(function () use ($fd, $server) {
            try {
                $result = $server->getFileManager()?->processChunk($fd, $this->toArray());
                $server->sendProtocolResponse(protocolResponse: 'fileChunkReceived', fd: $fd, data: $result);
            }catch (\Throwable $e){
                $server->logger?->error("File chunk error: {$e->getMessage()}");
                $server->sendError($fd, 'File chunk failed');
            }
        });
    }
}