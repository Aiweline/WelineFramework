<?php
declare(strict_types=1);

namespace Weline\Framework\Http {
    class Cookie { public static function getLangLocal(): string { return 'en_US'; } }
    class Url { public static function bumpWebsiteParserSitesVersion(): void { ++\LocaleLifecycleEffects::$url; } }
}
namespace Weline\Framework\System\OS {
    class FileHelper {
        public static function removeDirectory(string $directory, bool $recursive = true): void {
            \LocaleLifecycleEffects::$deletes[] = ['path' => $directory, 'in_transaction' => \LocaleLifecycleEffects::$connection->getConnector()->getWrappedConnection()->inTransaction()];
        }
    }
}
namespace Weline\I18n\Service {
    class RuntimeCacheBroadcaster { public function broadcast(): void { ++\LocaleLifecycleEffects::$broadcasts; } }
    function glob(string $pattern, int $flags = 0): array { return ['/tmp/weline-test-language-pack/en_US']; }
}
namespace {
    final class LocaleLifecycleEffects {
        public static int $clears = 0;
        public static int $broadcasts = 0;
        public static int $url = 0;
        public static int $catalog = 0;
        public static array $deletes = [];
        public static object $cache;
        public static object $connection;
    }
    function w_cache(string $identity = 'default'): object { return LocaleLifecycleEffects::$cache; }
    require __DIR__ . '/dictionary-write-transaction.php';

    use Weline\Framework\Cache\Contract\CachePoolInterface;
    use Weline\Framework\Database\Connection\Adapter\Sqlite\Connector;
    use Weline\Framework\Database\ConnectionFactory;
    use Weline\Framework\Database\DbManager\ConfigProvider;
    use Weline\Framework\Database\Transaction\TransactionCoordinator;
    use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
    use Weline\Framework\Database\TransactionContext;
    use Weline\Framework\Event\EventsManager;
    use Weline\Framework\Manager\ObjectManager;
    use Weline\Framework\Runtime\RequestContext;
    use Weline\I18n\Model\Countries;
    use Weline\I18n\Model\Countries\Locale\Name as CountryName;
    use Weline\I18n\Model\I18n;
    use Weline\I18n\Model\Locale;
    use Weline\I18n\Model\Locale\Name as LocaleName;
    use Weline\I18n\Model\Locals;
    use Weline\I18n\Service\CountryLocaleLifecycleService;
    use Weline\I18n\Service\I18nResourceChangePublisher;

