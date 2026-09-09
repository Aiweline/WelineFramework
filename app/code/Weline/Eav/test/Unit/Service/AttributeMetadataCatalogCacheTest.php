<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Weline\Eav\Api\Entity\EntityDefinitionInterface;
use Weline\Eav\Model\EavEntity;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\{Set, Group, Type, Option, Placement};
use Weline\Eav\Model\EavAttribute\LocalDescription as AttributeLocal;
use Weline\Eav\Model\EavAttribute\Option\LocalDescription as OptionLocal;
use Weline\Eav\Service\AttributeMetadataCatalog;
use Weline\Framework\App\State;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Context;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;

final class AttributeMetadataCatalogCacheTest extends TestCase
{
    private EavSqliteFixture $db;
    private AttributeMetadataCatalog $catalog;
    private EntityDefinitionInterface $entity;
    private array $originalInstances;
    private mixed $originalManager;

    protected function setUp(): void
    {
        require_once dirname((new ReflectionClass(Context::class))->getFileName()) . '/Common/functions.php';
        $this->originalInstances = ObjectManager::getInstances();
        $manager = new ReflectionProperty(ObjectManager::class, 'instance');
        $this->originalManager = $manager->getValue();
        $manager->setValue(null, (new ReflectionClass(ObjectManager::class))->newInstanceWithoutConstructor());
        if (Context::hasCurrent()) {
            Context::leave();
        }
        $this->newRequest('en_US');
        $this->db = new EavSqliteFixture();
        $events = new EavMetadataEventsFixture();
        $optionLocal = new EavOptionLocalFixture($this->db, 'option_local');
        $attributeLocal = new EavAttributeLocalFixture($this->db, 'attribute_local');
        $cache = new StorefrontScopeHotCache();
        ObjectManager::setInstance(EventsManager::class, $events);
        ObjectManager::setInstance(OptionLocal::class, $optionLocal);
        ObjectManager::setInstance(AttributeLocal::class, $attributeLocal);
        ObjectManager::setInstance(StorefrontScopeHotCache::class, $cache);
        $this->catalog = new AttributeMetadataCatalog(
            new EavEntityFixture($this->db, 'entity'),
            new EavSetFixture($this->db, 'attribute_set'),
            new EavGroupFixture($this->db, 'attribute_group'),
            new EavAttributeFixture($this->db, 'attribute'),
            new EavTypeFixture($this->db, 'attribute_type'),
            new EavOptionFixture($this->db, 'attribute_option'),
            new EavPlacementFixture($this->db, 'placement'),
        );
        $this->entity = new class implements EntityDefinitionInterface {
            public function getEntityCode(): string { return 'product'; }
            public function getEntityName(): string { return 'Product'; }
            public function getEntityFieldIdType(): string { return 'int'; }
            public function getEntityFieldIdLength(): int { return 11; }
        };
    }

    protected function tearDown(): void
    {
        if (Context::hasCurrent()) {
            Context::leave();
        }
        (new ReflectionProperty(ObjectManager::class, 'instances'))->setValue(null, $this->originalInstances);
        (new ReflectionProperty(ObjectManager::class, 'instance'))->setValue(null, $this->originalManager);
        State::resetRequestPathLocalizationCache();
    }

    public function testProductCatalogReusesSharedReadsWithoutSharingPrivateOptionsOrFreeAttributes(): void
    {
        $first = $this->catalog->catalogForProduct($this->entity, 101);
        $second = $this->catalog->catalogForProduct($this->entity, 202);
        self::assertSame(['common', 'only_a'], $this->optionCodes($first));
        self::assertSame(['common', 'only_b'], $this->optionCodes($second));
        self::assertSame('private_a', $first[1]->groups[0]->attributes[0]->code);
        self::assertSame('private_b', $second[1]->groups[0]->attributes[0]->code);
        self::assertSame(1, $this->db->readCount('attribute_type'));
        self::assertSame(1, $this->db->readCount('attribute_set'));
        self::assertSame(3, $this->db->readCount('attribute_group'));
        self::assertSame(3, $this->db->readCount('attribute'));
        self::assertSame(1, $this->db->readCount('placement'));
        self::assertSame(3, $this->db->readCount('attribute_option'));
        self::assertSame(1, $this->db->readCount('entity'));
        self::assertSame(3, $this->db->readCount('option_local'));
    }

