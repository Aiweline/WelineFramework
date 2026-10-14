<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http\Fpc;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Fpc\FpcHtmlDiskStore;
use Weline\Framework\Http\Fpc\FpcStoreAdapterInterface;

final class FpcHtmlDiskStorePurgeSlugsContractTest extends TestCase
{
    private string $fixtureHost = '';

    protected function setUp(): void
    {
        $this->fixtureHost = 'fpc-slug-test-' . \bin2hex(\random_bytes(4)) . '.example.test';
    }

    protected function tearDown(): void
    {
        try {
            FpcHtmlDiskStore::abandonStagingGeneration();
        } catch (\Throwable) {
        }
        FpcHtmlDiskStore::purgeSlugs([$this->fixtureHost], ['policy/privacy', 'terms']);
    }

    public function testPurgeSlugsRemovesBareAndLocaleLeaves(): void
    {
        $html = "<!doctype html><html><body>policy</body></html>\n";
        $bare = 'https://' . $this->fixtureHost . '/policy/privacy';
        $locale = 'https://' . $this->fixtureHost . '/en_US/policy/privacy';
        $other = 'https://' . $this->fixtureHost . '/terms';
        self::assertTrue(FpcHtmlDiskStore::publish($bare, $html, ''));
        self::assertTrue(FpcHtmlDiskStore::publish($locale, $html, ''));
        self::assertTrue(FpcHtmlDiskStore::publish($other, $html, ''));

        $barePath = FpcHtmlDiskStore::htmlPath($bare);
        $localePath = FpcHtmlDiskStore::htmlPath($locale);
        $otherPath = FpcHtmlDiskStore::htmlPath($other);
        self::assertIsString($barePath);
        self::assertIsString($localePath);
        self::assertIsString($otherPath);
        self::assertFileExists($barePath);
        self::assertFileExists($localePath);
        self::assertFileExists($otherPath);

        FpcHtmlDiskStore::purgeSlugs([$this->fixtureHost], ['policy/privacy']);
        self::assertFileDoesNotExist($barePath);
        self::assertFileDoesNotExist($localePath);
        self::assertFileExists($otherPath);
    }

    public function testAdapterInterfaceDeclaresPurgeSlugs(): void
    {
        $src = \file_get_contents(\dirname(__DIR__, 4) . '/Http/Fpc/FpcStoreAdapterInterface.php');
        self::assertIsString($src);
        self::assertStringContainsString('function purgeSlugs(array $hosts, array $slugs): void;', $src);
        self::assertTrue(\interface_exists(FpcStoreAdapterInterface::class));
    }

    public function testPipelineAndExpanderKeepForceReloadFalse(): void
    {
        $expander = \file_get_contents(\dirname(__DIR__, 4) . '/Event/Changed/UrlMatrixExpander.php');
        $pipeline = \file_get_contents(\dirname(__DIR__, 4) . '/Event/Changed/ChangedPipeline.php');
        self::assertIsString($expander);
        self::assertIsString($pipeline);
        self::assertStringContainsString("'force_reload' => false", $expander);
        self::assertStringNotContainsString("'force_reload' => true", $expander);
        self::assertStringContainsString("'slugs'", $pipeline);
    }
}
