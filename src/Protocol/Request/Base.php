<?php

namespace SIG\Server\Protocol\Request;

use SIG\Server\Exception\UnexpectedValueException;
use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;

class Base extends AbstractDescriptor
{

    protected(set) string $action {
        set(string $action) {
            $actions = $this->protocol instanceof Action ? $this->protocol->toArray() : $this->protocol;
            if (!in_array($action, $actions)) {
                throw new UnexpectedValueException('Invalid action: ' . $action . '. Must be one of: ' . implode(', ', $actions));
            }
            $this->action = $action;
        }
    }
    //protected(set) string $channel;
    //protected(set) ?string $message;
    /*protected(set) ?string $token;
    protected(set) array|AbstractDescriptor $data = [] {
        set(array|AbstractDescriptor $data) {
            $this->data = $data instanceof AbstractDescriptor ? $data->toArray() : $data;
        }
    }*/

    public function __construct(
        ?array                                    $values = [],
        private readonly array|Action $protocol = new Action()
    )
    {
        parent::__construct($values);
    }
}