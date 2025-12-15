<?php

namespace SIG\Server\Config;

use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;

class ChannelConfig extends AbstractDescriptor
{
    protected(set) string $name;
    protected(set) bool $required_auth = false;

    protected(set) string $description;
    protected(set) bool $auto_subscribe = false;
    protected(set) string $required_role {
        set(?string $value) {
            if (!empty($value)) {
                $this->required_auth = true;
            }
            $this->required_role = $value;
        }
    }

}