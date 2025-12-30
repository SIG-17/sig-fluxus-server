<?php

namespace SIG\Server\Protocol\Response;

class FileTransferStarted extends Base implements ResponseInterface
{
    /**
     * 'success' => true,
     * 'file_id' => $fileId,
     * 'total_chunks' => $totalChunks,
     * 'chunk_size' => $this->config['max_chunk_size'],
     * 'message' => 'Transfer initiated successfully'
     */
    protected(set) bool $success = true;
    protected(set) string $file_id;
    protected(set) int $total_chunks;
    protected(set) int $chunk_size;
    protected(set) string $message;

    public function __construct(
        ?array $values = [],
        private readonly Type   $responseTypes = new Type()
    )
    {
        if (empty($values)) {
            $values = [];
        }
        $values['type'] = $responseTypes->get(lcfirst(substr(strrchr(get_class($this), '\\'), 1)));
        parent::__construct($values, $responseTypes);
    }
    public function isValid(): bool
    {
        return $this->type && $this->type === $this->responseTypes->get(lcfirst(substr(strrchr(get_class($this), '\\'), 1)));
    }
}