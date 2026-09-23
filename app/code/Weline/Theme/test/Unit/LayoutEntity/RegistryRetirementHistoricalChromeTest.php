<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Service\SharedChromeService;
use Weline\Theme\Service\ThemeLayoutVersionBindingResolver;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityInjectionTargets;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionBakeMerger;

final class RegistryRetirementHistoricalChromeTest extends TestCase
{
    public function testDefinitionRetirementTargetsCurrentDraftOnlyWhileOrdinaryChangesKeepExistingReach(): void
    {
        $instances = ObjectManager::getInstances();
        $targets = new class { public function resolve(...$args): array { return []; } public function reportForTargets($targets): array { return ['unresolved' => []]; } };
        $merger = new stdClass();
        $versions = new RetirementChromeVersionsFixture();
        $resolver = new class {
            public array $scopes = [];
            public function resolveChromeOmissions($themeId, $scope, $nodes): array {
                $this->scopes[] = $scope;
                return ['resolved' => false, 'reason' => 'fixture_no_omission_binding'];
            }
        };
        ObjectManager::setInstance(ThemeLayoutEntityInjectionTargets::class, $targets);
        ObjectManager::setInstance(RequiredDefaultInjectionBakeMerger::class, $merger);
        ObjectManager::setInstance(ThemeScopeVersion::class, $versions);
        ObjectManager::setInstance(ThemeLayoutVersionBindingResolver::class, $resolver);
        try {
            $class = new ReflectionClass(ThemeLayoutEntityBakeCoordinator::class);
            $coordinator = $class->newInstanceWithoutConstructor();
            $chrome = (new ReflectionClass(SharedChromeService::class))->newInstanceWithoutConstructor();
            $class->getProperty('sharedChrome')->setValue($coordinator, $chrome);
            $retirement = ['definition_retired' => true, 'before' => [['slot' => 'footer-links', 'area' => 'footer']], 'after' => []];
            $coordinator->rebakeAfterInjectionCollect(3, [$retirement]);
            self::assertSame(['draft-current'], $resolver->scopes, 'Retired definitions must not rewrite current published or historical chrome.');
            $resolver->scopes = [];
            unset($retirement['definition_retired']);
            $coordinator->rebakeAfterInjectionCollect(3, [$retirement]);
            self::assertSame(['published-current', 'draft-history', 'draft-current'], $resolver->scopes, 'Ordinary declaration migration retains the existing reach.');
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
    public function load(int|string $field_or_pk_value, $value = null, bool $forceReload = false): self { $this->loaded = (int)$field_or_pk_value; return $this; }
    public function getThemeId(): int { return 3; }
    public function getScope(): string { return $this->getData('scope'); }
    public function getVersionId(): int { return $this->loaded; }
    public function getChromePayload(): array { return []; }
    public function isCurrent(): bool { return $this->getData('is_current'); }
    public function isPublished(): bool { return $this->getData('is_published'); }
    public function getData(string $key = '', $index = null): mixed {
        return ['theme_id' => 3, 'version_id' => $this->loaded, 'scope' => [1 => 'published-current', 2 => 'draft-history', 3 => 'draft-current'][$this->loaded] ?? '', 'is_current' => $this->loaded !== 2, 'is_published' => $this->loaded === 1, 'chrome_payload_json' => []][$key] ?? null;
    }
    public function __call($name, $args) { return $name === 'fetchArray' ? [['version_id' => 1], ['version_id' => 2], ['version_id' => 3]] : $this; }
}
