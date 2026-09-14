<?php
declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Weline\Eav\Api\Entity\EntityDefinitionInterface;
use Weline\Eav\Model\EavAttribute\LocalDescription as AttributeLocal;
use Weline\Eav\Model\EavAttribute\Option\LocalDescription as OptionLocal;
use Weline\Eav\Service\AttributeMetadataCatalog;
use Weline\Framework\App\State;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Context;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;

require_once BP . 'app/code/Weline/Eav/Test/Unit/Service/AttributeOptionProjectionReuseTest.php';

final class AttributeOptionRawProjectionTest extends TestCase
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
        Context::enter(new Context());
        RequestContext::setId(uniqid('eav-raw-projection-', true));
        State::resetRequestPathLocalizationCache();
        State::setRequestLanguageOverride('en_US');
        $this->db = new EavSqliteFixture();
        ObjectManager::setInstance(EventsManager::class, new EavMetadataEventsFixture());
        ObjectManager::setInstance(OptionLocal::class, new EavOptionLocalFixture($this->db, 'option_local'));
        ObjectManager::setInstance(AttributeLocal::class, new EavAttributeLocalFixture($this->db, 'attribute_local'));
        ObjectManager::setInstance(StorefrontScopeHotCache::class, new StorefrontScopeHotCache());
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

    public function testPrefetchedRowsProduceEquivalentOptionsWithoutHydratingOrmObjects(): void
    {
        foreach (['swatch_image', 'swatch_color', 'swatch_text'] as $field) {
            $this->db->pdo->exec('ALTER TABLE attribute_option ADD COLUMN ' . $field . ' TEXT');
        }
        $this->db->pdo->exec("UPDATE attribute_option SET value='  Shared source  ', swatch_image=' /image.jpg ', swatch_color=' #aabbcc ', swatch_text=' 色 ' WHERE option_id=100");
        $this->db->pdo->exec("INSERT INTO attribute_option (option_id,eav_entity_id,attribute_id,scope_instance_id,code,value) VALUES (160,4,30,0,'  ','  '),(150,4,30,0,' soft ',' 柔软 '),(250,4,999,0,'legacy','Legacy')");
        $this->db->pdo->exec("INSERT INTO option_local VALUES (150,'en_US','%E6%9F%94')");

        $this->catalog->prefetchForProducts($this->entity, [101, 202, 303]);
        $queryClones = $this->optionClones->count;
        $optionReads = $this->db->readCount('attribute_option');
        $shared = $this->catalog->catalog($this->entity)[0]->groups[0]->attributes[0]->options;
        self::assertSame([100, 150, 160], array_map(static fn($option): int => $option->id, $shared));
        self::assertSame('Shared source', $shared[0]->value);
        self::assertSame('English common', $shared[0]->label);
        self::assertSame('common', $shared[0]->code);
        self::assertSame(' /image.jpg ', $shared[0]->swatchImage);
        self::assertSame(' #aabbcc ', $shared[0]->swatchColor);
        self::assertSame(' 色 ', $shared[0]->swatchText);
        self::assertSame('soft', $shared[1]->code);
        self::assertSame('柔软', $shared[1]->value);
        self::assertSame('柔软', $shared[1]->label);
        self::assertSame('', $shared[1]->swatchImage);
        self::assertSame('', $shared[1]->swatchColor);
        self::assertSame('', $shared[1]->swatchText);
        self::assertSame('160', $shared[2]->value);
        self::assertSame('160', $shared[2]->code);
        self::assertSame('160', $shared[2]->label);

        $a = $this->catalog->catalogForProduct($this->entity, 101)[0]->groups[0]->attributes[0]->options;
        $b = $this->catalog->catalogForProduct($this->entity, 202)[0]->groups[0]->attributes[0]->options;
        self::assertSame([100, 101, 150, 160], array_map(static fn($option): int => $option->id, $a));
        self::assertSame([100, 150, 160, 202], array_map(static fn($option): int => $option->id, $b));
        self::assertSame($shared[0], $a[0]);
        self::assertSame($a[1], $this->catalog->productOptionIdentity($this->entity, 101, 'only_a'));
        self::assertNull($this->catalog->productOptionIdentity($this->entity, 202, 'only_a'));
        self::assertSame('Legacy', $this->catalog->productOptionIdentity($this->entity, 101, 'legacy')->label);
        self::assertSame($optionReads, $this->db->readCount('attribute_option'));
        self::assertSame($queryClones, $this->optionClones->count, '预取完成后，只读选项投影应直接复用原始行，不再逐行水化 ORM。');
    }
}
