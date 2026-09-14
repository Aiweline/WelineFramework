<?php
declare(strict_types=1);

namespace Weline\Widget\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\Contract\SingleFlightInterface;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Database\Connection\Adapter\Sqlite\Connector;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Framework\Database\TransactionContext;
use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\Cache\Namespace\NamespaceGenerationSnapshot;
use Weline\Framework\Cache\Namespace\NamespaceKeyDecorator;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Event\Async\ContextSnapshot;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Event\ResourceChange\ResourceRevisionService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Model\Cache\NamespaceVersion;
use Weline\Framework\Model\Event\ResourceRevision;
use Weline\Framework\Runtime\RuntimeNamespaceInvalidationPublisherInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Widget\Model\AiWidget;
use Weline\Widget\Service\AiWidgetRegistryMutation;
use Weline\Widget\Service\AiWidgetRegistrySource;

final class AiWidgetRegistryCacheTest extends TestCase
{
    protected function setUp(): void
    {
        Context::enter(new Context());
        RequestContext::init();
        StorefrontScopeHotCache::resetProcessCache();
    }

    protected function tearDown(): void
    {
        StorefrontScopeHotCache::resetProcessCache();
        RequestContext::cleanup();
        Context::leave();
    }

    public function testSuccessfulEmptyRegistryIsSharedAcrossRequestsAndWorkers(): void
    {
        $state = (object)['reads' => 0, 'rows' => [], 'fail' => false, 'generation' => 1];
        $hotCache = $this->hotCache($state);
        $source = new AiWidgetRegistrySource(new RegistryRowsFixture($state), $hotCache);
        self::assertSame([], $source->getRegistryEntries());
        self::assertSame([], $source->getRegistryEntries());
        self::assertSame(1, $state->reads, '成功的空注册表也必须复用');
        RequestContext::cleanup();
        RequestContext::init();
        RequestContext::setId('another-request');
        self::assertSame([], $source->getRegistryEntries());
        StorefrontScopeHotCache::resetProcessCache();
        $peer = new AiWidgetRegistrySource(new RegistryRowsFixture($state), $hotCache);
        self::assertSame([], $peer->getRegistryEntries());
        self::assertSame(1, $state->reads, '模拟新 worker 清 L1 后应复用共享池');
        $state->rows = [['ai_widget_id' => 7, 'widget_code' => 'example', 'name' => 'After changed', 'default_config_json' => '{"size":"small"}']];
        ++$state->generation;
        $actual = $peer->getRegistryEntries();
        self::assertSame('After changed', $actual['content']['example']['name']);
        self::assertSame(['size' => 'small'], $actual['content']['example']['default_config']);
        self::assertSame(2, $state->reads);
    }

    public function testDatabaseFailureIsNotCachedAsSuccessfulEmptyRegistry(): void
    {
        $state = (object)['reads' => 0, 'rows' => [], 'fail' => true, 'generation' => 1];
        $source = new AiWidgetRegistrySource(new RegistryRowsFixture($state), $this->hotCache($state));
        self::assertSame([], $source->getRegistryEntries());
        $state->rows = [['widget_code' => 'recovered', 'name' => 'Recovered']];
        $actual = $source->getRegistryEntries();
        self::assertSame('Recovered', $actual['content']['recovered']['name']);
        self::assertSame($actual, $source->getRegistryEntries());
        self::assertSame(2, $state->reads, '失败后下一次读取必须重试，成功后才复用');
    }

