<?php

namespace SIG\Server\Protocol\Request;
class Payload extends Base
{

    public function __construct(
        ?array $values = [],
        Action $requestProtocol = new Action()
    )
    {
        if (empty($values)) {
            $values = [];
        }
        if (!isset($values['action'])) {
            $values['action'] = $requestProtocol->get('publish');
        }
        parent::__construct($values, $requestProtocol);
    }
}