<?php

namespace SIG\Server\File;

interface FileManagerInterface
{
    /**
     * Inicia una transferencia de archivo por chunks
     */
    public function initiateTransfer(int $fd, array $data): array;

    /**
     * Procesa un chunk de archivo
     */
    public function processChunk(int $fd, array $data): array;

    /**
     * Sube un archivo completo (sin chunks)
     */
    public function uploadFile(int $fd, array $data): array;

    /**
     * Obtiene un archivo
     */
    public function getFile(int $fd, array $data): array;

    /**
     * Lista archivos disponibles
     */
    public function listFiles(int $fd, array $data): array;

    /**
     * Elimina un archivo
     */
    public function deleteFile(int $fd, array $data): array;

    /**
     * Obtiene información de transferencia
     */
    public function getTransferInfo(int $fd, array $data): array;

    /**
     * Obtiene transferencias activas
     */
    public function getActiveTransfers(): array;

    /**
     * Obtiene estadísticas
     */
    public function getStats(): array;

    /**
     * Maneja cualquier acción de archivos
     */
    public function handleAction(int $fd, array $data): array;
}