<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Api\Scoped\ThemeContentScope;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\ThemeScopeVersionRevision;
use Weline\Theme\Model\ThemeScopeVersionResourceSnapshot;
use Weline\Theme\Service\Version\ThemeVersionResourceSnapshotService;

final class ThemeVersionSnapshotCliHeadTest extends TestCase
{
    private array $previous = [];
    private object $written;
    private object $searched;

    protected function setUp(): void
    {
        $this->written = (object)['rows' => []];
        $this->searched = (object)['scopes' => []];
        $query = new class($this->written) {
            private array $data = [];
            public function __construct(private object $written) {}
            public function clearData(): self { $this->data = []; return $this; }
            public function clearQuery(): self { return $this; }
            public function where(...$args): self { return $this; }
            public function select(): self { return $this; }
            public function fetchArray(): array { return []; }
            public function setData(array $data): self { $this->data = $data; return $this; }
            public function save(): self { $this->written->rows[] = $this->data; return $this; }
        };
        $this->install(ThemeScopeVersionRevision::class, $query);
        $this->install(ThemeScopeVersionResourceSnapshot::class, clone $query);
        $this->install(\Weline\Framework\Cache\Service\StorefrontScopeHotCache::class, new class {
            public function forgetRequestMemo(...$args): void {}
            public function rememberForRequest(string $type, string $key, callable $read): mixed { return $read(); }
        });
        $normalizer = (new \ReflectionClass(\Weline\Theme\Service\ThemeLayoutScopeNormalizer::class))->newInstanceWithoutConstructor();
        $this->install($normalizer::class, $normalizer);
        $repo = $this->createStub(\Weline\Meta\Api\MetaConfigRepositoryInterface::class);
        $searched = $this->searched;
        $repo->method('search')->willReturnCallback(static function ($search) use ($searched): array {
            $searched->scopes[] = $search->scope;
            return [
                new \Weline\Meta\Api\Data\MetaConfigRecord(1, 'theme.frontend', 'partials.value', '{"header":"compact"}', $search->scope, null, '3', null, null),
                new \Weline\Meta\Api\Data\MetaConfigRecord(2, 'theme.frontend', 'layouts.homepage.default.param.image.value', 'image-fr.png', $search->scope, 'fr_FR', '3', null, null),
            ];
        });
        $this->install(\Weline\Meta\Api\MetaConfigRepositoryInterface::class, $repo);
        $metadata = $this->createStub(\Weline\Meta\Api\MetadataRepositoryInterface::class);
        $metadata->method('search')->willReturn([]);
        $this->install(\Weline\Meta\Api\MetadataRepositoryInterface::class, $metadata);
        $dictionary = $this->createStub(\Weline\I18n\Api\Translation\DictionaryRepositoryInterface::class);
        $dictionary->method('getEntries')->willReturnCallback(static fn(array $words, string $locale): array =>
            array_map(static fn(string $word): \Weline\I18n\Api\Translation\DictionaryEntry => new \Weline\I18n\Api\Translation\DictionaryEntry($word, $locale, 'dictionary-fr.png'), $words));
        $this->install(\Weline\I18n\Api\Translation\DictionaryRepositoryInterface::class, $dictionary);
        $locales = $this->createStub(\Weline\I18n\Api\Localization\LocaleCatalogInterface::class);
        $locales->method('all')->willReturn([['code' => 'fr_FR', 'name' => 'French']]);
        $this->install(\Weline\I18n\Api\Localization\LocaleCatalogInterface::class, $locales);
        // 用真实请求解析器复现缺少上下文错误，避免构造器连接数据库。
        $resolver = (new \ReflectionClass(\Weline\Theme\Service\ThemeRuntimeLayoutResolver::class))->newInstanceWithoutConstructor();
        $this->install($resolver::class, $resolver);
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $class => $previous) {
            ObjectManager::removeInstance($class);
            if ($previous !== null) { ObjectManager::setInstance($class, $previous); }
        }
    }

    public function testHeadCapturesCliOwnerWithoutRequestContext(): void
    {
        $this->writeHead();
        self::assertSame(['shop-a.__website__.default'], $this->searched->scopes);
        $head = $this->written->rows[0];
        self::assertSame(9, $head['theme_version_id']);
        self::assertSame(3, $head['content_revision']);
        $descriptor = json_decode($head['package_default_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($descriptor['current_package_defaults']);
        self::assertSame('compact', $descriptor['configuration']['partial_options']['header']);
        self::assertSame('dictionary-fr.png', $descriptor['configuration']['locale_params']['fr_FR']['layouts.homepage.default']['image']);
    }

    public function testHeadPreservesExplicitConfigurationAncestors(): void
    {
        $parent = new ThemeContentScope('websites', 'default.default.default', 'normal', '', 'fr_FR');
        $scope = new ThemeContentScope('websites', 'shop-a.__website__.default', 'normal', '', 'fr_FR', $parent);
        $context = new ThemeEditorContext($scope, 'frontend', themeId: 3, layoutType: 'homepage');
        $this->writeHead(context: $context);
        self::assertSame(['default', 'default.default.default', 'shop-a.__website__.default'], $this->searched->scopes);
    }

    public function testExistingHistoricalConfigurationIsNotRecaptured(): void
    {
        $configuration = ['params' => ['layouts.homepage.default' => ['image' => 'historical.png']], 'locale_params' => ['fr_FR' => ['title' => 'historical']], 'partial_options' => ['header' => 'historic']];
        $this->writeHead(configuration: $configuration);
        self::assertSame([], $this->searched->scopes);
        $descriptor = json_decode($this->written->rows[0]['package_default_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($configuration, $descriptor['configuration']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('creationSources')]
    public function testCaptureCurrentInitialBranchesPassTheExplicitConfigurationChain(string $source): void
    {
        $version = $this->version();
        $version->setCreationSourceKind($source);
        $this->install(\Weline\Theme\Service\ThemeScopeVersionService::class, new class($version) {
            public function __construct(private ThemeScopeVersion $version) {}
            public function getCurrent(...$args): ThemeScopeVersion { return $this->version; }
            public function getPublished(...$args): ?ThemeScopeVersion { return null; }
        });
        $this->install(\Weline\Theme\Model\ThemeScopeWorkspace::class, ObjectManager::getInstance(ThemeScopeVersionResourceSnapshot::class));
        $this->install(\Weline\Theme\Service\WidgetDefaultInjectionService::class, new class {
            public function uninstalledInjectionsForVersion(...$args): array { return []; }
        });
        $this->install(\Weline\Theme\Service\LayoutDataService::class, new class {
            public function getAllLayoutTypes(): array { return ['homepage' => 'Homepage']; }
        });
        $parent = new ThemeContentScope('websites', 'default.default.default', 'normal', '', 'fr_FR');
        $scope = new ThemeContentScope('websites', 'shop-a.__website__.default', 'normal', '', 'fr_FR', $parent);
        $context = new ThemeEditorContext($scope, 'frontend', themeId: 3, layoutType: 'homepage');
        (new ThemeVersionResourceSnapshotService())->captureCurrent($version, $context, true);

        self::assertSame(3, $version->getContentRevision());
        self::assertSame(['default', 'default.default.default', 'shop-a.__website__.default'], $this->searched->scopes);
        $head = array_values(array_filter($this->written->rows, static fn(array $row): bool => isset($row['package_default_json'])))[0];
        $descriptor = json_decode($head['package_default_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('dictionary-fr.png', $descriptor['configuration']['locale_params']['fr_FR']['layouts.homepage.default']['image']);
    }

    public static function creationSources(): array
    {
        return [
            'package' => [\Weline\Theme\Api\Version\ThemeVersionPublicationInterface::CREATION_PACKAGE_DEFAULTS],
            'continuation' => ['continue_current'],
        ];
    }

    private function writeHead(?array $configuration = null, ?ThemeEditorContext $context = null): void
    {
        $version = $this->version();
        $reflection = new \ReflectionClass(ThemeVersionResourceSnapshotService::class);
        self::assertStringContainsString('/app/code/Weline/Theme/', $reflection->getFileName());
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getMethod('writeHead')->invoke($service, $version, 'cli_test', $configuration, [], $context);
    }

    private function version(): ThemeScopeVersion
    {
        $version = (new \ReflectionClass(ThemeScopeVersion::class))->newInstanceWithoutConstructor();
        return $version->setData(['theme_id' => 3, 'scope' => 'shop-a.__website__.default', 'store_mode' => 'normal', 'area' => 'frontend', 'version_id' => 9, 'content_revision' => 3, 'lifecycle' => 'draft']);
    }

    private function install(string $class, object $service): void
    {
        $this->previous[$class] = ObjectManager::_getInstance($class);
        ObjectManager::setInstance($class, $service);
    }
}
