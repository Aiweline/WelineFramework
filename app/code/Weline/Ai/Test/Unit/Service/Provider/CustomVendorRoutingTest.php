<?php
declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service\Provider;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\Provider\CustomVendorService;

/**
 * 自定义/本地供应商合成（无 DB / ObjectManager）
 */
class CustomVendorRoutingTest extends TestCase
{
    public function testCustomVendorServiceSynthesizesOpenAiCompatConfig(): void
    {
        $service = new CustomVendorService();
        $config = $service->toVendorConfig([
            'code' => 'Ollama',
            'name' => 'Ollama Local',
            'base_url' => 'http://127.0.0.1:11434/v1/',
            'description' => 'local',
            'test_model' => 'llama3.2',
            'driver' => 'openai_compat',
            'config' => '{}',
        ]);

        $this->assertSame('ollama', $config['code']);
        $this->assertSame('custom', $config['source']);
        $this->assertSame('openai_compat', $config['driver']);
        $this->assertSame('http://127.0.0.1:11434/v1', $config['base_url']);
        $this->assertSame('llama3.2', $config['test_model']);
        $this->assertSame('/models', $config['models_api']['path'] ?? null);
    }

    public function testNormalizeCodeStripsInvalidChars(): void
    {
        $service = new CustomVendorService();
        $this->assertSame('ollama_local', $service->normalizeCode(' Ollama Local! '));
        $this->assertSame('lm_studio', $service->normalizeCode('LM Studio'));
        $this->assertSame('lmstudio', $service->normalizeCode('lmstudio'));
    }
}