    public function testScalarMemoDoesNotConstructTemporaryQueryModels(): void
    {
        $identities = $this->catalog->sharedOptionIdentities($this->entity, ['color']);
        self::assertSame([100], array_column($identities['color'], 'id'));
        self::assertSame('Common', $identities['color'][0]->value);
        self::assertSame(0, $this->db->temporaryQueryModels, '标量缓存回源不应先构造随后立即丢弃的模型。');
        $reads = count($this->db->reads);
        self::assertEquals($identities, $this->catalog->sharedOptionIdentities($this->entity, ['color']));
        self::assertCount($reads, $this->db->reads);
    }

    public function testSharedIdentityProjectionBoundsOptionsToAxesAndReusesIncrementalRequestReads(): void
    {
        $this->db->pdo->exec("INSERT INTO attribute VALUES (40,4,10,20,0,1,'size','Size'), (50,4,10,20,0,1,'unrelated','Unrelated')");
        $this->db->pdo->exec("INSERT INTO attribute_option VALUES (300,4,40,0,'large','成人L'), (400,4,50,0,'unused','Unused')");
        $first = $this->catalog->sharedOptionIdentities($this->entity, [' Color ', 'size', 'unknown', 'size']);
        self::assertSame(['color', 'size', 'unknown'], array_keys($first));
        self::assertSame([100], array_column($first['color'], 'id'));
        self::assertSame([300], array_column($first['size'], 'id'));
        self::assertSame('Common', $first['color'][0]->value);
        self::assertSame([], $first['unknown']);
        self::assertSame(1, $this->db->readCount('attribute_option'));
        self::assertSame(0, $this->db->readCount('option_local'));
        self::assertSame(0, $this->db->readCount('attribute_local'));
        $options = array_values(array_filter($this->db->reads, static fn(array $read): bool => $read['table'] === 'attribute_option'));
        self::assertSame([100, 300], array_column($options[0]['rows'], 'option_id'));
        $reads = count($this->db->reads);
        for ($i = 0; $i < 24; ++$i) {
            self::assertEquals(['size' => $first['size'], 'unknown' => []], $this->catalog->sharedOptionIdentities($this->entity, ['size', 'unknown']));
        }
        self::assertCount($reads, $this->db->reads);
        $next = $this->catalog->sharedOptionIdentities($this->entity, ['color', 'unrelated']);
        self::assertSame([400], array_column($next['unrelated'], 'id'));
        $options = array_values(array_filter($this->db->reads, static fn(array $read): bool => $read['table'] === 'attribute_option'));
        self::assertCount(2, $options);
        self::assertSame([400], array_column($options[1]['rows'], 'option_id'));
        self::assertSame(1, $this->db->readCount('attribute'), 'The axis metadata is carried by the existing request context.');

        $display = $this->catalog->catalog($this->entity);
        self::assertSame('English common', $display[0]->groups[0]->attributes[0]->options[0]->label);
        self::assertSame('English color', $display[0]->groups[0]->attributes[0]->name);
        $this->db->pdo->exec("UPDATE attribute_option SET code = 'updated' WHERE option_id = 100");
        $this->newRequest('en_US');
        self::assertSame('updated', $this->catalog->sharedOptionIdentities($this->entity, ['color'])['color'][0]->code);
    }

    public function testIdentityProjectionPreservesDisabledAttributesAndCatalogOrderForDuplicateCodes(): void
    {
        $this->db->pdo->exec('ALTER TABLE attribute ADD COLUMN basic_is_enable INTEGER DEFAULT 0');
        $this->db->pdo->exec("INSERT INTO attribute_set VALUES (12,4,'second','Second')");
        $this->db->pdo->exec("INSERT INTO attribute_group VALUES (23,4,12,0,'second','Second')");
        $this->db->pdo->exec("INSERT INTO attribute VALUES (40,4,12,23,0,1,'color','Other color',1)");
        $this->db->pdo->exec("INSERT INTO attribute_option VALUES (90,4,40,0,'override','Common')");
        $this->db->pdo->exec('INSERT INTO placement VALUES (4,12,23,30)');
        $full = $this->catalog->catalog($this->entity);
        self::assertFalse($full[0]->groups[0]->attributes[0]->enabled);
        $expected = [];
        foreach ($full as $set) {
            foreach ($set->groups as $group) {
                foreach ($group->attributes as $attribute) {
                    if ($attribute->code === 'color') {
                        foreach ($attribute->options as $option) {
                            $expected[] = [$option->id, $option->code, $option->value];
                        }
                    }
                }
            }
        }
        $this->newRequest('en_US');
        $this->db->reads = [];
        $identities = $this->catalog->sharedOptionIdentities($this->entity, ['color']);
        self::assertSame($expected, array_map(static fn($option): array => [$option->id, $option->code, $option->value], $identities['color']));
        self::assertSame([100, 100, 90], array_column($identities['color'], 'id'), 'Placement and duplicate-code order stay identical to the display catalog.');
        self::assertSame(1, $this->db->readCount('attribute'), 'Known placement metadata must reuse the attributes already loaded in this context.');
    }

