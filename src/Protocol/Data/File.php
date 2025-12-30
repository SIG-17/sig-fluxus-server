<?php

namespace SIG\Server\Protocol\Data;

use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;

class File extends AbstractDescriptor
{
    protected(set) string $name;
    protected(set) string $type;
    protected(set) int $size;
    protected(set) string $data;

    public function isValid(): bool
    {
        return isset($this->name, $this->type, $this->size, $this->data);
    }

}