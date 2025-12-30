<?php

namespace SIG\Server\Protocol\Response;

use SIG\Server\Protocol\Response\Base;
use SIG\Server\Protocol\Response\ResponseInterface;

class FileChunkReceived extends Base implements ResponseInterface
{
    protected(set) bool $success = true;
    protected(set) int $chunk_index;
    protected(set) int $received_chunks;
    protected(set) int $total_chunks;
    protected(set) float $progress;


    public function __construct(
        ?array $values = [],
        private readonly Type   $responseTypes = new Type()
    )
    {
        if (empty($values)) {
            $values = [];
        }
        $values['type'] = $responseTypes->get(lcfirst(substr(strrchr(get_class($this), '\\'), 1)));
        parent::__construct($values, $responseTypes);
    }
    public function isValid(): bool
    {
        return $this->type && $this->type === $this->responseTypes->get(lcfirst(substr(strrchr(get_class($this), '\\'), 1)));
    }
}