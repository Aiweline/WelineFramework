<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Weline\Acl\Api\Authorization\BackendObjectAuthorizationGuardInterface;
use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Acl\Api\Authorization\ObjectAuthorizationResult;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\SystemConfig\Api\Scope\ScopeUiStateInterface;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Service\ConfigEmbedResolver;
use Weline\SystemConfig\Service\SystemConfigCenterService;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;
use Weline\SystemConfig\Service\SystemConfigTemplateService;

final class ConfigEmbedResolverTest extends TestCase
{
    private SystemConfigTemplateService&MockObject $templates;
    private SystemConfigCenterService&MockObject $center;
    private BackendObjectAuthorizationGuardInterface&MockObject $guard;
    private SystemConfig&MockObject $systemConfig;
    private ConfigEmbedResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->templates = $this->createMock(SystemConfigTemplateService::class);
        $this->center = $this->createMock(SystemConfigCenterService::class);
        $this->guard = $this->createMock(BackendObjectAuthorizationGuardInterface::class);
        $this->systemConfig = $this->createMock(SystemConfig::class);
        $this->systemConfig->method('normalizeScope')->willReturnCallback(
            static fn (?string $scope = null): string => trim((string)$scope) !== ''
                ? trim((string)$scope)
                : SystemConfig::SCOPE_GLOBAL
        );
        $this->systemConfig->method('normalizeLocale')->willReturnCallback(
            static fn (?string $locale = null): string => trim((string)$locale) !== ''
                ? trim((string)$locale)
                : SystemConfig::LOCALE_DEFAULT
        );

        $targetScope = new SystemConfigTargetScopeService(
            new SystemConfigScopeResolver(),
            new ConfigEmbedTestScopeIdentityCatalog(),
            new ConfigEmbedInMemoryScopeUiState(),
        );

        $this->resolver = new ConfigEmbedResolver(
            $this->templates,
            $this->center,
            $targetScope,
            $this->guard,
            $this->systemConfig,
        );
    }

    public function testMissingModuleReturnsBlockError(): void
    {
        $view = $this->resolver->resolve(['module' => ''], []);
        self::assertFalse($view['ok']);
        self::assertStringContainsString('module', (string)$view['error']);
        self::assertSame([], $view['items']);
    }

    public function testFieldSelectionMarksUndeclaredWithoutBlockingDeclared(): void
    {
        $this->stubAcl(true, true);
        $this->stubDeclaredEnabledField();

        $view = $this->resolver->resolve([
            'module' => 'Weline_Demo',
            'area' => 'backend',
            'fields' => 'demo/enabled,demo/missing',
        ], []);

        self::assertTrue($view['ok']);
        self::assertCount(2, $view['items']);
        self::assertSame(ConfigEmbedResolver::STATUS_OK, $view['items'][0]['status']);
        self::assertTrue($view['items'][0]['editable']);
        self::assertSame(ConfigEmbedResolver::STATUS_UNDECLARED, $view['items'][1]['status']);
        self::assertFalse($view['items'][1]['editable']);
        self::assertSame('demo/missing', $view['items'][1]['key']);
        self::assertSame(SystemConfig::SCOPE_GLOBAL, $view['storage_scope']);
    }

    public function testUrlStoreScopeIsPassedThrough(): void
    {
        $this->stubAcl(true, true);
        $this->templates->method('getTemplates')->willReturn([]);
        $this->templates->method('getTemplateMeta')->willReturn(null);

        $view = $this->resolver->resolve([
            'module' => 'Weline_Demo',
            'field' => 'demo/enabled',
        ], ['target_scope' => 'shop.main.default']);

        self::assertSame('shop.main.default', $view['storage_scope']);
        self::assertSame('shop', $view['target']['website_code']);
        self::assertSame('main', $view['target']['store_code']);
        self::assertSame(ConfigEmbedResolver::STATUS_UNDECLARED, $view['items'][0]['status'] ?? null);
    }

    public function testNoUpdateAclMarksForbidden(): void
    {
        $this->stubAcl(true, false);
        $this->stubDeclaredEnabledField();

        $view = $this->resolver->resolve([
            'module' => 'Weline_Demo',
            'field' => 'demo/enabled',
        ], []);

        self::assertTrue($view['ok']);
        self::assertFalse($view['can_update']);
        self::assertSame(ConfigEmbedResolver::STATUS_FORBIDDEN, $view['items'][0]['status']);
        self::assertFalse($view['items'][0]['editable']);
        self::assertStringContainsString('UPDATE', (string)$view['deny_tip']);
    }

    public function testSensitiveFieldIsReadonlyWithDeeplinkStatus(): void
    {
        $this->stubAcl(true, true);
        $this->templates->method('getTemplates')->willReturn([
            ['module' => 'Weline_Demo', 'area' => 'backend', 'code' => 'demo'],
        ]);
        $this->templates->method('getTemplateMeta')->willReturn([
            'module' => 'Weline_Demo',
            'area' => 'backend',
            'code' => 'demo',
            'fields' => [
                [
                    'key' => 'demo/secret',
                    'label' => '密钥',
                    'type' => 'secret',
                    'scope' => 'global',
                    'value-type' => 'encrypted',
                ],
            ],
            'hints' => [],
            'adapters' => [],
        ]);
        $this->center->method('getFieldObject')->willReturn([
            'key' => 'demo/secret',
            'label' => '密钥',
            'description' => '',
            'type' => 'secret',
            'value_type' => 'encrypted',
            'value' => '***',
            'display_value' => '***',
            'options' => [],
            'is_sensitive' => true,
            'base_version' => 0,
            'field_found' => true,
            'has_override' => false,
            'source' => null,
            'group' => '',
            'template' => ['code' => 'demo'],
        ]);

        $view = $this->resolver->resolve([
            'module' => 'Weline_Demo',
            'field' => 'demo/secret',
        ], []);

        self::assertSame(ConfigEmbedResolver::STATUS_SENSITIVE_READONLY, $view['items'][0]['status']);
        self::assertFalse($view['items'][0]['editable']);
    }

    public function testAttributeScopeOverridesUrlTarget(): void
    {
        $this->stubAcl(true, true);
        $this->templates->method('getTemplates')->willReturn([]);
        $this->templates->method('getTemplateMeta')->willReturn(null);

        $view = $this->resolver->resolve([
            'module' => 'Weline_Demo',
            'field' => 'demo/enabled',
            'website_code' => 'shop',
            'scope_kind' => 'website',
            'target_scope' => 'shop.default.default',
        ], ['target_scope' => 'other.main.default']);

        self::assertSame('shop.default.default', $view['storage_scope']);
        self::assertSame('shop', $view['target']['website_code']);
        self::assertSame('website', $view['target']['kind'] ?? $view['target']['scope_kind'] ?? '');
    }

    private function stubAcl(bool $canView, bool $canUpdate): void
    {
        $this->guard->method('check')->willReturnCallback(
            static function (string $action) use ($canView, $canUpdate): ObjectAuthorizationResult {
                if ($action === ObjectAction::VIEW) {
                    return $canView
                        ? ObjectAuthorizationResult::allow('view', 3)
                        : ObjectAuthorizationResult::deny('no_view');
                }
                if ($action === ObjectAction::UPDATE) {
                    return $canUpdate
                        ? ObjectAuthorizationResult::allow('update', 3)
                        : ObjectAuthorizationResult::deny('no_update');
                }

                return ObjectAuthorizationResult::deny('other');
            }
        );
    }

    private function stubDeclaredEnabledField(): void
    {
        $this->templates->method('getTemplates')->willReturn([
            ['module' => 'Weline_Demo', 'area' => 'backend', 'code' => 'demo'],
        ]);
        $this->templates->method('getTemplateMeta')->willReturn([
            'module' => 'Weline_Demo',
            'area' => 'backend',
            'code' => 'demo',
            'fields' => [
                [
                    'key' => 'demo/enabled',
                    'label' => '启用',
                    'type' => 'switch',
                    'scope' => 'global,website,store',
                    'group' => 'main',
                ],
            ],
            'hints' => [],
            'adapters' => [],
        ]);
        $this->center->method('getFieldObject')->willReturn([
            'key' => 'demo/enabled',
            'label' => '启用',
            'description' => '',
            'type' => 'switch',
            'value_type' => 'bool',
            'value' => true,
            'display_value' => '1',
            'options' => [],
            'is_sensitive' => false,
            'base_version' => 1,
            'field_found' => true,
            'has_override' => true,
            'source' => null,
            'group' => 'main',
            'template' => ['code' => 'demo'],
        ]);
    }
}

