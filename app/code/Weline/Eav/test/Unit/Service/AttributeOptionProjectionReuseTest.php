<?php
declare(strict_types=1);
namespace Weline\Eav\Test\Unit\Service;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Weline\Eav\Api\Entity\EntityDefinitionInterface;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavAttribute\LocalDescription as AttributeLocal;
use Weline\Eav\Model\EavAttribute\Option\LocalDescription as OptionLocal;
use Weline\Eav\Service\AttributeMetadataCatalog;
use Weline\Framework\App\State;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Context;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
require_once BP . 'app/code/Weline/Eav/Test/Unit/Service/AttributeMetadataCatalogCacheTest.php';

final class AttributeOptionProjectionReuseTest extends TestCase
{
    private EavSqliteFixture $db;
    private AttributeMetadataCatalog $catalog;
    private EntityDefinitionInterface $entity;
    private array $originalInstances;
    private mixed $originalManager;
    private \stdClass $optionClones;

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
        $this->optionClones = (object)['count' => 0];
        $option = new EavOptionProjectionCountingFixture($this->db, 'attribute_option');
        $option->cloneCounter = $this->optionClones;
        $this->catalog = new AttributeMetadataCatalog(
            new EavEntityFixture($this->db, 'entity'),
            new EavSetFixture($this->db, 'attribute_set'),
            new EavGroupFixture($this->db, 'attribute_group'),
            new EavAttributeFixture($this->db, 'attribute'),
            new EavTypeFixture($this->db, 'attribute_type'),
            $option,
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

    public function testPrefetchCollectsOptionIdsWithoutMaterializingOneOrmPerRow(): void
    {
        $this->db->pdo->exec("INSERT INTO attribute_option VALUES (250,4,999,0,'unplaced','Legacy')");
        $this->catalog->prefetchForProducts($this->entity, [101, 202, 303]);
        self::assertSame(2, $this->db->readCount('attribute_option'));
        self::assertSame(
            $this->db->readCount('attribute_option'),
            $this->optionClones->count,
            'ID prefetch may clone query prototypes, but must not hydrate Option ORM for each scalar row.',
        );
        self::assertSame('Legacy', $this->catalog->productOptionIdentity($this->entity, 101, 'unplaced')->label);
    }

    public function testCatalogAndCompatibilityLookupReuseTheSameReadonlyOptionProjection(): void
    {
        $this->db->pdo->exec("INSERT INTO attribute_option VALUES (250,4,999,0,'unplaced','Legacy')");
        $this->catalog->prefetchForProducts($this->entity, [101, 202, 303]);
        $catalog = $this->catalog->catalog($this->entity);
        $shared = $catalog[0]->groups[0]->attributes[0]->options[0];
        $clones = $this->optionClones->count;
        $reads = count($this->db->reads);
        $identity = $this->catalog->productOptionIdentity($this->entity, 101, 'common');
        self::assertSame($shared, $identity, 'Catalog and identity lookup must share their immutable shared-scope Option DTO.');
        self::assertSame($clones, $this->optionClones->count, 'The same projection must not hydrate the shared ORM rows again.');
        self::assertCount($reads, $this->db->reads);
        self::assertSame('Legacy', $this->catalog->productOptionIdentity($this->entity, 101, 'unplaced')->label);

        $product = $this->catalog->catalogForProduct($this->entity, 101);
        $private = array_values(array_filter($product[0]->groups[0]->attributes[0]->options, static fn($option): bool => $option->id === 101))[0];
        self::assertSame($private, $this->catalog->productOptionIdentity($this->entity, 101, 'only_a'));
        self::assertNull($this->catalog->productOptionIdentity($this->entity, 202, 'only_a'));
    }

    private function newRequest(string $locale): void
    {
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Context::enter(new Context());
        RequestContext::setId(uniqid('eav-projection-', true));
        State::resetRequestPathLocalizationCache();
        State::setRequestLanguageOverride($locale);
    }
}

final class EavOptionProjectionCountingFixture extends Option
{
    use EavSqliteModelFixture { __clone as private cloneFixture; }
    public \stdClass $cloneCounter;
    public function __clone()
    {
        $this->cloneFixture();
        ++$this->cloneCounter->count;
    }
}

