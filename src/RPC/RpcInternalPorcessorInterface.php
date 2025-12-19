<?php

namespace SIG\Server\RPC;

use SIG\Server\Collection\MethodsCollection;
use SIG\Server\Fluxus;

interface RpcInternalPorcessorInterface
{
    public function methodsToRegister(Fluxus $server): ?MethodsCollection;
    public function init(Fluxus $server): void;
    public function deInit(Fluxus $server): void;
    public function process(string $method, array $params): mixed;

}