    public function testModelMutationsPublishOnlyTheFinalCommittedVectorAndRollback(): void
    {
        TransactionContext::reset();
        $file = tempnam(sys_get_temp_dir(), 'weline-widget-registry-');
        $config = new ConfigProvider(['type' => 'sqlite', 'database' => '', 'path' => $file, 'persistent' => false]);
        $connector = new Connector($config);
        $connection = $this->createMock(ConnectionFactory::class);
        $connection->method('getConnector')->willReturn($connector);
        $connection->method('getConfigProvider')->willReturn($config);
        $transactions = new TransactionCoordinator();
        $instances = (new \ReflectionProperty(ObjectManager::class, 'instances'))->getValue();
        $instance = (new \ReflectionProperty(ObjectManager::class, 'instance'))->getValue();
        try {
            $connector->query('CREATE TABLE namespace_versions (namespace_hash TEXT PRIMARY KEY, namespace TEXT NOT NULL, generation INTEGER NOT NULL, updated_at TEXT NOT NULL)')->fetch();
            $connector->query('CREATE TABLE resource_revisions (resource_key TEXT PRIMARY KEY, resource_type TEXT NOT NULL, resource_id TEXT NOT NULL, revision INTEGER NOT NULL, updated_at TEXT NOT NULL)')->fetch();
            $columns = [];
            foreach ((new \ReflectionClass(AiWidget::class))->getReflectionConstants() as $constant) {
                if ($constant->getDeclaringClass()->getName() === AiWidget::class && str_starts_with($constant->getName(), 'schema_fields_')) {
                    $field = $constant->getValue();
                    $columns[] = '"' . $field . '" ' . ($field === 'ai_widget_id' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'TEXT');
                }
            }
            $connector->query('CREATE TABLE ai_widgets (' . implode(',', $columns) . ')')->fetch();
            $newModel = static function (string $class, string $table, string $primary) use ($connection): object {
                $model = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
                $model->setConnection($connection);
                $model->_primary_key = $primary;
                $model->_unit_primary_keys = [$primary];
                $model->origin_table_name = $table;
                return $model;
            };
            $namespaces = new NamespaceGenerationRepository($newModel(NamespaceVersion::class, 'namespace_versions', 'namespace_hash'), new NamespacePath(), new NamespaceGenerationSnapshot(), new NamespaceKeyDecorator(), $transactions);
            $events = [];
            $manager = $this->createMock(EventsManager::class);
            $manager->method('dispatch')->willReturnCallback(static function (string $name, mixed &$payload) use (&$events, $manager, $namespaces): EventsManager {
                if ($payload instanceof ResourceChange) {
                    $events[] = $payload->toArray();
                    $namespaces->bumpMany($payload->toArray()['impact']['namespaces']);
                }
                return $manager;
            });
            ObjectManager::setInstance(EventsManager::class, $manager);
            $broadcasts = [];
            $publisher = $this->createMock(RuntimeNamespaceInvalidationPublisherInterface::class);
            $publisher->method('publish')->willReturnCallback(static function (int $clock, array $changes, ?string $instanceName, string $requestId) use (&$broadcasts): array { $broadcasts[] = compact('clock', 'changes'); return []; });
            $runtime = (new \ReflectionClass(RuntimeProviderResolver::class))->newInstanceWithoutConstructor();
            (new \ReflectionProperty(RuntimeProviderResolver::class, 'resolved'))->setValue($runtime, [RuntimeNamespaceInvalidationPublisherInterface::class => $publisher]);
            $writer = null;
            if (class_exists(AiWidgetRegistryMutation::class)) {
                $writer = new AiWidgetRegistryMutation($transactions, new ResourceChangeFactory(new ContextSnapshot()), new ResourceRevisionService($newModel(SqliteRevisionFixture::class, 'resource_revisions', 'resource_key'), $transactions), $namespaces, $runtime);
                ObjectManager::setInstance(AiWidgetRegistryMutation::class, $writer);
            }
            $model = $newModel(AiWidget::class, 'ai_widgets', 'ai_widget_id');
            $model->setData(['widget_code' => 'committed', 'name' => 'Original', 'is_active' => 1, 'template_content' => '<p>example</p>']);
            // SQLite 无 RETURNING 的 update fetch 为 false；编辑 callback 保留真实 SQL/事务，正式 PG 另验公开 save。
            $edit = static function (string $field, string|int $value) use ($writer, $model, $connector): void {
                $mutation = static function () use ($field, $value, $model, $connector): bool {
                    $statement = $connector->getLink()->prepare('UPDATE ai_widgets SET "' . $field . '" = ? WHERE ai_widget_id = ?');
                    $statement->execute([$value, $model->getId()]);
                    $model->setData($field, $value);
                    return true;
                };
                if ($writer !== null) {
                    $writer->run($model, (array)$model->getData(), $mutation);
                } else {
                    $mutation();
                }
            };
            $transactions->run($connection, function () use ($model, $edit, &$broadcasts, $namespaces): void {
                $model->save();
                $edit('name', 'Renamed');
                $namespaces->bump('global/widget/test-final-vector');
                self::assertSame([], $broadcasts, '外层提交前不得广播未提交事实');
            });
            self::assertCount(2, $events, '模型新增和编辑必须走标准 changed');
            self::assertCount(1, $broadcasts, '同一外层事务仅发布最终向量一次');
            self::assertArrayHasKey('global/widget/registry', $broadcasts[0]['changes']);
            self::assertArrayHasKey('global/storefront/theme', $broadcasts[0]['changes']);
            self::assertArrayHasKey('global/widget/test-final-vector', $broadcasts[0]['changes']);
            $generation = $namespaces->fingerprint(['global/widget/registry']);
            try {
                $transactions->run($connection, function () use ($edit): void {
                    $edit('is_active', 0);
                    throw new \RuntimeException('rollback expected');
                });
            } catch (\RuntimeException $error) {
                self::assertSame('rollback expected', $error->getMessage());
            }
            self::assertSame($generation, $namespaces->fingerprint(['global/widget/registry']));
            self::assertCount(1, $broadcasts, '回滚不得广播或推进权威版本');
            $edit('is_active', 0);
            self::assertNotSame($generation, $namespaces->fingerprint(['global/widget/registry']));
            self::assertCount(2, $broadcasts);
            $delete = $newModel(AiWidget::class, 'ai_widgets', 'ai_widget_id');
            $delete->where('widget_code', 'committed')->delete();
            self::assertCount(3, $broadcasts);
            self::assertSame('delete', $events[array_key_last($events)]['resource']['action']);
            self::assertSame([], $newModel(AiWidget::class, 'ai_widgets', 'ai_widget_id')->select()->fetchArray());
        } finally {
            TransactionContext::reset();
            (new \ReflectionProperty(ObjectManager::class, 'instances'))->setValue(null, $instances);
            (new \ReflectionProperty(ObjectManager::class, 'instance'))->setValue(null, $instance);
            unset($connector, $connection);
            @unlink($file);
        }
    }

