<?php

declare(strict_types=1);

namespace Weline\Customer\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Session\Auth\AuthenticableInterface;

#[Table(comment: 'Frontend customer auth table')]
class Customer extends Model implements AuthenticableInterface
{
    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Customer ID')]
    public const schema_fields_ID = 'customer_id';
    #[Col(type: 'varchar', length: 100, nullable: true, comment: 'Customer email')]
    public const schema_fields_email = 'email';
    #[Col(type: 'varchar', length: 100, nullable: true, comment: 'Legacy username / login identifier')]
    public const schema_fields_username = 'username';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Password hash')]
    public const schema_fields_password = 'password';
    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Avatar')]
    public const schema_fields_avatar = 'avatar';
    #[Col(type: 'varchar', length: 45, nullable: true, comment: 'Last login IP')]
    public const schema_fields_login_ip = 'login_ip';
    #[Col(type: 'varchar', length: 45, nullable: true, comment: 'Last failed attempt IP')]
    public const schema_fields_attempt_ip = 'attempt_ip';
    #[Col(type: 'int', nullable: false, default: 0, comment: 'Failed login attempts')]
    public const schema_fields_attempt_times = 'attempt_times';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: 'Auth session ID')]
    public const schema_fields_sess_id = 'sess_id';
    #[Col(type: 'smallint', length: 1, nullable: false, default: 0, comment: 'Sandbox flag')]
    public const schema_fields_is_sandbox = 'is_sandbox';

    public const schema_primary_key = 'customer_id';
    public const schema_primary_keys = ['customer_id'];

    public function _init(): void
    {
    }

    public function getAttemptTimes(): int
    {
        return (int) $this->getData(self::schema_fields_attempt_times);
    }

    public function addAttemptTimes(): static
    {
        $this->setData(self::schema_fields_attempt_times, $this->getAttemptTimes() + 1);
        return $this;
    }

    public function getAttemptIp(): ?string
    {
        $value = $this->getData(self::schema_fields_attempt_ip);
        return $value === null ? null : (string) $value;
    }

    public function setAttemptIp(string $ip): static
    {
        return $this->setData(self::schema_fields_attempt_ip, $ip);
    }

    public function resetAttemptTimes(): static
    {
        $this->setData(self::schema_fields_attempt_times, 0);
        return $this;
    }

    public function getUsername(): ?string
    {
        $email = $this->getData(self::schema_fields_email);
        return is_string($email) && trim($email) !== '' ? $email : null;
    }

    public function setUsername(string $username): static
    {
        return $this->setEmail($username);
    }

    public function getEmail(): string
    {
        return (string) ($this->getData(self::schema_fields_email) ?? '');
    }

    public function setEmail(string $email): static
    {
        return $this->setData(self::schema_fields_email, strtolower(trim($email)));
    }

    public function getAvatar(): ?string
    {
        $value = $this->getData(self::schema_fields_avatar);
        return $value === null ? null : (string) $value;
    }

    public function setAvatar(string $avatar): static
    {
        return $this->setData(self::schema_fields_avatar, $avatar);
    }

    public function getPassword(): string
    {
        return (string) ($this->getData(self::schema_fields_password) ?? '');
    }

    public function setPassword(string $password): static
    {
        return $this->setData(self::schema_fields_password, password_hash($password, PASSWORD_DEFAULT));
    }

    public function getSessionId(): string
    {
        return (string) ($this->getData(self::schema_fields_sess_id) ?? '');
    }

    public function setSessionId(string $sessId): static
    {
        return $this->setData(self::schema_fields_sess_id, $sessId);
    }

    public function getLoginIp(): ?string
    {
        $value = $this->getData(self::schema_fields_login_ip);
        return $value === null ? null : (string) $value;
    }

    public function setLoginIp(string $ip): static
    {
        return $this->setData(self::schema_fields_login_ip, $ip);
    }

    public function isSandboxAccount(): bool
    {
        return (bool) $this->getData(self::schema_fields_is_sandbox);
    }

    public function setSandboxAccount(bool $flag): static
    {
        return $this->setData(self::schema_fields_is_sandbox, $flag ? 1 : 0);
    }

    public function getAuthIdentifier(): int|string
    {
        return (int) $this->getId();
    }

    public function getAuthUsername(): string
    {
        return $this->getEmail() !== '' ? $this->getEmail() : (string) $this->getUsername();
    }

    public function getAuthSessionId(): string
    {
        return $this->getSessionId();
    }

    public static function getAuthModelClass(): string
    {
        return self::class;
    }
}
