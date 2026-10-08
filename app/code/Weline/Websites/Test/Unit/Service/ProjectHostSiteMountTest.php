<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\ProjectHostSiteMount;

final class ProjectHostSiteMountTest extends TestCase
{
    public function testMountPathForMountableCode(): void
    {
        self::assertSame('/~site/daocharms', ProjectHostSiteMount::mountPathForCode('DaoCharms'));
        self::assertSame('/~site/default', ProjectHostSiteMount::mountPathForCode(Website::CODE_DEFAULT));
        self::assertSame('', ProjectHostSiteMount::mountPathForCode('Bad Code'));
        self::assertSame('', ProjectHostSiteMount::mountPathForCode('~site'));
        self::assertSame('', ProjectHostSiteMount::mountPathForCode(''));
    }

    public function testEditorMountSkipsDefaultWebsite(): void
    {
        self::assertSame('', ProjectHostSiteMount::editorMountPath(Website::ID_DEFAULT, Website::CODE_DEFAULT));
        self::assertSame('/~site/daocharms', ProjectHostSiteMount::editorMountPath(158, 'daocharms'));
    }

    public function testParseMountFromPath(): void
    {
        self::assertNull(ProjectHostSiteMount::parseMountFromPath('/'));
        self::assertNull(ProjectHostSiteMount::parseMountFromPath('/daocharms'));
        self::assertSame(
            ['code' => 'daocharms', 'mount' => '/~site/daocharms'],
            ProjectHostSiteMount::parseMountFromPath('/~site/daocharms/en_US/product')
        );
        self::assertSame(
            ['code' => 'grocery', 'mount' => '/~site/grocery'],
            ProjectHostSiteMount::parseMountFromPath('/~site/grocery')
        );
    }

    public function testParseMountRejectsBareOrInvalidCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProjectHostSiteMount::parseMountFromPath('/~site');
    }

    public function testConflictsWithDomainSubPath(): void
    {
        self::assertTrue(ProjectHostSiteMount::conflictsWithDomainSubPath('/~site'));
        self::assertTrue(ProjectHostSiteMount::conflictsWithDomainSubPath('/~site/daocharms'));
        self::assertFalse(ProjectHostSiteMount::conflictsWithDomainSubPath('/daocharms'));
        self::assertFalse(ProjectHostSiteMount::conflictsWithDomainSubPath(''));
    }
}
