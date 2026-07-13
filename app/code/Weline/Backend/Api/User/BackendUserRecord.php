<?php

declare(strict_types=1);

namespace Weline\Backend\Api\User;

/** Immutable backend-user snapshot for declared module consumers. */
final readonly class BackendUserRecord
{
    public function __construct(
        private int $id,
        private string $username,
        private string $email,
        private string $avatar,
        private int $attemptTimes,
        private bool $deleted,
        private bool $enabled,
        private bool $sandbox,
        private string $createTime,
        private int $roleId,
        private string $roleName,
    ) {
    }

    public static function empty(): self
    {
        return new self(0, '', '', '', 0, false, false, false, '', 0, '');
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getAvatar(): string
    {
        return $this->avatar;
    }

    public function getAttemptTimes(): int
    {
        return $this->attemptTimes;
    }

    public function getIsDeleted(): bool
    {
        return $this->deleted;
    }

    public function getIsEnabled(): bool
    {
        return $this->enabled;
    }

    public function isSandboxAccount(): bool
    {
        return $this->sandbox;
    }

    public function getCreateTime(): string
    {
        return $this->createTime;
    }

    public function getRoleId(): int
    {
        return $this->roleId;
    }

    public function getRoleName(): string
    {
        return $this->roleName;
    }

    /** @return array<string,int|string> */
    public function toArray(): array
    {
        return [
            'user_id' => $this->id,
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'avatar' => $this->avatar,
            'attempt_times' => $this->attemptTimes,
            'is_deleted' => $this->deleted ? 1 : 0,
            'is_enabled' => $this->enabled ? 1 : 0,
            'is_sandbox' => $this->sandbox ? 1 : 0,
            'create_time' => $this->createTime,
            'role_id' => $this->roleId,
            'role_name' => $this->roleName,
        ];
    }
}
