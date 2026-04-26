<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\AiService;

class AiServiceStreamErrorMessageTest extends TestCase
{
    private AiService $service;

    private \ReflectionMethod $normalizeStreamErrorMessageMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $reflection = new \ReflectionClass(AiService::class);
        $this->service = $reflection->newInstanceWithoutConstructor();

        $this->normalizeStreamErrorMessageMethod = $reflection->getMethod('normalizeStreamErrorMessage');
        $this->normalizeStreamErrorMessageMethod->setAccessible(true);
    }

    public function testNormalizeStreamErrorMessageRepairsKnownAnthropicEmptyStreamMojibake(): void
    {
        $expected = 'AI 流式生成完成但未返回任何内容，请检查模型配置（API Key、Base URL、模型名称）是否正确';
        $garbled = 'AI 娴佸紡鐢熸垚瀹屾垚浣嗘湭杩斿洖浠讳綍鍐呭锛岃妫€鏌ユā鍨嬮厤缃紙API Key銆丅ase URL銆佹ā鍨嬪悕绉帮級鏄惁姝ｇ‘';

        $normalized = $this->normalize($garbled);

        $this->assertNotSame($expected, $garbled);
        $this->assertSame($expected, $normalized);
    }

    public function testNormalizeStreamErrorMessageKeepsReadableChineseUntouched(): void
    {
        $message = 'AI 流式生成完成但未返回任何内容，请检查模型配置（API Key、Base URL、模型名称）是否正确';

        $normalized = $this->normalize($message);

        $this->assertSame($message, $normalized);
    }

    private function normalize(string $message): string
    {
        return $this->normalizeStreamErrorMessageMethod->invoke($this->service, $message);
    }
}
