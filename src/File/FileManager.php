<?php

namespace SIG\Server\File;

use SIG\Server\Fluxus;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Table;

class FileManager implements FileManagerInterface
{
    private Table $activeTransfers;
    private ?FileStorageInterface $storage;
    private ?FileProcessorInterface $processor;
    private array $config;

    public function __construct(
        private readonly Fluxus $server,
        ?FileStorageInterface   $storage = null,
        ?FileProcessorInterface $processor = null,
        array                   $config = []
    ) {
        $this->storage = $storage;
        $this->processor = $processor;
        $this->config = array_merge([
            'max_file_size' => 100 * 1024 * 1024, // 100MB
            'max_chunk_size' => 64 * 1024, // 64KB
            'transfer_timeout' => 3600, // 1 hora
            'max_concurrent_transfers' => 10,
            'cleanup_interval' => 300, // 5 minutos
            'allowed_types' => [
                'application/pdf',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/csv',
                'application/json',
                'text/plain',
                'image/png',
                'image/jpeg'
            ]
        ], $config);

        $this->initTable();
        $this->startCleanupTimer();
    }

    private function initTable(): void
    {
        $this->activeTransfers = new Table(1024);
        $this->activeTransfers->column('file_id', Table::TYPE_STRING, 64);
        $this->activeTransfers->column('fd', Table::TYPE_INT);
        $this->activeTransfers->column('channel', Table::TYPE_STRING, 128);
        $this->activeTransfers->column('status', Table::TYPE_STRING, 20);
        $this->activeTransfers->column('started_at', Table::TYPE_INT);
        $this->activeTransfers->column('updated_at', Table::TYPE_INT);
        $this->activeTransfers->column('chunks_received', Table::TYPE_INT);
        $this->activeTransfers->column('total_chunks', Table::TYPE_INT);
        $this->activeTransfers->column('file_name', Table::TYPE_STRING, 255);
        $this->activeTransfers->column('file_size', Table::TYPE_INT);
        $this->activeTransfers->create();
    }

    private function startCleanupTimer(): void
    {
        // Limpiar transferencias antiguas periódicamente
        swoole_timer_tick($this->config['cleanup_interval'] * 1000, function() {
            $this->cleanupOldTransfers();
        });
    }

    // =========================================================================
    // MÉTODOS PRINCIPALES DE TRANSFERENCIA
    // =========================================================================

    /**
     * Inicia una transferencia de archivo por chunks
     */
    public function initiateTransfer(int $fd, array $data): array
    {
        $channel = new Channel(1);

        go(function () use ($fd, $data, $channel) {
            try {
                if (!$this->storage) {
                    $channel->push(['success' => false, 'error' => 'Storage not configured']);
                    return;
                }

                $fileId = $data['file_id'] ?? $this->generateFileId($fd, $data);
                $fileName = $data['file_name'] ?? 'file_' . uniqid('', true);
                $fileType = $data['file_type'] ?? 'application/octet-stream';
                $fileSize = (int)($data['file_size'] ?? 0);
                $totalChunks = (int)($data['total_chunks'] ?? 0);
                $channelName = $data['channel'] ?? '';
                $metadata = $data['metadata'] ?? [];

                // Validar datos básicos
                if (empty($fileName) || $fileSize <= 0) {
                    $channel->push(['success' => false, 'error' => 'Invalid file data']);
                    return;
                }

                // Validar tamaño máximo
                if ($fileSize > $this->config['max_file_size']) {
                    $channel->push([
                        'success' => false,
                        'error' => sprintf(
                            'File size exceeds maximum limit of %d MB',
                            $this->config['max_file_size'] / 1024 / 1024
                        )
                    ]);
                    return;
                }

                // Validar tipo de archivo
                if (!in_array($fileType, $this->config['allowed_types'], true)) {
                    $channel->push(['success' => false, 'error' => 'File type not allowed']);
                    return;
                }

                // Validar con procesador si existe
                if ($this->processor &&
                    !$this->processor->validateFile($this->server, $fileName, $fileType, $fileSize, $metadata)) {
                    $channel->push(['success' => false, 'error' => 'File validation failed']);
                    return;
                }

                // Calcular chunks si no se proporciona
                if ($totalChunks === 0) {
                    $totalChunks = (int)ceil($fileSize / $this->config['max_chunk_size']);
                }

                // Verificar límite de transferencias concurrentes
                if ($this->countConcurrentTransfers($fd) >= $this->config['max_concurrent_transfers']) {
                    $channel->push(['success' => false, 'error' => 'Maximum concurrent transfers reached']);
                    return;
                }

                // Inicializar en storage
                $success = $this->storage->initTransfer(
                    $this->server,
                    $fileId,
                    $fileName,
                    $fileType,
                    $fileSize,
                    $totalChunks,
                    array_merge($metadata, [
                        'fd' => $fd,
                        'channel' => $channelName,
                        'original_name' => $fileName
                    ])
                );

                if (!$success) {
                    $channel->push(['success' => false, 'error' => 'Failed to initialize transfer']);
                    return;
                }

                // Registrar en tabla activa
                $this->activeTransfers->set($fileId, [
                    'file_id' => $fileId,
                    'fd' => $fd,
                    'channel' => $channelName,
                    'status' => 'transferring',
                    'started_at' => time(),
                    'updated_at' => time(),
                    'chunks_received' => 0,
                    'total_chunks' => $totalChunks,
                    'file_name' => $fileName,
                    'file_size' => $fileSize
                ]);

                $this->server->logger?->info("Transfer initiated: {$fileId} ({$fileName}, {$fileSize} bytes)");

                $channel->push([
                    'success' => true,
                    'file_id' => $fileId,
                    'total_chunks' => $totalChunks,
                    'chunk_size' => $this->config['max_chunk_size'],
                    'message' => 'Transfer initiated successfully'
                ]);

            } catch (\Exception $e) {
                $this->server->logger?->error('Initiate transfer error: ' . $e->getMessage());
                $channel->push(['success' => false, 'error' => $e->getMessage()]);
            } finally {
                $channel->close();
            }
        });

        $result = $channel->pop(5.0);
        return $result ?? ['success' => false, 'error' => 'Timeout initiating transfer'];
    }

