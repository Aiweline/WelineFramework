<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Weline\Framework\App\Env;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\I18n\Model\Locale;
use Weline\I18n\Model\Locals;
use Weline\I18n\Service\ActiveLocaleCodeProvider;
use Weline\I18n\Service\TranslationResolver;
use Weline\I18n\Taglib\LanguageSelect;
use Weline\I18n\Taglib\LanguageSwitcher;
use Weline\I18n\Model\I18n;

final class TranslationNamespaceReadCacheTest extends TestCase
{
    private array $instances;
    private mixed $manager;
    private int $version = 1;

    protected function setUp(): void
    {
        $this->instances = ObjectManager::getInstances();
        $property = new ReflectionProperty(ObjectManager::class, 'instance');
        $this->manager = $property->getValue();
        $property->setValue(null, (new \ReflectionClass(ObjectManager::class))->newInstanceWithoutConstructor());
        $authority = $this->createStub(NamespaceGenerationInterface::class);
        $authority->method('fingerprint')->willReturnCallback(fn(): string => 'i18n-test-' . $this->version);
        ObjectManager::setInstance(NamespaceGenerationInterface::class, $authority);
        RequestContext::init();
        $this->installNamespaceRepository();
        \Weline\Framework\Phrase\Parser::clearWorkerCaches();
    }

    protected function tearDown(): void
    {
        \Weline\Framework\Phrase\Parser::clearWorkerCaches();
        LanguageSelect::clearProcessCaches();
        LanguageSwitcher::clearProcessCaches();
        RequestContext::cleanup();
        (new ReflectionProperty(ObjectManager::class, 'instances'))->setValue(null, $this->instances);
        (new ReflectionProperty(ObjectManager::class, 'instance'))->setValue(null, $this->manager);
    }


    public function testChromeAndCatalogTranslationKeysFollowTheRelevantLanguageOnly(): void
    {
        $this->installNamespaceRepository();
        $generations = ['global/i18n' => 1, 'global/i18n/content' => 1];
        $authority = $this->createStub(NamespaceGenerationInterface::class);
        $authority->method('fingerprint')->willReturnCallback(static function (array $namespaces) use (&$generations): string {
            $vector = [];
            foreach ($namespaces as $namespace) {
                $parts = explode('/', $namespace);
                while ($parts !== []) {
                    $path = implode('/', $parts);
                    $vector[$path] = $generations[$path] ?? 0;
                    array_pop($parts);
                }
            }
            ksort($vector);
            return hash('sha256', json_encode($vector));
        });
        ObjectManager::setInstance(NamespaceGenerationInterface::class, $authority);
        RequestContext::init();
        $label = 'Old locale name';
        $i18n = $this->createMock(I18n::class);
        $i18n->method('getLocaleName')->willReturnCallback(static function () use (&$label): string { return $label; });
        ObjectManager::setInstance(I18n::class, $i18n);
        $key = new \ReflectionMethod(LanguageSwitcher::class, 'buildHtmlCacheKey');
        $args = [false, 1, '', 'en_US', 'en_US', 'USD', '/products', '', '', ['en_US']];
        $before = $key->invoke(null, ...$args);
        self::assertSame('Old locale name', LanguageSelect::getLanguageItems('en_US', 'global')[0]['name']);

        $label = 'New locale name';
        $generations['global/i18n/ja_JP'] = 1;
        ++$generations['global/i18n/content'];
        RequestContext::init();
        self::assertSame($before, $key->invoke(null, ...$args));
        self::assertSame('Old locale name', LanguageSelect::getLanguageItems('en_US', 'global')[0]['name']);

        $generations['global/i18n/en_US'] = 1;
        ++$generations['global/i18n/content'];
        RequestContext::init();
        self::assertNotSame($before, $key->invoke(null, ...$args));
        self::assertSame('New locale name', LanguageSelect::getLanguageItems('en_US', 'global')[0]['name']);
    }

