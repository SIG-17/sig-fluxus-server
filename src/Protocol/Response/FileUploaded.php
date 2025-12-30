<?php

namespace SIG\Server\Protocol\Response;

class FileUploaded extends Base implements ResponseInterface
{
    protected(set) bool $success;
    protected(set) string $file_id;
    protected(set) string $file_name;
    protected(set) int $file_size;
    protected(set) int $stored_at;

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