    /**
     * Procesa un chunk de archivo
     */
    public function processChunk(int $fd, array $data): array
    {
        $channel = new Channel(1);

        go(function () use ($fd, $data, $channel) {
            try {
                if (!$this->storage) {
                    $channel->push(['success' => false, 'error' => 'Storage not configured']);
                    return;
                }

                $fileId = $data['file_id'] ?? '';
                $chunkIndex = (int)($data['chunk_index'] ?? -1);
                $chunkData = $data['chunk_data'] ?? '';
                $isLast = (bool)($data['is_last'] ?? false);

                if (empty($fileId) || $chunkIndex < 0 || empty($chunkData)) {
                    $channel->push(['success' => false, 'error' => 'Invalid chunk data']);
                    return;
                }

                // Verificar transferencia activa
                if (!$this->activeTransfers->exist($fileId)) {
                    $channel->push(['success' => false, 'error' => 'Transfer not found']);
                    return;
                }

                $transfer = $this->activeTransfers->get($fileId);
                if ($transfer['fd'] !== $fd) {
                    $channel->push(['success' => false, 'error' => 'Transfer ownership mismatch']);
                    return;
                }

                // Verificar índice de chunk
                if ($chunkIndex >= $transfer['total_chunks']) {
                    $channel->push(['success' => false, 'error' => 'Invalid chunk index']);
                    return;
                }

                // Guardar chunk
                $success = $this->storage->saveChunk(
                    $this->server,
                    $fileId,
                    $chunkIndex,
                    $chunkData,
                    $isLast
                );

                if (!$success) {
                    $channel->push(['success' => false, 'error' => 'Failed to save chunk']);
                    return;
                }

                // Actualizar contador
                $newCount = $transfer['chunks_received'] + 1;
                $transfer['chunks_received'] = $newCount;
                $transfer['updated_at'] = time();
                $this->activeTransfers->set($fileId, $transfer);

                $response = [
                    'success' => true,
                    'chunk_index' => $chunkIndex,
                    'received_chunks' => $newCount,
                    'total_chunks' => $transfer['total_chunks'],
                    'progress' => round(($newCount / $transfer['total_chunks']) * 100, 1)
                ];

                // Log progreso cada 10% o cada 10 chunks
                if ($newCount % max(10, (int)($transfer['total_chunks'] * 0.1)) === 0) {
                    $this->server->logger?->debug(
                        "Transfer progress: {$fileId} - {$response['progress']}% " .
                        "({$newCount}/{$transfer['total_chunks']})"
                    );
                }

                // Si es el último, iniciar ensamblaje
                if ($isLast) {
                    $response['assembling'] = true;
                    $this->assembleFile($fileId);
                }

                $channel->push($response);

            } catch (\Exception $e) {
                $this->server->logger?->error('Process chunk error: ' . $e->getMessage());
                $channel->push(['success' => false, 'error' => $e->getMessage()]);
            } finally {
                $channel->close();
            }
        });

        $result = $channel->pop(2.0);
        return $result ?? ['success' => false, 'error' => 'Timeout processing chunk'];
    }

