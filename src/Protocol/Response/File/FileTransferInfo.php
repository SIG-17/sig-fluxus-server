<?php

namespace SIG\Server\Protocol\Response\File;

use SIG\Server\Protocol\Response\Base;
use SIG\Server\Protocol\Response\ResponseInterface;
use SIG\Server\Protocol\Response\Type;

class FileTransferInfo extends Base implements ResponseInterface
{
    protected(set) bool $success = true;
    protected(set) string $request_id;
    protected(set) array $info;
    public function __construct(
        ?array                $values = [],
        private readonly Type $responseTypes = new FileType()
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