<?php

namespace SIG\Server\RPC;

use SIG\Server\Collection\MethodsCollection;
use SIG\Server\Fluxus;

interface RpcInternalProcessorInterface extends RpcPublisherInterface
{
    public function init(Fluxus $server): void;
    public function deInit(Fluxus $server): void;
    public function process(string $method, array $params): mixed;

}