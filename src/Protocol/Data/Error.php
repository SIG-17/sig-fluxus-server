<?php

namespace SIG\Server\Protocol\Data;

use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;

class Error extends AbstractDescriptor
{
    protected(set) int $code;
    protected(set) string $message;
}