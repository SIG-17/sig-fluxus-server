<?php

namespace SIG\Server\RPC;

use JsonException;
use PDO;
use Psr\Log\LoggerInterface;
use SIG\Server\Fluxus;
use Tabula17\Satelles\Omnia\Roga\Database\Connector;
use Tabula17\Satelles\Omnia\Roga\Database\DbConfig;
use Tabula17\Satelles\Omnia\Roga\Database\DbConfigCollection;
use Tabula17\Satelles\Omnia\Roga\Database\RequestDescriptor;
use Tabula17\Satelles\Omnia\Roga\Exception\ConfigException;
use Tabula17\Satelles\Omnia\Roga\Exception\ConnectionException;
use Tabula17\Satelles\Omnia\Roga\Exception\ExceptionDefinitions;
use Tabula17\Satelles\Omnia\Roga\Exception\StatementExecutionException;
use Tabula17\Satelles\Omnia\Roga\LoaderInterface;
use Tabula17\Satelles\Omnia\Roga\LoaderStorageInterface;
use Tabula17\Satelles\Omnia\Roga\StatementBuilder;
use Tabula17\Satelles\Utilis\Connectable\HealthManagerInterface;
use Tabula17\Satelles\Utilis\Exception\InvalidArgumentException;
use Tabula17\Satelles\Utilis\Exception\RuntimeException;
use Throwable;

class DbProcessor implements RpcInternalPorcessorInterface
{
    /**
     * @param Connector $connector
     * @param DbConfigCollection $poolCollection
     * @param LoaderStorageInterface $loaderStorage
     * @param LoggerInterface|null $logger
     * @param LoggerInterface|null $db_logger
     */
    public function __construct(
        public Connector                         $connector,
        public DbConfigCollection                $poolCollection,
        public LoaderStorageInterface            $loaderStorage,
        public ?LoggerInterface                  $logger = null,
        private readonly ?LoggerInterface        $db_logger = null,
        public readonly ?HealthManagerInterface $healthManager = null
    )
    {
    }

    /**
     * @throws ConnectionException
     * @throws ConfigException
     * @throws Throwable
     * @throws StatementExecutionException
     * @throws JsonException
     */
    public function process(string $method, array $params): array
    {
        $method = lcfirst(str_replace(' ', '', ucwords(preg_replace('/[^a-zA-Z]+/', ' ', $method))));
        $this->logger?->debug('Searching for method ' . $method . ' in DbProcessor');
        if (method_exists($this, $method)) {
            return $this->{$method}($params);
        }
        throw new \InvalidArgumentException("Method $method not found");
    }

    /**
     * @throws JsonException
     */
    private function getRequestAndStatementBuilder(array $params): array
    {
        $request = new RequestDescriptor($params);
        $builder = new StatementBuilder(
            statementName: $request->cfg,
            loader: $this->loaderStorage->getLoader(),
            reload: $request->forceReload
        );
        return [$request, $builder];
    }

