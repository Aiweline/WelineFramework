<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Cdn\Extends\Module\Weline_Framework\Query\CdnQueryProvider;

if (!defined('BP')) {
    require dirname(__DIR__, 7) . '/app/bootstrap.php';
}

final class CdnQueryProviderDescriptorTest extends TestCase
{
    public function testFpcManagementContractsRemainBackendOnlyAndPreserveNullableOverrides(): void
    {
        $provider = (new ReflectionClass(CdnQueryProvider::class))->newInstanceWithoutConstructor();
        $descriptor = (new \Weline\Framework\Service\Query\BinQueryDescriptorAttributeResolver())->merge($provider, $provider->getDescriptor());
        $operations = array_column($descriptor['operations'], null, 'name');
        foreach ([
            'listFpcPolicies' => ['read', 'Weline_Cdn::cdn_api_rules_list'],
            'saveFpcPolicyOverride' => ['write', 'Weline_Cdn::cdn_api_rules_toggle'],
            'restoreFpcPolicyInheritance' => ['write', 'Weline_Cdn::cdn_api_rules_toggle'],
            'collectFpcPolicies' => ['write', 'Weline_Cdn::cdn_api_rules_collect'],
            'listFpcSyncRecords' => ['read', 'Weline_Cdn::cdn_api_rules_list'],
            'retryFpcSync' => ['write', 'Weline_Cdn::cdn_rules_push'],
        ] as $name => [$mode, $source]) {
            self::assertArrayHasKey($name, $operations);
            self::assertTrue($operations[$name]['frontend']);
            self::assertTrue($operations[$name]['backend']);
            self::assertFalse($operations[$name]['external']);
            self::assertFalse($operations[$name]['graph']);
            self::assertSame('backend', $operations[$name]['auth']);
            self::assertSame($mode, $operations[$name]['mode']);
            self::assertSame(['kind' => 'source', 'source_id' => $source], $operations[$name]['backend_acl']);
            self::assertArrayNotHasKey('cache', $operations[$name]);
        }
        $override = array_column($operations['saveFpcPolicyOverride']['params'], null, 'name');
        foreach (['enabled', 'ttl'] as $field) {
            self::assertFalse($override[$field]['required']);
            self::assertTrue($override[$field]['nullable']);
            self::assertArrayNotHasKey('default', $override[$field]);
        }
        self::assertSame(1, $override['ttl']['min']);
        $listParams = array_column($operations['listFpcPolicies']['params'], null, 'name');
        $restoreParams = array_column($operations['restoreFpcPolicyInheritance']['params'], null, 'name');
        self::assertSame(100, $listParams['page_size']['max']);
        self::assertSame(['enabled', 'ttl'], $restoreParams['fields']['default']);
    }

    public function testApiRuleBrowserOperationsRequireTheirControllerAclSources(): void
    {
        $provider = (new ReflectionClass(CdnQueryProvider::class))->newInstanceWithoutConstructor();
        $operations = [];
        foreach ($provider->getDescriptor()['operations'] as $operation) {
            $operations[(string)($operation['name'] ?? '')] = $operation;
        }

        $expected = [
            'collectApiRules' => 'Weline_Cdn::cdn_api_rules_collect',
            'toggleApiRule' => 'Weline_Cdn::cdn_api_rules_toggle',
            'deleteApiRule' => 'Weline_Cdn::cdn_api_rules_delete',
        ];
        foreach ($expected as $name => $sourceId) {
            self::assertArrayHasKey($name, $operations);
            self::assertTrue($operations[$name]['frontend']);
            self::assertTrue($operations[$name]['backend']);
            self::assertSame('backend', $operations[$name]['auth']);
            self::assertSame('write', $operations[$name]['mode']);
            self::assertSame(
                ['kind' => 'source', 'source_id' => $sourceId],
                $operations[$name]['backend_acl'],
            );
        }
    }

    public function testRulesWorkbenchBrowserOperationsRequireTheirControllerAclSources(): void
    {
        $provider = (new ReflectionClass(CdnQueryProvider::class))->newInstanceWithoutConstructor();
        $operations = [];
        foreach ($provider->getDescriptor()['operations'] as $operation) {
            $operations[(string)($operation['name'] ?? '')] = $operation;
        }

        $expected = [
            'getGlobalRules' => ['read', 'Weline_Cdn::cdn_rules_list'],
            'getDomainRules' => ['read', 'Weline_Cdn::cdn_rules_list'],
            'saveGlobalRules' => ['write', 'Weline_Cdn::cdn_rules_global_save'],
            'saveDomainRules' => ['write', 'Weline_Cdn::cdn_rules_domain_save'],
            'importDomainRules' => ['write', 'Weline_Cdn::cdn_rules_import_do'],
            'pushDomainRules' => ['write', 'Weline_Cdn::cdn_rules_push'],
            'listEnabledDomains' => ['read', 'Weline_Cdn::cdn_rules_list'],
        ];
        foreach ($expected as $name => [$mode, $sourceId]) {
            self::assertArrayHasKey($name, $operations);
            self::assertTrue($operations[$name]['frontend']);
            self::assertTrue($operations[$name]['backend']);
            self::assertSame('backend', $operations[$name]['auth']);
            self::assertSame($mode, $operations[$name]['mode']);
            self::assertSame(
                ['kind' => 'source', 'source_id' => $sourceId],
                $operations[$name]['backend_acl'],
            );
        }
    }

