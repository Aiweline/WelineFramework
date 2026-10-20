<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\ThemeScopeVersionSelection;
use Weline\Theme\Service\ThemeScopeVersionService;

/**
 * 跨 Worker 发布解析 L1：值只存 version id，代次 bump 后其它 Worker 不再复用。
 */
final class ThemePublishedL1CacheTest extends TestCase
{
    private const THEME_ID = 392;
    private const SCOPE = 'default.default.default';
    private const VERSION_ID = 77;

    private object $generation;
    private ?object $previousGeneration = null;
    private ?object $previousSelection = null;
    private object $selection;
    private ThemeScopeVersionService $service;

    protected function setUp(): void
    {
        RequestContext::init();

        $this->generation = new class implements NamespaceGenerationInterface {
            public int $generation = 1;
            public int $bumps = 0;

            public function fingerprint(array $namespaces): string
            {
                return 'test-generation-' . $this->generation;
            }

            public function bumpMany(array $namespaces): array
            {
                ++$this->bumps;
                ++$this->generation;

                return [
                    'authority_clock' => $this->generation,
                    'changes' => [],
                ];
            }

            public function bump(string $namespace): array
            {
                return $this->bumpMany([$namespace]);
            }
        };

        $this->previousGeneration = $this->replaceInstance(NamespaceGenerationInterface::class, $this->generation);

        $this->selection = new class {
            public int $calls = 0;
            /** @var array<string, mixed> */
            public array $row = [];

            public function clearQuery(): self
            {
                return $this;
            }

            public function clearData(): self
            {
                return $this;
            }

            public function where(...$args): self
            {
                return $this;
            }

            public function limit(int $limit): self
            {
                return $this;
            }

            public function select(): self
            {
                return $this;
            }

            public function fetchArray(): array
            {
                ++$this->calls;

                return $this->row === [] ? [] : [$this->row];
            }
        };
        $this->previousSelection = $this->replaceInstance(ThemeScopeVersionSelection::class, $this->selection);

        $versionModel = (new \ReflectionClass(ThemeScopeVersion::class))->newInstanceWithoutConstructor();
        $this->service = new ThemeScopeVersionService($versionModel);

        $this->seedPublishedOwner();
    }

    protected function tearDown(): void
    {
        $this->restoreInstance(NamespaceGenerationInterface::class, $this->previousGeneration);
        $this->restoreInstance(ThemeScopeVersionSelection::class, $this->previousSelection);
        RequestContext::init();
    }

    public function testPublishedResolutionIsReusedAcrossRequestsUntilGenerationBumps(): void
    {
        $first = $this->service->getPublished(self::THEME_ID, self::SCOPE);
        self::assertSame(self::VERSION_ID, $first?->getVersionId());
        self::assertSame(1, $this->selection->calls);

        // 同请求第二次：R0 命中，不再读 selection。
        $second = $this->service->getPublished(self::THEME_ID, self::SCOPE);
        self::assertSame(self::VERSION_ID, $second?->getVersionId());
        self::assertSame(1, $this->selection->calls);

        // 新请求：请求 memo 清空，但进程袋仍在 → L1 命中，selection 仍为 1 查。
        $this->beginNewRequest();
        $third = $this->service->getPublished(self::THEME_ID, self::SCOPE);
        self::assertSame(self::VERSION_ID, $third?->getVersionId());
        self::assertSame(1, $this->selection->calls);

        // 代次 bump（发布内容变更）后：L1 失效，重新解析。
        ThemeScopeVersionService::bumpGeneration();
        self::assertSame(1, $this->generation->bumps);

        $this->beginNewRequest();
        $fourth = $this->service->getPublished(self::THEME_ID, self::SCOPE);
        self::assertSame(self::VERSION_ID, $fourth?->getVersionId());
        self::assertSame(2, $this->selection->calls);
    }

    public function testVersionAndSelectionWritesBumpGeneration(): void
    {
        $version = (new \ReflectionClass(ThemeScopeVersion::class))->newInstanceWithoutConstructor();
        $version->setVersionId(self::VERSION_ID)
            ->setThemeId(self::THEME_ID)
            ->setScope(self::SCOPE)
            ->setStoreMode('normal')
            ->setArea('frontend')
            ->setContentRevision(1)
            ->setLifecycle(ThemeScopeVersion::LIFECYCLE_SEALED);
        $version->save_after();
        self::assertSame(1, $this->generation->bumps);

        $selection = (new \ReflectionClass(ThemeScopeVersionSelection::class))->newInstanceWithoutConstructor();
        $selection->setThemeId(self::THEME_ID)
            ->setScope(self::SCOPE)
            ->setStoreMode('normal')
            ->setArea('frontend')
            ->setPublishedVersionId(self::VERSION_ID);
        $selection->save_after();
        self::assertSame(2, $this->generation->bumps);
    }

    public function testInvalidateOwnerBumpsGeneration(): void
    {
        $this->service->invalidateOwner(self::THEME_ID, self::SCOPE);
        self::assertSame(1, $this->generation->bumps);
    }

    private function beginNewRequest(): void
    {
        RequestContext::init();
        $this->seedPublishedOwner();
    }

    private function seedPublishedOwner(): void
    {
        RequestContext::set(ThemeScopeVersionService::ROW_CACHE_PREFIX . self::VERSION_ID, [
            ThemeScopeVersion::schema_fields_ID => self::VERSION_ID,
            ThemeScopeVersion::schema_fields_THEME_ID => self::THEME_ID,
            ThemeScopeVersion::schema_fields_SCOPE => self::SCOPE,
            ThemeScopeVersion::schema_fields_STORE_MODE => 'normal',
            ThemeScopeVersion::schema_fields_AREA => 'frontend',
            ThemeScopeVersion::schema_fields_VERSION_NUMBER => 3,
            ThemeScopeVersion::schema_fields_LIFECYCLE => ThemeScopeVersion::LIFECYCLE_SEALED,
            ThemeScopeVersion::schema_fields_CONTENT_REVISION => 1,
            ThemeScopeVersion::schema_fields_IS_PUBLISHED => 1,
        ]);
        $this->selection->row = [
            ThemeScopeVersionSelection::schema_fields_ID => 9,
            ThemeScopeVersionSelection::schema_fields_THEME_ID => self::THEME_ID,
            ThemeScopeVersionSelection::schema_fields_SCOPE => self::SCOPE,
            ThemeScopeVersionSelection::schema_fields_STORE_MODE => 'normal',
            ThemeScopeVersionSelection::schema_fields_AREA => 'frontend',
            ThemeScopeVersionSelection::schema_fields_PUBLISHED_VERSION_ID => self::VERSION_ID,
            ThemeScopeVersionSelection::schema_fields_DRAFT_VERSION_ID => null,
            ThemeScopeVersionSelection::schema_fields_SELECTION_REVISION => 1,
        ];
    }

    private function replaceInstance(string $class, object $instance): ?object
    {
        $previous = null;
        try {
            $previous = ObjectManager::getInstance($class);
        } catch (\Throwable) {
        }
        ObjectManager::setInstance($class, $instance);

        return $previous;
    }

    private function restoreInstance(string $class, ?object $previous): void
    {
        if ($previous === null) {
            ObjectManager::removeInstance($class);

            return;
        }
        ObjectManager::setInstance($class, $previous);
    }
}
