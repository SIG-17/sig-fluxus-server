<?php

namespace SIG\Server\File;

use SIG\Server\Fluxus;

interface FileStorageInterface
{
    /**
     * Guarda un archivo completo
     * @return array ['success' => bool, 'file_id' => string, ...]
     */
    public function saveFile(
        Fluxus $server,
        string $fileId,
        string $fileName,
        string $fileType,
        int    $fileSize,
        string $content,
        array  $metadata = []
    ): array;

    /**
     * Guarda un chunk de archivo
     * @return bool
     */
    public function saveChunk(
        Fluxus $server,
        string $fileId,
        int    $chunkIndex,
        string $chunkData,
        bool   $isLast = false
    ): bool;

    /**
     * Recupera un archivo
     * @return array|null ['metadata' => array, 'content' => string]
     */
    public function getFile(
        Fluxus $server,
        string $fileId
    ): ?array;

    /**
     * Lista archivos disponibles
     * @return array
     */
    public function listFiles(
        Fluxus $server,
        array  $filters = []
    ): array;

    /**
     * Elimina un archivo
     * @return bool
     */
    public function deleteFile(
        Fluxus $server,
        string $fileId
    ): bool;

    /**
     * Obtiene información de transferencia
     * @return array|null
     */
    public function getTransferInfo(
        Fluxus $server,
        string $fileId
    ): ?array;

    /**
     * Inicializa una transferencia
     * @return bool
     */
    public function initTransfer(
        Fluxus $server,
        string $fileId,
        string $fileName,
        string $fileType,
        int    $fileSize,
        int    $totalChunks,
        array  $metadata = []
    ): bool;

    /**
     * Ensambla archivo desde chunks (opcional)
     * @return array
     */
    public function assembleFileFromChunks(
        Fluxus $server,
        string $fileId
    ): array;
}