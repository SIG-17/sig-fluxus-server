<?php

namespace SIG\Server\Auth;

interface AuthInterface
{

    public function logout(int $fd): bool;

    public function isAuthenticated(int $fd): bool;

    public function getToken(int $fd): string;

    public function authenticate(int $fd, string $token): bool;

    public function getUserId(int $fd): int;

    public function getUsername(int $fd): string;

    public function getRoles(int $fd): array;

    public function getPermissions(int $fd): array;


}