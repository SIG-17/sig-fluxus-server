<?php

namespace SIG\Server\Protocol\Data;

use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;

class Channel extends AbstractDescriptor
{
    protected(set) string $name;
    protected(set) string $require_role;
    protected(set) bool $required_auth = false;
    protected(set) int $subscribers = 0;
}