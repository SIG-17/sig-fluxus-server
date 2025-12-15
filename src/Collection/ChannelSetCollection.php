<?php

namespace SIG\Server\Collection;

use Tabula17\Satelles\Utilis\Collection\TypedCollection;

class ChannelSetCollection extends TypedCollection
{
    protected static function getType(): string
    {
        return ChannelCollection::class;
    }
}