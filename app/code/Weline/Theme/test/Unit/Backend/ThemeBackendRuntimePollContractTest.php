<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Backend;

use PHPUnit\Framework\TestCase;

/**
 * Protects REQ-4: Theme backend long tasks must poll runtime_task.status, not createStream.
 */
final class ThemeBackendRuntimePollContractTest extends TestCase
{
    public function testBackendIndexUsesStatusPollingWithoutCreateStream(): void
    {
        $path = BP . '/app/code/Weline/Theme/view/templates/backend/index.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringNotContainsString('createStream(', $source);
        self::assertStringNotContainsString('new EventSource', $source);
        self::assertStringContainsString("resource('runtime_task')", $source);
        self::assertStringContainsString('.status({', $source);
        self::assertStringContainsString('pollRuntimeTask', $source);
    }
}
