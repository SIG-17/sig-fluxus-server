<?php

namespace SIG\Server\Protocol\Data;

use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;

class Stats extends AbstractDescriptor
{
    protected(set) int $worker_id;
    protected(set) float $execution_time;
    protected(set) int $timestamp;
    protected(set) string $server_time;
    protected(set) int $client_fd;
    protected(set) string $origin_server;
    protected(set) int $publisher;
}