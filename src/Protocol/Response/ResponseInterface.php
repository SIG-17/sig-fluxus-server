<?php

namespace SIG\Server\Protocol\Response;

interface ResponseInterface
{
    public function isValid(): bool;
}