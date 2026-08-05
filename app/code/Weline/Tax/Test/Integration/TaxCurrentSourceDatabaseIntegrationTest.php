<?php

declare(strict_types=1);

namespace Weline\Tax\Test\Integration;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Service\SystemConfigTemplateService;
use Weline\Tax\Api\TaxEngineInterface;
use Weline\Tax\Model\TaxClass;
use Weline\Tax\Model\TaxRule;
use Weline\Tax\Service\TaxEngine;
use Weline\Tax\Service\TaxLkgStore;
use Weline\Tax\Service\TaxScopeConfig;
use Weline\Tax\Service\TaxShadowComparator;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/**
 * Real configured-database proof for TEST-P3B-01.
 *
 * ORM current source, Tax-owned LKG persistence and rollback share the
 * Framework transaction coordinator. The shadow engine is rebuilt from a
 * frozen snapshot, not from the ORM loader.
 */
final class TaxCurrentSourceDatabaseIntegrationTest extends TestCase
{
    public function testPublishedTaxEngineInterfaceResolvesToProductionEngine(): void
    {
        self::assertInstanceOf(
            TaxEngine::class,
            ObjectManager::getInstance(TaxEngineInterface::class),
        );
    }

    public function testOrmCurrentSourceAgainstFrozenShadowPersistsVerifiedLkg(): void
    {
        $store = ObjectManager::getInstance(StoreCatalogInterface::class)->defaultStore(0);
        self::assertNotNull($store, 'Website 0 must have an installed default Store');

        $transaction = new TaxClass();
        $transaction->beginTransaction();
        $suffix = strtolower(bin2hex(random_bytes(5)));
        $classCode = 'p3b001_' . $suffix;
        $jurisdiction = 'ZZ|' . strtoupper(substr($suffix, 0, 8));
        $scopeConfig = TaxScopeConfig::forTesting([
            'scope_key' => 'integration|0|' . $store->id,
            'default_jurisdiction' => $jurisdiction,
        ]);
        $primary = new TaxEngine(scopeConfig: $scopeConfig);
        $lkg = new TaxLkgStore();
        $ruleSetHash = null;
        $scopeKey = null;

        try {
            (new TaxClass())->setData([
                TaxClass::schema_fields_WEBSITE_ID => 0,
                TaxClass::schema_fields_CLASS_CODE => $classCode,
                TaxClass::schema_fields_NAME => 'P3B-001 integration',
                TaxClass::schema_fields_ENABLED => 1,
            ])->save();
            (new TaxRule())->setData([
                TaxRule::schema_fields_WEBSITE_ID => 0,
                TaxRule::schema_fields_CLASS_CODE => $classCode,
                TaxRule::schema_fields_JURISDICTION_KEY => $jurisdiction,
                TaxRule::schema_fields_RATE_BPS => 1300,
                TaxRule::schema_fields_RULE_VERSION => 1,
                TaxRule::schema_fields_ROUNDING => TaxRule::ROUNDING_HALF_UP,
                TaxRule::schema_fields_ENABLED => 1,
            ])->save();

            $requests = $this->requests($store->id, $classCode, $jurisdiction, 100);
            $snapshot = $primary->ruleSetSnapshot($requests[0]);
            $shadow = TaxEngine::fromSnapshot($snapshot);
            $report = (new TaxShadowComparator($primary, $shadow, $lkg))->observe($requests);

            self::assertTrue($report['ok'], json_encode($report['diffs']));
            self::assertSame(100, $report['quote_count']);
            self::assertSame(100, $report['unique_quote_count']);
            self::assertSame(0, $report['classified_diff_count']);
            self::assertSame(0, $report['unclassified_diff_count']);
            self::assertTrue($report['conserved']);
            self::assertLessThanOrEqual(1.0, $report['max_line_rounding_drift']);
            self::assertNotNull($report['lkg_id']);

            $ruleSetHash = (string) $report['rule_set_hash'];
            $scopeKey = (string) $report['scope_key'];
            $freshSnapshot = (new TaxLkgStore())->requireVerified(
                TaxEngine::SCHEMA_VERSION,
                $ruleSetHash,
                $scopeKey,
            );
            $current = $primary->calculate($requests[99]);
            $replayed = TaxEngine::fromSnapshot($freshSnapshot)->calculate($requests[99]);
            self::assertSame($current['tax_amount_minor'], $replayed['tax_amount_minor']);
            self::assertSame($current['rule_set_hash'], $replayed['rule_set_hash']);
        } finally {
            $transaction->rollBack();
        }

        self::assertFalse($this->classExists($classCode));
        if ($ruleSetHash !== null && $scopeKey !== null) {
            self::assertNull((new TaxLkgStore())->readVerified(
                TaxEngine::SCHEMA_VERSION,
                $ruleSetHash,
                $scopeKey,
            ));
        }
    }

    public function testTypedScopeAdapterPreservesDefaultWebsiteZero(): void
    {
        $store = ObjectManager::getInstance(StoreCatalogInterface::class)->defaultStore(0);
        self::assertNotNull($store);

        $resolved = (new TaxScopeConfig())->resolve(0, $store->id);

        self::assertSame(0, $resolved['website_id']);
        self::assertSame($store->id, $resolved['store_id']);
        self::assertNotSame('', $resolved['scope_key']);
        self::assertSame(TaxEngine::SCHEMA_VERSION, $resolved['schema_version']);
        self::assertSame(TaxRule::ROUNDING_HALF_UP, $resolved['rounding']);
    }

    public function testSystemConfigTemplateIsRegisteredWithExpectedTaxFields(): void
    {
        $template = ObjectManager::getInstance(SystemConfigTemplateService::class)
            ->getTemplateMeta('Weline_Tax', 'backend', 'tax', true);

        self::assertNotNull($template);
        self::assertSame('Weline_Tax', $template['module']);
        self::assertSame('backend', $template['area']);
        self::assertSame('tax', $template['code']);
        self::assertSame('Weline_SystemConfig::config', $template['acl']);
        self::assertSame(
            [
                'tax/general/enabled',
                'tax/general/default_jurisdiction',
                'tax/general/rule_schema_version',
                'tax/general/rounding',
            ],
            $template['field_keys'],
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function requests(int $storeId, string $classCode, string $jurisdiction, int $count): array
    {
        $requests = [];
        for ($index = 0; $index < $count; $index++) {
            $requests[] = [
                'website_id' => 0,
                'store_id' => $storeId,
                'currency' => 'CNY',
                'jurisdiction_key' => $jurisdiction,
                'rule_schema_version' => TaxEngine::SCHEMA_VERSION,
                'lines' => [
                    [
                        'line_id' => 'db-' . $index . '-a',
                        'tax_class_code' => $classCode,
                        'taxable_amount_minor' => 1000 + ($index * 17),
                    ],
                    [
                        'line_id' => 'db-' . $index . '-b',
                        'tax_class_code' => $classCode,
                        'taxable_amount_minor' => 333 + ($index % 11),
                    ],
                ],
            ];
        }

        return $requests;
    }

    private function classExists(string $classCode): bool
    {
        $model = (new TaxClass())
            ->where(TaxClass::schema_fields_WEBSITE_ID, 0)
            ->where(TaxClass::schema_fields_CLASS_CODE, $classCode)
            ->find()
            ->fetch();

        return $model instanceof TaxClass && (bool) $model->getId();
    }
}
