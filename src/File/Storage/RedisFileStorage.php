<?php

namespace SIG\Server\File\Storage;

use SIG\Server\File\FileStorageInterface;
use SIG\Server\Fluxus;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

class RedisFileStorage implements FileStorageInterface
{
    private const string PREFIX = 'file:';
    private const string CHUNK_PREFIX = 'chunk:';
    private const string TRANSFER_PREFIX = 'transfer:';
    private const string LIST_PREFIX = 'files:list';

    public function saveFile(
        Fluxus $server,
        string $fileId,
        string $fileName,
        string $fileType,
        int $fileSize,
        string $content,
        array $metadata = []
    ): array
    {
        // Usar un channel para obtener el resultado
        $channel = new Channel(1);

        go(function () use ($server, $fileId, $fileName, $fileType, $fileSize, $content, $metadata, $channel) {
            try {
                $redis = $server->redis();

                // Guardar metadata
                $fileData = [
                    'id' => $fileId,
                    'name' => $fileName,
                    'type' => $fileType,
                    'size' => $fileSize,
                    'created_at' => time(),
                    'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    'status' => 'complete',
                    'channel' => $metadata['channel'] ?? '',
                    'fd' => $metadata['fd'] ?? 0
                ];

                // Usar pipeline para atomicidad
                $redis->pipeline()
                    ->hMSet(self::PREFIX . $fileId, $fileData)
                    ->set(self::PREFIX . $fileId . ':content', $content)
                    ->expire(self::PREFIX . $fileId, 86400) // 24 horas
                    ->expire(self::PREFIX . $fileId . ':content', 86400)
                    ->exec();

                // Agregar a lista de archivos
                $listKey = self::LIST_PREFIX . ':' . date('Y-m-d');
                $redis->zAdd($listKey, time(), $fileId);
                $redis->expire($listKey, 86400 * 7);

                $channel->push([
                    'success' => true,
                    'file_id' => $fileId,
                    'file_name' => $fileName,
                    'file_size' => $fileSize,
                    'stored_at' => time()
                ]);

            } catch (\Exception $e) {
                $server->logger?->error('Redis save error: ' . $e->getMessage());
                $channel->push(['success' => false, 'error' => $e->getMessage()]);
            } finally {
                $channel->close();
            }
        });

        // Esperar resultado con timeout
        $result = $channel->pop(5.0); // 5 segundos timeout
        return $result ?? ['success' => false, 'error' => 'Timeout saving file'];
    }

    public function saveChunk(
        Fluxus $server,
        string $fileId,
        int $chunkIndex,
        string $chunkData,
        bool $isLast = false
    ): bool
    {
        $channel = new Channel(1);

        go(function () use ($server, $fileId, $chunkIndex, $chunkData, $isLast, $channel) {
            try {
                $redis = $server->redis();
                $chunkKey = self::CHUNK_PREFIX . $fileId . ':' . $chunkIndex;

                // Guardar chunk
                $redis->setex($chunkKey, 3600, $chunkData);

                // Actualizar contador en hash
                $transferKey = self::TRANSFER_PREFIX . $fileId;
                $redis->hIncrBy($transferKey, 'received_chunks', 1);
                $redis->hSet($transferKey, 'last_chunk_at', time());
                $redis->expire($transferKey, 3600);

                // Si es el último, marcar como completo
                if ($isLast) {
                    $redis->hSet($transferKey, 'complete', 1);
                    $redis->hSet($transferKey, 'completed_at', time());
                }

                // Actualizar bitmap
                $bitmapKey = self::TRANSFER_PREFIX . $fileId . ':bitmap';
                $bitmapIndex = (int)floor($chunkIndex / 8);
                $bitPosition = $chunkIndex % 8;

                $currentByte = $redis->getRange($bitmapKey, $bitmapIndex, $bitmapIndex);
                $currentByte = $currentByte === false ? 0 : ord($currentByte);
                $newByte = $currentByte | (1 << $bitPosition);
                $redis->setRange($bitmapKey, $bitmapIndex, chr($newByte));
                $redis->expire($bitmapKey, 3600);

                $channel->push(true);

            } catch (\Exception $e) {
                $server->logger?->error('Chunk save error: ' . $e->getMessage());
                $channel->push(false);
            } finally {
                $channel->close();
            }
        });

        $result = $channel->pop(2.0); // 2 segundos timeout
        return $result ?? false;
    }

