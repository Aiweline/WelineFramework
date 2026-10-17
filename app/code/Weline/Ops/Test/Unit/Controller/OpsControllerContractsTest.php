<?php

declare(strict_types=1);

namespace Weline\Ops\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllOpsControllerContractsPass().
 */
final class OpsControllerContractsTest extends TestCase
{
    public function testAllOpsControllerContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'OpsPersonaSse::test_desk_exposes_persona_sse_stream_endpoint' => function () {
            $this->assert_OpsPersonaSse_test_desk_exposes_persona_sse_stream_endpoint();
        },
            'OpsPersonaSse::test_desk_template_wires_sse_progress_tag' => function () {
            $this->assert_OpsPersonaSse_test_desk_template_wires_sse_progress_tag();
        },
        ];
    }

private function assert_OpsPersonaSse_test_desk_exposes_persona_sse_stream_endpoint(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Ops' . '/Controller/Backend/Desk.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('function refreshPersonaStream', $src);
    self::assertStringContainsString('SseWriter', $src);
    self::assertStringContainsString('generateFromContextStreaming', $src);
    self::assertStringContainsString("sendEvent('done'", $src);
    self::assertStringContainsString("sendEvent('failed'", $src);
}

private function assert_OpsPersonaSse_test_desk_template_wires_sse_progress_tag(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Ops' . '/view/templates/backend/Desk/index.phtml';
    $html = (string) file_get_contents($path);
    self::assertStringContainsString('w:theme:sse-progress', $html);
    self::assertStringContainsString('data-testid="ops-persona-sse-panel"', $html);
    self::assertStringContainsString('ops-persona-progress', $html);
    self::assertStringContainsString('persona_sse_url', $html);
    self::assertStringContainsString('persona_sse_steps_json', $html);
    self::assertStringContainsString('WelineSseProgress', $html);
    self::assertStringContainsString('id="ops-refresh-persona-btn"', $html);
}
}
