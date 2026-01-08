<?php

namespace SIG\Server\Config;

use Closure;
use SIG\Server\Collection\ParameterCollection;
use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;

class MethodConfig extends AbstractDescriptor
{
    protected(set) string $method;
    protected(set) Closure $handler {
        set(callable|Closure $handler) {
            $this->handler = $handler instanceof Closure ? $handler : $handler(...);
        }
    }
    protected(set) bool $requires_auth = false;
    protected(set) array $allowed_roles = ['ws:general'];
    protected(set) string $description = 'RPC Method';
    protected(set) bool $only_internal = false;
    protected(set) bool $coroutine = true;
    protected(set) ParameterCollection $parameters
        {
            set(ParameterCollection|array $parameters) {
                $this->parameters = is_array($parameters) ? ParameterCollection::fromArray($parameters) : $parameters;
            }
        }
    protected(set) array $returns = []
        {
            set {
                $default = [
                    'type' => 'mixed',
                    'description' => '',
                    'structure' => []
                ];
                $this->returns = array_merge($default, $value);
            }
        }
    protected(set) array $examples = [];
    protected(set) bool $deprecated = false;
    protected(set) string $since = '0.0.1'
        {
            set {
                if (version_compare($value, '0.0.1', '>=')) {
                    $this->since = $value;
                }
            }
        }
}
/*
 *
        string   $method,
        callable $handler,
        bool     $requiresAuth = false,
        array    $allowed_roles = ['ws:general'],
        string   $description = '',
        bool     $only_internal = false


'method' => '',
        'handler' => null,
        'requires_auth' => false,
        'allowed_roles' => ['ws:general'],
        'description' => '',
        'only_internal' => false,
        'parameters' => [],
        'returns' => [
            'type' => 'mixed',
            'description' => '',
        ],
        'examples' => [],
        'deprecated' => false,
        'since' => '1.0.0',
 */