    public function testOptionScopeFilteringHappensInSqlBeforeRowsAreMaterialized(): void
    {
        $first = $this->catalog->catalogForProduct($this->entity, 101);
        self::assertSame(['common', 'only_a'], $this->optionCodes($first));
        $returned = [];
        foreach ($this->db->reads as $read) {
            if ($read['table'] === 'attribute_option') {
                $returned = array_merge($returned, array_column($read['rows'], 'scope_instance_id'));
            }
        }
        sort($returned);
        self::assertSame([0, 101], $returned, 'The SQL boundary must never return another product private option.');
    }

    public function testLocalizedMetadataReadsAreBoundedToMaterializedIds(): void
    {
        $this->catalog->catalogForProduct($this->entity, 101);

        $optionLocalReads = array_values(array_filter(
            $this->db->reads,
            static fn(array $read): bool => $read['table'] === 'option_local',
        ));
        self::assertCount(2, $optionLocalReads);
        self::assertStringContainsString('"id" IN (', $optionLocalReads[0]['sql']);
        self::assertSame([100], array_values(array_unique(array_column($optionLocalReads[0]['rows'], 'id'))));
        self::assertSame([101], [$optionLocalReads[1]['values'][1]]);

        $attributeLocalReads = array_values(array_filter(
            $this->db->reads,
            static fn(array $read): bool => $read['table'] === 'attribute_local',
        ));
        self::assertCount(1, $attributeLocalReads);
        self::assertStringContainsString('"id" IN (', $attributeLocalReads[0]['sql']);
        self::assertSame([30], array_values(array_unique(array_column($attributeLocalReads[0]['rows'], 'id'))));
    }

    public function testLocaleChangesAndNewRequestObserveFreshMetadata(): void
    {
        $english = $this->catalog->catalogForProduct($this->entity, 101);
        self::assertSame('English common', $english[0]->groups[0]->attributes[0]->options[0]->label);
        State::setRequestLanguageOverride('fr_FR');
        $french = $this->catalog->catalogForProduct($this->entity, 101);
        self::assertSame('Commun', $french[0]->groups[0]->attributes[0]->options[0]->label);
        self::assertSame('Color', $french[0]->groups[0]->attributes[0]->name);
        $this->db->pdo->exec("UPDATE option_local SET value = 'Updated common' WHERE local_code = 'en_US' AND id = 100");
        $this->newRequest('en_US');
        $next = $this->catalog->catalogForProduct($this->entity, 101);
        self::assertSame('Updated common', $next[0]->groups[0]->attributes[0]->options[0]->label);
        self::assertSame(6, $this->db->readCount('option_local'));
    }

    public function testReadonlySharedMetadataIsReusedWithoutMutatingProductOptions(): void
    {
        $shared = $this->catalog->catalog($this->entity);
        $withoutPrivateOptions = $this->catalog->catalogForProduct($this->entity, 303);
        $anotherProduct = $this->catalog->catalogForProduct($this->entity, 404);
        self::assertSame($shared[0], $withoutPrivateOptions[0]);
        self::assertSame($shared[0], $anotherProduct[0]);
        self::assertSame([], $withoutPrivateOptions[1]->groups);

        $first = $this->catalog->catalogForProduct($this->entity, 101);
        $second = $this->catalog->catalogForProduct($this->entity, 202);
        self::assertSame(['common'], $this->optionCodes($shared));
        self::assertSame(['common', 'only_a'], $this->optionCodes($first));
        self::assertSame(['common', 'only_b'], $this->optionCodes($second));
        self::assertNotSame($shared[0], $first[0]);
        self::assertSame(
            $shared[0]->groups[0]->attributes[0]->options[0],
            $first[0]->groups[0]->attributes[0]->options[0],
        );
        self::assertSame($first, $this->catalog->catalogForProduct($this->entity, 101));
        self::assertCount(1, $this->catalog->catalogForProduct($this->entity, 101, 'missing_free_set'));
        self::assertSame($shared, $this->catalog->catalog($this->entity));
    }