    public function getFile(
        Fluxus $server,
        string $fileId
    ): ?array
    {
        // Método síncrono - no necesita corutina
        try {
            $redis = $server->redis();

            // Obtener metadata
            $metadata = $redis->hGetAll(self::PREFIX . $fileId);
            if (empty($metadata)) {
                return null;
            }

            // Obtener contenido
            $content = $redis->get(self::PREFIX . $fileId . ':content');
            if ($content === false) {
                return null;
            }

            // Parsear metadata JSON
            if (isset($metadata['metadata'])) {
                $metadata['metadata'] = json_decode($metadata['metadata'], true) ?? [];
            }

            return [
                'metadata' => $metadata,
                'content' => $content
            ];

        } catch (\Exception $e) {
            $server->logger?->error('Get file error: ' . $e->getMessage());
            return null;
        }
    }

    public function listFiles(
        Fluxus $server,
        array $filters = []
    ): array
    {
        // Método síncrono
        try {
            $redis = $server->redis();
            $result = [];

            $date = $filters['date'] ?? date('Y-m-d');
            $channel = $filters['channel'] ?? null;
            $limit = $filters['limit'] ?? 100;
            $offset = $filters['offset'] ?? 0;
            $type = $filters['type'] ?? null;

            $listKey = self::LIST_PREFIX . ':' . $date;

            // Obtener IDs de archivos
            $fileIds = $redis->zRevRange($listKey, $offset, $offset + $limit - 1);

            foreach ($fileIds as $fileId) {
                $metadata = $redis->hGetAll(self::PREFIX . $fileId);

                if (empty($metadata)) {
                    continue;
                }

                // Aplicar filtros
                if ($channel && ($metadata['channel'] ?? '') !== $channel) {
                    continue;
                }

                if ($type && ($metadata['type'] ?? '') !== $type) {
                    continue;
                }

                // Parsear metadata
                if (isset($metadata['metadata'])) {
                    $metadata['metadata'] = json_decode($metadata['metadata'], true) ?? [];
                }

                $result[] = [
                    'id' => $fileId,
                    'name' => $metadata['name'] ?? '',
                    'type' => $metadata['type'] ?? '',
                    'size' => (int)($metadata['size'] ?? 0),
                    'created_at' => (int)($metadata['created_at'] ?? 0),
                    'status' => $metadata['status'] ?? 'unknown',
                    'channel' => $metadata['channel'] ?? '',
                    'metadata' => $metadata['metadata'] ?? []
                ];
            }

            $total = $redis->zCard($listKey);

            return [
                'files' => $result,
                'total' => $total,
                'page' => $limit > 0 ? (int)($offset / $limit) + 1 : 1,
                'pages' => $limit > 0 ? (int)ceil($total / $limit) : 0,
                'date' => $date
            ];

        } catch (\Exception $e) {
            $server->logger?->error('List files error: ' . $e->getMessage());
            return ['files' => [], 'total' => 0, 'error' => $e->getMessage()];
        }
    }

    public function deleteFile(
        Fluxus $server,
        string $fileId
    ): bool
    {
        $channel = new Channel(1);

        go(function () use ($server, $fileId, $channel) {
            try {
                $redis = $server->redis();

                // Obtener metadata para saber la fecha
                $metadata = $redis->hGetAll(self::PREFIX . $fileId);
                $createdDate = isset($metadata['created_at'])
                    ? date('Y-m-d', $metadata['created_at'])
                    : date('Y-m-d');

                // Eliminar
                $redis->pipeline()
                    ->del(self::PREFIX . $fileId)
                    ->del(self::PREFIX . $fileId . ':content')
                    ->zRem(self::LIST_PREFIX . ':' . $createdDate, $fileId)
                    ->exec();

                // Limpiar transferencia si existe
                $this->cleanupTransfer($server, $fileId);

                $server->logger?->info("File deleted: {$fileId}");
                $channel->push(true);

            } catch (\Exception $e) {
                $server->logger?->error('Delete file error: ' . $e->getMessage());
                $channel->push(false);
            } finally {
                $channel->close();
            }
        });

        $result = $channel->pop(2.0);
        return $result ?? false;
    }

