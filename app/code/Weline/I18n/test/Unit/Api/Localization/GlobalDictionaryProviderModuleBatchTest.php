<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Api\Localization;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\ModuleGlobalDictionaryProviderInterface;
use Weline\Framework\Runtime\RequestContext;
use Weline\I18n\Api\Localization\GlobalDictionaryProvider;
use Weline\I18n\Model\Locale\Dictionary;

final class GlobalDictionaryProviderModuleBatchTest extends TestCase
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
        $this->db->pdo->exec("INSERT INTO dictionary VALUES ('A','Translated A','en_US','Weline_A'), ('B','Translated B','en_US','Weline_B'), ('Same','Same','en_US','Weline_B'), ('Blank','','en_US','Weline_A'), ('Other','Other module','en_US','Weline_C'), ('A','French A','fr_FR','Weline_A'), ('Duty','Shipping excludes duties and taxes','en_US',NULL), ('Duty','Duty FR','fr_FR','')");
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
                $parts = []; $values = [];
                foreach ($this->filters as [$field, $value, $operator]) {
                    if (strtolower($operator) === 'in') {
                        $parts[] = $field . ' IN (' . implode(',', array_fill(0, count($value), '?')) . ')';
                        $values = array_merge($values, $value);
                    } else {
                        $parts[] = $field . ' ' . $operator . ' ?'; $values[] = $value;
                    }
                }
                $sql = 'SELECT * FROM dictionary WHERE ' . implode(' AND ', $parts);
                $query = $this->db->pdo->prepare($sql);
                $query->execute($values);
                $rows = $query->fetchAll(PDO::FETCH_ASSOC);
                $this->db->reads[] = ['sql' => $sql, 'rows' => count($rows)];
                yield from $rows;
            }
        };
        ObjectManager::setInstance(Dictionary::class, $model);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(ObjectManager::class, 'instances'))->setValue(null, $this->originalInstances);
        (new ReflectionProperty(ObjectManager::class, 'instance'))->setValue(null, $this->originalManager);
        RequestContext::cleanup();
    }

    public function testOneSqlReturnsIndependentModuleMapsAndKeepsWordsSemantics(): void
    {
        $provider = new GlobalDictionaryProvider();
        $maps = $provider->wordsByModule('en_US', [' Weline_A ', 'Weline_B', 'Weline_Empty', 'Weline_A']);
        self::assertSame([
            'Weline_A' => ['A' => 'Translated A'],
            'Weline_B' => ['B' => 'Translated B', 'Same' => 'Same'],
            'Weline_Empty' => [],
        ], $maps);
        self::assertCount(1, $this->db->reads);
        self::assertSame(3, $this->db->reads[0]['rows']);

        $withGlobal = $provider->wordsByModule('en_US', [
            'Weline_A',
            ModuleGlobalDictionaryProviderInterface::NULL_SOURCE_MODULE_KEY,
        ]);
        self::assertSame(['A' => 'Translated A'], $withGlobal['Weline_A']);
        self::assertSame(
            ['Duty' => 'Shipping excludes duties and taxes'],
            $withGlobal[ModuleGlobalDictionaryProviderInterface::NULL_SOURCE_MODULE_KEY],
        );

        $merged = array_merge(
            $withGlobal[ModuleGlobalDictionaryProviderInterface::NULL_SOURCE_MODULE_KEY],
            $maps['Weline_A'],
            $maps['Weline_B'],
        );
        $fromWords = $provider->words('en_US', ['Weline_A', 'Weline_B', 'Weline_Empty']);
        ksort($merged);
        ksort($fromWords);
        self::assertSame($merged, $fromWords);
        self::assertSame(['Weline_A' => ['A' => 'French A']], $provider->wordsByModule('fr_FR', ['Weline_A']));
        self::assertSame(
            ['Duty' => 'Duty FR'],
            $provider->wordsByModule('fr_FR', [ModuleGlobalDictionaryProviderInterface::NULL_SOURCE_MODULE_KEY])[ModuleGlobalDictionaryProviderInterface::NULL_SOURCE_MODULE_KEY],
        );
        $reads = count($this->db->reads);
        self::assertSame([], $provider->wordsByModule('en_US', []));
        self::assertCount($reads, $this->db->reads);
    }

    public function testSqlFailureIsNotReturnedAsConfirmedEmptyMaps(): void
    {
        $this->db->pdo->exec('DROP TABLE dictionary');
        $this->expectException(\PDOException::class);
        (new GlobalDictionaryProvider())->wordsByModule('en_US', ['Weline_A']);
    }
}
