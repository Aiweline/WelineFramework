<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Service\SharedChromeService;
use Weline\Theme\Service\ThemeScopeVersionService;
use Weline\Theme\Service\Version\ThemeScopeVersionWidgetDecisionService;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityInjectionTargets;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityMaterializer;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPointerResolver;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutSlotTreeBuilder;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionBakeMerger;

final class RegistryRetirementHistoricalChromeTest extends TestCase
{
    public function testDefinitionRetirementTargetsCurrentDraftOnlyWhileOrdinaryChangesKeepExistingReach(): void
    {
        $instances = ObjectManager::getInstances();
        $targets = new class {
            public function resolve(...$args): array { return []; }
            public function reportForTargets($targets): array { return ['unresolved' => []]; }
        };
        $merger = new class {
            public function mergeIntoNodes($nodes, ...$args): array { return \is_array($nodes) ? $nodes : []; }
            public function unresolvedRetiredNodes(...$args): array { return []; }
        };
        $versions = new RetirementChromeVersionsFixture();
        $decisions = new class {
            /** @var list<int> */
            public array $versionIds = [];
            public function listUninstallOmissions(int $themeVersionId): array
            {
                $this->versionIds[] = $themeVersionId;

                return [];
            }
        };

        ObjectManager::setInstance(ThemeLayoutEntityInjectionTargets::class, $targets);
        ObjectManager::setInstance(RequiredDefaultInjectionBakeMerger::class, $merger);
        ObjectManager::setInstance(ThemeScopeVersion::class, $versions);
        ObjectManager::setInstance(ThemeScopeVersionWidgetDecisionService::class, $decisions);
        try {
            $class = new ReflectionClass(ThemeLayoutEntityBakeCoordinator::class);
            $coordinator = $class->newInstanceWithoutConstructor();
            $chrome = (new ReflectionClass(SharedChromeService::class))->newInstanceWithoutConstructor();
            // Typed deps left uninitialized so chrome rebake fails after omission lookup;
            // reach is asserted via ThemeScopeVersionWidgetDecisionService calls.
            $class->getProperty('sharedChrome')->setValue($coordinator, $chrome);
            $class->getProperty('materializer')->setValue(
                $coordinator,
                (new ReflectionClass(ThemeLayoutEntityMaterializer::class))->newInstanceWithoutConstructor(),
            );
            $class->getProperty('pointers')->setValue(
                $coordinator,
                (new ReflectionClass(ThemeLayoutEntityPointerResolver::class))->newInstanceWithoutConstructor(),
            );
            $class->getProperty('scopeVersions')->setValue(
                $coordinator,
                (new ReflectionClass(ThemeScopeVersionService::class))->newInstanceWithoutConstructor(),
            );
            $class->getProperty('slotTree')->setValue(
                $coordinator,
                (new ReflectionClass(ThemeLayoutSlotTreeBuilder::class))->newInstanceWithoutConstructor(),
            );

            $retirement = ['definition_retired' => true, 'before' => [['slot' => 'footer-links', 'area' => 'footer']], 'after' => []];
            $coordinator->rebakeAfterInjectionCollect(3, [$retirement]);
            self::assertSame([3], $decisions->versionIds, 'Retired definitions must not rewrite current published or historical chrome.');

            $decisions->versionIds = [];
            unset($retirement['definition_retired']);
            $coordinator->rebakeAfterInjectionCollect(3, [$retirement]);
            self::assertSame([1, 2, 3], $decisions->versionIds, 'Ordinary declaration migration retains the existing reach.');
        } finally {
            (new ReflectionMethod(ObjectManager::class, 'setScopedInstances'))->invoke(null, $instances);
        }
    }
}

final class RetirementChromeVersionsFixture
{
    private int $loaded = 0;
    public function __construct() {}
    public function __clone() {}
    public function clearData(bool $with_query = true): static { return $this; }
    public function clearQuery(): static { return $this; }
    public function where(...$args): static { return $this; }
    public function select(): static { return $this; }
    public function load(int|string $field_or_pk_value, $value = null, bool $forceReload = false): self
    {
        $this->loaded = (int)$field_or_pk_value;

        return $this;
    }
    public function getThemeId(): int { return 3; }
    public function getScope(): string { return (string)$this->getData('scope'); }
    public function getVersionId(): int { return $this->loaded; }
    public function getChromePayload(): array { return []; }
    public function isCurrent(): bool { return (bool)$this->getData('is_current'); }
    public function isPublished(): bool { return (bool)$this->getData('is_published'); }
    public function getData(string $key = '', $index = null): mixed
    {
        return [
            'theme_id' => 3,
            'version_id' => $this->loaded,
            'scope' => [1 => 'published-current', 2 => 'draft-history', 3 => 'draft-current'][$this->loaded] ?? '',
            'is_current' => $this->loaded !== 2,
            'is_published' => $this->loaded === 1,
            'chrome_payload_json' => [],
        ][$key] ?? null;
    }
    public function __call($name, $args)
    {
        return $name === 'fetchArray' ? [['version_id' => 1], ['version_id' => 2], ['version_id' => 3]] : $this;
    }
}
