<?php

namespace SIG\Server\Protocol;

use SIG\Server\Collection\MethodsCollection;
use SIG\Server\Config\MethodConfig;
use SIG\Server\Protocol\Request\Action;
use SIG\Server\Protocol\Response\Type;
use Swoole\Http\Request;
use Swoole\WebSocket\Server;

interface ProtocolManagerInterface
{
    public Action $protocol {
        get;
    }
    public Type $responses{
        get;
    }
    public function initializeOnStart(): void;

    public function initializeOnWorkers();
    public function runOnOpenConnection(Server $server, Request $request): void;
    public function runOnCloseConnection(Server $server, int $fd): void;
    public function cleanUpResources();

}