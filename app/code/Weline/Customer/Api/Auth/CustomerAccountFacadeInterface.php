<?php

declare(strict_types=1);

namespace Weline\Customer\Api\Auth;

/** Customer-owned registration, identity and session facade for declared module consumers. */
interface CustomerAccountFacadeInterface
{
    public function current(): ?CustomerIdentity;

    public function find(int $customerId): ?CustomerIdentity;

    public function findByEmail(string $email): ?CustomerIdentity;

    /**
     * @param array<string, mixed> $profileData
     */
    public function register(string $email, string $password, array $profileData = []): CustomerIdentity;

    public function updateAvatar(CustomerIdentity $identity, string $avatar): CustomerIdentity;

    public function login(CustomerIdentity $identity): void;

    public function issueRememberToken(CustomerIdentity $identity, int $rememberDuration): void;
}
