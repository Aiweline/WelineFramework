<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Api\Theme\ThemeApplicationReference;
use Weline\Websites\Api\Theme\ThemeApplicationRepositoryInterface;
use Weline\Websites\Service\ThemeApplicationService;

final class ThemeApplicationServiceTest extends TestCase
{
    private const GLOBAL = 'global||||||v1';
    private const SITE = 'website|1|changan||||v1';
    private const CHANNEL = 'channel|1|changan|main|web|normal|v1';

    protected function setUp(): void
    {
        self::assertTrue(class_exists(ThemeApplicationService::class), 'Website-owned Theme application service is missing.');
        self::assertSame(realpath(dirname(__DIR__, 3) . '/Service/ThemeApplicationService.php'), (new \ReflectionClass(ThemeApplicationService::class))->getFileName());
    }

    public function testExplicitSameThemeRemainsFixedWhenParentChanges(): void
    {
        $service = $this->service();
        $old = $this->reference(3, 31, 7);
        $service->save(self::GLOBAL, 'normal', 'frontend', $old, 0);
        $service->save(self::CHANNEL, 'normal', 'frontend', $old, 0);
        $service->save(self::GLOBAL, 'normal', 'frontend', $this->reference(4, 41, 1), 1);
        $resolved = $service->resolve([self::CHANNEL, self::SITE, self::GLOBAL], 'normal', 'frontend');
        self::assertTrue($resolved->own);
        self::assertSame(self::CHANNEL, $resolved->sourceScopeKey);
        self::assertSame([3, 31, 7], [$resolved->reference->themeId, $resolved->reference->themeVersionId, $resolved->reference->contentRevision]);
    }

    public function testRestoringInheritanceOnlyRemovesLocalApplicationReference(): void
    {
        $service = $this->service();
        $service->save(self::GLOBAL, 'normal', 'frontend', $this->reference(3, 31, 7), 0);
        $service->save(self::CHANNEL, 'normal', 'frontend', $this->reference(4, 41, 1), 0);
        $removed = $service->removeOwn(self::CHANNEL, 'normal', 'frontend', 1);
        self::assertNull($removed['reference']);
        self::assertSame(2, $removed['revision']);
        $resolved = $service->resolve([self::CHANNEL, self::SITE, self::GLOBAL], 'normal', 'frontend');
        self::assertFalse($resolved->own);
        self::assertSame(self::GLOBAL, $resolved->sourceScopeKey);
        self::assertSame(31, $resolved->reference->themeVersionId);
        self::assertSame(2, $resolved->localRevision);
    }

    public function testModesAndBackendAreIndependent(): void
    {
        $service = $this->service();
        $service->save(self::GLOBAL, 'normal', 'frontend', $this->reference(3, 31, 7), 0);
        $service->save(self::GLOBAL, 'test', 'frontend', $this->reference(5, 51, 2, 'test'), 0);
        $service->save(self::GLOBAL, 'normal', 'backend', $this->reference(9, 91, 3, 'normal', 'backend'), 0);
        self::assertSame(5, $service->resolve([self::SITE, self::GLOBAL], 'test', 'frontend')->reference->themeId);
        self::assertSame(3, $service->resolve([self::SITE, self::GLOBAL], 'normal', 'frontend')->reference->themeId);
        self::assertSame(9, $service->resolve([self::GLOBAL], 'normal', 'backend')->reference->themeId);
    }

    public function testApplicationKeepsExactVersionOwnerAndRevision(): void
    {
        $service = $this->service();
        $service->save(self::CHANNEL, 'normal', 'frontend', $this->reference(3, 31, 7), 0);
        $resolved = $service->resolve([self::CHANNEL], 'normal', 'frontend');
        self::assertSame('changan.default.default', $resolved->reference->versionOwnerScope);
        self::assertSame('normal', $resolved->reference->versionOwnerStoreMode);
        self::assertSame(7, $resolved->reference->contentRevision);
        self::assertSame(31, $resolved->reference->themeVersionId);
    }

    public function testStaleSaveCannotOverwriteNewerApplication(): void
    {
        $service = $this->service();
        $service->save(self::SITE, 'normal', 'frontend', $this->reference(3, 31, 7), 0);
        try {
            $service->save(self::SITE, 'normal', 'frontend', $this->reference(4, 41, 1), 0);
            self::fail('A stale application save was accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('application_revision_conflict:1', $error->getMessage());
        }
        self::assertSame(3, $service->getOwn(self::SITE, 'normal', 'frontend')['reference']->themeId);
    }

    public function testCrossModeVersionIsRejectedBeforeWrite(): void
    {
        $service = $this->service();
        $this->expectException(\InvalidArgumentException::class);
        $service->save(self::SITE, 'normal', 'frontend', $this->reference(3, 31, 7, 'test'), 0);
    }

    public function testPackageDefaultsReferenceKeepsZeroVersionAndRevision(): void
    {
        $service = $this->service();
        $service->save(self::SITE, 'normal', 'frontend', $this->reference(3, 0, 0), 0);
        $reference = $service->resolve([self::SITE], 'normal', 'frontend')->reference;
        self::assertSame(0, $reference->themeVersionId);
        self::assertSame(0, $reference->contentRevision);
    }

    public function testNegativeVersionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->reference(3, -1, 0);
    }

    public function testNegativeRevisionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->reference(3, 31, -1);
    }

    private function reference(int $theme, int $version, int $revision, string $mode = 'normal', string $area = 'frontend'): ThemeApplicationReference
    {
        return new ThemeApplicationReference($theme, $version, $revision, 'changan.default.default', $mode, $area);
    }

    private function service(): ThemeApplicationService
    {
        // 仅替代外部持久化边界，测试调用真实服务的继承与保存行为。
        return new ThemeApplicationService(new class implements ThemeApplicationRepositoryInterface {
            private array $records = [];
            public function read(string $scopeKey, string $storeMode, string $area): array
            {
                return $this->records[$scopeKey][$storeMode][$area] ?? ['reference' => null, 'revision' => 0];
            }
            public function compareAndSwap(string $scopeKey, string $storeMode, string $area, ?ThemeApplicationReference $reference, int $expectedRevision): array
            {
                $old = $this->read($scopeKey, $storeMode, $area);
                if ($old['revision'] !== $expectedRevision) {
                    throw new \RuntimeException('application_revision_conflict:' . $old['revision']);
                }
                return $this->records[$scopeKey][$storeMode][$area] = ['reference' => $reference, 'revision' => $old['revision'] + 1];
            }
        });
    }
}
