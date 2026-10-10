<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service\Provider;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\Provider\ConnectionTestModelResolver;

final class ConnectionTestModelResolverTest extends TestCase
{
    public function test_prefers_configured_vendor_test_model(): void
    {
        $code = ConnectionTestModelResolver::resolveDefaultModelCode([
            ['code' => 'old-model', 'created' => 200, 'primary_modality' => 'text2text'],
            ['code' => 'deepseek-flash', 'created' => 100, 'primary_modality' => 'text2text'],
        ], 'deepseek-flash');

        self::assertSame('deepseek-flash', $code);
    }

    public function test_prefers_newest_created_chat_model_when_no_configured_default(): void
    {
        $code = ConnectionTestModelResolver::resolveDefaultModelCode([
            ['code' => 'embed-1', 'created' => 999, 'primary_modality' => 'embedding'],
            ['code' => 'chat-old', 'created' => 10, 'primary_modality' => 'text2text'],
            ['code' => 'chat-new', 'created' => 50, 'primary_modality' => 'text2text'],
        ], '');

        self::assertSame('chat-new', $code);
    }

    public function test_prefers_is_default_flag(): void
    {
        $code = ConnectionTestModelResolver::resolveDefaultModelCode([
            ['code' => 'chat-new', 'created' => 50, 'primary_modality' => 'text2text'],
            ['code' => 'chat-default', 'created' => 1, 'primary_modality' => 'text2text', 'is_default' => 1],
        ], '');

        self::assertSame('chat-default', $code);
    }
}
