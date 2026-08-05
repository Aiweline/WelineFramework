<?php

declare(strict_types=1);

namespace Weline\Vendor\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Acl\Api\Authorization\ObjectScopeGrantRecord;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Api\ProductIdentity;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\Vendor\Model\VendorIdentity;
use Weline\Vendor\Service\VendorConflictException;
use Weline\Vendor\Service\VendorService;
use Weline\Vendor\Service\VendorStoreAccountBindingService;
use Weline\Websites\Api\Catalog\Data\StoreSummary;

/**
 * TASK-P4A-001 / TEST-P4A-01 and TEST-P4A-04：identity、跨站授权、撤权、禁用、sandbox 隔离、ACL、mode off.
 */
final class VendorIdentityAuthorizationTest extends TestCase
{
    private VendorService $service;

    protected function setUp(): void
    {
        $this->service = VendorService::forTesting();
        $this->service->rollout()->setMode(
            VendorService::CAPABILITY,
            CommerceRolloutGateInterface::MODE_ALLOWLIST,
            ['website:0', 'website:1'],
        );
        $this->service->acl()->replaceGrantsForTesting([
            new ObjectScopeGrantRecord(
                9,
                false,
                ScopeIdentity::KIND_WEBSITE,
                0,
                'default',
                null,
                null,
                [ObjectAction::LIST, ObjectAction::VIEW, ObjectAction::CREATE, ObjectAction::UPDATE],
                1,
            ),
            new ObjectScopeGrantRecord(
                9,
                false,
                ScopeIdentity::KIND_WEBSITE,
                1,
                'shop-b',
                null,
                null,
                [ObjectAction::LIST, ObjectAction::VIEW, ObjectAction::CREATE, ObjectAction::UPDATE],
                1,
            ),
        ]);
        $this->service->accounts()->registerStoreForTesting(
            new StoreSummary(10, 0, 'test', 'Test Store', 'test', false, true, 'active', null),
        );
        $this->service->accounts()->registerStoreForTesting(
            new StoreSummary(11, 0, 'normal', 'Live Store', 'normal', true, true, 'active', null),
        );
        $this->service->accounts()->registerStoreForTesting(
            new StoreSummary(12, 1, 'shop-b-test', 'Shop B Test', 'test', false, true, 'active', null),
        );
        $this->registerProduct(101, 'SKU-ALPHA');
        $this->registerProduct(102, 'LIVE-ONLY');
        $this->registerProduct(103, 'BLOCKED');
    }

    public function testRegisterAuthorizeOnWebsiteZeroAndBindProduct(): void
    {
        $vendor = $this->service->registerVendor([
            'code' => 'acme_sandbox',
            'legal_name' => 'Acme Sandbox LLC',
            'environment' => VendorIdentity::ENV_SANDBOX,
        ], 9, 0, 'default');

        self::assertSame(VendorIdentity::ENV_SANDBOX, $vendor['environment']);
        self::assertSame(VendorIdentity::STATUS_ACTIVE, $vendor['status']);
        self::assertStringStartsWith('sandbox:', $vendor['account_ref']);

        $auth = $this->service->authorizeWebsite($vendor['vendor_id'], 0, 9, 'default');
        self::assertSame('authorized', $auth['status']);
        self::assertTrue($this->service->isAuthorized($vendor['vendor_id'], 0));
        $account = $this->service->bindAccount([
            'vendor_id' => $vendor['vendor_id'],
            'website_id' => 0,
            'store_id' => 10,
            'environment' => VendorIdentity::ENV_SANDBOX,
            'account_ref' => 'sandbox:acct_acme',
        ], 9, 'default');
        self::assertSame(VendorStoreAccountBindingService::STATUS_BOUND, $account['status']);

        $bound = $this->service->bindProduct([
            'vendor_id' => $vendor['vendor_id'],
            'website_id' => 0,
            'store_id' => 10,
            'product_sku' => 'SKU-ALPHA',
            'required_environment' => VendorIdentity::ENV_SANDBOX,
        ], 9, 'default');
        self::assertSame('bound', $bound['status']);
        self::assertTrue($this->service->bindings()->isBound($vendor['vendor_id'], 0, 'SKU-ALPHA'));
    }

