<?php

namespace SIG\Server\Config;

use Closure;
use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;

class MethodConfig extends AbstractDescriptor
{
    protected(set) string $method;
    protected(set) Closure $handler;
    protected(set) bool $requires_auth = false;
    protected(set) array $allowed_roles = [];
    protected(set) string $description = 'RPC Method';
    protected(set) bool $only_internal = false;
}
/*
 *
        string   $method,
        callable $handler,
        bool     $requiresAuth = false,
        array    $allowed_roles = ['ws:general'],
        string   $description = '',
        bool     $only_internal = false
 */