    final class LocaleLifecycleTransactionFixture extends \PHPUnit\Framework\TestCase
    {
        public function runScenario(string $scenario): array
        {
            $file = tempnam(sys_get_temp_dir(), 'weline-locale-transaction-');
            $config = new ConfigProvider(['type' => 'sqlite', 'database' => '', 'path' => $file, 'persistent' => false]);
            $connector = new Connector($config);
            $connection = $this->createMock(ConnectionFactory::class);
            $connection->method('getConnector')->willReturn($connector);
            $connection->method('getConfigProvider')->willReturn($config);
            LocaleLifecycleEffects::$connection = $connection;
            $transactions = new TransactionCoordinator();
            $publisher = new I18nResourceChangePublisher($connection, $transactions);
            ObjectManager::setInstance(TransactionCoordinatorInterface::class, $transactions);
            ObjectManager::setInstance(I18nResourceChangePublisher::class, $publisher);
            $cache = $this->createMock(CachePoolInterface::class);
            $cache->method('clear')->willReturnCallback(static function (): bool { ++LocaleLifecycleEffects::$clears; return true; });
            LocaleLifecycleEffects::$cache = $cache;
            $events = $this->createMock(EventsManager::class);
            $events->method('getEventObservers')->willReturn([]);
            $events->method('dispatch')->willReturnCallback(static function ($name) use ($events): EventsManager { if ($name === 'Weline_I18n::locale_catalog_changed') { ++LocaleLifecycleEffects::$catalog; } return $events; });
            ObjectManager::setInstance(EventsManager::class, $events);
            $i18n = $this->createMock(I18n::class);
            $i18n->method('getCountryFlag')->willReturn('flag');
            $i18n->method('getCountryFlagWithLocal')->willReturn(['flag' => 'flag']);
            $i18n->method('getCountry')->willReturn(['locales' => ['en_US']]);
            $i18n->method('getCountries')->willReturn(['US' => 'United States']);
            $i18n->method('getLocaleName')->willReturn('English');
            ObjectManager::setInstance(I18n::class, $i18n);
            RequestContext::init();
            TransactionContext::reset();
            try {
                foreach ([
                    'CREATE TABLE i18n_countries (code TEXT PRIMARY KEY, is_active INTEGER, is_install INTEGER, flag TEXT)',
                    'CREATE TABLE i18n_locale (code TEXT PRIMARY KEY, country_code TEXT, short_code TEXT, iso2 TEXT, iso3 TEXT, is_active INTEGER, is_install INTEGER, flag TEXT)',
                    'CREATE TABLE i18n_locals (code TEXT, target_code TEXT, name TEXT CHECK(name <> \'FAIL_SQL\'), is_active INTEGER, is_install INTEGER, flag TEXT, PRIMARY KEY(code,target_code))',
                    'CREATE TABLE i18n_countries_locale_name (country_code TEXT, display_locale_code TEXT, display_name TEXT, PRIMARY KEY(country_code,display_locale_code))',
                    'CREATE TABLE i18n_locale_name (locale_code TEXT, display_locale_code TEXT, display_name TEXT, PRIMARY KEY(locale_code,display_locale_code))',
                    'CREATE TABLE probe_changes (marker TEXT)',
                    "INSERT INTO i18n_countries VALUES ('US',1,1,'flag')",
                    "INSERT INTO i18n_locale VALUES ('en_US','US','EN','EN','ENG',1,1,'flag')",
                    "INSERT INTO i18n_locals VALUES ('en_US','en_US','English',1,1,'flag')",
                ] as $sql) { $connector->query($sql)->fetch(); }
                foreach ([Countries::class, Locale::class, Locals::class, CountryName::class, LocaleName::class] as $class) {
                    $model = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
                    $model->setConnection($connection);
                    $model->_primary_key = $class::schema_fields_ID;
                    $model->origin_table_name = $class::schema_table;
                    ObjectManager::setInstance($class, $model);
                }
                $service = new CountryLocaleLifecycleService();
                $publisher->fail = str_ends_with($scenario, '-failure');
                $error = null;
                $during = null;
                $operation = function () use ($scenario, $service, $connection): void {
                    if (str_starts_with($scenario, 'locals-')) {
                        $model = ObjectManager::getInstance(Locals::class);
                        $model->setData(['code' => 'en_US', 'target_code' => 'en_US', 'name' => $scenario === 'locals-sql-failure' ? 'FAIL_SQL' : 'updated', 'is_active' => 1, 'is_install' => 1, 'flag' => 'flag']);
                        $model->save();
                    } else {
                        $method = str_starts_with($scenario, 'rollback-') ? substr($scenario, 9) : explode('-', $scenario)[0];
                        $service->$method(str_contains($method, 'Country') ? 'US' : 'en_US');
                    }
                };
                try {
                    if (str_starts_with($scenario, 'rollback-') || $scenario === 'locals-rollback') {
                        $transactions->run($connection, function () use ($operation, &$during): void {
                            $operation();
                            $during = ['deletes' => LocaleLifecycleEffects::$deletes, 'url' => LocaleLifecycleEffects::$url, 'catalog' => LocaleLifecycleEffects::$catalog];
                            throw new \RuntimeException('outer_rollback');
                        });
                    } else { $operation(); }
                } catch (\Throwable $exception) { $error = $exception->getMessage(); }
                return [
                    'scenario' => $scenario, 'error' => $error, 'calls' => $publisher->calls, 'committed' => $publisher->committed,
                    'country' => $connector->query("SELECT is_active,is_install FROM i18n_countries WHERE code='US'")->fetch()[0],
                    'locale' => $connector->query("SELECT is_active,is_install FROM i18n_locale WHERE code='en_US'")->fetch()[0],
                    'locals' => $connector->query("SELECT name,is_active,is_install FROM i18n_locals WHERE code='en_US'")->fetch()[0],
                    'change_rows' => (int)$connector->query('SELECT count(*) AS n FROM probe_changes')->fetch()[0]['n'],
                    'clears' => LocaleLifecycleEffects::$clears, 'broadcasts' => LocaleLifecycleEffects::$broadcasts,
                    'url' => LocaleLifecycleEffects::$url, 'catalog' => LocaleLifecycleEffects::$catalog,
                    'deletes' => LocaleLifecycleEffects::$deletes, 'during' => $during,
                    'transaction_open' => $connector->getWrappedConnection()->inTransaction(),
                ];
            } finally {
                TransactionContext::reset(); RequestContext::cleanup(); $connector->close();
                if (is_file($file)) { unlink($file); }
            }
        }
    }
    echo json_encode((new LocaleLifecycleTransactionFixture('runScenario'))->runScenario($argv[1] ?? 'deactivateLocale'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), PHP_EOL;
}
