<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Context;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Transaction\TransactionState;
use Weline\Framework\Database\TransactionContext;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\ThemeScopeVersionResourceSnapshot;
use Weline\Theme\Model\ThemeScopeVersionRevision;
use Weline\Theme\Service\Version\ThemeVersionResourceSnapshotService;

final class ThemeVersionSnapshotRequestReuseTest extends TestCase
{
    private object $state;
    private ThemeVersionResourceSnapshotService $reader;
    private ThemeVersionIdentity $identity;

    protected function setUp(): void
    {
        Context::enter(new Context());
        RequestContext::init();
        RequestContext::setId('snapshot-reuse-test');
        $this->state = (object)['reads' => ['head' => 0, 'resources' => 0], 'rows' => [
            'head' => [['theme_version_id' => 87, 'content_revision' => 4, 'actor_id' => 'saved']],
            'resources' => [['theme_version_id' => 87, 'content_revision' => 4, 'resource_identity_hash' => 'layout-a']],
        ]];
        $head = new SnapshotReuseQueryFixture($this->state, 'head');
        $resources = new SnapshotReuseQueryFixture($this->state, 'resources');
        $cache = new StorefrontScopeHotCache();
        ObjectManager::setInstance(ThemeScopeVersionRevision::class, $head);
        ObjectManager::setInstance(ThemeScopeVersionResourceSnapshot::class, $resources);
        ObjectManager::setInstance(StorefrontScopeHotCache::class, $cache);
        $this->reader = new ThemeVersionResourceSnapshotService();
        $this->identity = new ThemeVersionIdentity(1, 'shop-a.store.channel', 'normal', 'frontend', 87, 'draft', 4);
    }

    protected function tearDown(): void
    {
        TransactionContext::reset();
        RequestContext::cleanup();
        Context::leave();
    }

    public function testRepeatedFrozenInputsReadEachTableOnce(): void
    {
        self::assertSame($this->reader->head($this->identity), $this->reader->head($this->identity));
        self::assertSame($this->reader->resources($this->identity), $this->reader->resources($this->identity));
        self::assertSame(['head' => 1, 'resources' => 1], $this->state->reads);
    }

    public function testDifferentRevisionAndRequestNeverReusePreviousInputs(): void
    {
        self::assertSame(4, $this->reader->head($this->identity)['content_revision']);
        $this->state->rows['head'][] = ['theme_version_id' => 87, 'content_revision' => 5];
        self::assertSame(5, $this->reader->head($this->identity->withVersion(87, 'draft', 5))['content_revision']);
        RequestContext::cleanup();
        RequestContext::init();
        RequestContext::setId('snapshot-reuse-next-request');
        self::assertSame(4, $this->reader->head($this->identity)['content_revision']);
        self::assertSame(3, $this->state->reads['head']);
    }

    public function testPublishingFirstHeadInvalidatesEarlierMissingHeadAndResources(): void
    {
        $this->state->rows['head'] = [];
        self::assertNull($this->reader->head($this->identity));
        self::assertCount(1, $this->reader->resources($this->identity));
        $this->state->rows['resources'][] = ['theme_version_id' => 87, 'content_revision' => 4, 'resource_identity_hash' => 'layout-b'];
        $version = (new ReflectionClass(ThemeScopeVersion::class))->newInstanceWithoutConstructor();
        $version->setData(['version_id' => 87, 'theme_id' => 1, 'scope' => 'shop-a.store.channel',
            'store_mode' => 'normal', 'area' => 'frontend', 'lifecycle' => 'draft', 'content_revision' => 4]);
        (new ReflectionMethod(ThemeVersionResourceSnapshotService::class, 'writeHead'))->invoke($this->reader, $version, 'first-head', [], []);
        self::assertSame('first-head', $this->reader->head($this->identity)['actor_id']);
        self::assertCount(2, $this->reader->resources($this->identity));
    }

    public function testTransactionReadsBypassAndInvalidatePreTransactionMemo(): void
    {
        self::assertSame('saved', $this->reader->head($this->identity)['actor_id']);
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->method('getConfigProvider')->willReturn(new ConfigProvider(['type' => 'sqlite', 'path' => ':memory:']));
        TransactionContext::storeTransactionState($connector, new TransactionState($this->createMock(QueryInterface::class), 1));
        $this->state->rows['head'][0]['actor_id'] = 'uncommitted';
        self::assertSame('uncommitted', $this->reader->head($this->identity)['actor_id']);
        self::assertSame('uncommitted', $this->reader->head($this->identity)['actor_id']);
        $this->state->rows['head'][0]['actor_id'] = 'saved';
        TransactionContext::removeTransactionState($connector);
        self::assertSame('saved', $this->reader->head($this->identity)['actor_id']);
        self::assertSame(4, $this->state->reads['head'], '事务内不缓存，结束后必须重新读取已提交值');
    }
}

/** Only substitutes the database row boundary; request memo and snapshot reader are real. */
final class SnapshotReuseQueryFixture
{
    private array $filters = [];
    private array $data = [];
    public function __construct(private object $state, private string $table) {}
    public function clearData(): self { $this->filters = []; $this->data = []; return $this; }
    public function clearQuery(): self { $this->filters = []; return $this; }
    public function where(string $field, mixed $value): self { $this->filters[$field] = $value; return $this; }
    public function select(): self { return $this; }
    public function fetchArray(): array
    {
        ++$this->state->reads[$this->table];
        return array_values(array_filter($this->state->rows[$this->table], fn($row) => array_diff_assoc($this->filters, $row) === []));
    }
    public function setData(array $data): self { $this->data = $data; return $this; }
    public function save(): self { $this->state->rows[$this->table][] = $this->data; return $this; }
}
