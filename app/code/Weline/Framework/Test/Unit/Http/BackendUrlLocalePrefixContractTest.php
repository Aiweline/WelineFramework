<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);
require_once BP . 'app/bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\App\State;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;

final class BackendUrlLocalePrefixContractTest extends TestCase
{
    private array $serverBackup = [];
    private array $envSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->envSnapshot = WelineEnv::getInstance()->capture();
        $this->resetParserState();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        WelineEnv::getInstance()->restore($this->envSnapshot);
        $this->resetParserState();
        parent::tearDown();
    }

    public function testGetBackendUrlKeepsNonDefaultLanguageSegment(): void
    {
        $backendPrefix = (string)(Env::getAreaRoutePrefix('backend') ?? '');
        self::assertNotSame('', $backendPrefix);

        $parsed = $this->parsePath('/' . $backendPrefix . '/en_US/admin');
        WelineEnv::getInstance()->initFromSnapshot([], [], [], [], $parsed['server']);
        WelineEnv::set('website.currency', 'CNY', 'backend url locale contract');
        WelineEnv::set('website.language', 'zh_Hans_CN', 'backend url locale contract');

        self::assertSame('en_US', State::getLang());
        self::assertSame('/en_US', Url::getPrefix());

        /** @var Url $url */
        $url = ObjectManager::getInstance(Url::class);
        $root = $url->getBackendUrl('/');
        $leaf = $url->getBackendUrl('weline_product/backend/catalog/products');

        self::assertStringContainsString('/en_US', $root);
        self::assertStringContainsString('/en_US/', $leaf);
        self::assertStringContainsString('weline_product/backend/catalog/products', $leaf);
        self::assertDoesNotMatchRegularExpression('#/en_US/.+/en_US/#', $leaf);
    }

    public function testGetBackendUrlOmitsDefaultLanguageSegment(): void
    {
        $backendPrefix = (string)(Env::getAreaRoutePrefix('backend') ?? '');
        self::assertNotSame('', $backendPrefix);

        $parsed = $this->parsePath('/' . $backendPrefix . '/admin');
        WelineEnv::getInstance()->initFromSnapshot([], [], [], [], $parsed['server']);
        WelineEnv::set('website.currency', 'CNY', 'backend url locale contract');
        WelineEnv::set('website.language', 'zh_Hans_CN', 'backend url locale contract');
        WelineEnv::set('user.currency', 'CNY', 'backend url locale contract');
        WelineEnv::set('user.lang', 'zh_Hans_CN', 'backend url locale contract');

        self::assertSame('', Url::getPrefix());

        /** @var Url $url */
        $url = ObjectManager::getInstance(Url::class);
        $leaf = $url->getBackendUrl('admin/index');
        $path = (string)(parse_url($leaf, PHP_URL_PATH) ?: $leaf);

        self::assertStringNotContainsString('/zh_Hans_CN/', $path);
        self::assertStringContainsString('/admin/index', $path);
    }

    /** @return array<string, mixed> */
    private function parsePath(string $path): array
    {
        $this->resetParserState();
        $parsed = Url::parser('http://localhost' . $path);
        self::assertIsArray($parsed);

        return $parsed;
    }

    private function resetParserState(): void
    {
        Url::$parserServer = [];
        Url::resetWebsiteParserSites();
        Url::resetParserRequestCaches();
        State::resetRequestPathLocalizationCache();
    }
}
