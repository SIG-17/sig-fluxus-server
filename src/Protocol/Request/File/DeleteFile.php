<?php

namespace SIG\Server\Protocol\Request\File;

use SIG\Server\File\FileManager;
use SIG\Server\Fluxus;
use SIG\Server\Protocol\Request\Action;
use SIG\Server\Protocol\Request\Base;
use SIG\Server\Protocol\Request\RequestHandlerInterface;
use Swoole\Coroutine;

class DeleteFile extends Base implements RequestHandlerInterface
{

        protected(set) string $file_id;
        public function __construct(
            ?array $values = [],
            Action $protocol = new FileDefinition()
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
            $protocol = $this->protocol;
            Coroutine::create(function () use ($fd, $server, $protocol) {
                try {
                    /**@var FileManager $manager */
                    $manager = $server->getProtocolManager('file');
                    if(!$manager){
                        return;
                    }
                    $result = $manager?->deleteFile($fd, $this->toArray());
                    $server->sendProtocolResponse(
                        protocol: $protocol::getProtocolName(),
                        protocolResponse: 'fileDeleted', fd: $fd, data: $result);
                }catch (\Throwable $e){
                    $server->logger?->error("File delete error: {$e->getMessage()}");
                    $server->sendError($fd, 'File delete failed');
                }
            });
        }
}