    /**
     * Sube un archivo completo (sin chunks)
     */
    public function uploadFile(int $fd, array $data): array
    {
        $channel = new Channel(1);

        go(function () use ($fd, $data, $channel) {
            try {
                if (!$this->storage) {
                    $channel->push(['success' => false, 'error' => 'Storage not configured']);
                    return;
                }

                $requestId = $data['request_id'] ?? '';
                $channelName = $data['channel'] ?? '';
                $fileData = $data['file'] ?? [];
                $metadata = $data['metadata'] ?? [];

                // Validar
                if (empty($fileData) || !isset($fileData['name'], $fileData['data'])) {
                    $channel->push(['success' => false, 'error' => 'Invalid file data']);
                    return;
                }

                $fileName = $fileData['name'];
                $fileType = $fileData['type'] ?? 'application/octet-stream';
                $fileSize = (int)($fileData['size'] ?? 0);
                $base64Data = $fileData['data'];

                // Decodificar
                $content = base64_decode($base64Data);
                if ($content === false) {
                    $channel->push(['success' => false, 'error' => 'Failed to decode file']);
                    return;
                }

                // Validar tamaño
                if (strlen($content) !== $fileSize) {
                    $this->server->logger?->warning(
                        "File size mismatch: expected {$fileSize}, got " . strlen($content)
                    );
                }

                // Validaciones
                if (strlen($content) > $this->config['max_file_size']) {
                    $channel->push(['success' => false, 'error' => 'File too large']);
                    return;
                }

                if (!in_array($fileType, $this->config['allowed_types'], true)) {
                    $channel->push(['success' => false, 'error' => 'File type not allowed']);
                    return;
                }

                if ($this->processor &&
                    !$this->processor->validateFile($this->server, $fileName, $fileType, $fileSize, $metadata)) {
                    $channel->push(['success' => false, 'error' => 'File validation failed']);
                    return;
                }

                // Generar ID y guardar
                $fileId = $this->generateFileId($fd, ['file_name' => $fileName]);

                $result = $this->storage->saveFile(
                    $this->server,
                    $fileId,
                    $fileName,
                    $fileType,
                    strlen($content),
                    $content,
                    array_merge($metadata, [
                        'fd' => $fd,
                        'channel' => $channelName,
                        'request_id' => $requestId
                    ])
                );

                if ($result['success'] ?? false) {
                    // Procesar archivo en background
                    $this->processFileInBackground($fileId, [
                        'name' => $fileName,
                        'type' => $fileType,
                        'size' => strlen($content)
                    ], $metadata);

                    // Notificar al canal si existe
                    if (!empty($channelName)) {
                        $this->notifyFileAvailable($channelName, $fileId, $fileName, $fileType, strlen($content), $metadata);
                    }
                }

                $channel->push($result);

            } catch (\Exception $e) {
                $this->server->logger?->error('Upload file error: ' . $e->getMessage());
                $channel->push(['success' => false, 'error' => $e->getMessage()]);
            } finally {
                $channel->close();
            }
        });

        $result = $channel->pop(10.0);
        return $result ?? ['success' => false, 'error' => 'Timeout uploading file'];
    }

    // =========================================================================
    // MÉTODOS DE CONSULTA Y GESTIÓN
    // =========================================================================

