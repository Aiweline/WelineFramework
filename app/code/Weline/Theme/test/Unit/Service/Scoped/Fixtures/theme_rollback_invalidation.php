<?php

declare(strict_types=1);

namespace Weline\Framework\Database {
    /** 隔离夹具：仅替代 ORM 持久化，真实工作区服务和事务协调器执行完整回滚流程。 */
    abstract class Model
    {
        public static \PDO $pdo;
        public static ConnectionFactory $connection;
        public static array $reads = [];
        private array $row = [];
        private array $conditions = [];
        public function getConnection(): ConnectionFactory { return self::$connection; }
        public function clearData(bool $withQuery = true): static { $this->row = []; return $this; }
        public function clearQuery(): static { $this->conditions = []; return $this; }
        public function setData(array|string $key, mixed $value = null): static
        {
            $this->row = array_replace($this->row, is_array($key) ? $key : [$key => $value]);
            return $this;
        }
        public function getData(string $key = '', mixed $default = null): mixed
        {
            return $key === '' ? $this->row : ($this->row[$key] ?? $default);
        }
        public function where(string $key, mixed $value, string $operator = '='): static
        {
            $this->conditions[] = [$key, $value];
            return $this;
        }
        public function find(): static { return $this; }
        public function select(): static { return $this; }
        public function order(string $field, string $direction = 'ASC'): static { return $this; }
        public function fetch(): static { $this->row = $this->fetchArray()[0] ?? []; return $this; }
        public function load(string|int $field_or_pk_value, mixed $value = null, bool $forceReload = false)
        {
            if ($value !== null) {
                return $this->where((string)$field_or_pk_value, $value)->fetch();
            }

            return $this->where(static::schema_fields_ID, (int)$field_or_pk_value)->fetch();
        }
        public function fetchArray(): array
        {
            self::$reads[static::class] = (self::$reads[static::class] ?? 0) + 1;
            $query = self::$pdo->prepare('SELECT payload FROM records WHERE kind = ? ORDER BY id');
            $query->execute([static::class]);
            $rows = array_map(static fn(string $json): array => json_decode($json, true), $query->fetchAll(\PDO::FETCH_COLUMN));
            return array_values(array_filter($rows, function (array $row): bool {
                foreach ($this->conditions as [$key, $value]) {
                    if (($row[$key] ?? null) != $value) { return false; }
                }
                return true;
            }));
        }
        public function save(mixed $data = [], mixed $sequence = ''): bool
        {
            if (\is_array($data) && $data !== []) {
                $this->setData($data);
            }
            $id = (int)($this->row[static::schema_fields_ID] ?? 0);
            if ($id === 0) {
                $query = self::$pdo->prepare('SELECT COALESCE(MAX(id), 0) + 1 FROM records WHERE kind = ?');
                $query->execute([static::class]);
                $id = (int)$query->fetchColumn();
                $this->row[static::schema_fields_ID] = $id;
            }
            $query = self::$pdo->prepare('INSERT INTO records(kind, id, payload) VALUES (?, ?, ?) ON CONFLICT(kind, id) DO UPDATE SET payload = excluded.payload');
            return $query->execute([static::class, $id, json_encode($this->row)]);
        }
        public function save_before(): void {}
    }
}

namespace Weline\Websites\Model {
    /**
     * Fixture stub: ThemeScopedWorkspace may class_exists(Website) for default-locale.
     * Keep real Website.php off the fixture Model to avoid signature clashes.
     */
    final class Website extends \Weline\Framework\Database\Model
    {
        public const schema_fields_ID = 'website_id';
        public const schema_fields_CODE = 'code';

        public function getDefaultLanguage(): string
        {
            $lang = \trim((string)$this->getData('default_language', 'en_US'));

            return $lang !== '' ? $lang : 'en_US';
        }
    }
}

namespace Weline\Framework\Event\ResourceChange {
    /** 修订分配不是本用例被测对象；保留同连接、可回滚的修订写入。 */
    final class ResourceRevisionService
    {
        public function next(string $type, string $id): int
        {
            \Weline\Framework\Database\Model::$pdo->exec('UPDATE revisions SET revision = revision + 1');
            return (int)\Weline\Framework\Database\Model::$pdo->query('SELECT revision FROM revisions')->fetchColumn();
        }
    }
}

