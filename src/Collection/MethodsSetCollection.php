<?php

namespace SIG\Server\Collection;

use Tabula17\Satelles\Utilis\Collection\TypedCollection;

class MethodsSetCollection extends TypedCollection
{
    protected static function getType(): string
    {
        return MethodsCollection::class;
    }
}