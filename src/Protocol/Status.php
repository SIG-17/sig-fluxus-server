<?php

namespace SIG\Server\Protocol;

enum Status: string
{
    /*
     * accepted
     * pending
     * success
     * error
     * ok
     */
    case accepted = 'accepted';
    case pending = 'pending';
    case success = 'success';
    case error = 'error';
    case ok = 'ok';
    case unknown = 'unknown';

    public function isValid(): bool
    {
        return $this->value !== 'unknown';
    }

    public static function fromString(string $status): self
    {
        return match ($status) {
            'accepted' => self::accepted,
            'pending' => self::pending,
            'success' => self::success,
            'error', 'failure' => self::error,
            'ok' => self::ok,
            default => self::unknown,
        };
    }

    public static function list(): array
    {
        $currentStatus = self::cases();
        return array_filter($currentStatus, static fn($status) => $status->isValid());
    }

}
