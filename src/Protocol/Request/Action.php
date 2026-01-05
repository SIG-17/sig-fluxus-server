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
    const string PROTOCOL = '';
    // PUB-SUB RELATED
    /*    protected(set) string $subscribe = 'subscribe';
        protected(set) string $unsubscribe = 'unsubscribe';
        protected(set) string $publish = 'publish';
        protected(set) string $listChannels = 'list_channels';*/
    // END PUB-SUB RELATED

    // RPC RELATED
    //  protected(set) string $rpc = 'rpc';
    // protected(set) string $listRpcMethods = 'list_rpc_methods';
    // END RPC RELATED

    // FILE RELATED
    /*    protected(set) string $sendFile = 'send_file';
        protected(set) string $requestFile = 'request_file';
        protected(set) string $startFileTransfer = 'start_file_transfer';
        protected(set) string $fileChunk = 'file_chunk';
        protected(set) string $listFiles = 'list_files';
        protected(set) string $deleteFile = 'delete_file';
        protected(set) string $getTransferInfo = 'get_transfer_info';*/
    // END FILE RELATED

    // AUTH RELATED
    protected(set) string $authenticate = 'authenticate';

    // END AUTH RELATED
    private array $resolvers {
        set(array $resolvers) {
            $resolvers = array_filter($resolvers, fn($resolver) => $this->offsetExists($resolver), ARRAY_FILTER_USE_KEY);
            $this->resolvers = $resolvers;
        }
    }

    public function getProtocolFor(array $data)
    {
        if (isset($data['action']) && in_array($data['action'], $this->toArray())) {
            $resolver = array_search($data['action'], $this->toArray(), true);
            if (isset($this->resolvers[$resolver])) {
                if (is_callable($this->resolvers[$resolver])) {
                    return $this->resolvers[$resolver]($data);
                }
                if (is_string($this->resolvers[$resolver]) && class_exists($this->resolvers[$resolver])) {
                    return new $this->resolvers[$resolver]($data);
                }
            }
            $className = $this->getNamespace() . '\\' . str_replace(' ', '', ucwords(str_replace('_', ' ', $data['action'])));
            if (class_exists($className)) {
                return new $className($data);
            }
            return new Base($data);
        }
        throw new UnexpectedValueException('No action rpcProtocol {' . ($data['action'] ?? 'noType') . '} detected. Must be one of: ' . implode(', ', $this->toArray()) . '');
    }

    private function getNamespace(): string
    {
        $fullClassName = get_class($this);
        // Find the last backslash position
        $lastBackslashPos = strrpos($fullClassName, '\\');
        if ($lastBackslashPos === false) {
            // No namespace (global namespace)
            return '';
        }

        // Extract the substring before the last backslash
        return substr($fullClassName, 0, $lastBackslashPos);
    }

    public static function getProtocolName(): ?string
    {
        return static::PROTOCOL;
    }
}