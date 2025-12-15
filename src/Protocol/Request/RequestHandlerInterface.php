<?php

namespace SIG\Server\Protocol\Request;

use SIG\Server\Fluxus;

interface RequestHandlerInterface
{
    public function handle(int $fd, Fluxus $server): void;
}