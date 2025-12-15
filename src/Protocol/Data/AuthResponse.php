<?php

namespace SIG\Server\Protocol\Data;

use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;

class AuthResponse extends AbstractDescriptor
{
    protected(set) array $roles = [];
    protected(set) array $permissions = [];
    protected(set) bool $authenticated = false;

}