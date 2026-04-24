<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Sse\SseWriter;

require_once __DIR__ . '/../Support/DuplicateObserverHeartbeatWriter.php';

/**
 * Bug Condition Exploration Test
 * 
 * This test explores the bug condition where DuplicateObserverHeartbeatWriter
 * attempts to extend SseWriter. The test MUST FAIL on unfixed code to confirm
 * the bug exists.
 * 
 * **Validates: Requirements 1.1, 1.2**
 */
final class BugConditionExplorationTest extends TestCase
{
    /**
     * Test that DuplicateObserverHeartbeatWriter can be loaded and instantiated
     * 
     * This test attempts to:
     * 1. Load the test file containing DuplicateObserverHeartbeatWriter
     * 2. Verify the class declaration exists
     * 3. Instantiate DuplicateObserverHeartbeatWriter with a callback
     * 4. Assert that the instance is an instance of SseWriter
     * 5. Assert that overridden methods are callable
     * 
     * Expected behavior on UNFIXED code: Test FAILS with "Cannot extend final class SseWriter" error
     * Expected behavior on FIXED code: Test PASSES
     */
    public function testDuplicateObserverHeartbeatWriterCanBeLoadedAndInstantiated(): void
    {
        // Verify that the test file can be loaded
        $testFilePath = __DIR__ . '/AiSiteAgentOperationObserverIntegrationTest.php';
        self::assertFileExists($testFilePath, 'Test file should exist');

        // Verify that the class can be loaded (this will fail if SseWriter is final)
        self::assertTrue(
            \class_exists(DuplicateObserverHeartbeatWriter::class),
            'DuplicateObserverHeartbeatWriter class should be loadable'
        );

        // Verify that the class is a subclass of SseWriter
        self::assertTrue(
            \is_subclass_of(DuplicateObserverHeartbeatWriter::class, SseWriter::class),
            'DuplicateObserverHeartbeatWriter should extend SseWriter'
        );

        // Attempt to instantiate DuplicateObserverHeartbeatWriter with a callback
        $callback = static function (): void {
            // Mock callback
        };
        
        $instance = new DuplicateObserverHeartbeatWriter($callback);
        
        // Assert that the instance was created successfully
        self::assertNotNull($instance, 'Instance should be created successfully');
        
        // Assert that the instance is an instance of SseWriter
        self::assertInstanceOf(
            SseWriter::class,
            $instance,
            'Instance should be an instance of SseWriter'
        );

        // Assert that overridden methods are callable
        self::assertTrue(
            \method_exists($instance, 'sendEvent'),
            'sendEvent method should exist'
        );
        self::assertTrue(
            \method_exists($instance, 'maybeHeartbeat'),
            'maybeHeartbeat method should exist'
        );
        self::assertTrue(
            \method_exists($instance, 'complete'),
            'complete method should exist'
        );
        self::assertTrue(
            \method_exists($instance, 'isAlive'),
            'isAlive method should exist'
        );

        // Verify that methods are callable
        self::assertTrue(
            \is_callable([$instance, 'sendEvent']),
            'sendEvent should be callable'
        );
        self::assertTrue(
            \is_callable([$instance, 'maybeHeartbeat']),
            'maybeHeartbeat should be callable'
        );
        self::assertTrue(
            \is_callable([$instance, 'complete']),
            'complete should be callable'
        );
        self::assertTrue(
            \is_callable([$instance, 'isAlive']),
            'isAlive should be callable'
        );
    }
}
