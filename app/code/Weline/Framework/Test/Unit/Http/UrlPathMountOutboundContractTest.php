<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Url;

/**
 * path 挂载站出站 URL 契约：匹配站点后的相对 path 才参与 currency/lang 拼装；
 * 入参若仍带 mount，须先剥掉，避免 getBaseHost()（已含 mount）重复前缀。
 */
final class UrlPathMountOutboundContractTest extends TestCase
{
    private array $serverBackup = [];
    private array $envSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->envSnapshot = WelineEnv::getInstance()->capture();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        WelineEnv::getInstance()->restore($this->envSnapshot);
        parent::tearDown();
    }

    public function testResolveCurrentWebsiteMountPathFromWebsiteUrl(): void
    {
        $_SERVER['WELINE_WEBSITE_URL'] = 'https://pre.example.test/aisite_accept_ok';
        WelineEnv::getInstance()->initFromSnapshot([], [], [], [], $_SERVER);
        WelineEnv::set('website_url', 'https://pre.example.test/aisite_accept_ok', 'mount outbound test');

        self::assertSame('/aisite_accept_ok', Url::resolveCurrentWebsiteMountPath());
    }

    public function testPeelMountPrefixFromRelativePath(): void
    {
        $_SERVER['WELINE_WEBSITE_URL'] = 'https://pre.example.test/aisite_accept_ok';
        WelineEnv::getInstance()->initFromSnapshot([], [], [], [], $_SERVER);
        WelineEnv::set('website_url', 'https://pre.example.test/aisite_accept_ok', 'mount outbound test');

        self::assertSame('/about', Url::peelWebsiteMountPathFromRelativePath('/aisite_accept_ok/about'));
        self::assertSame('/hi_IN/about', Url::peelWebsiteMountPathFromRelativePath('/aisite_accept_ok/hi_IN/about'));
        self::assertSame('/about?x=1', Url::peelWebsiteMountPathFromRelativePath('/aisite_accept_ok/about?x=1'));
        self::assertSame('/about', Url::peelWebsiteMountPathFromRelativePath('about'));
        self::assertSame('/', Url::peelWebsiteMountPathFromRelativePath('/aisite_accept_ok'));
        // 段边界：不误剥 /aisite_accept_ok_extra
        self::assertSame('/aisite_accept_ok_extra/about', Url::peelWebsiteMountPathFromRelativePath('/aisite_accept_ok_extra/about'));
    }

    public function testRootSitePeelIsNoOp(): void
    {
        $_SERVER['WELINE_WEBSITE_URL'] = 'https://pre.example.test';
        WelineEnv::getInstance()->initFromSnapshot([], [], [], [], $_SERVER);
        WelineEnv::set('website_url', 'https://pre.example.test', 'mount outbound test');

        self::assertSame('', Url::resolveCurrentWebsiteMountPath());
        self::assertSame('/aisite_accept_ok/about', Url::peelWebsiteMountPathFromRelativePath('/aisite_accept_ok/about'));
    }
}
