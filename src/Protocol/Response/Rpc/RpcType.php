<?php

namespace SIG\Server\Protocol\Response\Rpc;

use SIG\Server\Protocol\Response\Type;

class RpcType extends Type
{
    protected(set) string $rpcResponse = 'rpc_response';
    protected(set) string $rpcSuccess = 'rpc_success';
    protected(set) string $rpcError = 'rpc_error';
    protected(set) string $rpcMethodsList = 'rpc_methods_list';
    protected(set) string $authResponse = 'auth_response';
    protected(set) string $rpcMethodList = 'rpc_method_list';
}