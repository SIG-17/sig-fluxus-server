<?php

namespace SIG\Server\Protocol\Data;

use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;

class Method extends AbstractDescriptor
{
    protected(set) string $name;
    protected(set) bool $requires_auth = false;
    protected(set) array $allowed_roles = [];
    protected(set) int $available_in_worker = 0;
    protected(set) string $description = 'RPC Method';
    protected(set) int $registered_at = 0;

/*
 * 'name' => $methodName,
                'requires_auth' => $requiresAuth,
                'allowed_roles' => $auth_roles,
                'available_in_worker' => $workerId,
                'description' => $description,
                'registered_at' => $registeredAt
 */
}