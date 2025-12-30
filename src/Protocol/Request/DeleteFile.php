<?php

namespace SIG\Server\Protocol\Request;

use SIG\Server\Fluxus;
use SIG\Server\Protocol\Request\Base;
use SIG\Server\Protocol\Request\RequestHandlerInterface;
use Swoole\Coroutine;

class DeleteFile extends Base implements RequestHandlerInterface
{

        protected(set) string $file_id;
        public function __construct(
            ?array $values = [],
            Action $protocol = new Action()
        )
        {
            if (empty($values)) {
                $values = [];
            }
            $values['action'] = $protocol->get('deleteFile');
            parent::__construct($values, $protocol);
        }

        public function handle(int $fd, Fluxus $server): void
        {
            Coroutine::create(function () use ($fd, $server) {
                try {
                    $result = $server->getFileManager()?->deleteFile($fd, $this->toArray());
                    $server->sendProtocolResponse(protocolResponse: 'fileDeleted', fd: $fd, data: $result);
                }catch (\Throwable $e){
                    $server->logger?->error("File delete error: {$e->getMessage()}");
                    $server->sendError($fd, 'File delete failed');
                }
            });
        }
}