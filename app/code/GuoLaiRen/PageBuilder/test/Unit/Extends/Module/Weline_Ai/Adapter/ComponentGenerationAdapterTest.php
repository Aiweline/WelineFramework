<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Extends\Module\Weline_Ai\Adapter;

use GuoLaiRen\PageBuilder\Extends\Module\Weline_Ai\Adapter\ComponentGenerationAdapter;
use PHPUnit\Framework\TestCase;

final class ComponentGenerationAdapterTest extends TestCase
{
    public function testPartialPatchModeKeepsPromptLightweight(): void
    {
        $adapter = new ComponentGenerationAdapter();
        $prompt = 'Return JSON only with keys: block, change_summary, changed_fields, reason.';

        $partial = $adapter->adaptPrompt($prompt, ['partial_patch_mode' => true]);
        $normal = $adapter->adaptPrompt('Create a PageBuilder hero component.', []);

        self::assertStringContainsString('PageBuilder block partial patch constraints', $partial);
        self::assertStringNotContainsString('@component_start', $partial);
        self::assertGreaterThan(\strlen($partial) + 1000, \strlen($normal));
    }

    public function testPartialPatchModeDoesNotWrapJsonResponseAsPhp(): void
    {
        $adapter = new ComponentGenerationAdapter();
        $json = ' {"block":{"block_id":"hero"},"change_summary":"Updated."} ';

        $processed = $adapter->processResponse($json, ['partial_patch_mode' => true]);

        self::assertSame('{"block":{"block_id":"hero"},"change_summary":"Updated."}', $processed);
        self::assertStringNotContainsString('<?php', $processed);
    }
}