    public function testInstalledLocaleMemoFollowsCommittedGeneration(): void
    {
        $rows = [['code' => 'en_US']];
        $locale = $this->query(Locale::class, static function () use (&$rows): array { return $rows; });
        $locals = $this->query(Locals::class, static fn(): array => []);
        $provider = new ActiveLocaleCodeProvider($locals, $locale);
        self::assertSame(['en_US'], $provider->getInstalledActiveCodes());
        $rows[] = ['code' => 'fr_FR'];
        self::assertSame(['en_US'], $provider->getInstalledActiveCodes());
        ++$this->version;
        RequestContext::init();
        self::assertSame(['en_US', 'fr_FR'], $provider->getInstalledActiveCodes());
    }

    public function testCsvMemoFollowsGenerationWithoutChangingIdentityTranslationPriority(): void
    {
        $directory = sys_get_temp_dir() . '/weline-namespace-csv-' . bin2hex(random_bytes(6));
        mkdir($directory . '/i18n', 0777, true);
        $file = $directory . '/i18n/en_US.csv';
        file_put_contents($file, "Title,Old title\nIdentity,Identity\n");
        $env = Env::getInstance();
        $property = new ReflectionProperty(Env::class, 'module_list');
        $original = $property->getValue($env);
        $property->setValue($env, $original + ['Weline_NamespaceCsv' => ['name' => 'Weline_NamespaceCsv', 'base_path' => $directory]]);
        $resolver = new TranslationResolver();
        try {
            self::assertSame('Old title', $resolver->translate('Title', 'en_US', ['Weline_NamespaceCsv']));
            file_put_contents($file, "Title,New title\nIdentity,Identity\n");
            ++$this->version;
            RequestContext::init();
            self::assertSame('New title', $resolver->translate('Title', 'en_US', ['Weline_NamespaceCsv']));
            self::assertSame('Identity', $resolver->translate('Identity', 'en_US', ['Weline_NamespaceCsv']));
        } finally {
            $property->setValue($env, $original);
            unlink($file);
            rmdir($directory . '/i18n');
            rmdir($directory);
        }
    }

    public function testLanguageSelectDoesNotKeepOldTranslatedCatalogNames(): void
    {
        $label = 'Old name';
        $i18n = $this->createMock(I18n::class);
        $i18n->method('getLocaleName')->willReturnCallback(static function () use (&$label): string { return $label; });
        ObjectManager::setInstance(I18n::class, $i18n);
        self::assertSame('Old name', LanguageSelect::getLanguageItems('en_US', 'global')[0]['name']);
        $label = 'New name';
        ++$this->version;
        RequestContext::init();
        self::assertSame('New name', LanguageSelect::getLanguageItems('en_US', 'global')[0]['name']);
    }

    public function testSwitcherOutputIdentityIncludesTheDictionaryGeneration(): void
    {
        $key = new \ReflectionMethod(LanguageSwitcher::class, 'buildHtmlCacheKey');
        $args = [false, 1, '', 'en_US', 'en_US', 'USD', '/products', '', '', ['en_US']];
        $before = $key->invoke(null, ...$args);
        ++$this->version;
        RequestContext::init();
        self::assertNotSame($before, $key->invoke(null, ...$args));
    }

    public function testBackendChromeCatalogIdentityChangesWhenOnlyNamesChange(): void
    {
        $provider = $this->createMock(ActiveLocaleCodeProvider::class);
        $provider->method('getInstalledActiveCodes')->willReturn(['en_US']);
        ObjectManager::setInstance(ActiveLocaleCodeProvider::class, $provider);
        $before = LanguageSwitcher::backendLocaleCatalogFingerprint();
        ++$this->version;
        RequestContext::init();
        self::assertNotSame($before, LanguageSwitcher::backendLocaleCatalogFingerprint());
    }

