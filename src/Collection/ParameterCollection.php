<?php

namespace SIG\Server\Collection;

use SIG\Server\Config\ParameterConfig;
use Tabula17\Satelles\Utilis\Collection\TypedCollection;

class ParameterCollection extends TypedCollection
{

    protected static function getType(): string
    {
        return ParameterConfig::class;
    }
}