    /**
     * Obtiene un archivo
     */
    public function getFile(int $fd, array $data): array
    {
        $channel = new Channel(1);

        go(function () use ($fd, $data, $channel) {
            try {
                if (!$this->storage) {
                    $channel->push(['success' => false, 'error' => 'Storage not configured']);
                    return;
                }

                $fileId = $data['file_id'] ?? '';
                $fileName = $data['file_name'] ?? 'download';

                if (empty($fileId)) {
                    $channel->push(['success' => false, 'error' => 'File ID required']);
                    return;
                }

                $fileData = $this->storage->getFile($this->server, $fileId);

                if (!$fileData) {
                    $channel->push(['success' => false, 'error' => 'File not found']);
                    return;
                }

                // Preparar respuesta
                $response = [
                    'success' => true,
                    'request_id' => $data['request_id'] ?? '',
                    'file' => [
                        'name' => $fileName,
                        'type' => $fileData['metadata']['type'] ?? 'application/octet-stream',
                        'size' => $fileData['metadata']['size'] ?? 0,
                        'data' => base64_encode($fileData['content'])
                    ],
                    'metadata' => $fileData['metadata']
                ];

                // Para archivos grandes, enviar por chunks
                $maxDirectSize = 10 * 1024 * 1024; // 10MB
                if (strlen($response['file']['data']) > $maxDirectSize) {
                    $this->sendLargeFileByChunks($fd, $fileData, $data['request_id'] ?? '', $fileName);
                    $channel->push(['success' => true, 'sending_chunks' => true]);
                    return;
                }

                $channel->push($response);

            } catch (\Exception $e) {
                $this->server->logger?->error('Get file error: ' . $e->getMessage());
                $channel->push(['success' => false, 'error' => $e->getMessage()]);
            } finally {
                $channel->close();
            }
        });

        $result = $channel->pop(5.0);
        return $result ?? ['success' => false, 'error' => 'Timeout retrieving file'];
    }

