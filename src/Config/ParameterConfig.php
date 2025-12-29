<?php

namespace SIG\Server\Config;

use Closure;
use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;

class ParameterConfig extends AbstractDescriptor
{

    protected(set) string $name;
    protected(set) string $type = 'string';
    protected(set) string $description = 'RPC parameter';
    protected(set) mixed $default = null;
    protected(set) bool $required = false;
    protected(set) mixed $example = null;
    protected(set) string|null|Closure $validation = null;
    protected(set) array $enum;
    protected(set) bool $injected = false;

}
/*
 *
        'name' => $name,
        'type' => $type,
        'description' => '',
        'required' => true,
        'default' => null,
        'example' => null,
        'validation' => null, // Regex o callback
        'enum' => [], // Valores permitidos,
                            'injected' => false
 */