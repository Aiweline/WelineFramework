<?php

declare(strict_types=1);

namespace Weline\Ops\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class OpsPersonaServiceContractTest extends TestCase
{
    public function test_persona_requires_real_ai_and_forbids_description_paste(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/OpsPersonaService.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringNotContainsString('$summary = $description', $src);
        self::assertStringNotContainsString('inferHeuristically', $src);
        self::assertStringNotContainsString('ops_persona_heuristic', $src);
        self::assertStringNotContainsString('buildOperationalSummary', $src);
        self::assertStringContainsString('inferViaAi', $src);
        self::assertStringContainsString('validateAiPersona', $src);
        self::assertStringContainsString('containsLongDescriptionChunk', $src);
        self::assertStringContainsString('resolvePersonaModelCode', $src);
        self::assertStringContainsString('isLocalOllamaSupplier', $src);
        self::assertStringContainsString('ops_persona_ai_v4', $src);
        self::assertStringContainsString('generateFromContextStreaming', $src);
        self::assertStringContainsString('EVIDENCE', $src);
        self::assertStringContainsString('LOW_TRUST_BLURB', $src);
        self::assertStringContainsString('deepseek-flash', $src);
        self::assertStringContainsString('禁止回退为网站简介或启发式模板', $src);
        self::assertStringContainsString('AiRuntimeInterface', $src);
        self::assertStringNotContainsString('ops_persona_ai_v3', $src);
    }

    public function test_progress_steps_cover_evidence_pipeline(): void
    {
        $keys = array_column(\Weline\Ops\Service\OpsPersonaService::progressSteps(), 'key');
        foreach (['site', 'catalog', 'products', 'policy', 'market', 'compress', 'ai', 'persist'] as $key) {
            self::assertContains($key, $keys);
        }
    }
}
