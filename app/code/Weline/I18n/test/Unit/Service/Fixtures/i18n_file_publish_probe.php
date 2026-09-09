<?php
declare(strict_types=1);
namespace {
    $root = dirname(__DIR__, 8);
    $directory = $argv[2];
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
    define('SANDBOX', false);
    define('CLI', true);
    define('IS_WIN', false);
    define('PHP_CS', false);
    require $root . '/vendor/autoload.php';
    require APP_CODE_PATH . 'Weline/Framework/Common/functions.php';

    use Weline\Framework\Database\ConnectionFactory;
    use Weline\Framework\Database\Connection\Adapter\Sqlite\Connector;
    use Weline\Framework\Database\Connection\Api\ConnectorInterface;
    use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
    use Weline\Framework\Database\DbManager\ConfigProvider;
    use Weline\Framework\Database\Transaction\TransactionCoordinator;
    use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
    use Weline\Framework\Event\ResourceChange\ResourceRevisionService;
    use Weline\Framework\Manager\ObjectManager;
    use Weline\Framework\Model\Event\ResourceRevision;
    use Weline\I18n\Model\Locale\Dictionary;
    use Weline\I18n\Service\AiTranslationPublisher;
    use Weline\I18n\Service\I18nResourceChangePublisher;

    final class FixtureConnection extends ConnectionFactory
    {
        public function __construct(private ConnectorInterface $fixtureConnector) {}
        public function getConnector(): ConnectorInterface { return $this->fixtureConnector; }
        public function getConfigProvider(): ConfigProvider { return $this->fixtureConnector->getConfigProvider(); }
    }

    /** SQLite 当前 UPDATE 返回 false；夹具按 QueryInterface 更新计数契约返回真实 rowCount。 */
    final class FixtureRevisionQuery extends \Weline\Framework\Database\Connection\Adapter\Sqlite\Query
    {
        public function __construct(private Connector $fixtureConnector)
        {
            $formatter = new \Weline\Framework\Database\Connection\Adapter\Sqlite\Dialect\SqliteIdentifierFormatter();
            parent::__construct($formatter, new \Weline\Framework\Database\Connection\Api\Sql\Dialect\DefaultTableNameStrategy($formatter, ''));
        }
        private ?array $fixtureInsert = null;
        public function insert(array $data, array|string $update_where_fields = [], string $update_fields = '', bool $ignore_primary_key = false): QueryInterface
        {
            $this->fixtureInsert = $data;
            return $this;
        }
        public function getLink(): \PDO { return $this->fixtureConnector->getLink(); }
        public function getConnector(): ConnectorInterface { return $this->fixtureConnector; }
        public function fetch(string $model_class = ''): mixed
        {
            if ($this->fixtureInsert !== null) {
                // 夹具明确使用 SQLite UPSERT，且允许并发用例等待前一事务释放真实写锁。
                $this->getLink()->exec('PRAGMA busy_timeout = 3000');
                $statement = $this->getLink()->prepare('INSERT INTO revisions(resource_key, resource_type, resource_id, revision, updated_at) VALUES (:resource_key, :resource_type, :resource_id, :revision, :updated_at) ON CONFLICT(resource_key) DO UPDATE SET resource_key = excluded.resource_key');
                $statement->execute($this->fixtureInsert);
                $this->fixtureInsert = null;
                return true;
            }
            if ($this->fetch_type === 'update') {
                $statement = $this->getLink()->prepare($this->sql);
                $statement->execute($this->bound_values);
                return $statement->rowCount();
            }
            return parent::fetch($model_class);
        }
    }

