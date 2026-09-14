<?php
declare(strict_types=1);

namespace Weline\I18n\Service {
    function w_changed(\Weline\Framework\Event\ResourceChange\ResourceChange $change): void
    {
        \I18nLocaleNamespaceFixture::$changes[] = $change->toArray();
        $event = new \Weline\Framework\Event\Event();
        $event->setData('data', $change);
        \I18nLocaleNamespaceFixture::$observer->execute($event);
    }
}

namespace Weline\Framework\Event\ResourceChange {
    // Revision allocation is outside this test's scope; keep its database writes
    // inside the real owner transaction while exercising the real event chain.
    final class ResourceRevisionService
    {
        public function __construct(private readonly \Weline\Framework\Database\ConnectionFactory $connection) {}
        public function next(string $resourceType, string|int $resourceId): int
        {
            $pdo = $this->connection->getConnector()->getWrappedConnection();
            if (!$pdo->inTransaction()) { throw new \RuntimeException('revision_outside_transaction'); }
            $key = hash('sha256', $resourceType . "\0" . $resourceId);
            $statement = $pdo->prepare('INSERT INTO revisions(resource_key,resource_type,resource_id,revision,updated_at) VALUES (?,?,?,1,?) ON CONFLICT(resource_key) DO UPDATE SET revision=revision+1');
            $statement->execute([$key, $resourceType, (string)$resourceId, gmdate('Y-m-d H:i:s')]);
            $read = $pdo->prepare('SELECT revision FROM revisions WHERE resource_key=?');
            $read->execute([$key]);
            return (int)$read->fetchColumn();
        }
    }
}

namespace {
    require dirname(__DIR__, 5) . '/bootstrap_phpunit.php';

    use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
    use Weline\Framework\Cache\Namespace\NamespaceGenerationSnapshot;
    use Weline\Framework\Cache\Namespace\NamespaceKeyDecorator;
    use Weline\Framework\Cache\Namespace\NamespacePath;
    use Weline\Framework\Database\Connection\Adapter\Sqlite\Connector;
    use Weline\Framework\Database\ConnectionFactory;
    use Weline\Framework\Database\DbManager\ConfigProvider;
    use Weline\Framework\Database\Transaction\TransactionCoordinator;
    use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
    use Weline\Framework\Database\TransactionContext;
    use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
    use Weline\Framework\Event\ResourceChange\ResourceRevisionService;
    use Weline\Framework\Manager\ObjectManager;
    use Weline\Framework\Model\Cache\NamespaceVersion;
    use Weline\Framework\Runtime\RequestContext;
    use Weline\Framework\Runtime\RuntimeNamespaceInvalidationPublisherInterface;
    use Weline\Framework\Runtime\RuntimeProviderResolver;
    use Weline\I18n\Model\Locale\Dictionary;
    use Weline\I18n\Observer\ResourceChanged;
    use Weline\I18n\Service\AiTranslationService;
    use Weline\I18n\Service\I18nResourceChangePublisher;
    use Weline\I18n\Service\RuntimeCacheBroadcaster;

    final class I18nLocaleNamespaceFixture extends \PHPUnit\Framework\TestCase
    {
        public static ResourceChanged $observer;
        public static array $changes = [];