    public function testFreeSetReadsOnlyMaterializeTheRequestedProductAndSet(): void
    {
        $this->catalog->catalog($this->entity);
        $offset = count($this->db->reads);
        $product = $this->catalog->catalogForProduct($this->entity, 101);
        $freeReads = array_values(array_filter(
            array_slice($this->db->reads, $offset),
            static fn(array $read): bool => in_array($read['table'], ['attribute_group', 'attribute'], true),
        ));
        self::assertCount(2, $freeReads);
        foreach ($freeReads as $read) {
            self::assertStringContainsString('"set_id" = ?', $read['sql']);
            self::assertStringContainsString('"scope_product_id" = ?', $read['sql']);
            self::assertNotEmpty($read['rows']);
            foreach ($read['rows'] as $row) {
                self::assertSame(101, $row['scope_product_id']);
                self::assertSame($product[1]->id, $row['set_id']);
            }
        }
        self::assertSame('private_a', $product[1]->groups[0]->attributes[0]->code);
    }

    public function testRequestMemoSeparatesEntityCatalogs(): void
    {
        $this->db->pdo->exec("INSERT INTO entity VALUES (5, 'category')");
        $this->db->pdo->exec("INSERT INTO attribute_set VALUES (12, 5, 'category_base', 'Category')");
        $otherEntity = new class implements EntityDefinitionInterface {
            public function getEntityCode(): string { return 'category'; }
            public function getEntityName(): string { return 'Category'; }
            public function getEntityFieldIdType(): string { return 'int'; }
            public function getEntityFieldIdLength(): int { return 11; }
        };
        $product = $this->catalog->catalogForProduct($this->entity, 101);
        $category = $this->catalog->catalogForProduct($otherEntity, 101);
        self::assertCount(1, $category);
        self::assertSame(5, $category[0]->entityId);
        self::assertSame('category_base', $category[0]->code);
        self::assertSame([], $category[0]->groups);
        self::assertSame(['common', 'only_a'], $this->optionCodes($product));
    }