    public function testAccountAndDomainWritesPublishValidatedBackendContracts(): void
    {
        $provider = (new ReflectionClass(CdnQueryProvider::class))->newInstanceWithoutConstructor();
        $operations = [];
        foreach ($provider->getDescriptor()['operations'] as $operation) {
            $operations[(string)($operation['name'] ?? '')] = $operation;
        }

        $expectedSources = [
            'saveAccount' => 'Weline_Cdn::cdn_account_save',
            'deleteAccount' => 'Weline_Cdn::cdn_account_delete',
            'setDefaultAccount' => 'Weline_Cdn::cdn_account_set_default',
            'saveDomain' => 'Weline_Cdn::cdn_domain_save',
        ];
        foreach ($expectedSources as $name => $sourceId) {
            self::assertArrayHasKey($name, $operations);
            self::assertTrue($operations[$name]['frontend']);
            self::assertTrue($operations[$name]['backend']);
            self::assertSame('backend', $operations[$name]['auth']);
            self::assertSame('write', $operations[$name]['mode']);
            self::assertFalse($operations[$name]['graph']);
            self::assertSame(
                ['kind' => 'source', 'source_id' => $sourceId],
                $operations[$name]['backend_acl'],
            );
            self::assertSame(['type' => 'array'], $operations[$name]['returns']);
            self::assertArrayNotHasKey('id', $operations[$name]['params']);
        }

        $accountParams = $operations['saveAccount']['params'];
        self::assertSame(['account_id', 'adapter', 'name', 'description', 'credentials', 'is_default', 'status'], array_keys($accountParams));
        self::assertSame(['type' => 'int', 'required' => false, 'min' => 1, 'description' => __('账户 ID（更新时必填）')], $accountParams['account_id']);
        self::assertSame('string', $accountParams['adapter']['type']);
        self::assertTrue($accountParams['adapter']['required']);
        self::assertSame(50, $accountParams['adapter']['max_length']);
        self::assertSame('string', $accountParams['name']['type']);
        self::assertTrue($accountParams['name']['required']);
        self::assertSame(128, $accountParams['name']['max_length']);
        self::assertSame('array', $accountParams['credentials']['type']);
        self::assertSame(32, $accountParams['credentials']['max_items']);
        self::assertSame('bool', $accountParams['is_default']['type']);

        self::assertSame(
            ['account_id' => ['type' => 'int', 'required' => true, 'min' => 1, 'description' => __('账户 ID')]],
            $operations['deleteAccount']['params'],
        );
        self::assertSame(
            ['account_id' => ['type' => 'int', 'required' => true, 'min' => 1, 'description' => __('账户 ID')]],
            $operations['setDefaultAccount']['params'],
        );
        $domainParams = $operations['saveDomain']['params'];
        self::assertSame(
            ['domain_id', 'site_id', 'adapter', 'domain_name', 'zone_id', 'account_id', 'inherit_default', 'warmup_interval_seconds', 'enabled'],
            array_keys($domainParams),
        );
        self::assertSame(0, $domainParams['site_id']['min']);
        self::assertTrue($domainParams['site_id']['required']);
        self::assertSame(50, $domainParams['adapter']['max_length']);
        self::assertSame(255, $domainParams['domain_name']['max_length']);
        self::assertSame(128, $domainParams['zone_id']['max_length']);
        self::assertSame('bool', $domainParams['inherit_default']['type']);
        self::assertSame(60, $domainParams['warmup_interval_seconds']['min']);
        self::assertSame('bool', $domainParams['enabled']['type']);
    }

    public function testWarmupProviderTreeOperationsRequireDedicatedAclSources(): void
    {
        $provider = (new ReflectionClass(CdnQueryProvider::class))->newInstanceWithoutConstructor();
        $operations = [];
        foreach ($provider->getDescriptor()['operations'] as $operation) {
            $operations[(string)($operation['name'] ?? '')] = $operation;
        }

        $expected = [
            'listWarmupProviders' => ['read', 'Weline_Cdn::cdn_warmup_list'],
            'listWarmupUrls' => ['read', 'Weline_Cdn::cdn_warmup_list'],
            'collectWarmup' => ['write', 'Weline_Cdn::cdn_warmup_collect'],
            'executeWarmup' => ['write', 'Weline_Cdn::cdn_warmup_execute'],
        ];
        foreach ($expected as $name => [$mode, $sourceId]) {
            self::assertArrayHasKey($name, $operations);
            self::assertTrue($operations[$name]['frontend']);
            self::assertTrue($operations[$name]['backend']);
            self::assertSame('backend', $operations[$name]['auth']);
            self::assertSame($mode, $operations[$name]['mode']);
            self::assertSame(
                ['kind' => 'source', 'source_id' => $sourceId],
                $operations[$name]['backend_acl'],
            );
        }

        $executeParams = $operations['executeWarmup']['params'];
        $paramNames = array_map(static fn ($p) => is_array($p) ? ($p['name'] ?? '') : '', $executeParams);
        self::assertContains('domain_id', $paramNames);
        self::assertContains('provider', $paramNames);
        self::assertContains('target_scope', $paramNames);
        self::assertContains('site_id', $paramNames);
        $collectParams = $operations['collectWarmup']['params'];
        $collectNames = array_map(static fn ($p) => is_array($p) ? ($p['name'] ?? '') : '', $collectParams);
        self::assertContains('provider', $collectNames);
        self::assertContains('domain_id', $collectNames);
        self::assertContains('target_scope', $collectNames);
        self::assertContains('site_id', $collectNames);
        $listParams = $operations['listWarmupProviders']['params'];
        $listNames = array_map(static fn ($p) => is_array($p) ? ($p['name'] ?? '') : '', $listParams);
        self::assertContains('target_scope', $listNames);
    }
}
