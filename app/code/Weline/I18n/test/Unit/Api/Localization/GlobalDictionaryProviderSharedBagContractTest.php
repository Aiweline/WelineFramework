<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Api\Localization;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\GlobalDictionaryModuleBagCache;
use Weline\Framework\Phrase\ModuleGlobalDictionaryProviderInterface;
use Weline\Framework\Runtime\RequestContext;
use Weline\I18n\Api\Localization\GlobalDictionaryProvider;
use Weline\I18n\Model\Locale\Dictionary;

final class GlobalDictionaryProviderSharedBagContractTest extends TestCase
{
    private mixed $originalManager;
    private array $originalInstances;
    private object $db;

    protected function setUp(): void
    {
        RequestContext::init();
        $this->originalInstances = ObjectManager::getInstances();
        $manager = new ReflectionProperty(ObjectManager::class, 'instance');
        $this->originalManager = $manager->getValue();
        $manager->setValue(null, (new ReflectionClass(ObjectManager::class))->newInstanceWithoutConstructor());
        $this->db = (object)['pdo' => new PDO('sqlite::memory:'), 'reads' => []];
        $this->db->pdo->exec('CREATE TABLE dictionary (word TEXT, translate TEXT, locale_code TEXT, source_module TEXT)');
        $this->db->pdo->exec("INSERT INTO dictionary VALUES ('Duty','Shipping excludes duties and taxes','en_US',NULL), ('Duty','Duty FR','fr_FR','')");
        $model = new class($this->db) {
            private array $filters = [];
            public function __construct(private readonly object $db) {}
            public function reset(): self { $this->filters = []; return $this; }
            public function where(string $field, mixed $value, string $operator = '='): self { $this->filters[] = [$field, $value, $operator]; return $this; }
            public function select(): self { return $this; }
            public function getTable(): string { return 'dictionary'; }
            public function getConnection(): object
            {
                $db = $this->db;
                return new class($db) {
                    public function __construct(private readonly object $db) {}
                    public function getConnector(): object
                    {
                        $db = $this->db;
                        return new class($db) {
                            public function __construct(private readonly object $db) {}
                            public function query(string $sql): object
                            {
                                $db = $this->db;
                                return new class($db, $sql) {
                                    public function __construct(private readonly object $db, private readonly string $sql) {}
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

    protected function tearDown(): void
    {
        (new ReflectionProperty(ObjectManager::class, 'instances'))->setValue(null, $this->originalInstances);
        (new ReflectionProperty(ObjectManager::class, 'instance'))->setValue(null, $this->originalManager);
        RequestContext::cleanup();
        GlobalDictionaryModuleBagCache::resetProcessPoolHandle();
    }

    public function testNullSourceBagUsesRequestMemoWithoutSecondSql(): void
    {
        $provider = new GlobalDictionaryProvider();
        $key = ModuleGlobalDictionaryProviderInterface::NULL_SOURCE_MODULE_KEY;
        $first = $provider->wordsByModule('en_US', [$key])[$key];
        self::assertSame(['Duty' => 'Shipping excludes duties and taxes'], $first);
        $globalReads = \count(\array_filter($this->db->reads, static fn(array $r): bool => !empty($r['global'])));
        self::assertSame(1, $globalReads);

        $second = $provider->wordsByModule('en_US', [$key])[$key];
        self::assertSame($first, $second);
        $globalReadsAfter = \count(\array_filter($this->db->reads, static fn(array $r): bool => !empty($r['global'])));
        self::assertSame(1, $globalReadsAfter);
    }

    public function testProviderBindsSameNullSourceSharedKeyAsPhraseHelper(): void
    {
        $source = \file_get_contents(
            (string)(new ReflectionClass(GlobalDictionaryProvider::class))->getFileName()
        );
        self::assertIsString($source);
        self::assertStringContainsString('GlobalDictionaryModuleBagCache::getNullSource', $source);
        self::assertStringContainsString('GlobalDictionaryModuleBagCache::putNullSource', $source);
        self::assertSame(
            GlobalDictionaryModuleBagCache::sharedKey('lt_LT', ModuleGlobalDictionaryProviderInterface::NULL_SOURCE_MODULE_KEY),
            GlobalDictionaryModuleBagCache::nullSourceSharedKey('lt_LT'),
        );
    }
}
