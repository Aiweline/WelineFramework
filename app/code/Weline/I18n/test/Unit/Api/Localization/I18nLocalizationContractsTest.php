<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Api\Localization;

use PHPUnit\Framework\TestCase;
use PDO as PDO;
use ReflectionClass as ReflectionClass;
use ReflectionProperty as ReflectionProperty;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\GlobalDictionaryModuleBagCache;
use Weline\Framework\Phrase\ModuleGlobalDictionaryProviderInterface;
use Weline\Framework\Runtime\RequestContext;
use Weline\I18n\Api\Localization\GlobalDictionaryProvider;
use Weline\I18n\Model\Locale\Dictionary;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllI18nLocalizationContractsPass().
 */
final class I18nLocalizationContractsTest extends TestCase
{
    public function testAllI18nLocalizationContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'GlobalDictionaryProviderSharedBag::testNullSourceBagUsesProcessBagWithoutSecondSql' => function () {
            $this->setUp_GlobalDictionaryProviderSharedBag();
            try {
                $this->assert_GlobalDictionaryProviderSharedBag_testNullSourceBagUsesProcessBagWithoutSecondSql();
            } finally {
                $this->tearDown_GlobalDictionaryProviderSharedBag();
            }
        },
            'GlobalDictionaryProviderSharedBag::testProviderBindsSameNullSourceSharedKeyAsPhraseHelper' => function () {
            $this->setUp_GlobalDictionaryProviderSharedBag();
            try {
                $this->assert_GlobalDictionaryProviderSharedBag_testProviderBindsSameNullSourceSharedKeyAsPhraseHelper();
            } finally {
                $this->tearDown_GlobalDictionaryProviderSharedBag();
            }
        },
            'GlobalDictionaryProviderSharedBag::testPersistentWorkerSkipsWholeLocaleNullSourceMaterialization' => function () {
            $this->setUp_GlobalDictionaryProviderSharedBag();
            try {
                $this->assert_GlobalDictionaryProviderSharedBag_testPersistentWorkerSkipsWholeLocaleNullSourceMaterialization();
            } finally {
                $this->tearDown_GlobalDictionaryProviderSharedBag();
            }
        },
        ];
    }

private mixed $originalManager_GlobalDictionaryProviderSharedBag;

private array $originalInstances_GlobalDictionaryProviderSharedBag;

private object $db_GlobalDictionaryProviderSharedBag;