namespace {
    $root = dirname(__DIR__, 9);
    $directory = sys_get_temp_dir() . '/weline-theme-rollback-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    define('BP', $directory . '/');
    define('DS', '/');
    define('APP_PATH', $root . '/app/');
    define('APP_CODE_PATH', APP_PATH . 'code/');
    define('APP_ETC_PATH', APP_PATH . 'etc/');
    define('VENDOR_PATH', $root . '/vendor/');
    define('VAR_DIR', BP . 'var/');
    define('PUB', BP . 'pub/');
    define('DEV', false);
    define('DEBUG', false);
    define('CLI', true);
    define('SANDBOX', false);
    define('IS_WIN', false);
    define('PHP_CS', false);
    function __(string $words, mixed $arguments = ''): string { return $words; }
    require $root . '/vendor/autoload.php';
    require APP_CODE_PATH . 'Weline/Framework/Common/functions.php';

    use Weline\Framework\Database\ConnectionFactory;
    use Weline\Framework\Database\Connection\Adapter\Sqlite\Connector;
    use Weline\Framework\Database\Connection\Api\ConnectorInterface;
    use Weline\Framework\Database\DbManager\ConfigProvider;
    use Weline\Framework\Database\Model;
    use Weline\Framework\Database\Transaction\TransactionCoordinator;
    use Weline\Framework\Manager\ObjectManager;
    use Weline\Framework\Runtime\ScopeIdentity;
    use Weline\SystemConfig\Api\Scope\ScopeContext;
    use Weline\Theme\Api\Scoped\ThemeEditorContext;
    use Weline\Theme\Model\ThemeScopeWorkspace;
    use Weline\Theme\Model\ThemeScopeRelease;
    use Weline\Theme\Model\ThemeScopeReleaseBatch;
    use Weline\Theme\Service\Scoped\ThemeScopedWorkspace;

    final class FixtureConnection extends ConnectionFactory
    {
        public function __construct(private ConnectorInterface $connector) {}
        public function getConnector(): ConnectorInterface { return $this->connector; }
        public function getConfigProvider(): ConfigProvider { return $this->connector->getConfigProvider(); }
    }
    final class FixtureScopes implements \Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface
    {
        public function contextFromIdentity(ScopeIdentity $identity): ScopeContext
        {
            $scope = $this->toStorageScope($identity);
            return new ScopeContext($identity, $scope, ScopeIdentity::MODE_NORMAL, $this->chainFromIdentity($identity));
        }
        public function contextFromClaims(array $claims, ScopeIdentity $identity): ScopeContext { return $this->contextFromIdentity($identity); }
        public function parentIdentity(ScopeIdentity $identity): ?ScopeIdentity
        {
            return match ($identity->scopeKind) {
                ScopeIdentity::KIND_STORE => ScopeIdentity::website(7, 'shop'),
                ScopeIdentity::KIND_WEBSITE => ScopeIdentity::global(),
                default => null,
            };
        }
        public function toStorageScope(ScopeIdentity $identity): string
        {
            return match ($identity->scopeKind) {
                ScopeIdentity::KIND_STORE => 'shop.cn.default',
                ScopeIdentity::KIND_WEBSITE => 'shop.default.default',
                default => 'default.default.default',
            };
        }
        public function chainFromIdentity(ScopeIdentity $identity): array
        {
            $parent = $this->parentIdentity($identity);
            return [$this->toStorageScope($identity), ...($parent ? $this->chainFromIdentity($parent) : [])];
        }
        public function fromStorageScope(string $scope, bool $allowLegacy = true): ?ScopeIdentity
        {
            return match ($scope) {
                'shop.cn.default' => ScopeIdentity::store(7, 'shop', 'cn', ScopeIdentity::MODE_NORMAL),
                'shop.default.default' => ScopeIdentity::website(7, 'shop'),
                default => ScopeIdentity::global(),
            };
        }
        public function assertWritableRawScope(?string $scope): void {}
    }
    final class FixtureAdapter implements \Weline\Theme\Api\Scoped\ThemeScopedResourceAdapterInterface
    {
        public function loadBase(ThemeEditorContext $context): array { return ['nodes' => []]; }
        public function loadLegacyPublished(ThemeEditorContext $context): array { return $this->loadBase($context); }
        public function compile(ThemeEditorContext $context, array $payload): array { return ['payload' => $payload]; }
        public function projectPublished(ThemeEditorContext $context, array $payload, int $releaseId): void
        {
            $statement = Model::$pdo->prepare('INSERT INTO projections(scope, resource, release_id) VALUES (?, ?, ?)');
            $statement->execute([$context->scope->storageScope, $context->resourceType, $releaseId]);
        }
        public function projectDraft(ThemeEditorContext $context, array $payload): void {}
    }

