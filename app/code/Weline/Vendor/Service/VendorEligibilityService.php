<?php

declare(strict_types=1);

namespace Weline\Vendor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Vendor\Model\VendorIdentity;

/**
 * Sandbox/live + active + website-auth eligibility（P4A-001）.
 *
 * test/sandbox Vendor must never be treated as live-eligible.
 */
final class VendorEligibilityService
{
    public const ERROR_DISABLED = 'vendor_disabled';
    public const ERROR_ENV_MISMATCH = 'vendor_environment_mismatch';
    public const ERROR_NOT_AUTHORIZED = 'vendor_not_eligible_unauthorized';

    public function __construct(
        private readonly VendorRegistryStore $registry,
        private readonly VendorAuthorizationService $authorization,
        private readonly ?VendorStoreAccountBindingService $accounts = null,
    ) {
    }

    public static function forTesting(
        ?VendorRegistryStore $registry = null,
        ?VendorAuthorizationService $authorization = null,
        ?VendorStoreAccountBindingService $accounts = null,
    ): self {
        return new self(
            $registry ?? VendorRegistryStore::forTesting(),
            $authorization ?? VendorAuthorizationService::forTesting(),
            $accounts,
        );
    }

    /**
     * @param array{vendor_id:string,website_id:int,store_id?:int,required_environment?:string} $request
     * @return array<string, mixed>
     */
    public function assertEligible(array $request): array
    {
        $vendorId = (string) ($request['vendor_id'] ?? '');
        $websiteId = (int) ($request['website_id'] ?? -1);
        VendorIdentity::assertWebsiteId($websiteId);
        $vendor = $this->registry->get($vendorId);

        if ((string) $vendor['status'] !== VendorIdentity::STATUS_ACTIVE) {
            throw new VendorConflictException(
                self::ERROR_DISABLED,
                __('Vendor 已禁用：%{1}', [$vendorId]),
                ['vendor_id' => $vendorId],
            );
        }

        $required = array_key_exists('required_environment', $request)
            ? VendorIdentity::assertEnvironment((string) $request['required_environment'])
            : null;
        if ($required !== null && (string) $vendor['environment'] !== $required) {
            throw new VendorConflictException(
                self::ERROR_ENV_MISMATCH,
                __('Vendor environment 不匹配：%{1} 需要 %{2}', [$vendor['environment'], $required]),
                [
                    'vendor_id' => $vendorId,
                    'environment' => $vendor['environment'],
                    'required_environment' => $required,
                ],
            );
        }

        try {
            $auth = $this->authorization->assertAuthorized($vendorId, $websiteId);
        } catch (VendorConflictException $e) {
            throw new VendorConflictException(
                self::ERROR_NOT_AUTHORIZED,
                $e->getMessage(),
                $e->context,
                0,
                $e,
            );
        }

        $result = [
            'ok' => true,
            'eligible' => true,
            'vendor' => $vendor,
            'authorization' => $auth,
        ];
        if (array_key_exists('store_id', $request)) {
            $storeId = (int) $request['store_id'];
            if ($storeId <= 0) {
                throw new VendorConflictException(
                    VendorStoreAccountBindingService::ERROR_STORE_NOT_FOUND,
                    __('Store ID 无效'),
                );
            }
            $result['account_binding'] = $this->accountBindings()->assertBound(
                $vendorId,
                $websiteId,
                $storeId,
                $required,
            );
        }

        return $result;
    }

    private function accountBindings(): VendorStoreAccountBindingService
    {
        $accounts = $this->accounts
            ?? ObjectManager::getInstance(VendorStoreAccountBindingService::class);
        if (!$accounts instanceof VendorStoreAccountBindingService) {
            throw new \LogicException('VendorStoreAccountBindingService is unavailable');
        }
        return $accounts;
    }
}
