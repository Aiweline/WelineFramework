<?php
declare(strict_types=1);

namespace Weline\I18n\Service {
    final class I18nResourceChangePublisher
    {
        public array $calls = [];
        public int $committed = 0;
        public bool $fail = false;

        public function __construct(
            private readonly \Weline\Framework\Database\ConnectionFactory $database,
            private readonly \Weline\Framework\Database\Transaction\TransactionCoordinatorInterface $transactions,
        ) {}

        public function connection(): \Weline\Framework\Database\ConnectionFactory
        {
            return $this->database;
        }

        public function publishAction(string $action, array $payload): void
        {
            if (!$this->transactions->isActive($this->database)) {
                throw new \RuntimeException('changed_outside_transaction');
            }
            $this->calls[] = ['action' => $action, 'locale' => $payload['locale_code'] ?? null];
            $this->database->getConnector()->query("INSERT INTO probe_changes (marker) VALUES ('changed')")->fetch();
            $this->transactions->afterCommit($this->database, 'probe-i18n', function (): void { ++$this->committed; });
            if ($this->fail) {
                throw new \RuntimeException('changed_failure');
            }
        }
    }
}

namespace {
    require dirname(__DIR__, 3) . '/Product/Test/Unit/bootstrap.php';

    use Weline\Framework\Database\Connection\Adapter\Sqlite\Connector;
    use Weline\Framework\Database\ConnectionFactory;
    use Weline\Framework\Database\DbManager\ConfigProvider;
    use Weline\Framework\Database\Transaction\TransactionCoordinator;
    use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
    use Weline\Framework\Database\TransactionContext;
    use Weline\Framework\Manager\ObjectManager;
    use Weline\Framework\Runtime\RequestContext;
    use Weline\I18n\Model\Locale\Dictionary;
    use Weline\I18n\Service\AiTranslationService;
    use Weline\I18n\Service\I18nResourceChangePublisher;

    final class DictionaryWriteTransactionFixture extends \PHPUnit\Framework\TestCase
    {
        public function runScenario(string $scenario): array
        {
            $file = tempnam(sys_get_temp_dir(), 'weline-i18n-transaction-');
            $config = new ConfigProvider(['type' => 'sqlite', 'database' => '', 'path' => $file, 'persistent' => false]);
            $connector = new Connector($config);
            $connection = $this->createMock(ConnectionFactory::class);
            $connection->method('getConnector')->willReturn($connector);
            $connection->method('getConfigProvider')->willReturn($config);
            $transactions = new TransactionCoordinator();
            $publisher = new I18nResourceChangePublisher($connection, $transactions);
            ObjectManager::setInstance(TransactionCoordinatorInterface::class, $transactions);
            ObjectManager::setInstance(I18nResourceChangePublisher::class, $publisher);
            RequestContext::init();
            TransactionContext::reset();

            try {
                $connector->query("CREATE TABLE i18n_locale_dictionary (md5 TEXT PRIMARY KEY, word TEXT NOT NULL, locale_code TEXT NOT NULL, translate TEXT NOT NULL CHECK(translate <> 'FAIL_SQL'), is_ai INTEGER DEFAULT 0, source_module TEXT, exported_at TEXT)")->fetch();
                $connector->query('CREATE TABLE probe_changes (marker TEXT NOT NULL)')->fetch();
                $dictionary = (new \ReflectionClass(Dictionary::class))->newInstanceWithoutConstructor();
                $dictionary->setConnection($connection);
                $dictionary->_primary_key = 'md5';
                $dictionary->origin_table_name = 'i18n_locale_dictionary';
                $word = 'transaction-word';
                $locale = 'en_US';
                $md5 = Dictionary::generateMd5($word, $locale);
                if (in_array($scenario, ['delete', 'ai-update'], true)) {
                    $connector->query("INSERT INTO i18n_locale_dictionary (md5,word,locale_code,translate,is_ai,source_module) VALUES ('$md5','$word','$locale','before',1,'Weline_Product')")->fetch();
                }
                $publisher->fail = $scenario === 'changed-failure';
                $error = null;
                $result = null;
                try {
                    if (str_starts_with($scenario, 'ai-')) {
                        $service = (new \ReflectionClass(AiTranslationService::class))->newInstanceWithoutConstructor();
                        (new \ReflectionProperty($service, 'localeDictionary'))->setValue($service, $dictionary);
                        $result = (new \ReflectionMethod($service, 'saveTranslation'))->invoke($service, $word, 'after', $locale, $scenario === 'ai-insert', $scenario === 'ai-insert' ? 'Weline_Product' : '');
                    } elseif ($scenario === 'outer-rollback') {
                        $transactions->run($connection, function () use ($dictionary, $word, $locale): void {
                            $dictionary->upsert($word, $locale, 'after');
                            throw new \RuntimeException('outer_rollback');
                        });
                    } elseif (in_array($scenario, ['delete', 'delete-missing'], true)) {
                        $result = $dictionary->deleteEntry($word, $locale);
                    } else {
                        $result = $dictionary->upsert($word, $locale, $scenario === 'sql-failure' ? 'FAIL_SQL' : 'after');
                    }
                } catch (\Throwable $exception) {
                    $error = $exception->getMessage();
                }
                return [
                    'scenario' => $scenario, 'result' => $result, 'error' => $error,
                    'rows' => $connector->query('SELECT word,locale_code,translate,is_ai,source_module FROM i18n_locale_dictionary')->fetch(),
                    'change_rows' => (int)($connector->query('SELECT count(*) AS n FROM probe_changes')->fetch()[0]['n'] ?? -1),
                    'calls' => $publisher->calls, 'committed' => $publisher->committed,
                    'transaction_open' => $connector->getWrappedConnection()->inTransaction(),
                ];
            } finally {
                TransactionContext::reset();
                RequestContext::cleanup();
                $connector->close();
                if (is_file($file)) { unlink($file); }
            }
        }
    }

    if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
        echo json_encode((new DictionaryWriteTransactionFixture('runScenario'))->runScenario($argv[1] ?? 'upsert'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), PHP_EOL;
    }
}