    public function testBatchPrefetchPreservesEveryCandidateWhileBoundingPrivateReads(): void
    {
        // SQLite insertion order deliberately differs from metadata sort order.
        $this->db->pdo->exec("INSERT INTO attribute_option VALUES (250, 4, 30, 101, 'a_last', 'A last'), (95, 4, 30, 101, 'a_first', 'A first'), (900, 4, 30, 909090, 'unrequested', 'Unrequested'), (901, 5, 30, 101, 'other_entity', 'Other entity')");
        $this->db->pdo->exec("INSERT INTO attribute_group VALUES (90, 4, 11, 909090, 'unrequested', 'Unrequested'), (91, 5, 11, 101, 'other_entity', 'Other entity'), (92, 4, 999, 101, 'other_set', 'Other set')");
        $this->db->pdo->exec("INSERT INTO attribute VALUES (90, 4, 11, 90, 909090, 1, 'unrequested', 'Unrequested'), (91, 5, 11, 91, 101, 1, 'other_entity', 'Other entity'), (92, 4, 999, 92, 101, 1, 'other_set', 'Other set')");
        $productIds = array_merge([101, 202, 303], range(1000, 1212));
        $expected = [];
        foreach ($productIds as $productId) {
            $expected[$productId] = $this->catalog->catalogForProduct($this->entity, $productId);
        }

        $this->newRequest('en_US');
        $this->db->reads = [];
        $this->catalog->prefetchForProducts($this->entity, $productIds);
        $actual = [];
        foreach ($productIds as $productId) {
            $actual[$productId] = $this->catalog->catalogForProduct($this->entity, $productId);
        }
        self::assertCount(216, $actual);
        self::assertEquals($expected, $actual, 'Prefetch must preserve complete ordinary per-product metadata for every candidate.');
        self::assertSame(['a_first', 'common', 'only_a', 'a_last'], $this->optionCodes($actual[101]));
        self::assertSame(['common', 'only_b'], $this->optionCodes($actual[202]));
        self::assertSame('private_a', $actual[101][1]->groups[0]->attributes[0]->code);
        self::assertSame('private_b', $actual[202][1]->groups[0]->attributes[0]->code);
        self::assertSame([], $actual[303][1]->groups);
        self::assertSame([], $actual[1212][1]->groups);
        self::assertSame(['common'], $this->optionCodes($this->catalog->catalog($this->entity)));
        // Two batches of at most 200 owners plus the shared read. Only the
        // first batch has private groups, so attributes need one private read.
        self::assertSame(3, $this->db->readCount('attribute_option'));
        self::assertSame(3, $this->db->readCount('attribute_group'));
        self::assertSame(2, $this->db->readCount('attribute'));
        foreach ($this->db->reads as $read) {
            if (!in_array($read['table'], ['attribute_option', 'attribute_group', 'attribute'], true)) {
                continue;
            }
            // The existing shared catalog reads groups/attributes by entity
            // before filtering DTOs. Check the newly prefetched private SQL.
            if ($read['table'] !== 'attribute_option'
                && !str_contains($read['sql'], '"scope_product_id" IN (')) {
                continue;
            }
            foreach ($read['rows'] as $row) {
                self::assertSame(4, $row['eav_entity_id'], 'Another entity must not cross the SQL materialization boundary.');
                $owner = $row[$read['table'] === 'attribute_option' ? 'scope_instance_id' : 'scope_product_id'];
                self::assertTrue($owner === 0 || in_array($owner, $productIds, true));
                if ($owner !== 0 && $read['table'] !== 'attribute_option') {
                    self::assertSame(11, $row['set_id'], 'Only the requested free set may be prefetched.');
                }
            }
        }
        $readCount = count($this->db->reads);
        $this->catalog->prefetchForProducts($this->entity, $productIds);
        foreach ($productIds as $productId) {
            self::assertSame($actual[$productId], $this->catalog->catalogForProduct($this->entity, $productId));
        }
        self::assertCount($readCount, $this->db->reads, 'Repeated prefetch and ordinary reads reuse the central request cache.');
    }

    public function testBatchPrefetchPreservesLocaleAndFreeSetBehaviorAcrossRequests(): void
    {
        $english = $this->catalog->catalogForProduct($this->entity, 101);
        State::setRequestLanguageOverride('fr_FR');
        $french = $this->catalog->catalogForProduct($this->entity, 101);
        $missingFreeSet = $this->catalog->catalogForProduct($this->entity, 101, 'missing_free_set');

        $this->newRequest('en_US');
        $this->db->reads = [];
        $this->catalog->prefetchForProducts($this->entity, [101, 202, 303]);
        self::assertEquals($english, $this->catalog->catalogForProduct($this->entity, 101));
        self::assertSame('private_b', $this->catalog->catalogForProduct($this->entity, 202)[1]->groups[0]->attributes[0]->code);
        self::assertSame([], $this->catalog->catalogForProduct($this->entity, 303)[1]->groups);
        self::assertSame(2, $this->db->readCount('attribute_option'));
        self::assertSame(2, $this->db->readCount('attribute_group'));
        self::assertSame(2, $this->db->readCount('attribute'));

        State::setRequestLanguageOverride('fr_FR');
        $this->catalog->prefetchForProducts($this->entity, [101, 202, 303]);
        self::assertEquals($french, $this->catalog->catalogForProduct($this->entity, 101));
        self::assertSame('Commun', $this->catalog->catalogForProduct($this->entity, 101)[0]->groups[0]->attributes[0]->options[0]->label);
        self::assertSame(2, $this->db->readCount('attribute_option'), 'Raw private option rows are locale independent.');
        self::assertSame(2, $this->db->readCount('option_local'), 'Localized labels remain isolated by language.');
        $this->catalog->prefetchForProducts($this->entity, [101, 202, 303], 'missing_free_set');
        $withoutFreeSet = $this->catalog->catalogForProduct($this->entity, 101, 'missing_free_set');
        self::assertEquals($missingFreeSet, $withoutFreeSet);
        self::assertCount(1, $withoutFreeSet);

        $this->db->pdo->exec("UPDATE option_local SET value = 'Updated common' WHERE local_code = 'en_US' AND id = 100");
        $this->db->pdo->exec("UPDATE attribute SET name = 'Updated private A' WHERE attribute_id = 31");
        $this->newRequest('en_US');
        $this->catalog->prefetchForProducts($this->entity, [101, 202, 303]);
        $fresh = $this->catalog->catalogForProduct($this->entity, 101);
        self::assertSame('Updated common', $fresh[0]->groups[0]->attributes[0]->options[0]->label);
        self::assertSame('Updated private A', $fresh[1]->groups[0]->attributes[0]->name);
        self::assertSame(['common', 'only_a'], $this->optionCodes($fresh));
    }