    public function getTransferInfo(
        Fluxus $server,
        string $fileId
    ): ?array
    {
        // Método síncrono
        try {
            $redis = $server->redis();
            $transferKey = self::TRANSFER_PREFIX . $fileId;

            $transferInfo = $redis->hGetAll($transferKey);
            if (empty($transferInfo)) {
                return null;
            }

            // Obtener bitmap
            $bitmapKey = self::TRANSFER_PREFIX . $fileId . ':bitmap';
            $bitmap = $redis->get($bitmapKey);

            // Calcular chunks recibidos
            $receivedCount = 0;
            $missingChunks = [];
            $totalChunks = (int)($transferInfo['total_chunks'] ?? 0);

            if ($bitmap !== false && $totalChunks > 0) {
                for ($i = 0; $i < $totalChunks; $i++) {
                    $byteIndex = (int)floor($i / 8);
                    $bitPosition = $i % 8;

                    if (isset($bitmap[$byteIndex])) {
                        $byte = ord($bitmap[$byteIndex]);
                        if (($byte >> $bitPosition) & 1) {
                            $receivedCount++;
                        } else {
                            $missingChunks[] = $i;
                        }
                    } else {
                        $missingChunks[] = $i;
                    }
                }
            }

            return [
                'file_id' => $fileId,
                'total_chunks' => $totalChunks,
                'received_chunks' => $receivedCount,
                'missing_chunks' => $missingChunks,
                'progress' => $totalChunks > 0 ? round(($receivedCount / $totalChunks) * 100, 1) : 0,
                'started_at' => (int)($transferInfo['started_at'] ?? 0),
                'last_chunk_at' => (int)($transferInfo['last_chunk_at'] ?? 0),
                'completed_at' => (int)($transferInfo['completed_at'] ?? 0),
                'is_complete' => (bool)($transferInfo['complete'] ?? false),
                'metadata' => isset($transferInfo['metadata'])
                    ? json_decode($transferInfo['metadata'], true)
                    : []
            ];

        } catch (\Exception $e) {
            $server->logger?->error('Get transfer info error: ' . $e->getMessage());
            return null;
        }
    }

    public function initTransfer(
        Fluxus $server,
        string $fileId,
        string $fileName,
        string $fileType,
        int $fileSize,
        int $totalChunks,
        array $metadata = []
    ): bool
    {
        $channel = new Channel(1);

        go(function () use ($server, $fileId, $fileName, $fileType, $fileSize, $totalChunks, $metadata, $channel) {
            try {
                $redis = $server->redis();
                $transferKey = self::TRANSFER_PREFIX . $fileId;

                $transferData = [
                    'file_id' => $fileId,
                    'file_name' => $fileName,
                    'file_type' => $fileType,
                    'file_size' => $fileSize,
                    'total_chunks' => $totalChunks,
                    'received_chunks' => 0,
                    'started_at' => time(),
                    'status' => 'transferring',
                    'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)
                ];

                $redis->hMSet($transferKey, $transferData);
                $redis->expire($transferKey, 3600);

                // Inicializar bitmap
                $bitmapSize = (int)ceil($totalChunks / 8);
                $bitmapKey = self::TRANSFER_PREFIX . $fileId . ':bitmap';
                $redis->set($bitmapKey, str_repeat("\0", $bitmapSize));
                $redis->expire($bitmapKey, 3600);

                // Agregar a lista temporal
                $listKey = self::LIST_PREFIX . ':' . date('Y-m-d');
                $redis->zAdd($listKey, time(), $fileId);

                $server->logger?->info("Transfer initialized: {$fileId} ({$fileName})");
                $channel->push(true);

            } catch (\Exception $e) {
                $server->logger?->error('Init transfer error: ' . $e->getMessage());
                $channel->push(false);
            } finally {
                $channel->close();
            }
        });

        $result = $channel->pop(2.0);
        return $result ?? false;
    }

