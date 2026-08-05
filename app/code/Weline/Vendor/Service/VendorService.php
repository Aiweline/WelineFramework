<?php

declare(strict_types=1);

namespace Weline\Vendor\Service;

use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\SystemConfig\Service\CommerceRolloutGate;
use Weline\Vendor\Model\VendorIdentity;

/**
 * Vendor facade：identity / website auth / product binding / ACL / eligibility（P4A-001）.
 *
 * capability=`vendor`；默认 mode off —— 可读查询，禁止新授权/绑定写路径。
 * Split/payout 属 P4A-002。
 */
final class VendorService
{
    public const CAPABILITY = 'vendor';

    public const ERROR_MODE_OFF = 'vendor_mode_off_blocks_mutation';

    private readonly VendorStoreAccountBindingService $accounts;
    private readonly CommerceRolloutGateInterface $rollout;

    public function __construct(
        private readonly VendorRegistryStore $registry,
        private readonly VendorAuthorizationService $authorization,
        private readonly VendorEligibilityService $eligibility,
        private readonly VendorProductBindingService $bindings,
        private readonly VendorAclGuard $acl,
        CommerceRolloutGateInterface $rollout,
        ?VendorStoreAccountBindingService $accounts = null,
    ) {
        $this->rollout = $rollout;
        $this->accounts = $accounts
            ?? new VendorStoreAccountBindingService($this->registry, $this->authorization);
    }

    public static function forTesting(?CommerceRolloutGateInterface $rollout = null): self
    {
        $registry = VendorRegistryStore::forTesting();
        $authorization = VendorAuthorizationService::forTesting();
        $accounts = VendorStoreAccountBindingService::forTesting($registry, $authorization);
        $eligibility = new VendorEligibilityService($registry, $authorization, $accounts);
        $bindings = VendorProductBindingService::forTesting($eligibility);
        $acl = VendorAclGuard::forTesting();
        $gate = $rollout ?? new CommerceRolloutGate();
        $gate->setMode(self::CAPABILITY, CommerceRolloutGateInterface::MODE_OFF);

        return new self($registry, $authorization, $eligibility, $bindings, $acl, $gate, $accounts);
    }

    public function registry(): VendorRegistryStore
    {
        return $this->registry;
    }

    public function authorization(): VendorAuthorizationService
    {
        return $this->authorization;
    }

    public function eligibility(): VendorEligibilityService
    {
        return $this->eligibility;
    }

    public function accounts(): VendorStoreAccountBindingService
    {
        return $this->accounts;
    }

    public function bindings(): VendorProductBindingService
    {
        return $this->bindings;
    }

    public function acl(): VendorAclGuard
    {
        return $this->acl;
    }