    final class FixtureRevision extends ResourceRevision
    {
        private array $row = [];
        private ?QueryInterface $fixtureQuery = null;
        public function __construct(private FixtureConnection $fixtureConnection) {}
        public function getConnection() { return $this->fixtureConnection; }
        public function clearData(bool $with_query = true): static { $this->row = []; return $this; }
        public function getQuery(bool $keep_condition = true): QueryInterface
        {
            return $this->fixtureQuery ??= (new FixtureRevisionQuery($this->fixtureConnection->getConnector()))
                ->table('revisions')->identity('resource_key');
        }
        public function getId(mixed $default = 0) { return $this->row['resource_key'] ?? $default; }
        public function getData(string $key = '', $index = null): mixed { return $key === '' ? $this->row : ($this->row[$key] ?? null); }
        public function __call($method, $args)
        {
            if ($method === 'clearQuery') { $this->fixtureQuery = null; return $this; }
            if ($method === 'find') { return $this; }
            if ($method === 'fetch') {
                $rows = $this->getQuery()->find()->fetch();
                $this->row = is_array($rows[0] ?? null) ? $rows[0] : (is_array($rows) ? $rows : []);
                return $this;
            }
            $this->getQuery()->$method(...$args);
            return $this;
        }
    }

    final class FixtureDictionary extends Dictionary
    {
        public function __construct(private FixtureConnection $fixtureConnection, private string $mode) {}
        public function getConnection() { return $this->fixtureConnection; }
        public function clear(bool $with_query = true): static { return $this; }
        public function __call($method, $args)
        {
            if ($method !== 'fetchArray') { return $this; }
            $rows = $this->fixtureConnection->getConnector()->query('SELECT word, translate FROM dictionary')->fetch();
            if (in_array($this->mode, ['hold', 'second'], true)) {
                echo json_encode(['stage' => 'dictionary_read', 'mode' => $this->mode]), "\n";
                flush();
                if ($this->mode === 'hold' && trim((string)fgets(STDIN)) !== 'release') {
                    throw new RuntimeException('fixture_release_missing');
                }
            }
            return $rows;
        }
    }

    $connector = new Connector(new ConfigProvider([
        'type' => 'sqlite', 'database' => '', 'path' => $directory . '/probe.sqlite', 'persistent' => false,
    ]));
    $connection = new FixtureConnection($connector);
    $GLOBALS['fixture_connection'] = $connection;
    $GLOBALS['fixture_fail'] = $argv[1] === 'failure';
    $transactions = new TransactionCoordinator();
    ObjectManager::setInstance(TransactionCoordinatorInterface::class, $transactions);
    $dictionary = new FixtureDictionary($connection, $argv[1]);
    $publisher = new I18nResourceChangePublisher(
        $dictionary,
        new ResourceRevisionService(new FixtureRevision($connection), $transactions),
        new \Weline\Framework\Event\ResourceChange\ResourceChangeFactory(new \Weline\Framework\Event\Async\ContextSnapshot()),
        new \Weline\Framework\Cache\Namespace\NamespacePath(),
    );
    ObjectManager::setInstance(I18nResourceChangePublisher::class, $publisher);
    \Weline\Framework\Context::enter(new \Weline\Framework\Context(['meta' => ['type' => 'request', 'mode' => 'cli']]));
    \Weline\Framework\Runtime\RequestContext::setId('file-publish-' . getmypid());
    \Weline\Framework\Runtime\RequestContext::setWelineArea('cli');
    \Weline\Framework\Runtime\RequestContext::setWelineUserLang('en_US');
    \Weline\Framework\Runtime\RequestContext::setWelineUserCurrency('CNY');
    try {
        $ok = (new AiTranslationPublisher($dictionary))->publishLocale('en_US');
        echo json_encode(['ok' => $ok]), "\n";
    } catch (Throwable $error) {
        echo json_encode(['ok' => false, 'error' => $error->getMessage(), 'at' => $error->getFile() . ':' . $error->getLine()]), "\n";
    }
    $connector->close();
}

namespace Weline\I18n\Service {
    /** 测试边界：真实 ResourceChange 契约 + 同一 SQLite 事务，模拟 critical 消费者失败。 */
    function w_changed(\Weline\Framework\Event\ResourceChange\ResourceChange $change): void
    {
        if ($GLOBALS['fixture_fail']) { throw new \RuntimeException('fixture_critical_observer_failed'); }
        $query = $GLOBALS['fixture_connection']->getConnector();
        $query->query('UPDATE versions SET generation = generation + 1')->fetch();
        $hash = $change->toArray()['after']['content_sha256'] ?? '';
        $query->query("INSERT INTO publications(content_sha256) VALUES ('" . $hash . "')")->fetch();
    }
}
