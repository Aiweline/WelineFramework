<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service\Provider;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\Provider\FakeProvider;

/**
 * T1 验收：离线 fake 供应商——确定性、批量翻译返回同长度 JSON 数组、非翻译提示返回标记文本。
 */
final class FakeProviderTest extends TestCase
{
    private function model(): AiModel
    {
        $model = new AiModel();
        $model->setData(AiModel::schema_fields_MODEL_CODE, 'fake-translator');
        $model->setData(AiModel::schema_fields_SUPPLIER, 'fake');

        return $model;
    }

    public function testProviderIdentity(): void
    {
        $provider = new FakeProvider();
        $this->assertSame('fake', $provider->getProviderCode());
        $this->assertTrue($provider->supports('fake-translator'));
        $this->assertFalse($provider->supports('gpt-4o-mini'));
        $this->assertContains('fake-translator', $provider->getSupportedModels());
    }

    public function testBatchTranslationReturnsJsonArrayOfSameLength(): void
    {
        $prompt = "Translate each string.\nReturn ONLY a valid JSON array.\nInput JSON:\n[\"冰青直裾\",\"银几何腰带\",\"袖上白花\"]";
        $result = (new FakeProvider())->generate($this->model(), $prompt, ['target_language' => 'French']);

        $this->assertArrayHasKey('content', $result);
        $decoded = json_decode((string)$result['content'], true);
        $this->assertIsArray($decoded);
        $this->assertCount(3, $decoded);
        $this->assertSame('[French] 冰青直裾', $decoded[0]);
        $this->assertSame('[French] 银几何腰带', $decoded[1]);
        $this->assertSame('[French] 袖上白花', $decoded[2]);
        $this->assertSame('fake-translator', $result['model'] ?? null);
    }

    public function testPlainPromptReturnsMarkedText(): void
    {
        $result = (new FakeProvider())->generate($this->model(), '写一句关于汉服的介绍', []);
        $this->assertStringStartsWith('[fake]', (string)$result['content']);
    }

    public function testStreamEmitsWholeContent(): void
    {
        $chunks = [];
        $result = (new FakeProvider())->generateStream(
            $this->model(),
            "Input JSON:\n[\"你好\"]",
            static function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
            ['target_language' => 'English']
        );
        $this->assertSame(implode('', $chunks), (string)$result['content']);
        $this->assertSame('["[English] 你好"]', (string)$result['content']);
    }

    public function testDeterministic(): void
    {
        $provider = new FakeProvider();
        $prompt = "Input JSON:\n[\"同一句\"]";
        $first = $provider->generate($this->model(), $prompt, ['target_language' => 'English']);
        $second = $provider->generate($this->model(), $prompt, ['target_language' => 'English']);
        $this->assertSame($first['content'], $second['content']);
    }
}
