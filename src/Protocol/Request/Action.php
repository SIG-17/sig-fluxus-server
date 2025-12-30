<?php

namespace SIG\Server\Protocol\Request;

use SIG\Server\Exception\UnexpectedValueException;
use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;

/**
 * ACTION_LIST_RPC_METHODS = 'list_rpc_methods';
 * ACTION_RPC_CALL = 'rpc';
 * ACTION_SUBSCRIBE = 'subscribe';
 * ACTION_UNSUBSCRIBE = 'unsubscribe';
 * ACTION_PUBLISH = 'publish';
 * ACTION_SEND_FILE = 'send_file';
 * ACTION_START_FILE_TRANSFER = 'start_file_transfer';
 * ACTION_FILE_CHUNK = 'file_chunk';
 * ACTION_REQUEST_FILE = 'request_file';
 * ACTION_AUTHENTICATE = 'authenticate';
 */
class Action extends AbstractDescriptor
{
    // PUB-SUB RELATED
    protected(set) string $subscribe = 'subscribe';
    protected(set) string $unsubscribe = 'unsubscribe';
    protected(set) string $publish = 'publish';
    protected(set) string $listChannels = 'list_channels';
    // END PUB-SUB RELATED

    // RPC RELATED
    protected(set) string $rpc = 'rpc';
    protected(set) string $listRpcMethods = 'list_rpc_methods';
    // END RPC RELATED

    // FILE RELATED
    protected(set) string $sendFile = 'send_file';
    protected(set) string $requestFile = 'request_file';
    protected(set) string $startFileTransfer = 'start_file_transfer';
    protected(set) string $fileChunk = 'file_chunk';
    protected(set) string $listFiles = 'list_files';
    protected(set) string $deleteFile = 'delete_file';
    protected(set) string $getTransferInfo = 'get_transfer_info';
    // END FILE RELATED

    // AUTH RELATED
    protected(set) string $authenticate = 'authenticate';
    // END AUTH RELATED

    public function getProtocolFor(array $data)
    {
        if (isset($data['action']) && in_array($data['action'], $this->toArray())) {
            $className = __NAMESPACE__ . '\\' . str_replace(' ', '', ucwords(str_replace('_', ' ', $data['action'])));
            if (class_exists($className)) {
                return new $className($data);
            }
            return new Base($data);
        }
        throw new UnexpectedValueException('No action protocol detected. Must be one of: ' . implode(', ', $this->toArray()) . '');
    }
}