    public function testCrossSiteAuthorizationDoesNotLeak(): void
    {
        $vendor = $this->service->registerVendor([
            'code' => 'site_a_only',
            'environment' => VendorIdentity::ENV_LIVE,
        ], 9, 0, 'default');
        $this->service->authorizeWebsite($vendor['vendor_id'], 0, 9, 'default');

        self::assertTrue($this->service->isAuthorized($vendor['vendor_id'], 0));
        self::assertFalse($this->service->isAuthorized($vendor['vendor_id'], 1));

        try {
            $this->service->assertEligible([
                'vendor_id' => $vendor['vendor_id'],
                'website_id' => 1,
            ]);
            self::fail('expected cross-site denial');
        } catch (VendorConflictException $e) {
            self::assertSame('vendor_not_eligible_unauthorized', $e->errorCode);
        }
    }

    public function testRevokeAndDisableBlockEligibility(): void
    {
        $vendor = $this->service->registerVendor([
            'code' => 'revocable',
            'environment' => VendorIdentity::ENV_SANDBOX,
        ], 9, 0, 'default');
        $this->service->authorizeWebsite($vendor['vendor_id'], 0, 9, 'default');

        $revoked = $this->service->revokeWebsite($vendor['vendor_id'], 0, 9, 'default');
        self::assertSame('revoked', $revoked['status']);
        self::assertFalse($this->service->isAuthorized($vendor['vendor_id'], 0));

        $this->service->authorizeWebsite($vendor['vendor_id'], 0, 9, 'default');
        $disabled = $this->service->disableVendor($vendor['vendor_id'], 9, 0, 'default');
        self::assertSame(VendorIdentity::STATUS_DISABLED, $disabled['status']);

        try {
            $this->service->assertEligible([
                'vendor_id' => $vendor['vendor_id'],
                'website_id' => 0,
            ]);
            self::fail('expected disabled denial');
        } catch (VendorConflictException $e) {
            self::assertSame('vendor_disabled', $e->errorCode);
        }
    }

    public function testSandboxLiveEnvironmentIsolation(): void
    {
        $sandbox = $this->service->registerVendor([
            'code' => 'env_pair',
            'environment' => VendorIdentity::ENV_SANDBOX,
        ], 9, 0, 'default');
        $this->service->authorizeWebsite($sandbox['vendor_id'], 0, 9, 'default');

        try {
            $this->service->bindProduct([
                'vendor_id' => $sandbox['vendor_id'],
                'website_id' => 0,
                'store_id' => 10,
                'product_sku' => 'LIVE-ONLY',
                'required_environment' => VendorIdentity::ENV_LIVE,
            ], 9, 'default');
            self::fail('expected env mismatch');
        } catch (VendorConflictException $e) {
            self::assertSame('vendor_environment_mismatch', $e->errorCode);
        }

        // Same code may exist independently under live environment.
        $live = $this->service->registerVendor([
            'code' => 'env_pair',
            'environment' => VendorIdentity::ENV_LIVE,
        ], 9, 0, 'default');
        self::assertNotSame($sandbox['vendor_id'], $live['vendor_id']);
        self::assertSame(VendorIdentity::ENV_LIVE, $live['environment']);
    }

    public function testAclDefaultDenyWithoutGrant(): void
    {
        $this->expectException(VendorConflictException::class);
        $this->expectExceptionMessageMatches('/vendor_acl_denied|角色无权/');
        $this->service->registerVendor([
            'code' => 'no_acl',
            'environment' => VendorIdentity::ENV_SANDBOX,
        ], 42, 0, 'default');
    }