    public function rollout(): CommerceRolloutGateInterface
    {
        return $this->rollout;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function registerVendor(array $input, ?int $actorRoleId = null, ?int $actorWebsiteId = null, ?string $actorWebsiteCode = null): array
    {
        $this->assertMutable($actorWebsiteId ?? 0);
        if ($actorRoleId !== null) {
            $this->acl->assertRoleMayManage(
                $actorRoleId,
                $actorWebsiteId ?? 0,
                $actorWebsiteCode ?? 'default',
                ObjectAction::CREATE,
            );
        }

        return $this->registry->register($input);
    }

    /**
     * @return array<string, mixed>
     */
    public function authorizeWebsite(
        string $vendorId,
        int $websiteId,
        ?int $actorRoleId = null,
        string $websiteCode = 'default',
    ): array {
        VendorIdentity::assertWebsiteId($websiteId);
        $this->assertMutable($websiteId);
        $this->registry->get($vendorId);
        if ($actorRoleId !== null) {
            $this->acl->assertRoleMayManage($actorRoleId, $websiteId, $websiteCode, ObjectAction::UPDATE);
        }

        return $this->authorization->authorizeWebsite($vendorId, $websiteId);
    }

    /**
     * @return array<string, mixed>
     */
    public function revokeWebsite(
        string $vendorId,
        int $websiteId,
        ?int $actorRoleId = null,
        string $websiteCode = 'default',
    ): array {
        VendorIdentity::assertWebsiteId($websiteId);
        $this->assertMutable($websiteId);
        if ($actorRoleId !== null) {
            $this->acl->assertRoleMayManage($actorRoleId, $websiteId, $websiteCode, ObjectAction::UPDATE);
        }

        return $this->authorization->revoke($vendorId, $websiteId);
    }

    /**
     * @return array<string, mixed>
     */
    public function disableVendor(string $vendorId, ?int $actorRoleId = null, int $websiteId = 0, string $websiteCode = 'default'): array
    {
        $this->assertMutable($websiteId);
        if ($actorRoleId !== null) {
            $this->acl->assertRoleMayManage($actorRoleId, $websiteId, $websiteCode, ObjectAction::UPDATE);
        }

        return $this->registry->disable($vendorId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function bindAccount(
        array $input,
        ?int $actorRoleId = null,
        string $websiteCode = 'default',
    ): array {
        $websiteId = (int) ($input['website_id'] ?? -1);
        $storeId = (int) ($input['store_id'] ?? 0);
        VendorIdentity::assertWebsiteId($websiteId);
        $this->assertMutable($websiteId, $storeId);
        if ($actorRoleId !== null) {
            $this->acl->assertRoleMayManage(
                $actorRoleId,
                $websiteId,
                $websiteCode,
                ObjectAction::UPDATE,
            );
        }
        return $this->accounts->bind($input);
    }

    /** @return array<string, mixed> */
    public function revokeAccount(
        string $vendorId,
        int $websiteId,
        int $storeId,
        ?int $actorRoleId = null,
        string $websiteCode = 'default',
    ): array {
        VendorIdentity::assertWebsiteId($websiteId);
        $this->assertMutable($websiteId, $storeId);
        if ($actorRoleId !== null) {
            $this->acl->assertRoleMayManage(
                $actorRoleId,
                $websiteId,
                $websiteCode,
                ObjectAction::UPDATE,
            );
        }
        return $this->accounts->revoke($vendorId, $websiteId, $storeId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function bindProduct(array $input, ?int $actorRoleId = null, string $websiteCode = 'default'): array
    {
        $websiteId = (int) ($input['website_id'] ?? -1);
        $storeId = (int) ($input['store_id'] ?? 0);
        VendorIdentity::assertWebsiteId($websiteId);
        $this->assertMutable($websiteId, $storeId);
        if ($actorRoleId !== null) {
            $this->acl->assertRoleMayManage($actorRoleId, $websiteId, $websiteCode, ObjectAction::UPDATE);
        }

        return $this->bindings->bind($input);
    }

    /**
     * Read path：mode off 仍可查询 eligibility / auth（不写）.
     *
     * @param array{vendor_id:string,website_id:int,required_environment?:string} $request
     * @return array<string, mixed>
     */
    public function assertEligible(array $request): array
    {
        return $this->eligibility->assertEligible($request);
    }

    public function isAuthorized(string $vendorId, int $websiteId): bool
    {
        return $this->authorization->isAuthorized($vendorId, $websiteId);
    }

    private function assertMutable(int $websiteId, ?int $storeId = null): void
    {
        VendorIdentity::assertWebsiteId($websiteId);
        $websiteSubject = 'website:' . $websiteId;
        if ($this->rollout->mode(self::CAPABILITY) === CommerceRolloutGateInterface::MODE_OFF) {
            throw new VendorConflictException(
                self::ERROR_MODE_OFF,
                __('Vendor capability mode off，禁止写路径'),
                ['capability' => self::CAPABILITY, 'subject' => $websiteSubject],
            );
        }
        if ($storeId !== null && $storeId > 0) {
            $storeSubject = VendorRolloutGate::scopeKey($websiteId, $storeId);
            if ($this->rollout->isEffectivelyOn(self::CAPABILITY, $storeSubject)) {
                return;
            }
        }
        // Compatibility for pre-P4A tests and explicitly broad admin gates.
        $this->rollout->assertMutable(self::CAPABILITY, $websiteSubject);
    }
}
