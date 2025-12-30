<?php

namespace SIG\Server\File;

use SIG\Server\Fluxus;

interface FileProcessorInterface
{
    /**
     * Procesa un archivo después de recibirlo
     */
    public function processFile(
        Fluxus $server,
        string $fileId,
        array  $fileInfo,
        array  $metadata = []
    ): array;

    /**
     * Valida un archivo antes de guardarlo
     */
    public function validateFile(
        Fluxus $server,
        string $fileName,
        string $fileType,
        int    $fileSize,
        array  $metadata = []
    ): bool;

    /**
     * Obtiene límites de configuración
     */
    public function getLimits(): array;
}