    public function testTestStoreRejectsLiveAccountAndAcceptsSandboxOnly(): void
    {
        $live = $this->service->registerVendor([
            'code' => 'live_test_store',
            'environment' => VendorIdentity::ENV_LIVE,
        ], 9, 0, 'default');
        $this->service->authorizeWebsite($live['vendor_id'], 0, 9, 'default');
        try {
            $this->service->bindAccount([
                'vendor_id' => $live['vendor_id'],
                'website_id' => 0,
                'store_id' => 10,
                'environment' => VendorIdentity::ENV_LIVE,
                'account_ref' => 'live:acct_forbidden',
            ], 9, 'default');
            self::fail('expected live account rejection for test Store');
        } catch (VendorConflictException $e) {
            self::assertSame(VendorStoreAccountBindingService::ERROR_LIVE_ON_TEST, $e->errorCode);
        }

        $sandbox = $this->service->registerVendor([
            'code' => 'sandbox_test_store',
            'environment' => VendorIdentity::ENV_SANDBOX,
        ], 9, 0, 'default');
        $this->service->authorizeWebsite($sandbox['vendor_id'], 0, 9, 'default');
        $bound = $this->service->bindAccount([
            'vendor_id' => $sandbox['vendor_id'],
            'website_id' => 0,
            'store_id' => 10,
            'environment' => VendorIdentity::ENV_SANDBOX,
            'account_ref' => 'sandbox:acct_allowed',
        ], 9, 'default');
        self::assertSame('test', $bound['store_mode_snapshot']);
        self::assertSame(VendorIdentity::ENV_SANDBOX, $bound['environment']);

        $normal = $this->service->bindAccount([
            'vendor_id' => $live['vendor_id'],
            'website_id' => 0,
            'store_id' => 11,
            'environment' => VendorIdentity::ENV_LIVE,
            'account_ref' => 'live:acct_allowed',
        ], 9, 'default');
        self::assertSame('normal', $normal['store_mode_snapshot']);
    }

    public function testProductBindingRejectsUnknownIdentity(): void
    {
        $vendor = $this->service->registerVendor([
            'code' => 'unknown_product',
            'environment' => VendorIdentity::ENV_SANDBOX,
        ], 9, 0, 'default');
        $this->service->authorizeWebsite($vendor['vendor_id'], 0, 9, 'default');
        $this->service->bindAccount([
            'vendor_id' => $vendor['vendor_id'],
            'website_id' => 0,
            'store_id' => 10,
            'environment' => VendorIdentity::ENV_SANDBOX,
            'account_ref' => 'sandbox:acct_unknown_product',
        ], 9, 'default');

        try {
            $this->service->bindProduct([
                'vendor_id' => $vendor['vendor_id'],
                'website_id' => 0,
                'store_id' => 10,
                'product_sku' => 'DOES-NOT-EXIST',
            ], 9, 'default');
            self::fail('expected missing Product identity');
        } catch (VendorConflictException $e) {
            self::assertSame('vendor_product_identity_not_found', $e->errorCode);
        }
    }

    public function testModeOffBlocksMutationButAllowsReadEligibilityPathSetup(): void
    {
        // Prepare under allowlist, then switch off.
        $vendor = $this->service->registerVendor([
            'code' => 'mode_off_demo',
            'environment' => VendorIdentity::ENV_SANDBOX,
        ], 9, 0, 'default');
        $this->service->authorizeWebsite($vendor['vendor_id'], 0, 9, 'default');

        $this->service->rollout()->setMode(VendorService::CAPABILITY, CommerceRolloutGateInterface::MODE_OFF);

        try {
            $this->service->bindProduct([
                'vendor_id' => $vendor['vendor_id'],
                'website_id' => 0,
                'product_sku' => 'BLOCKED',
            ], 9, 'default');
            self::fail('expected mode off');
        } catch (VendorConflictException $e) {
            self::assertSame(VendorService::ERROR_MODE_OFF, $e->errorCode);
        }

        // Read/eligibility still works under mode off (no mutation).
        $eligible = $this->service->assertEligible([
            'vendor_id' => $vendor['vendor_id'],
            'website_id' => 0,
            'required_environment' => VendorIdentity::ENV_SANDBOX,
        ]);
        self::assertTrue($eligible['eligible']);
        self::assertFalse($this->service->bindings()->isBound($vendor['vendor_id'], 0, 'BLOCKED'));
    }

    private function registerProduct(int $registryId, string $sku): void
    {
        $suffix = str_pad((string) $registryId, 12, '0', STR_PAD_LEFT);
        $this->service->bindings()->registerProductForTesting(new ProductIdentity(
            $registryId,
            $sku,
            '00000000-0000-4000-8000-' . $suffix,
            '10000000-0000-4000-8000-' . $suffix,
            str_repeat('ab', 32),
        ));
    }
}
