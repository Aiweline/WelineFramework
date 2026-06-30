<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use Weline\Framework\App\State;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\View\TemplateCacheManager;

final class TemplateCacheManagerEnvironmentKeyTest extends TestCore
{
    private string $sourceFile;
    /** @var array<int, string> */
    private array $compiledFiles = [];
    private mixed $envSnapshot;
    private bool $hadContext = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hadContext = Context::getCurrent() !== null;
        $this->envSnapshot = WelineEnv::getInstance()->capture();

        $tmpDir = BP . 'var' . DS . 'tmp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }
        $this->sourceFile = $tmpDir . DS . 'template-cache-manager-env-key-test.phtml';
        file_put_contents($this->sourceFile, '<span><?= __("Price") ?></span>');
    }

    protected function tearDown(): void
    {
        $manager = TemplateCacheManager::getInstance();

        foreach ([
            ['USD', 'en_US'],
            ['CNY', 'en_US'],
            ['CNY', 'zh_Hans_CN'],
        ] as [$currency, $lang]) {
            $this->applyRequestContext($currency, $lang);
            $manager->clearCache($this->sourceFile);
        }

        foreach ($this->compiledFiles as $compiledFile) {
            @unlink($compiledFile);
            $hash = basename($compiledFile, '.phtml');
            @unlink(BP . 'var' . DS . 'cache' . DS . 'template' . DS . substr($hash, 0, 2) . DS . $hash . '.meta');
        }

        @unlink($this->sourceFile);
        State::resetRequestPathLocalizationCache();
        if ($this->hadContext) {
            WelineEnv::getInstance()->restore($this->envSnapshot);
        } else {
            WelineEnv::getInstance()->reset();
        }

        parent::tearDown();
    }

    public function testCompiledTemplateCacheVariesByLanguageAndCurrency(): void
    {
        $manager = TemplateCacheManager::getInstance();

        $this->applyRequestContext('USD', 'en_US');
        $enUsdKey = $manager->getCacheKey($this->sourceFile);
        $enUsdFile = $manager->writeCache($this->sourceFile, '<?php echo "en-usd"; ?>');

        $this->applyRequestContext('CNY', 'en_US');
        $enCnyKey = $manager->getCacheKey($this->sourceFile);
        $enCnyFile = $manager->writeCache($this->sourceFile, '<?php echo "en-cny"; ?>');

        $this->applyRequestContext('CNY', 'zh_Hans_CN');
        $zhCnyKey = $manager->getCacheKey($this->sourceFile);
        $zhCnyFile = $manager->writeCache($this->sourceFile, '<?php echo "zh-cny"; ?>');

        $this->compiledFiles = [$enUsdFile, $enCnyFile, $zhCnyFile];

        self::assertNotSame($enUsdKey, $enCnyKey);
        self::assertNotSame($enCnyKey, $zhCnyKey);
        self::assertNotSame($enUsdFile, $enCnyFile);
        self::assertNotSame($enCnyFile, $zhCnyFile);
        self::assertSame('<?php echo "en-usd"; ?>', file_get_contents($enUsdFile));
        self::assertSame('<?php echo "en-cny"; ?>', file_get_contents($enCnyFile));
        self::assertSame('<?php echo "zh-cny"; ?>', file_get_contents($zhCnyFile));
    }

    private function applyRequestContext(string $currency, string $lang): void
    {
        State::resetRequestPathLocalizationCache();
        WelineEnv::getInstance()->initFromSnapshot([], [], [], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/' . $currency . '/' . $lang . '/cache-key-test',
            'HTTP_HOST' => 'example.test',
            'SERVER_NAME' => 'example.test',
            'SERVER_PORT' => '80',
            'HTTPS' => 'off',
        ]);
        WelineEnv::setCurrency($currency);
        WelineEnv::setLang($lang);
    }
}
