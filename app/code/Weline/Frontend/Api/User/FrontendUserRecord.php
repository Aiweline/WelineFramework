<?php

declare(strict_types=1);

namespace Weline\Frontend\Api\User;

/** Immutable admin projection of one legacy frontend user. */
final readonly class FrontendUserRecord
{
    public function __construct(
        private int $id,
        private string $username,
        private string $avatar,
        private string $loginIp,
        private int $attemptTimes,
        private string $sessionId,
        private int $tokenCount,
        private bool $sandbox,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getAvatar(): string
    {
        return $this->avatar;
    }

    public function getLoginIp(): string
    {
        return $this->loginIp;
    }

    public function getAttemptTimes(): int
    {
        return $this->attemptTimes;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getTokenCount(): int
    {
        return $this->tokenCount;
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    /** @return array<string, int|string> */
    public function toAdminArray(): array
    {
        return [
            'user_id' => $this->id,
            'username' => $this->username,
            'avatar' => $this->avatar,
            'login_ip' => $this->loginIp,
            'attempt_times' => $this->attemptTimes,
            'sess_id' => $this->sessionId,
            'token_count' => $this->tokenCount,
            'is_sandbox' => $this->sandbox ? 1 : 0,
        ];
    }
}