final class ConfigEmbedInMemoryScopeUiState implements ScopeUiStateInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $values = [];

    public function read(string $key): ?array
    {
        return $this->values[$key] ?? null;
    }

    public function write(string $key, array $value): void
    {
        $this->values[$key] = $value;
    }
}

final class ConfigEmbedTestScopeIdentityCatalog implements ScopeIdentityCatalogInterface
{
    public function websiteIdForCode(string $websiteCode): int
    {
        if (strtolower(trim($websiteCode)) !== 'shop') {
            throw new \InvalidArgumentException('system_config_website_scope_not_found');
        }

        return 17;
    }

    public function authoritativeIdentity(ScopeIdentity $candidate): ScopeIdentity
    {
        if ($candidate->isGlobal()) {
            return ScopeIdentity::global($candidate->contextVersion);
        }
        if ($candidate->websiteId !== 17 || $candidate->websiteCode !== 'shop') {
            throw new \InvalidArgumentException('system_config_scope_claim_identity_mismatch');
        }
        if ($candidate->scopeKind === ScopeIdentity::KIND_WEBSITE) {
            return $candidate;
        }
        if ($candidate->storeCode !== 'main') {
            throw new \InvalidArgumentException('system_config_store_scope_not_found');
        }
        if ($candidate->scopeKind === ScopeIdentity::KIND_STORE) {
            return $candidate;
        }
        if ($candidate->channelCode !== 'app') {
            throw new \InvalidArgumentException('system_config_channel_scope_not_found');
        }

        return $candidate;
    }

    public function options(): array
    {
        return [[
            'code' => 'shop',
            'name' => 'Shop',
            'website_id' => 17,
            'stores' => [[
                'id' => 31,
                'code' => 'main',
                'name' => 'Main',
                'store_mode' => ScopeIdentity::MODE_NORMAL,
                'channels' => [['id' => 41, 'code' => 'app', 'name' => 'App']],
            ]],
        ]];
    }
}
