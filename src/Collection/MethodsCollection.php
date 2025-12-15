<?php

namespace SIG\Server\Collection;

use SIG\Server\Config\MethodConfig;
use Tabula17\Satelles\Utilis\Collection\TypedCollection;

class MethodsCollection extends TypedCollection
{

    protected static function getType(): string
    {
        return MethodConfig::class;
    }
}