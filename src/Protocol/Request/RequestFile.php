<?php

namespace SIG\Server\Protocol\Request;

use SIG\Server\Fluxus;
use SIG\Server\Protocol\Request\Base;
use SIG\Server\Protocol\Request\RequestHandlerInterface;
use Swoole\Coroutine;

class RequestFile extends Base implements RequestHandlerInterface
{

    protected(set) string $request_id;
    protected(set) string $file_id;
    protected(set) string $file_name;
    public function __construct(
        ?array $values = [],
        Action $protocol = new Action()
    )
    {
        if (empty($values)) {
            $values = [];
        }
        $values['action'] = $protocol->get('requestFile');
        parent::__construct($values, $protocol);
    }

    public function handle(int $fd, Fluxus $server): void
    {
        Coroutine::create(function () use ($fd, $server) {
            try {
                $result = $server->getFileManager()?->getFile($fd, $this->toArray());
                $server->sendProtocolResponse(protocolResponse: 'fileResponse', fd: $fd, data: $result);
            }catch (\Throwable $e){
                $server->logger?->error("File requested error: {$e->getMessage()}");
                $server->sendError($fd, 'File requested failed');
            }
        });
    }
}