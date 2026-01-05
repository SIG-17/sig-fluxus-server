<?php

namespace SIG\Server\Protocol\Response\File;

use SIG\Server\Protocol\Data\File as FileDescriptor;
use SIG\Server\Protocol\Response\Base;
use SIG\Server\Protocol\Response\ResponseInterface;
use SIG\Server\Protocol\Response\Type;

class File extends Base implements ResponseInterface
{
    protected(set) bool $success = true;
    protected(set) string $request_id;
    protected(set) FileDescriptor $file {
        set(array|FileDescriptor $file) {
            $file = $file instanceof FileDescriptor ? $file : new FileDescriptor($file);
            $this->file = $file;
        }
    }
    protected(set) array $metadata;

    public function __construct(
        ?array $values = [],
        private readonly Type   $responseTypes = new FileType()
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
        return isset($this->file) && $this->file->isValid() && $this->type && $this->type === $this->responseTypes->get(lcfirst(substr(strrchr(get_class($this), '\\'), 1)));
    }
}