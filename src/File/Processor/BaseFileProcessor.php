<?php

namespace SIG\Server\File\Processor;

use SIG\Server\File\FileProcessorInterface;
use SIG\Server\Fluxus;

class BaseFileProcessor implements FileProcessorInterface
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'max_file_size' => 100 * 1024 * 1024, // 100MB
            'allowed_types' => [
                'application/pdf',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/csv',
                'application/json',
                'text/plain'
            ],
            'max_chunk_size' => 64 * 1024, // 64KB
            'auto_cleanup' => true,
            'cleanup_age' => 3600 // 1 hora
        ], $config);
    }

    public function processFile(
        Fluxus $server,
        string $fileId,
        array  $fileInfo,
        array  $metadata = []
    ): array
    {
        // Procesamiento básico - puedes extender esto
        return [
            'processed' => true,
            'file_id' => $fileId,
            'original_name' => $fileInfo['name'],
            'file_size' => $fileInfo['size'],
            'timestamp' => time()
        ];
    }

    public function validateFile(
        Fluxus $server,
        string $fileName,
        string $fileType,
        int    $fileSize,
        array  $metadata = []
    ): bool
    {
        // Validar tamaño
        if ($fileSize > $this->config['max_file_size']) {
            $server->logger?->warning("File too large: {$fileName} ({$fileSize} bytes)");
            return false;
        }

        // Validar tipo
        if (!in_array($fileType, $this->config['allowed_types'], true)) {
            $server->logger?->warning("Invalid file type: {$fileType} for {$fileName}");
            return false;
        }

        // Validar nombre (protección básica)
        if (preg_match('/\.\.\/|\.\.\\\\/', $fileName)) {
            $server->logger?->warning("Invalid file name: {$fileName}");
            return false;
        }

        return true;
    }

    public function getLimits(): array
    {
        return $this->config;
    }
}