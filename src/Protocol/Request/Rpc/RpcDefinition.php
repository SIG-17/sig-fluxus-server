<?php

namespace SIG\Server\Protocol\Request\Rpc;

use SIG\Server\Protocol\Request\Action;

class RpcDefinition extends Action
{
    CONST string PROTOCOL = 'rpc';

    protected(set) string $rpc = 'rpc';
    protected(set) string $listRpcMethods = 'list_rpc_methods';

}