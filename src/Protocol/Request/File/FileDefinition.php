<?php

namespace SIG\Server\Protocol\Request\File;

use SIG\Server\Protocol\Request\Action;

class FileDefinition extends Action
{
    const string PROTOCOL = 'file';
    protected(set) string $sendFile = 'send_file';
    protected(set) string $requestFile = 'request_file';
    protected(set) string $startFileTransfer = 'start_file_transfer';
    protected(set) string $fileChunk = 'file_chunk';
    protected(set) string $listFiles = 'list_files';
    protected(set) string $deleteFile = 'delete_file';
    protected(set) string $getTransferInfo = 'get_transfer_info';

}