    /**
     * @throws ConnectionException
     * @throws ConfigException
     * @throws Throwable
     * @throws StatementExecutionException
     * @throws JsonException
     */
    private function dbProcessor(array $params): array
    {
        /**
         * @var RequestDescriptor $request
         * @var StatementBuilder $builder
         */
        [$request, $builder] = $this->getRequestAndStatementBuilder($params);
        $identifier = $request->getFor();
        $this->logger?->debug('Buscando statement para ' . implode(': ', $identifier));
        $builder->loadStatementBy(...$identifier)?->setValues($request->params ?? []);
        $descriptor = $builder->getDescriptorBy(...$identifier);
        if ($descriptor === null) {
            throw new \InvalidArgumentException(sprintf(ExceptionDefinitions::STATEMENT_NOT_FOUND_FOR_VARIANT->value, implode(': ', $identifier), $request->cfg ?? ''));
        }
        $this->logger?->debug('Buscando conexión para ' . $builder->getMetadataValue('connection'));
        /** @var PDO $conn */
        $conn = $this->connector->getConnection($builder->getMetadataValue('connection'));
        if (!isset($conn)) {
            throw new ConnectionException(sprintf(ExceptionDefinitions::POOL_NOT_FOUND->value, $builder->getMetadataValue('connection')), 500);
        }
        try {
            $stmt = $conn->prepare($builder->getStatement());
            $this->logger?->debug('Statement generado: ' . $builder->getPrettyStatement());
            foreach ($builder->getBindings() as $key => $value) {
                $this->logger?->debug('Binding: ' . $key . ' => ' . $value);
                $stmt->bindValue($key, $value, $builder->getParamType($key)); //bindValue($key, $value);
            }
            try {
                $stmt->execute();
            } catch (Throwable $e) {
                $this->logger?->error('Error executing statement: ' . $e->getMessage());
                $server->db_logger?->error($builder->getPrettyStatement(), [
                    'error' => $e->getMessage(),
                    'connection' => $server->connector->getPoolStats($builder->getMetadataValue('connection')),
                    'bindings' => $builder->getBindings(),
                    'builderParams' => $builder->getParams() ?? [],
                    'request' => $request->toArray()
                ]);
                throw new StatementExecutionException($e->getMessage(), 500, $e);
            }
            $result = [];
            if ($descriptor->canHaveResultset() || $stmt->columnCount() > 0) {
                $this->logger?->debug('Statement have resultset: FETCHING');
                $result[] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Manejar múltiples resultsets (stored procedures)
                while ($stmt->nextRowset()) {
                    if ($stmt->columnCount() > 0) {
                        $result[] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                }

                $multiRowset = count($result) > 1;
                if ($multiRowset) {
                    $this->logger?->debug('Statement have multiple resultsets: ' . count($result));
                }
                $result = $multiRowset ? $result : $result[0];
                $total = $multiRowset ? array_sum(array_map('count', $result)) : count($result);
                $this->logger?->debug('Statement have resultset with: ' . $total . ' rows');
            } else {
                $this->logger?->debug('Statement have no resultset: ' . $stmt->rowCount());
                // Para consultas sin resultados
                $affectedRows = $stmt->rowCount();

                if ($descriptor->isInsert()) {
                    $lastInsertId = $conn->lastInsertId();
                    if ($lastInsertId !== false && $lastInsertId !== '0') {
                        $result['lastInsertId'] = $lastInsertId;
                    }
                }

                $result['affectedRows'] = $affectedRows;
                $total = $affectedRows;
            }

            $this->db_logger?->info($builder->getPrettyStatement(), [
                    'connection' => $this->connector->getPoolStats($builder->getMetadataValue('connection')),
                    'bindings' => $builder->getBindings(),
                    'requiredParams' => $builder->getRequiredParams() ?? [],
                    'request' => $request->toArray(),
                    'total' => $total,
                    'multiRowset' => $multiRowset ?? false
                ]
            );
            // Respuesta
            $response = [
                'data' => $result,
                'status' => 200,
                'message' => $builder->getMetadataValue('operation') . ': OK',
                'total' => $total
            ];

            if (isset($multiRowset) && $multiRowset === true) {
                $response['multiRowset'] = true;
                $response['resultsets'] = count($result);
            }
            return $response;

        } catch (Throwable $e) {
            $env = [
                'error' => $e->getMessage(),
                'connection' => $this->connector->getPoolStats($builder->getMetadataValue('connection')),
                'bindings' => $builder->getBindings(),
                'requiredParams' => $builder->getRequiredParams() ?? [],
                'request' => $request->toArray()
            ];
            $this->db_logger?->error($builder->getPrettyStatement(), $env);
            throw $e;
        } finally {
            $this->connector->putConnection($conn);
        }
    }

    /**
     * @throws RuntimeException
     * @throws ConfigException
     * @throws JsonException
     */
    private function dbGetStatementFor(array $params): array
    {
        /**
         * @var RequestDescriptor $request
         * @var StatementBuilder $builder
         */
        [$request, $builder] = $this->getRequestAndStatementBuilder($params);
        $identifier = $request->getFor();
        $this->logger?->debug('Buscando statement para ' . implode(': ', $identifier));
        $builder->loadStatementBy(...$identifier)?->setValues($request->params ?? []);
        $descriptor = $builder->getDescriptorBy(...$identifier);
        if ($descriptor === null) {
            throw new \InvalidArgumentException(sprintf(ExceptionDefinitions::STATEMENT_NOT_FOUND_FOR_VARIANT->value, implode(': ', $identifier), $request->cfg ?? ''));
        }
        $this->logger?->debug('Buscando conexión para ' . $builder->getMetadataValue('connection'));

        return [
            'cfg' => (string)$request->cfg,
            'statement' => $builder->getStatement(),
            'prettyStatement' => $builder->getPrettyStatement(),
            'bindings' => $builder->getBindings(),
            'requiredParams' => $builder->getRequiredParams() ?? [],
            'optionalParams' => $builder->getOptionalParams() ?? [],
            'sentParams' => $request->params ?? [],
            'descriptor' => $descriptor->toArray(),
            'connection' => $builder->getMetadataValue('connection'),
            'operation' => $builder->getMetadataValue('operation')

        ];
    }

    private function dbPoolList(): array
    {
        return $this->connector->getPoolStats();
    }

    private function dbPoolInfo(array $params): array
    {
        return $this->connector->getPoolStats($params['name']);
    }

    private function dbStatementList(array $params): array
    {
        return $this->loaderStorage->listAvailableStatements();
    }

    private function dbStatementInfo(array $params): array
    {
        return $this->loaderStorage->getStatementInfo($params['cfg']);
    }

    public function init(Fluxus $server): void
    {
        try {
            $this->connector->loadConnections($this->poolCollection);
            foreach ($this->connector->getPoolGroupNames() as $poolGroupName) {
                $this->logger?->info("Pool group $poolGroupName loaded");
            }
            if ($this->connector->getUnreachableConnections()->count() > 0) {
                /** @var DbConfig $connection */
                foreach ($this->connector->getUnreachableConnections() as $connection) {
                    $this->logger?->notice("Connection {$connection->name} unreachable: " . $connection->lastConnectionError ?? 'Unknown error');
                }
            }
            $this->healthManager?->startHealthCheckCycle($server, $server->getWorkerId());
        } catch (Throwable $e) {
            $this->logger?->error('Error inicializando DB Processor: ' . $e->getMessage());
        }
    }

    public function deInit(Fluxus $server): void
    {
        $logger = $server->logger ?? $this->logger;
        $logger?->info('Closing ALL DB Processor connections...');
        $this->healthManager?->stopHealthCheckCycle(1);
        $this->connector->closeAllPools();
    }
}