    private function optionCodes(array $sets): array
    {
        return array_map(static fn($option): string => $option->code, $sets[0]->groups[0]->attributes[0]->options);
    }

    private function newRequest(string $locale): void
    {
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Context::enter(new Context());
        RequestContext::setId(uniqid('eav-metadata-', true));
        State::resetRequestPathLocalizationCache();
        State::setRequestLanguageOverride($locale);
    }
}

final class EavSqliteFixture
{
    public PDO $pdo;
    public array $reads = [];
    public int $temporaryQueryModels = 0;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $data = [
            'entity' => [['eav_entity_id' => 4, 'code' => 'product']],
            'attribute_type' => [['type_id' => 1, 'code' => 'select', 'field_type' => 'varchar', 'element' => 'select', 'required' => 0]],
            'attribute_set' => [
                ['set_id' => 10, 'eav_entity_id' => 4, 'code' => 'base', 'name' => 'Base'],
                ['set_id' => 11, 'eav_entity_id' => 4, 'code' => '__product_free', 'name' => 'Private'],
            ],
            'attribute_group' => [
                ['group_id' => 20, 'eav_entity_id' => 4, 'set_id' => 10, 'scope_product_id' => 0, 'code' => 'details', 'name' => 'Details'],
                ['group_id' => 21, 'eav_entity_id' => 4, 'set_id' => 11, 'scope_product_id' => 101, 'code' => 'a', 'name' => 'A'],
                ['group_id' => 22, 'eav_entity_id' => 4, 'set_id' => 11, 'scope_product_id' => 202, 'code' => 'b', 'name' => 'B'],
            ],
            'attribute' => [
                ['attribute_id' => 30, 'eav_entity_id' => 4, 'set_id' => 10, 'group_id' => 20, 'scope_product_id' => 0, 'type_id' => 1, 'code' => 'color', 'name' => 'Color'],
                ['attribute_id' => 31, 'eav_entity_id' => 4, 'set_id' => 11, 'group_id' => 21, 'scope_product_id' => 101, 'type_id' => 1, 'code' => 'private_a', 'name' => 'A private'],
                ['attribute_id' => 32, 'eav_entity_id' => 4, 'set_id' => 11, 'group_id' => 22, 'scope_product_id' => 202, 'type_id' => 1, 'code' => 'private_b', 'name' => 'B private'],
            ],
            'attribute_option' => [
                ['option_id' => 100, 'eav_entity_id' => 4, 'attribute_id' => 30, 'scope_instance_id' => 0, 'code' => 'common', 'value' => 'Common'],
                ['option_id' => 101, 'eav_entity_id' => 4, 'attribute_id' => 30, 'scope_instance_id' => 101, 'code' => 'only_a', 'value' => 'A only'],
                ['option_id' => 202, 'eav_entity_id' => 4, 'attribute_id' => 30, 'scope_instance_id' => 202, 'code' => 'only_b', 'value' => 'B only'],
            ],
            'option_local' => [
                ['id' => 100, 'local_code' => 'en_US', 'value' => 'English common'],
                ['id' => 100, 'local_code' => 'fr_FR', 'value' => 'Commun'],
            ],
            'attribute_local' => [['id' => 30, 'local_code' => 'en_US', 'name' => 'English color']],
            'placement' => [],
        ];
        foreach ($data as $table => $rows) {
            $fields = array_keys($rows[0] ?? ['eav_entity_id' => 0, 'set_id' => 0, 'group_id' => 0, 'attribute_id' => 0]);
            $first = $rows[0] ?? [];
            $this->pdo->exec('CREATE TABLE ' . $table . ' (' . implode(',', array_map(static fn($key) => '"' . $key . '" ' . (is_int($first[$key] ?? null) ? 'INTEGER' : 'TEXT'), $fields)) . ')');
            foreach ($rows as $row) {
                $this->pdo->prepare('INSERT INTO ' . $table . ' VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')')->execute(array_values($row));
            }
        }
    }

    public function select(string $table, array $filters, bool $first = false): array
    {
        $sql = 'SELECT * FROM ' . $table;
        $values = [];
        $conditions = [];
        foreach ($filters as [$field, $value, $operator]) {
            $field = preg_replace('/^main_table\./', '', $field);
            if (strtolower($operator) === 'in' || is_array($value)) {
                $value = (array)$value;
                $conditions[] = '"' . $field . '" IN (' . implode(',', array_fill(0, count($value), '?')) . ')';
                $values = array_merge($values, array_values($value));
            } else {
                $conditions[] = '"' . $field . '" = ?';
                $values[] = $value;
            }
        }
        if ($conditions !== []) { $sql .= ' WHERE ' . implode(' AND ', $conditions); }
        if ($first) { $sql .= ' LIMIT 1'; }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($values);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $this->reads[] = ['table' => $table, 'sql' => $sql, 'values' => $values, 'rows' => $rows];
        return $rows;
    }

    public function readCount(string $table): int
    {
        return count(array_filter($this->reads, static fn($row): bool => $row['table'] === $table));
    }
}