    public function assembleFileFromChunks(
        Fluxus $server,
        string $fileId
    ): array
    {
        $channel = new Channel(1);

        go(function () use ($server, $fileId, $channel) {
            try {
                $redis = $server->redis();
                $transferInfo = $this->getTransferInfo($server, $fileId);

                if (!$transferInfo || $transferInfo['total_chunks'] === 0) {
                    $channel->push(['success' => false, 'error' => 'Invalid transfer info']);
                    return;
                }

                // Verificar chunks completos
                if ($transferInfo['received_chunks'] < $transferInfo['total_chunks']) {
                    $channel->push([
                        'success' => false,
                        'error' => 'Missing chunks',
                        'missing' => $transferInfo['missing_chunks']
                    ]);
                    return;
                }

                // Ensamblar archivo
                $fileContent = '';
                $totalChunks = $transferInfo['total_chunks'];

                for ($i = 0; $i < $totalChunks; $i++) {
                    $chunkKey = self::CHUNK_PREFIX . $fileId . ':' . $i;
                    $chunk = $redis->get($chunkKey);

                    if ($chunk === false) {
                        $channel->push(['success' => false, 'error' => "Missing chunk {$i}"]);
                        return;
                    }

                    $fileContent .= $chunk;

                    // Limpiar chunk
                    $redis->del($chunkKey);

                    // Pequeña pausa
                    if ($i % 10 === 0) {
                        Coroutine::sleep(0.001);
                    }
                }

                // Verificar tamaño
                $actualSize = strlen($fileContent);
                if ($actualSize !== $transferInfo['file_size']) {
                    $server->logger?->warning(
                        "File size mismatch for {$fileId}: " .
                        "expected {$transferInfo['file_size']}, got {$actualSize}"
                    );
                }

                // Guardar archivo completo
                $saveResult = $this->saveFile(
                    $server,
                    $fileId,
                    $transferInfo['metadata']['original_name'] ?? $transferInfo['file_name'],
                    $transferInfo['file_type'],
                    $actualSize,
                    $fileContent,
                    array_merge($transferInfo['metadata'], [
                        'assembled_at' => time(),
                        'original_size' => $transferInfo['file_size']
                    ])
                );

                // Limpiar transferencia
                $this->cleanupTransfer($server, $fileId);

                $channel->push($saveResult);

            } catch (\Exception $e) {
                $server->logger?->error('Assemble file error: ' . $e->getMessage());
                $channel->push(['success' => false, 'error' => $e->getMessage()]);
            } finally {
                $channel->close();
            }
        });

        $result = $channel->pop(30.0); // 30 segundos timeout para ensamblaje
        return $result ?? ['success' => false, 'error' => 'Timeout assembling file'];
    }

    /**
     * Limpia datos de transferencia
     */
    private function cleanupTransfer(Fluxus $server, string $fileId): void
    {
        go(function () use ($server, $fileId) {
            try {
                $redis = $server->redis();

                $redis->pipeline()
                    ->del(self::TRANSFER_PREFIX . $fileId)
                    ->del(self::TRANSFER_PREFIX . $fileId . ':bitmap')
                    ->exec();

                // Limpiar chunks
                $pattern = self::CHUNK_PREFIX . $fileId . ':*';
                $chunkKeys = $redis->keys($pattern);

                if (!empty($chunkKeys)) {
                    $redis->del(...$chunkKeys);
                }

            } catch (\Exception $e) {
                $server->logger?->error('Cleanup transfer error: ' . $e->getMessage());
            }
        });
    }

    /**
     * Obtiene estadísticas
     */
    public function getStats(Fluxus $server): array
    {
        try {
            $redis = $server->redis();

            $filesByDay = [];
            $totalFiles = 0;

            for ($i = 0; $i < 7; $i++) {
                $date = date('Y-m-d', strtotime("-{$i} days"));
                $listKey = self::LIST_PREFIX . ':' . $date;
                $count = $redis->zCard($listKey);

                if ($count > 0) {
                    $filesByDay[$date] = $count;
                    $totalFiles += $count;
                }
            }

            return [
                'total_files' => $totalFiles,
                'files_by_day' => $filesByDay,
                'storage_type' => 'redis',
                'measured_at' => time()
            ];

        } catch (\Exception $e) {
            $server->logger?->error('Get stats error: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
}