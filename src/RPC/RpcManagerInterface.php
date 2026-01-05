<?php

namespace SIG\Server\RPC;

use SIG\Server\Collection\MethodsCollection;
use SIG\Server\Config\MethodConfig;
use SIG\Server\Exception\InvalidArgumentException;
use SIG\Server\Protocol\ProtocolManagerInterface;
use Swoole\Table;

interface RpcManagerInterface extends ProtocolManagerInterface
{

    public function getRpcMethods(): Table;

    /**
     * Envía error RPC al cliente
     */
    public function sendRpcError(int $fd, string $requestId, string $error, int $code = 500): void;

    /**
     * Genera ID único para RPC
     */
    public function generateRpcId(): string;

    /**
     * Ejecuta un método RPC en una corutina separada con timeout
     * @throws InvalidArgumentException
     */
    public function executeRpcMethod(int $fd, string $requestId, string $method, array $params, int $timeout, int $workerId): void;

    /**
     * Registra un método RPC (puede ser llamado por múltiples workers)
     */
    public function registerRpcMethod(MethodConfig $method): bool;

    public function registerRpcMethods(MethodsCollection $collection): void;

    public function getRpcMethodInfo(string $methodName): array;

    public function getRpcMethodsInfo(): array;

    public function registerInternalRpcProcessor(string $processorName, RpcInternalProcessorInterface $processor): void;

    public function getInternalRpcProcessor(string $processorName): ?RpcInternalProcessorInterface;

    public function listInternalRpcProcessors(): array;
}