<?php

namespace SIG\Server\Protocol\Response\File;

use SIG\Server\Protocol\Response\Type;

class FileType extends Type
{
    protected(set) string $fileAvailable = 'file_available';
    protected(set) string $fileResponse = 'file_response';
    protected(set) string $fileUploaded = 'file_uploaded';
    protected(set) string $fileDeleted = 'file_deleted';
    protected(set) string $fileTransferStarted = 'file_transfer_started';
    protected(set) string $fileTransferInfo = 'file_transfer_info';
    protected(set) string $fileChunkReceived = 'file_chunk_received';
    protected(set) string $fileList = 'file_list';

}