    /** Test storage only: real StorefrontScopeHotCache owns keying, envelopes, L1 and L2 flow. */
    final class FixturePublishedPool implements \Weline\Framework\Cache\Contract\CachePoolInterface
    {
        public array $values = [];
        public int $reads = 0;
        public int $writes = 0;
        public function getIdentity(): string { return 'fixture_theme_published'; }
        public function getTip(): string { return 'isolated snapshot fixture'; }
        public function isPermanent(): bool { return false; }
        public function get(string $key): mixed { ++$this->reads; return $this->values[$key] ?? null; }
        public function set(string $key, mixed $value, int $ttl = 0): bool { ++$this->writes; $this->values[$key] = $value; return true; }
        public function delete(string $key): bool { unset($this->values[$key]); return true; }
        public function clear(): bool { $this->values = []; return true; }
        public function has(string $key): bool { return array_key_exists($key, $this->values); }
        public function getMultiple(array $keys): array { $out = []; foreach ($keys as $key) { $out[$key] = $this->get($key); } return $out; }
        public function setMultiple(array $values, int $ttl = 0): bool { foreach ($values as $key => $value) { $this->set($key, $value, $ttl); } return true; }
        public function deleteMultiple(array $keys): bool { foreach ($keys as $key) { $this->delete($key); } return true; }
        public function getStats(): array { return ['reads' => $this->reads, 'writes' => $this->writes]; }
        public function getCustom(string $key, bool $website = false, bool $lang = false, bool $currency = false): mixed { return $this->get($key); }
        public function setCustom(string $key, mixed $value, int $ttl = 0, bool $website = false, bool $lang = false, bool $currency = false): bool { return $this->set($key, $value, $ttl); }
        public function deleteCustom(string $key, bool $website = false, bool $lang = false, bool $currency = false): bool { return $this->delete($key); }
        public function hasCustom(string $key, bool $website = false, bool $lang = false, bool $currency = false): bool { return $this->has($key); }
    }
    final class FixturePublishedCacheManager extends \Weline\Framework\Cache\CacheManager
    {
        public function __construct(private FixturePublishedPool $fixturePool) {}
        public function pool(string $identity): \Weline\Framework\Cache\Contract\CachePoolInterface { return $this->fixturePool; }
    }
    final class FixturePublishedGenerations implements \Weline\Framework\Cache\Contract\NamespaceGenerationInterface
    {
        public function fingerprint(array $namespaces): string
        {
            sort($namespaces, SORT_STRING);
            // Same SQLite row modified transactionally by this fixture's existing w_changed().
            return hash('sha256', json_encode([$namespaces, (int)Model::$pdo->query('SELECT generation FROM generations')->fetchColumn()]));
        }
        public function bumpMany(array $namespaces): array { throw new \LogicException('Use the existing transactional fixture changed entry.'); }
        public function bump(string $namespace): array { throw new \LogicException('Use the existing transactional fixture changed entry.'); }
    }
    final class FixturePublishedFlight implements \Weline\Framework\Cache\Contract\SingleFlightInterface
    {
        public function acquire(string $key, int $timeoutMs = 1500, int $ttlSeconds = 30): ?string { return 'fixture-token'; }
        public function release(string $key, string $token): void {}
    }
    function installPublishedFixtureCarrier(ScopeIdentity $identity): void
    {
        \Weline\Framework\Runtime\RequestContext::setWelineArea('cli');
        \Weline\Framework\Runtime\RequestContext::setWelineUserLang('en_US');
        \Weline\Framework\Runtime\RequestContext::setWelineUserCurrency('CNY');
        \Weline\Framework\Cache\StorefrontCacheKeyContext::install(new \Weline\Framework\Cache\StorefrontCacheKeyContext(
            $identity, 'en_US', 'CNY', hash('sha256', 'fixture-frozen'), hash('sha256', $identity->canonicalKey()), true,
        ));
    }
    function nextPublishedFixtureRequest(string $id, ?ScopeIdentity $ambient = null): void
    {
        \Weline\Framework\Context::enter(new \Weline\Framework\Context(['meta' => ['type' => 'request', 'mode' => 'cli']]));
        \Weline\Framework\Runtime\RequestContext::setId($id);
        installPublishedFixtureCarrier($ambient ?? ScopeIdentity::channel(7, 'shop', 'cn', 'web', ScopeIdentity::MODE_NORMAL));
    }