trait EavSqliteModelFixture
{
    private array $fixtureFilters = [];
    private array $fixtureItems = [];

    public function __construct(private EavSqliteFixture $fixtureDb, private string $fixtureTable) {}
    public function __clone() { $this->fixtureFilters = []; $this->fixtureItems = []; }
    public function reset(): static { $this->fixtureFilters = []; $this->fixtureItems = []; return $this; }
    public function clearData(bool $with_query = true): static { $this->_data = []; if ($with_query) { $this->reset(); } return $this; }
    public function getData(string $key = '', $index = null): mixed { return $key === '' ? $this->_data : ($this->_data[$key] ?? null); }
    public function setData($key, $value = null, bool $is_unique = false): static { if (is_array($key)) { $this->_data = $key; } else { $this->_data[$key] = $value; } return $this; }
    public function getId(mixed $default = 0) { return $this->_data[static::schema_fields_ID] ?? $default; }
    public function where(string $field, mixed $value, string $operator = '='): static { $this->fixtureFilters[] = [$field, $value, $operator]; return $this; }
    public function select(): static { $this->fixtureItems = $this->fixtureDb->select($this->fixtureTable, $this->fixtureFilters); return $this; }
    public function find(): static { $this->_data = $this->fixtureDb->select($this->fixtureTable, $this->fixtureFilters, true)[0] ?? []; return $this; }
    public function fetch(): static { return $this; }
    public function fetchArray(): array { return $this->fixtureItems; }
    // The fixture eagerly executes real SQLite in select(); this adapter tests
    // the production iterator consumer, SQL filters and row grouping, not the
    // ORM eager-read guard or streaming memory behavior.
    public function fetchIterator(): \Generator { yield from $this->fixtureItems; }
    public function getItems(): array { return array_map(function ($data) { ++$this->fixtureDb->temporaryQueryModels; $row = clone $this; $row->_data = $data; return $row; }, $this->fixtureItems); }
    public function load(int|string $field_or_pk_value, $value = null, bool $forceReload = false): AbstractModel { return $this->where((string)$field_or_pk_value, $value)->find(); }
}

final class EavEntityFixture extends EavEntity { use EavSqliteModelFixture; }
final class EavSetFixture extends Set { use EavSqliteModelFixture; }
final class EavGroupFixture extends Group { use EavSqliteModelFixture; }
final class EavAttributeFixture extends EavAttribute
{
    use EavSqliteModelFixture;
    public function loadByAttributeId(int $attribute_id): AbstractModel { return $this->where('attribute_id', $attribute_id)->find(); }
}
final class EavTypeFixture extends Type { use EavSqliteModelFixture; }
final class EavOptionFixture extends Option { use EavSqliteModelFixture; }
final class EavPlacementFixture extends Placement { use EavSqliteModelFixture; }
final class EavAttributeLocalFixture extends AttributeLocal { use EavSqliteModelFixture; }
final class EavOptionLocalFixture extends OptionLocal { use EavSqliteModelFixture; }
final class EavMetadataEventsFixture extends EventsManager
{
    public function __construct() {}
    public function dispatch(string $eventName, mixed &$data = []): static { return $this; }
}