    private function hotCache(object $state): StorefrontScopeHotCache
    {
        $values = [];
        $pool = $this->createMock(CachePoolInterface::class);
        $pool->method('getCustom')->willReturnCallback(static function (string $key) use (&$values): mixed { return $values[$key] ?? null; });
        $pool->method('setCustom')->willReturnCallback(static function (string $key, mixed $value) use (&$values): bool { $values[$key] = $value; return true; });
        $manager = $this->createMock(CacheManager::class);
        $manager->method('registerPolicy')->willReturnArgument(0);
        $manager->method('pool')->willReturn($pool);
        $generations = $this->createMock(NamespaceGenerationInterface::class);
        $generations->method('fingerprint')->willReturnCallback(static fn(array $paths): string => hash('sha256', serialize([$paths, $state->generation])));
        $flight = $this->createMock(SingleFlightInterface::class);
        return new StorefrontScopeHotCache($manager, $generations, $flight);
    }
}

final class RegistryRowsFixture extends AiWidget
{
    public function __construct(private object $state) {}
    public function clearData(bool $with_query = true): static { return $this; }
    public function __call($name, $arguments)
    {
        if ($name === 'fetchArray') {
            ++$this->state->reads;
            if ($this->state->fail) {
                $this->state->fail = false;
                throw new \RuntimeException('expected database read failure');
            }
            return $this->state->rows;
        }
        return $this;
    }
}

/** SQLite 的 UPDATE fetch 无返回行时为 false；测试只归一 affected-row，SQL和事务均真实。 */
trait SqliteAffectedRowsFixture
{
    public function getQuery(bool $keep_condition = true): \Weline\Framework\Database\Connection\Api\Sql\QueryInterface
    {
        $query = parent::getQuery($keep_condition);
        if ($query instanceof SqliteRevisionQueryFixture) {
            return $query;
        }
        $adapted = new SqliteRevisionQueryFixture();
        $adapted->testLink = $query->getLink();
        foreach ((new \ReflectionClass(\Weline\Framework\Database\Connection\Adapter\Sqlite\Query::class))->getProperties() as $property) {
            if (!$property->isStatic() && $property->isInitialized($query)) {
                $property->setValue($adapted, $property->getValue($query));
            }
        }
        $adapted->setConnector($this->getConnection()->getConnector());
        $this->bindQuery($adapted);
        return $adapted;
    }
}

final class SqliteRevisionFixture extends ResourceRevision { use SqliteAffectedRowsFixture; }

final class SqliteRevisionQueryFixture extends \Weline\Framework\Database\Connection\Adapter\Sqlite\Query
{
    public \PDO $testLink;
    public function getLink(): \PDO { return $this->testLink; }
    public function fetch(string $model_class = ''): mixed
    {
        if (!empty($this->insert)) {
            $rows = $this->insert['origin'] ?? [];
            foreach ($rows as $row) {
                if (is_array($row) && isset($row['resource_key'])) {
                    // 正式修订服务的原子建件语义，SQLite fixture 明确使用原生冲突 no-op。
                    $statement = $this->testLink->prepare('INSERT OR IGNORE INTO resource_revisions (resource_key, resource_type, resource_id, revision, updated_at) VALUES (?, ?, ?, ?, ?)');
                    $statement->execute([$row['resource_key'], $row['resource_type'], $row['resource_id'], $row['revision'], $row['updated_at']]);
                    return true;
                }
            }
        }
        $isUpdate = !empty($this->updates) || !empty($this->single_updates);
        $result = parent::fetch($model_class);
        return $isUpdate && $result === false
            ? (int)$this->testLink->query('SELECT changes()')->fetchColumn() : $result;
    }
}