private function setUp_GlobalDictionaryProviderSharedBag(): void
{
    RequestContext::init();
    $this->originalInstances_GlobalDictionaryProviderSharedBag = ObjectManager::getInstances();
    $manager = new ReflectionProperty(ObjectManager::class, 'instance');
    $this->originalManager_GlobalDictionaryProviderSharedBag = $manager->getValue();
    $manager->setValue(null, (new ReflectionClass(ObjectManager::class))->newInstanceWithoutConstructor());
    $this->db_GlobalDictionaryProviderSharedBag = (object) ['pdo' => new PDO('sqlite::memory:'), 'reads' => []];
    $this->db_GlobalDictionaryProviderSharedBag->pdo->exec('CREATE TABLE dictionary (word TEXT, translate TEXT, locale_code TEXT, source_module TEXT)');
    // Use a locale without generated/language/{locale}.php so the test exercises the DB path.
    $this->db_GlobalDictionaryProviderSharedBag->pdo->exec("INSERT INTO dictionary VALUES ('Duty','Shipping excludes duties and taxes','zz_UT',NULL), ('Duty','Duty FR','zy_UT','')");
    $model = new class($this->db_GlobalDictionaryProviderSharedBag)
    {
        private array $filters = [];
        public function __construct(private readonly object $db)
        {
        }
        public function reset(): self
        {
            $this->filters = [];
            return $this;
        }
        public function where(string $field, mixed $value, string $operator = '='): self
        {
            $this->filters[] = [$field, $value, $operator];
            return $this;
        }
        public function select(): self
        {
            return $this;
        }
        public function getTable(): string
        {
            return 'dictionary';
        }
        public function getConnection(): object
        {
            $db = $this->db;
            return new class($db)
            {
                public function __construct(private readonly object $db)
                {
                }
                public function getConnector(): object
                {
                    $db = $this->db;
                    return new class($db)
                    {
                        public function __construct(private readonly object $db)
                        {
                        }
                        public function query(string $sql): object
                        {
                            $db = $this->db;
                            return new class($db, $sql)
                            {
                                public function __construct(private readonly object $db, private readonly string $sql)
                                {
                                }
                                public function fetchIterator(): \Generator
                                {
                                    $rows = $this->db->pdo->query($this->sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
                                    $this->db->reads[] = ['sql' => $this->sql, 'rows' => count($rows), 'global' => true];
                                    yield from $rows;
                                }
                            };
                        }
                    };
                }
            };
        }
        public function fetchIterator(): \Generator
        {
            yield from [];
        }
    };
    ObjectManager::setInstance(Dictionary::class, $model);
}

private function tearDown_GlobalDictionaryProviderSharedBag(): void
{
    (new ReflectionProperty(ObjectManager::class, 'instances'))->setValue(null, $this->originalInstances_GlobalDictionaryProviderSharedBag);
    (new ReflectionProperty(ObjectManager::class, 'instance'))->setValue(null, $this->originalManager_GlobalDictionaryProviderSharedBag);
    RequestContext::cleanup();
    GlobalDictionaryModuleBagCache::resetProcessPoolHandle();
}

private function assert_GlobalDictionaryProviderSharedBag_testNullSourceBagUsesProcessBagWithoutSecondSql(): void
{
    $provider = new GlobalDictionaryProvider();
    $key = ModuleGlobalDictionaryProviderInterface::NULL_SOURCE_MODULE_KEY;
    $first = $provider->wordsByModule('zz_UT', [$key])[$key];
    self::assertSame(['Duty' => 'Shipping excludes duties and taxes'], $first);
    $globalReads = \count(\array_filter($this->db_GlobalDictionaryProviderSharedBag->reads, static fn(array $r): bool => !empty($r['global'])));
    self::assertSame(1, $globalReads);
    self::assertSame($first, GlobalDictionaryModuleBagCache::getNullSource('zz_UT'));
    $second = $provider->wordsByModule('zz_UT', [$key])[$key];
    self::assertSame($first, $second);
    $globalReadsAfter = \count(\array_filter($this->db_GlobalDictionaryProviderSharedBag->reads, static fn(array $r): bool => !empty($r['global'])));
    self::assertSame(1, $globalReadsAfter);
}

private function assert_GlobalDictionaryProviderSharedBag_testProviderBindsSameNullSourceSharedKeyAsPhraseHelper(): void
{
    $source = \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Api/Localization/GlobalDictionaryProvider.php');
    self::assertIsString($source);
    self::assertStringContainsString('GlobalDictionaryModuleBagCache::getNullSource', $source);
    self::assertStringContainsString('GlobalDictionaryModuleBagCache::putNullSource', $source);
    self::assertStringContainsString('loadLocaleFileWords', $source);
    self::assertStringContainsString('LocaleAreaDictionaryLoader::loadMerged', $source);
    self::assertStringNotContainsString('i18n.global_dictionary.null_source', $source);
    self::assertStringNotContainsString('rememberNullSourceMemo', $source);
    self::assertStringNotContainsString('RequestContext::set', $source);
    self::assertSame(GlobalDictionaryModuleBagCache::sharedKey('lt_LT', ModuleGlobalDictionaryProviderInterface::NULL_SOURCE_MODULE_KEY), GlobalDictionaryModuleBagCache::nullSourceSharedKey('lt_LT'));
}

private function assert_GlobalDictionaryProviderSharedBag_testPersistentWorkerSkipsWholeLocaleNullSourceMaterialization(): void
{
    // Lock app/code authority (composer may still resolve vendor/weline/module-i18n).
    $source = \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Api/Localization/GlobalDictionaryProvider.php');
    self::assertIsString($source);
    self::assertStringContainsString('Runtime::isPersistent()', $source);
    self::assertStringContainsString('never materialize whole-locale NULL-source bags', $source);
    self::assertStringContainsString('prefetchTemplateWords', $source);
    // File/PDO whole-bag paths must stay after the persistent gate.
    $persistentPos = \strpos($source, 'Runtime::isPersistent()');
    $filePos = \strpos($source, 'loadLocaleFileWords(');
    self::assertNotFalse($persistentPos);
    self::assertNotFalse($filePos);
    self::assertLessThan($filePos, $persistentPos);
}
}
