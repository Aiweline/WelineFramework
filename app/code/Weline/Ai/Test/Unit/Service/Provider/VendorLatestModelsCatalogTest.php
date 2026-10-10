<?php
declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service\Provider;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\Provider\VendorConfigManager;

/**
 * Contract: builtin etc/vendors catalogs expose current official model IDs.
 */
class VendorLatestModelsCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        VendorConfigManager::clearCache();
    }

    public function testDeepSeekCatalogIncludesV41FlashCanonicalId(): void
    {
        $codes = $this->modelCodes('deepseek');
        $this->assertContains('deepseek-flash', $codes);
        $this->assertContains('deepseek-v4-pro', $codes);
        $this->assertSame('deepseek-flash', VendorConfigManager::getTestModel('deepseek'));
    }

    public function testOpenAiCatalogIncludesGpt6Flagships(): void
    {
        $codes = $this->modelCodes('openai');
        $this->assertContains('gpt-6-astra', $codes);
        $this->assertContains('gpt-6.1-sol', $codes);
        $this->assertContains('gpt-6-luna', $codes);
        $this->assertSame('gpt-6-luna', VendorConfigManager::getTestModel('openai'));
    }

    public function testAnthropicCatalogIncludesClaude55Series(): void
    {
        $codes = $this->modelCodes('anthropic');
        $this->assertContains('claude-sonnet-5-5', $codes);
        $this->assertContains('claude-opus-5-5', $codes);
        $this->assertContains('claude-haiku-5-5', $codes);
        $this->assertSame('claude-haiku-5-5', VendorConfigManager::getTestModel('anthropic'));
    }

    public function testGoogleCatalogIncludesGemini38Flash(): void
    {
        $codes = $this->modelCodes('google');
        $this->assertContains('gemini-3.8-flash', $codes);
        $this->assertContains('gemini-nano-banana-2.1', $codes);
        $this->assertSame('gemini-3.8-flash', VendorConfigManager::getTestModel('google'));
    }

    public function testKimiCatalogIncludesK3(): void
    {
        $codes = $this->modelCodes('kimi');
        $this->assertContains('kimi-k3', $codes);
        $this->assertContains('kimi-k2.7-code', $codes);
        $this->assertSame('kimi-k3', VendorConfigManager::getTestModel('kimi'));
    }

    /**
     * @return list<string>
     */
    private function modelCodes(string $provider): array
    {
        $models = VendorConfigManager::getProviderModels($provider);
        $codes = [];
        foreach ($models as $model) {
            if (is_array($model) && isset($model['code'])) {
                $codes[] = (string)$model['code'];
            }
        }

        return $codes;
    }
}