    function remainingPointers(): int
    {
        return count(array_filter((new ThemeScopeWorkspace())->fetchArray(), static fn(array $row): bool => (int)($row['published_release_id'] ?? 0) > 0));
    }
    function seedPublished(ThemeEditorContext $item, array $payload): void
    {
        $hash = $item->identityHash();
        // Structure identity is locale-neutral: re-seeds must replace the same hash, not fork by locale.
        foreach ([ThemeScopeWorkspace::class, ThemeScopeRelease::class] as $kind) {
            $query = Model::$pdo->prepare('SELECT id, payload FROM records WHERE kind = ?');
            $query->execute([$kind]);
            while ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
                $decoded = json_decode((string)$row['payload'], true);
                if (($decoded['identity_hash'] ?? null) === $hash) {
                    $delete = Model::$pdo->prepare('DELETE FROM records WHERE kind = ? AND id = ?');
                    $delete->execute([$kind, (int)$row['id']]);
                }
            }
        }
        $release = new ThemeScopeRelease();
        $release->setData([
            'identity_hash' => $hash, 'resource_type' => $item->resourceType,
            'scope' => $item->scope->storageScope, 'revision_id' => 1,
            'effective_payload_json' => json_encode($payload),
        ])->save();
        (new ThemeScopeWorkspace())->setData([
            'identity_hash' => $hash, 'scope' => $item->scope->storageScope,
            'scope_kind' => $item->scope->identity->scopeKind, 'website_id' => 7, 'store_mode' => ScopeIdentity::MODE_NORMAL,
            'area' => $item->area, 'resource_type' => $item->resourceType, 'theme_id' => $item->identityThemeId(),
            'layout_type' => $item->identityLayoutType(), 'layout_option' => $item->identityLayoutOption(),
            'locale' => $item->identityLocale(), 'target_type' => $item->identityTargetType(), 'target_id' => $item->identityTargetId(),
            'revision' => 1, 'published_release_id' => $release->getId(), 'last_good_release_id' => $release->getId(),
        ])->save();
    }
    function w_changed(\Weline\Framework\Event\ResourceChange\ResourceChange $change): \Weline\Framework\Event\ResourceChange\ResourceChange
    {
        $GLOBALS['events'][] = [
            'active' => $GLOBALS['transactions']->isActive(Model::$connection),
            'projections' => (int)Model::$pdo->query('SELECT COUNT(*) FROM projections')->fetchColumn(),
            'remaining_pointers' => remainingPointers(),
            'batches' => count((new ThemeScopeReleaseBatch())->fetchArray()),
            'change' => $change->toArray(),
        ];
        Model::$pdo->exec('UPDATE generations SET generation = generation + 1');
        if ($GLOBALS['mode'] === 'fail') { throw new RuntimeException('fixture_changed_failed'); }
        return $change;
    }

    $connector = new Connector(new ConfigProvider(['type' => 'sqlite', 'database' => '', 'path' => BP . 'fixture.sqlite', 'persistent' => false]));
    Model::$connection = new FixtureConnection($connector);
    Model::$pdo = $connector->getLink();
    Model::$pdo->exec('CREATE TABLE records(kind TEXT, id INTEGER, payload TEXT, PRIMARY KEY(kind,id))');
    Model::$pdo->exec('CREATE TABLE projections(scope TEXT, resource TEXT, release_id INTEGER)');
    Model::$pdo->exec('CREATE TABLE generations(generation INTEGER)');
    Model::$pdo->exec('INSERT INTO generations VALUES (0)');
    Model::$pdo->exec('CREATE TABLE revisions(revision INTEGER)');
    Model::$pdo->exec('INSERT INTO revisions VALUES (0)');
    $GLOBALS['mode'] = $argv[1];
    $GLOBALS['events'] = [];
    $GLOBALS['transactions'] = $transactions = new TransactionCoordinator();
    $scopes = new FixtureScopes();
    $context = new ThemeEditorContext($scopes->contextFromIdentity(ScopeIdentity::website(7, 'shop')), 'frontend', themeId: 19, locale: 'en_US');
    $sourceResources = [];
    foreach (ThemeEditorContext::RESOURCES as $resource) {
        $parent = $context->withResource($resource);
        $child = $parent->withScope($scopes->contextFromIdentity(ScopeIdentity::store(7, 'shop', 'cn', ScopeIdentity::MODE_NORMAL)));
        foreach ([$parent, $child] as $item) {
            $seedPayload = ['nodes' => [], 'brand' => ['favicon' => '/' . $item->scope->storageScope . '.png']];
            if ($GLOBALS['mode'] === 'snapshot') { $seedPayload['resource_marker'] = $resource; }
            seedPublished($item, $seedPayload);
        }
        $sourceResources[] = [
            'resource_type' => $resource, 'identity_hash' => $parent->identityHash(), 'scope' => $parent->scope->storageScope,
            'release_id' => null, 'effective_release_id' => null,
            'descendants' => [['identity_hash' => $child->identityHash(), 'release_id' => null]],
        ];
    }
    (new ThemeScopeReleaseBatch())->setData([
        'scope' => $context->scope->storageScope, 'store_mode' => ScopeIdentity::MODE_NORMAL,
        'area' => 'frontend', 'theme_id' => 19, 'layout_type' => 'default', 'layout_option' => 'default',
        'locale' => 'en_US', 'target_type' => 'global', 'target_id' => 0,
        'state' => ThemeScopeReleaseBatch::STATE_PUBLISHED, 'batch_digest' => 'original',
        'receipt_json' => json_encode(['resources' => $sourceResources]),
    ])->save();
    $validators = new \Weline\Theme\Service\LayoutContentValidationRegistry();
    (new ReflectionProperty($validators, 'validatorImplementations'))->setValue($validators, []);
    $workspace = new ThemeScopedWorkspace(
        new ThemeScopeWorkspace(), new \Weline\Theme\Model\ThemeScopeRevision(), new \Weline\Theme\Model\ThemeScopePatch(),
        new ThemeScopeRelease(), $scopes, new FixtureAdapter(), new \Weline\Theme\Service\Scoped\ThemePatchEngine(),
        new \Weline\Theme\Service\Scoped\ThemeLayoutPayloadDiffer(), $transactions, $validators,
        new \Weline\Theme\Service\Scoped\ThemeLayoutSnapshotNormalizer(new \Weline\Theme\Service\Scoped\ThemeNodePlacementResolver()),
        new ThemeScopeReleaseBatch(),
    );
    $namespacePath = new \Weline\Framework\Cache\Namespace\NamespacePath();
    $resourceRevisions = new \Weline\Framework\Event\ResourceChange\ResourceRevisionService();
    $changeFactory = new \Weline\Framework\Event\ResourceChange\ResourceChangeFactory(new \Weline\Framework\Event\Async\ContextSnapshot());
    ObjectManager::setInstance(\Weline\Framework\Cache\Namespace\NamespacePath::class, $namespacePath);
    ObjectManager::setInstance(\Weline\Framework\Event\ResourceChange\ResourceRevisionService::class, $resourceRevisions);
    ObjectManager::setInstance(\Weline\Framework\Event\ResourceChange\ResourceChangeFactory::class, $changeFactory);
    \Weline\Framework\Context::enter(new \Weline\Framework\Context(['meta' => ['type' => 'request', 'mode' => 'cli']]));
    \Weline\Framework\Runtime\RequestContext::setId('theme-rollback-fixture');
    \Weline\Framework\Runtime\RequestContext::setWelineArea('cli');
    \Weline\Framework\Runtime\RequestContext::setWelineUserLang('en_US');
    \Weline\Framework\Runtime\RequestContext::setWelineUserCurrency('CNY');
    $error = null;
    if ($GLOBALS['mode'] === 'snapshot') {
        $publishedPool = new FixturePublishedPool();
        $publishedGenerations = new FixturePublishedGenerations();
        $publishedCacheManager = new FixturePublishedCacheManager($publishedPool);
        $publishedCache = new \Weline\Framework\Cache\Service\StorefrontScopeHotCache(
            $publishedCacheManager, $publishedGenerations, new FixturePublishedFlight(),
        );
        ObjectManager::setInstance(\Weline\Framework\Cache\Service\StorefrontScopeHotCache::class, $publishedCache);
        installPublishedFixtureCarrier(ScopeIdentity::channel(7, 'shop', 'cn', 'web', ScopeIdentity::MODE_NORMAL));
        // 同一生产服务、SQLite 事务：轻量读取、请求复用、事务内更新与回滚后读取。
        $brandContext = $context->withResource(ThemeEditorContext::RESOURCE_APPEARANCE)
            ->withScope($scopes->contextFromIdentity(ScopeIdentity::store(7, 'shop', 'cn', ScopeIdentity::MODE_NORMAL)));
        $read = static fn(ThemeEditorContext $item): array => $workspace instanceof \Weline\Theme\Api\Scoped\ThemePublishedSnapshotReaderInterface
            ? $workspace->readPublishedSnapshot($item)
            : $workspace->load($item, false);
        $payload = static fn(array $snapshot): array => $snapshot['payload'] ?? $snapshot['published_payload'] ?? [];
        Model::$reads = [];
        $first = $read($brandContext);
        $firstReads = Model::$reads;
        $second = $read($brandContext);
        $repeatReads = array_sum(Model::$reads) - array_sum($firstReads);
        $patchReads = Model::$reads[\Weline\Theme\Model\ThemeScopePatch::class] ?? 0;
        $workspace->load($brandContext, false);
        $editorPatchReads = (Model::$reads[\Weline\Theme\Model\ThemeScopePatch::class] ?? 0) - $patchReads;
        \Weline\Framework\Runtime\RequestContext::set('theme.scoped.workspace.load.v1.unrelated-fixture', ['keep' => true]);
        \Weline\Framework\Runtime\RequestContext::set('theme.scoped.workspace.load.v1.keys', [
            ...\Weline\Framework\Runtime\RequestContext::get('theme.scoped.workspace.load.v1.keys', []),
            'theme.scoped.workspace.load.v1.unrelated-fixture',
        ]);
        $inside = [];
        try {
            $transactions->runWrite(Model::$connection, function () use ($brandContext, $read, &$inside): void {
                (new ThemeScopeWorkspace())->where('identity_hash', $brandContext->identityHash())->fetch()
                    ->setData(['published_release_id' => null, 'last_good_release_id' => null])->save();
                $inside = $read($brandContext);
                throw new RuntimeException('fixture_read_rollback');
            });
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'fixture_read_rollback') { throw $exception; }
        }
        $afterRollback = $read($brandContext);
        $unrelatedRetained = \Weline\Framework\Runtime\RequestContext::get('theme.scoped.workspace.load.v1.unrelated-fixture') === ['keep' => true];
        nextPublishedFixtureRequest('theme-snapshot-second-request');
        $beforeNewRequest = array_sum(Model::$reads);
        $newRequest = $read($brandContext);
        $newRequestReads = array_sum(Model::$reads) - $beforeNewRequest;
        \Weline\Framework\Cache\Service\StorefrontScopeHotCache::resetProcessCache();
        nextPublishedFixtureRequest('theme-snapshot-l2-request');
        $beforeL2 = array_sum(Model::$reads);
        $beforePoolReads = $publishedPool->reads;
        $fromShared = $read($brandContext);
        $l2Reads = array_sum(Model::$reads) - $beforeL2;
        $l2PoolReads = $publishedPool->reads - $beforePoolReads;
        nextPublishedFixtureRequest('theme-snapshot-other-ambient', ScopeIdentity::channel(90, 'other', 'store-x', 'mobile', ScopeIdentity::MODE_NORMAL));
        $beforeAmbient = array_sum(Model::$reads);
        $otherAmbient = $read($brandContext);
        $ambientReads = array_sum(Model::$reads) - $beforeAmbient;
        $scopePayloads = [$payload($read($context->withResource(ThemeEditorContext::RESOURCE_APPEARANCE))), $payload($read($brandContext))];
        $resourcePayloads = [];
        foreach ([ThemeEditorContext::RESOURCE_LAYOUT, ThemeEditorContext::RESOURCE_META, ThemeEditorContext::RESOURCE_APPEARANCE, ThemeEditorContext::RESOURCE_I18N] as $resource) {
            $resourcePayloads[$resource] = $payload($read($context->withResource($resource)->withScope($brandContext->scope)));
        }
        $bindingContext = $brandContext->withResource(ThemeEditorContext::RESOURCE_THEME_BINDING);
        $bindingFirst = $read($bindingContext);
        nextPublishedFixtureRequest('theme-snapshot-binding-next');
        $beforeBinding = array_sum(Model::$reads);
        $bindingNext = $read($bindingContext);
        $bindingNextReads = array_sum(Model::$reads) - $beforeBinding;
        $generationBeforePublish = (int)Model::$pdo->query('SELECT generation FROM generations')->fetchColumn();
        $workspace->rollbackReleaseBatch(1, $context, 'test:rollback');
        $afterPublish = $read($brandContext);
        $generationAfterPublish = (int)Model::$pdo->query('SELECT generation FROM generations')->fetchColumn();
        nextPublishedFixtureRequest('theme-snapshot-after-publish-next');
        $afterPublishNext = $read($brandContext);

        // 新请求中的夹具种子，验证同范围默认语言与业务目标不会因轻读而改变。
        nextPublishedFixtureRequest('theme-snapshot-locale-target');
        $layoutDefault = $context->withResource(ThemeEditorContext::RESOURCE_LAYOUT)->withLocale('default');
        seedPublished($layoutDefault, ['marker' => 'scope-default-locale']);
        $targetDefault = new ThemeEditorContext($context->scope, 'frontend', ThemeEditorContext::RESOURCE_LAYOUT, 19, locale: 'default', targetType: 'product', targetId: 99);
        seedPublished($targetDefault, ['marker' => 'target-99']);
        $defaultLocale = $read($layoutDefault->withLocale('fr_FR'));
        $targetLocale = $read($targetDefault->withLocale('fr_FR'));
        $missingTarget = $read(new ThemeEditorContext($context->scope, 'frontend', ThemeEditorContext::RESOURCE_LAYOUT, 19, locale: 'fr_FR', targetType: 'product', targetId: 100));
        nextPublishedFixtureRequest('theme-snapshot-locale-target-repeat');
        $beforeIsolatedRepeat = array_sum(Model::$reads);
        $missingTargetAgain = $read(new ThemeEditorContext($context->scope, 'frontend', ThemeEditorContext::RESOURCE_LAYOUT, 19, locale: 'fr_FR', targetType: 'product', targetId: 100));
        $targetLocaleAgain = $read($targetDefault->withLocale('fr_FR'));
        $defaultLocaleAgain = $read($layoutDefault->withLocale('fr_FR'));
        $isolatedRepeatReads = array_sum(Model::$reads) - $beforeIsolatedRepeat;
        echo json_encode([
            'shared' => $payload($fromShared), 'shared_reads' => $l2Reads, 'shared_pool_reads' => $l2PoolReads,
            'other_ambient' => $payload($otherAmbient), 'other_ambient_reads' => $ambientReads,
            'scope_payloads' => $scopePayloads, 'resource_payloads' => $resourcePayloads,
            'binding_first' => $payload($bindingFirst), 'binding_next' => $payload($bindingNext), 'binding_next_reads' => $bindingNextReads,
            'generation_before_publish' => $generationBeforePublish, 'generation_after_publish' => $generationAfterPublish,
            'after_publish_next' => $payload($afterPublishNext),
            'default_locale_again' => $payload($defaultLocaleAgain), 'target_locale_again' => $payload($targetLocaleAgain), 'missing_target_again' => $payload($missingTargetAgain),
            'isolated_repeat_reads' => $isolatedRepeatReads,
            'keys' => array_keys($first), 'first' => $payload($first), 'second' => $payload($second),
            'first_reads' => $firstReads, 'repeat_reads' => $repeatReads,
            'inside' => $payload($inside), 'after_rollback' => $payload($afterRollback),
            'after_publish' => $payload($afterPublish), 'unrelated_retained' => $unrelatedRetained,
            'editor_patch_reads' => $editorPatchReads, 'new_request' => $payload($newRequest), 'new_request_reads' => $newRequestReads,
            'default_locale' => $payload($defaultLocale), 'target_locale' => $payload($targetLocale), 'missing_target' => $payload($missingTarget),
        ]);
    } else {
        try { $workspace->rollbackReleaseBatch(1, $context, 'test:rollback'); }
        catch (Throwable $exception) { $error = $exception->getMessage(); }
        echo json_encode([
            'error' => $error, 'events' => $GLOBALS['events'], 'remaining_pointers' => remainingPointers(),
            'batches' => count((new ThemeScopeReleaseBatch())->fetchArray()),
            'generation' => (int)Model::$pdo->query('SELECT generation FROM generations')->fetchColumn(),
            'projections' => (int)Model::$pdo->query('SELECT COUNT(*) FROM projections')->fetchColumn(),
        ]);
    }
    $connector->close();
    // 仅清理本进程创建的临时夹具目录。
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($directory);
}
