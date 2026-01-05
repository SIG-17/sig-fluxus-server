<?php

namespace SIG\Server\Protocol\Response;

use SIG\Server\Exception\UnexpectedValueException;
use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;

class Type extends AbstractDescriptor
{
    const string PROTOCOL = '';

    protected(set) string $message = 'message';
    protected(set) string $error = 'error';
    protected(set) string $success = 'success';
    private array $resolvers = [
        'message' => Message::class,
        'error' => Error::class,
        'success' => Success::class
    ] {
        set(array $resolvers) {
            $resolvers = array_filter($resolvers, fn($resolver) => $this->offsetExists($resolver), ARRAY_FILTER_USE_KEY);
            $this->resolvers = $resolvers;
        }
    }

    /**
     * @throws UnexpectedValueException
     */
    public function getProtocolFor(array $data): Base|ResponseInterface
    {
        if (isset($data['type']) && in_array($data['type'], $this->toArray())) {
            $resolver = array_search($data['type'], $this->toArray(), true);
            if (isset($this->resolvers[$resolver])) {
                if (is_callable($this->resolvers[$resolver])) {
                    return $this->resolvers[$resolver]($data);
                }
                if (is_string($this->resolvers[$resolver]) && class_exists($this->resolvers[$resolver])) {
                    return new $this->resolvers[$resolver]($data);
                }
            }
            $className = $this->getNamespace() . '\\' . str_replace(' ', '', ucwords(str_replace('_', ' ', str_replace('_response', '', $data['type']))));
            if (class_exists($className)) {
                return new $className($data);
            }
            return new Base($data);
        }
        throw new UnexpectedValueException('No response rpcProtocol detected. Must be one of: ' . implode(', ', $this->toArray()) . '');
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