    /**
     * Lista archivos disponibles
     */
    public function listFiles(int $fd, array $data): array
    {
        try {
            if (!$this->storage) {
                return ['success' => false, 'error' => 'Storage not configured'];
            }

            $filters = $data['filters'] ?? [];
            $result = $this->storage->listFiles($this->server, $filters);

            return [
                'success' => true,
                'request_id' => $data['request_id'] ?? '',
                'result' => $result
            ];

        } catch (\Exception $e) {
            $this->server->logger?->error('List files error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Elimina un archivo
     */
    public function deleteFile(int $fd, array $data): array
    {
        $channel = new Channel(1);

        go(function () use ($fd, $data, $channel) {
            try {
                if (!$this->storage) {
                    $channel->push(['success' => false, 'error' => 'Storage not configured']);
                    return;
                }

                $fileId = $data['file_id'] ?? '';

                if (empty($fileId)) {
                    $channel->push(['success' => false, 'error' => 'File ID required']);
                    return;
                }

                $success = $this->storage->deleteFile($this->server, $fileId);

                if ($success) {
                    // Limpiar transferencia activa si existe
                    if ($this->activeTransfers->exist($fileId)) {
                        $this->activeTransfers->del($fileId);
                    }

                    $channel->push([
                        'success' => true,
                        'message' => 'File deleted successfully'
                    ]);
                } else {
                    $channel->push(['success' => false, 'error' => 'Failed to delete file']);
                }

            } catch (\Exception $e) {
                $this->server->logger?->error('Delete file error: ' . $e->getMessage());
                $channel->push(['success' => false, 'error' => $e->getMessage()]);
            } finally {
                $channel->close();
            }
        });

        $result = $channel->pop(5.0);
        return $result ?? ['success' => false, 'error' => 'Timeout deleting file'];
    }

    /**
     * Obtiene información de transferencia
     */
    public function getTransferInfo(int $fd, array $data): array
    {
        try {
            if (!$this->storage) {
                return ['success' => false, 'error' => 'Storage not configured'];
            }

            $fileId = $data['file_id'] ?? '';

            if (empty($fileId)) {
                return ['success' => false, 'error' => 'File ID required'];
            }

            $info = $this->storage->getTransferInfo($this->server, $fileId);

            if (!$info) {
                return ['success' => false, 'error' => 'Transfer not found'];
            }

            // Combinar con información de tabla activa
            if ($this->activeTransfers->exist($fileId)) {
                $active = $this->activeTransfers->get($fileId);
                $info['active_status'] = $active['status'];
                $info['active_chunks_received'] = $active['chunks_received'];
            }

            return [
                'success' => true,
                'request_id' => $data['request_id'] ?? '',
                'info' => $info
            ];

        } catch (\Exception $e) {
            $this->server->logger?->error('Get transfer info error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // MÉTODOS AUXILIARES PRIVADOS
    // =========================================================================

    /**
     * Ensambla archivo desde chunks
     */
    private function assembleFile(string $fileId): void
    {
        go(function () use ($fileId) {
            try {
                if (!$this->storage) {
                    $this->notifyTransferError($fileId, 'Storage not configured');
                    return;
                }

                $transfer = $this->activeTransfers->get($fileId);
                if (!$transfer) {
                    return;
                }

                $this->server->logger?->info("Assembling file: {$fileId}");

                // Actualizar estado
                $transfer['status'] = 'assembling';
                $this->activeTransfers->set($fileId, $transfer);

                // Notificar inicio de ensamblaje
                $this->server->sendToClient($transfer['fd'], [
                    'type' => 'transfer_assembling',
                    'file_id' => $fileId,
                    'message' => 'Assembling file from chunks...'
                ]);

                // Ensamblar
                $result = $this->storage->assembleFileFromChunks($this->server, $fileId);

                if ($result['success'] ?? false) {
                    $this->notifyTransferComplete($fileId, $result);

                    // Notificar al canal
                    $this->notifyFileAvailable(
                        $transfer['channel'],
                        $fileId,
                        $transfer['file_name'],
                        '', // Tipo se obtiene del metadata
                        $transfer['file_size'],
                        []
                    );

                    // Procesar archivo
                    $this->processFileInBackground($fileId, [
                        'name' => $transfer['file_name'],
                        'size' => $transfer['file_size']
                    ], []);

                } else {
                    $this->notifyTransferError($fileId, $result['error'] ?? 'Assembly failed');
                }

                // Limpiar transferencia
                $this->activeTransfers->del($fileId);

            } catch (\Exception $e) {
                $this->server->logger?->error('Assemble file error: ' . $e->getMessage());
                $this->notifyTransferError($fileId, $e->getMessage());
            }
        });
    }

    /**
     * Procesa archivo en background
     */
    private function processFileInBackground(string $fileId, array $fileInfo, array $metadata): void
    {
        go(function () use ($fileId, $fileInfo, $metadata) {
            try {
                if ($this->processor) {
                    $result = $this->processor->processFile(
                        $this->server,
                        $fileId,
                        $fileInfo,
                        $metadata
                    );

                    $this->server->logger?->debug("File processed: {$fileId}", $result);
                }
            } catch (\Exception $e) {
                $this->server->logger?->error('Background file processing error: ' . $e->getMessage());
            }
        });
    }

    /**
     * Envía archivo grande por chunks
     */
    private function sendLargeFileByChunks(int $fd, array $fileData, string $requestId, string $fileName): void
    {
        go(function () use ($fd, $fileData, $requestId, $fileName) {
            try {
                $content = $fileData['content'];
                $fileSize = strlen($content);
                $chunkSize = $this->config['max_chunk_size'];
                $totalChunks = (int)ceil($fileSize / $chunkSize);

                $this->server->sendToClient($fd, [
                    'type' => 'file_transfer_started',
                    'request_id' => $requestId,
                    'file_name' => $fileName,
                    'file_size' => $fileSize,
                    'total_chunks' => $totalChunks,
                    'chunk_size' => $chunkSize
                ]);

                // Enviar chunks
                for ($i = 0; $i < $totalChunks; $i++) {
                    $start = $i * $chunkSize;
                    $chunk = substr($content, $start, $chunkSize);
                    $isLast = ($i === $totalChunks - 1);

                    $this->server->sendToClient($fd, [
                        'type' => 'file_chunk',
                        'request_id' => $requestId,
                        'chunk_index' => $i,
                        'chunk_data' => base64_encode($chunk),
                        'is_last' => $isLast
                    ]);

                    // Pequeña pausa para no saturar
                    Coroutine::sleep(0.001);
                }

                $this->server->sendToClient($fd, [
                    'type' => 'file_transfer_complete',
                    'request_id' => $requestId,
                    'status' => 'complete'
                ]);

            } catch (\Exception $e) {
                $this->server->logger?->error('Send large file error: ' . $e->getMessage());
            }
        });
    }

    /**
     * Notifica archivo disponible en canal
     */
    private function notifyFileAvailable(string $channel, string $fileId, string $fileName, string $fileType, int $fileSize, array $metadata): void
    {
        $message = [
            'type' => 'file_available',
            'channel' => $channel,
            'file_id' => $fileId,
            'file_name' => $fileName,
            'file_type' => $fileType,
            'file_size' => $fileSize,
            'timestamp' => time(),
            'metadata' => $metadata
        ];

        $this->server->broadcastToChannel($channel, $message);
    }

    /**
     * Notifica transferencia completa
     */
    private function notifyTransferComplete(string $fileId, array $result): void
    {
        $transfer = $this->activeTransfers->get($fileId);
        if ($transfer) {
            $this->server->sendToClient($transfer['fd'], [
                'type' => 'transfer_complete',
                'file_id' => $fileId,
                'success' => true,
                'result' => $result
            ]);
        }
    }

    /**
     * Notifica error en transferencia
     */
    private function notifyTransferError(string $fileId, string $error): void
    {
        $transfer = $this->activeTransfers->get($fileId);
        if ($transfer) {
            $this->server->sendToClient($transfer['fd'], [
                'type' => 'transfer_error',
                'file_id' => $fileId,
                'error' => $error
            ]);
        }
    }

    /**
     * Cuenta transferencias concurrentes
     */
    private function countConcurrentTransfers(int $fd): int
    {
        $count = 0;
        foreach ($this->activeTransfers as $transfer) {
            if ($transfer['fd'] === $fd && $transfer['status'] === 'transferring') {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Genera ID único para archivo
     */
    private function generateFileId(int $fd, array $data): string
    {
        $hash = md5($fd . ($data['file_name'] ?? '') . microtime(true) . random_bytes(16));
        return 'file_' . substr($hash, 0, 16);
    }

    /**
     * Limpia transferencias antiguas
     */
    private function cleanupOldTransfers(): void
    {
        $cleaned = 0;
        $currentTime = time();
        $maxAge = $this->config['transfer_timeout'];

        foreach ($this->activeTransfers as $fileId => $transfer) {
            if (($currentTime - $transfer['updated_at']) > $maxAge) {
                $this->activeTransfers->del($fileId);
                $cleaned++;

                $this->server->logger?->debug("Cleaned up old transfer: {$fileId}");
            }
        }

        if ($cleaned > 0) {
            $this->server->logger?->info("Cleaned up {$cleaned} old file transfers");
        }
    }

    // =========================================================================
    // MÉTODOS PÚBLICOS DE CONSULTA
    // =========================================================================

    /**
     * Obtiene transferencias activas
     */
    public function getActiveTransfers(): array
    {
        $transfers = [];
        foreach ($this->activeTransfers as $transfer) {
            $transfers[] = $transfer;
        }
        return $transfers;
    }

    /**
     * Obtiene estadísticas
     */
    public function getStats(): array
    {
        $activeTransfers = $this->getActiveTransfers();

        $stats = [
            'active_transfers' => count($activeTransfers),
            'config' => [
                'max_file_size' => $this->config['max_file_size'],
                'max_chunk_size' => $this->config['max_chunk_size'],
                'max_concurrent_transfers' => $this->config['max_concurrent_transfers'],
                'allowed_types' => $this->config['allowed_types']
            ],
            'storage_configured' => $this->storage !== null,
            'processor_configured' => $this->processor !== null,
            'measured_at' => time()
        ];

        // Obtener estadísticas del storage si está disponible
        if ($this->storage && method_exists($this->storage, 'getStats')) {
            $storageStats = $this->storage->getStats($this->server);
            $stats['storage'] = $storageStats;
        }

        return $stats;
    }

    /**
     * Maneja cualquier acción de archivos
     */
    public function handleAction(int $fd, array $data): array
    {
        $action = $data['action'] ?? '';

        return match ($action) {
            'start_file_transfer' => $this->initiateTransfer($fd, $data),
            'file_chunk' => $this->processChunk($fd, $data),
            'send_file' => $this->uploadFile($fd, $data),
            'request_file' => $this->getFile($fd, $data),
            'list_files' => $this->listFiles($fd, $data),
            'delete_file' => $this->deleteFile($fd, $data),
            'get_transfer_info' => $this->getTransferInfo($fd, $data),
            default => ['success' => false, 'error' => "Unknown file action: {$action}"],
        };
    }
}