<?php

namespace SIG\Server\Collection;

use SIG\Server\Config\ChannelConfig;
use Tabula17\Satelles\Utilis\Collection\TypedCollection;

class ChannelCollection extends TypedCollection
{
    protected static function getType(): string
    {
        return ChannelConfig::class;
    }
}