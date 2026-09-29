<?php

declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Layout\LayoutIdentity;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Service\LayoutEntity\SolidifiedControllerTemplateResolver;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutSourceSnapshot;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\ThemeScopeVersionService;
use Weline\Theme\Service\ThemeVersionPreviewResolver;

final class SolidifiedControllerTemplateFallbackTest extends TestCase
{
    private array $previousInstances = [];
    private ThemeLayoutEntityPaths $paths;
    private ThemeVersionIdentity $identity;
    private ThemeScopeVersionService $versions;
    private object $preview;
    private object $coordinator;

    protected function setUp(): void
    {
        RequestContext::resetWelineVars();
        RequestContext::set(LayoutIdentity::REQUEST_CONTEXT_KEY, null);
        $this->identity = new ThemeVersionIdentity(392, 'default.default.default', 'normal', 'frontend', 77, 'formal', 4);
        $this->paths = new ThemeLayoutEntityPaths(sys_get_temp_dir() . '/weline-formal-fallback-' . bin2hex(random_bytes(6)) . '/theme-layout-entities');
        $version = (new \ReflectionClass(ThemeScopeVersion::class))->newInstanceWithoutConstructor();
        $version->setVersionId(77)->setThemeId(392)->setScope('default.default.default')->setStoreMode('normal')
            ->setArea('frontend')->setContentRevision(4)->setLifecycle(ThemeScopeVersion::LIFECYCLE_SEALED);
        $this->versions = new ThemeScopeVersionService($version);
        RequestContext::set('theme.scope_version.selection.392|default.default.default|normal|frontend', ['selection'=>null]);
        RequestContext::set('theme.scope_version.flag.392|default.default.default|normal|frontend|is_published', ['version'=>$version]);
        $this->preview = new class {
            public bool $authorized = false;
            public array $context = [];
            public function hasAuthoritativePreviewContext(): bool { return $this->authorized; }
            public function getCurrentContext(): array { return $this->context; }
        };
        $this->replaceInstance(PreviewContextService::class, $this->preview);
        $this->coordinator = new class {
            public int $calls = 0;
            public ?ThemeVersionIdentity $receivedIdentity = null;
            public \RuntimeException $failure;
            public function __construct() { $this->failure = new \RuntimeException('historical_resource_snapshot_missing'); }
            public function candidateForIdentity(ThemeVersionIdentity $identity, ...$args): array
            {
                ++$this->calls;
                $this->receivedIdentity = $identity;
                throw $this->failure;
            }
        };
        $this->replaceInstance(ThemeLayoutEntityBakeCoordinator::class, $this->coordinator);
    }

    protected function tearDown(): void
    {
        foreach ($this->previousInstances as $class => $previous) {
            if ($previous === null) { ObjectManager::removeInstance($class); }
            else { ObjectManager::setInstance($class, $previous); }
        }
        $this->paths->purgeAllEntities();
        RequestContext::set(LayoutIdentity::REQUEST_CONTEXT_KEY, null);
        RequestContext::set(ThemeLayoutSourceSnapshot::REQUEST_KEY, null);
        RequestContext::set(ThemeVersionPreviewResolver::REQUEST_KEY, null);
        RequestContext::set('theme.scope_version.selection.392|default.default.default|normal|frontend', null);
        RequestContext::set('theme.scope_version.flag.392|default.default.default|normal|frontend|is_published', null);
    }

    public static function absentOrStalePage(): array { return [['absent'], ['wrong_revision']]; }

    #[DataProvider('absentOrStalePage')]
    public function testOrdinaryFormalRequestFallsBackWithoutBakingAndKeepsValidPartials(string $pageState): void
    {
        $partial = $this->paths->partialPhtml($this->identity, 'header', 'compact');
        $this->writeSource($partial, $this->identity, '<b>fixed-public-header</b>', [
            'resource_type'=>'partial', 'partial_type'=>'header', 'partial_option'=>'compact',
        ]);
        if ($pageState === 'wrong_revision') {
            $this->writeSource($this->paths->pageLayoutPhtml($this->identity, 'homepage'), $this->identity->withVersion(77, 'formal', 3), '<b>stale-page</b>');
        }

        self::assertNull($this->resolver()->resolveExecutableLayoutPath(392, 'homepage'));
        self::assertSame(0, $this->coordinator->calls);
        self::assertSame($partial, ThemeLayoutSourceSnapshot::current()?->partialPath('header', 'compact'));
        self::assertStringContainsString('fixed-public-header', ThemeLayoutSourceSnapshot::current()?->source($partial)['bytes'] ?? '');
    }

    public static function authorizedPreviewKinds(): array { return [['formal', true], ['draft', false]]; }

    #[DataProvider('authorizedPreviewKinds')]
    public function testAuthorizedPreviewRebuildStillPropagatesMissingHistoricalInput(string $mode, bool $hasToken): void
    {
        $this->identity = $this->identity->withVersion(77, $mode, 2);
        $this->preview->authorized = true;
        $this->preview->context = $this->identity->toArray();
        $tokenService = new class($hasToken ? $this->identity->toArray() : null) {
            public function __construct(private readonly ?array $token) {}
            public function getCurrentPreviewData(): ?array { return $this->token; }
        };
        $this->replaceInstance(PreviewTokenService::class, $tokenService);
        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnCallback(static fn($key, $default = null) => $default);
        $this->replaceInstance(Request::class, $request);
        $versionResolver = new class($this->identity) {
            public function __construct(private readonly ThemeVersionIdentity $identity) {}
            public function resolve(...$args): array { return ['resolved'=>true, 'version_identity'=>$this->identity]; }
        };
        $this->replaceInstance(ThemeVersionPreviewResolver::class, $versionResolver);

        $actual = null;
        try { $this->resolver()->resolveExecutableLayoutPath(392, 'homepage'); }
        catch (\RuntimeException $error) { $actual = $error; }
        self::assertSame($this->coordinator->failure, $actual);
        self::assertSame(1, $this->coordinator->calls);
        self::assertSame($this->identity->cacheKey(), $this->coordinator->receivedIdentity?->cacheKey());
    }

    private function resolver(): SolidifiedControllerTemplateResolver
    {
        return new SolidifiedControllerTemplateResolver($this->paths, $this->versions, $this->createMock(ScopeHierarchyInterface::class));
    }

    private function writeSource(string $path, ThemeVersionIdentity $identity, string $html, array $metadata = []): void
    {
        if (!is_dir(dirname($path))) { mkdir(dirname($path), 0770, true); }
        file_put_contents($path, '<?php /* weline-source:' . base64_encode(json_encode(['origin'=>__FILE__, 'identity'=>$identity->toArray()] + $metadata, JSON_THROW_ON_ERROR)) . ' */ ?>' . $html);
    }

    private function replaceInstance(string $class, object $instance): void
    {
        $this->previousInstances[$class] = ObjectManager::_getInstance($class);
        ObjectManager::setInstance($class, $instance);
    }
}
