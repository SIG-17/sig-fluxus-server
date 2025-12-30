<?php

namespace SIG\Server\RPC;

use SIG\Server\Collection\MethodsCollection;
use SIG\Server\Fluxus;

interface RpcPublisherInterface
{
    public function publishRpcMethods(Fluxus $server): ?MethodsCollection;

}