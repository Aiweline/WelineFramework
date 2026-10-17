<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Controller\FrontendController;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllSessionLocalContractsPass().
 */
final class SessionLocalContractsTest extends TestCase
{
    public function testAllSessionLocalContractsPass(): void
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
            'FrontendControllerLifecycle::testDocumentedFrontendLifecycleEventsWrapParentInitialization' => function () {
            $this->assert_FrontendControllerLifecycle_testDocumentedFrontendLifecycleEventsWrapParentInitialization();
        },
        ];
    }

private function assert_FrontendControllerLifecycle_testDocumentedFrontendLifecycleEventsWrapParentInitialization(): void
{
    $source = (string) file_get_contents((string) (new \ReflectionClass(FrontendController::class))->getFileName());
    $before = strpos($source, "dispatch('Weline_Framework_FrontendController::init_before', \$this)");
    $parent = strpos($source, 'parent::__init();');
    $after = strpos($source, "dispatch('Weline_Framework_FrontendController::init_after', \$this)");
    self::assertIsInt($before);
    self::assertIsInt($parent);
    self::assertIsInt($after);
    self::assertLessThan($parent, $before);
    self::assertLessThan($after, $parent);
}
}