    public function testModelLocaleNamesUseVersionedSharedCacheWithoutClearingOtherKeys(): void
    {
        $this->installNamespaceRepository();
        $storage = ['unrelated-product' => ['id' => 123]];
        $pool = $this->createMock(\Weline\Framework\Cache\Contract\CachePoolInterface::class);
        $pool->method('get')->willReturnCallback(static function ($key) use (&$storage): mixed { return $storage[$key] ?? null; });
        $pool->method('set')->willReturnCallback(static function ($key, $value) use (&$storage): bool { $storage[$key] = $value; return true; });
        $pool->expects(self::never())->method('clear');
        $names = ['en_US' => 'Old name'];
        $i18n = $this->getMockBuilder(I18n::class)->disableOriginalConstructor()->onlyMethods(['getLocaleNames'])->getMock();
        $i18n->method('getLocaleNames')->willReturnCallback(static function () use (&$names): array { return $names; });
        $i18n->i18nCache = $pool;
        self::assertSame($names, $i18n->getLocals('en_US'));
        $names['en_US'] = 'New name';
        ++$this->version;
        RequestContext::init();
        self::assertSame($names, $i18n->getLocals('en_US'));
        self::assertSame(['id' => 123], $storage['unrelated-product']);
    }

    public function testActiveLocaleQueryIsClonedWithoutSharedObjectCaching(): void
    {
        $prototype = $this->getMockBuilder(Locals::class)->disableOriginalConstructor()
            ->onlyMethods(['clearData'])->addMethods(['clearQuery', 'where'])->getMock();
        foreach (['clearData', 'clearQuery', 'where'] as $method) { $prototype->method($method)->willReturnSelf(); }
        ObjectManager::setInstance(Locals::class, $prototype);
        $pool = $this->createMock(\Weline\Framework\Cache\Contract\CachePoolInterface::class);
        $pool->expects(self::never())->method('set');
        $i18n = (new \ReflectionClass(I18n::class))->newInstanceWithoutConstructor();
        $i18n->i18nCache = $pool;
        $first = $i18n->getActiveLocalsModel('en_US');
        $second = $i18n->getActiveLocalsModel('fr_FR');
        self::assertNotSame($prototype, $first);
        self::assertNotSame($first, $second);
    }

    private function installNamespaceRepository(): void
    {
        $model = $this->getMockBuilder(\Weline\Framework\Model\Cache\NamespaceVersion::class)
            ->disableOriginalConstructor()->onlyMethods(['clearData'])
            ->addMethods(['clearQuery', 'fields', 'where', 'select', 'fetchArray'])->getMock();
        foreach (['clearData', 'clearQuery', 'fields', 'select'] as $method) { $model->method($method)->willReturnSelf(); }
        $wanted = [];
        $model->method('where')->willReturnCallback(static function ($field, $values) use (&$wanted, $model): object {
            $wanted = $values;
            return $model;
        });
        $model->method('fetchArray')->willReturnCallback(function () use (&$wanted): array {
            $rows = [];
            foreach (['@clock', 'global/i18n'] as $namespace) {
                $hash = hash('sha256', $namespace);
                if (in_array($hash, $wanted, true)) {
                    $rows[] = ['namespace_hash' => $hash, 'namespace' => $namespace, 'generation' => $this->version];
                }
            }
            return $rows;
        });
        $repository = new \Weline\Framework\Cache\Namespace\NamespaceGenerationRepository(
            $model, new \Weline\Framework\Cache\Namespace\NamespacePath(),
            new \Weline\Framework\Cache\Namespace\NamespaceGenerationSnapshot(),
            new \Weline\Framework\Cache\Namespace\NamespaceKeyDecorator(),
        );
        ObjectManager::setInstance(\Weline\Framework\Cache\Namespace\NamespaceGenerationRepository::class, $repository);
    }

    private function query(string $class, callable $rows): object
    {
        $model = $this->getMockBuilder($class)->disableOriginalConstructor()
            ->addMethods(['clearQuery', 'where', 'select', 'fetchArray'])->getMock();
        foreach (['clearQuery', 'where', 'select'] as $method) { $model->method($method)->willReturnSelf(); }
        $model->method('fetchArray')->willReturnCallback($rows);
        return $model;
    }
}
