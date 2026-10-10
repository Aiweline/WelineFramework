<?php

declare(strict_types=1);

namespace Weline\Ops\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

final class OpsPersonaSseContractTest extends TestCase
{
    public function test_desk_exposes_persona_sse_stream_endpoint(): void
    {
        $path = dirname(__DIR__, 3) . '/Controller/Backend/Desk.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('function refreshPersonaStream', $src);
        self::assertStringContainsString('SseWriter', $src);
        self::assertStringContainsString('generateFromContextStreaming', $src);
        self::assertStringContainsString("sendEvent('done'", $src);
        self::assertStringContainsString("sendEvent('failed'", $src);
    }

    public function test_desk_template_wires_sse_progress_tag(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/Desk/index.phtml';
        $html = (string)file_get_contents($path);
        self::assertStringContainsString('w:theme:sse-progress', $html);
        self::assertStringContainsString('data-testid="ops-persona-sse-panel"', $html);
        self::assertStringContainsString('ops-persona-progress', $html);
        self::assertStringContainsString('persona_sse_url', $html);
        self::assertStringContainsString('persona_sse_steps_json', $html);
        self::assertStringContainsString('WelineSseProgress', $html);
        self::assertStringContainsString('id="ops-refresh-persona-btn"', $html);
    }
}