        public function runScenario(string $scenario): array
        {
            $file = tempnam(sys_get_temp_dir(), 'weline-i18n-namespace-');
            $config = new ConfigProvider(['type' => 'sqlite', 'database' => '', 'path' => $file, 'persistent' => false]);
            $connector = new Connector($config);
            $connection = $this->createMock(ConnectionFactory::class);
            $connection->method('getConnector')->willReturn($connector);
            $connection->method('getConfigProvider')->willReturn($config);
            $transactions = new TransactionCoordinator();
            ObjectManager::setInstance(TransactionCoordinatorInterface::class, $transactions);
            RequestContext::init();
            TransactionContext::reset();
            try {
                $connector->query('CREATE TABLE versions(namespace_hash TEXT PRIMARY KEY, namespace TEXT NOT NULL, generation INTEGER NOT NULL, updated_at TEXT NOT NULL)')->fetch();
                $connector->query('CREATE TABLE revisions(resource_key TEXT PRIMARY KEY, resource_type TEXT NOT NULL, resource_id TEXT NOT NULL, revision INTEGER NOT NULL, updated_at TEXT NOT NULL)')->fetch();
                $connector->query('CREATE TABLE dictionary(md5 TEXT PRIMARY KEY, word TEXT NOT NULL, locale_code TEXT NOT NULL, translate TEXT NOT NULL, is_ai INTEGER DEFAULT 0, source_module TEXT, exported_at TEXT)')->fetch();
                $model = static function (string $class, string $table, string $key) use ($connection): object {
                    $instance = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
                    $instance->setConnection($connection);
                    $instance->_primary_key = $key;
                    $instance->origin_table_name = $table;
                    return $instance;
                };
                $dictionary = $model(Dictionary::class, 'dictionary', 'md5');
                $revisions = new ResourceRevisionService($connection);
                $namespaces = new NamespaceGenerationRepository($model(NamespaceVersion::class, 'versions', 'namespace_hash'), new NamespacePath(), new NamespaceGenerationSnapshot(), new NamespaceKeyDecorator(), $transactions);
                $publisher = new I18nResourceChangePublisher($dictionary, $revisions, ObjectManager::getInstance(ResourceChangeFactory::class), new NamespacePath());
                ObjectManager::setInstance(I18nResourceChangePublisher::class, $publisher);
                $broadcasts = [];
                $sink = $this->createMock(RuntimeNamespaceInvalidationPublisherInterface::class);
                $sink->method('publish')->willReturnCallback(static function (int $clock, array $changes) use (&$broadcasts, $connector): array {
                    $broadcasts[] = ['clock' => $clock, 'changes' => $changes, 'transaction_open' => $connector->getWrappedConnection()->inTransaction()];
                    return ['success' => true];
                });
                $resolver = (new \ReflectionClass(RuntimeProviderResolver::class))->newInstanceWithoutConstructor();
                (new \ReflectionProperty(RuntimeProviderResolver::class, 'resolved'))->setValue($resolver, [RuntimeNamespaceInvalidationPublisherInterface::class => $sink]);
                self::$observer = new ResourceChanged(new RuntimeCacheBroadcaster($resolver), $namespaces, $transactions, $dictionary);

                $error = null;
                $during = null;
                try {
                    if ($scenario === 'mixed' || $scenario === 'rollback') {
                        $transactions->run($connection, function () use ($dictionary, $scenario, &$during, &$broadcasts): void {
                            $dictionary->upsert('First', 'en_US', 'English');
                            $dictionary->upsert('Second', 'fr_FR', 'French');
                            $dictionary->upsert('Third', 'en_US', 'More English');
                            $during = $broadcasts;
                            if ($scenario === 'rollback') { throw new \RuntimeException('owner_rollback'); }
                        });
                    } elseif ($scenario === 'ai') {
                        $service = (new \ReflectionClass(AiTranslationService::class))->newInstanceWithoutConstructor();
                        (new \ReflectionProperty($service, 'localeDictionary'))->setValue($service, $dictionary);
                        (new \ReflectionMethod($service, 'saveTranslation'))->invoke($service, 'Source', 'English', 'en_US', true, 'Weline_Product');
                    } elseif ($scenario === 'file') {
                        $publisher->publishLocaleFile('fr_FR', static fn(): array => ['locale_code' => 'en_US', 'content_sha256' => str_repeat('a', 64)], static function (): void {});
                    } elseif ($scenario === 'delete') {
                        $dictionary->upsert('Source', 'fr_FR', 'French');
                        self::$changes = [];
                        $broadcasts = [];
                        $dictionary->deleteEntry('Source', 'fr_FR');
                    } elseif ($scenario === 'delete-missing') {
                        $dictionary->deleteEntry('Missing', 'fr_FR');
                    } elseif ($scenario === 'upsert') {
                        $dictionary->upsert('Source', 'en_US', 'English');
                    } elseif ($scenario === 'unknown') {
                        $publisher->publishAction('dictionary-ai-save', ['locale_code' => 'en_US', 'word' => 'Source'], '');
                    } elseif ($scenario === 'catalog') {
                        $publisher->publishAction('locale-catalog-changed', ['locale_code' => 'en_US']);
                    } elseif ($scenario === 'clear') {
                        $publisher->publishAction('dictionary-clear-all', []);
                    } else {
                        $publisher->publishAction('dictionary-quick-save', ['locale_code' => 'en_US', 'md5' => 'opaque-existing-row', 'word' => 'Source', 'translate' => 'Private translation']);
                    }
                } catch (\Throwable $failure) { $error = $failure->getMessage(); }
                $versions = [];
                foreach ($connector->query('SELECT namespace,generation FROM versions ORDER BY namespace')->fetch() as $row) { $versions[$row['namespace']] = (int)$row['generation']; }
                return ['error' => $error, 'versions' => $versions, 'broadcasts' => $broadcasts, 'changes' => self::$changes, 'during' => $during, 'rows' => $connector->query('SELECT locale_code,translate FROM dictionary')->fetch()];
            } finally {
                TransactionContext::reset();
                RequestContext::cleanup();
                $connector->close();
                unlink($file);
            }
        }
    }

    echo json_encode((new I18nLocaleNamespaceFixture('runScenario'))->runScenario($argv[1] ?? 'upsert'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), PHP_EOL;
}
