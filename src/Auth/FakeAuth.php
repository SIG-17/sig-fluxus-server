<?php

namespace SIG\Server\Auth;

class FakeAuth implements AuthInterface
{
    public function logout(int $fd): bool
    {
        return true;
    }

    public function isAuthenticated(int $fd): bool
    {
        return true;
    }

    public function getToken(int $fd): string
    {
        return 'fake-token';
    }

    public function authenticate(int $fd, string|array $token): bool
    {
        return true;
    }

    public function getUserId(int $fd): int
    {
        return 1;
    }

    public function getUsername(int $fd): string
    {
        return 'fake-user';
    }

    public function getRoles(int $fd): array
    {
        return ['ws:user', 'ws:admin', 'ws:general'];
    }

    public function getPermissions(int $fd): array
    {
        return [];
    }
}