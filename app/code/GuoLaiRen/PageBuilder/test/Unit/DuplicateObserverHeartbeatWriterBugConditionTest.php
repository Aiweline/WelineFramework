<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit;

use Closure;
use GuoLaiRen\PageBuilder\Test\Integration\DuplicateObserverHeartbeatWriter;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Sse\SseWriter;

/**
 * Bug Condition Exploration Test for DuplicateObserverHeartbeatWriter
 * 
 * This test validates that the DuplicateObserverHeartbeatWriter class can be loaded
 * and instantiated without fatal errors. On unfixed code, this test MUST FAIL with
 * "Cannot extend final class SseWriter" error, confirming the bug exists.
 * 
 * **Validates: Requirements 1.1, 1.2**
 */
final class DuplicateObserverHeartbeatWriterBugConditionTest extends TestCase
{
    /**
     * Test that DuplicateObserverHeartbeatWriter class can be loaded without fatal errors
     * 
     * Expected on UNFIXED code: FAIL with "Cannot extend final class SseWriter"
     * Expected on FIXED code: PASS
     */
    public function testClassCanBeLoaded(): void
    {
        // This will trigger a fatal error on unfixed code if SseWriter is final
        $this->assertTrue(class_exists(DuplicateObserverHeartbeatWriter::class));
    }

    /**
     * Test that DuplicateObserverHeartbeatWriter can be instantiated with a callback
     * 
     * Expected on UNFIXED code: FAIL with "Cannot extend final class SseWriter"
     * Expected on FIXED code: PASS
     */
    public function testCanInstantiateWithCallback(): void
    {
        $callback = function (): void {
            // Mock callback
        };

        $writer = new DuplicateObserverHeartbeatWriter($callback);
        
        $this->assertNotNull($writer);
        $this->assertInstanceOf(DuplicateObserverHeartbeatWriter::class, $writer);
    }

    /**
     * Test that DuplicateObserverHeartbeatWriter is an instance of SseWriter
     * 
     * Expected on UNFIXED code: FAIL with "Cannot extend final class SseWriter"
     * Expected on FIXED code: PASS
     */
    public function testInstanceOfSseWriter(): void
    {
        $callback = function (): void {
            // Mock callback
        };

        $writer = new DuplicateObserverHeartbeatWriter($callback);
        
        $this->assertInstanceOf(SseWriter::class, $writer);
    }

    /**
     * Test that overridden methods are callable on the mock instance
     * 
     * Expected on UNFIXED code: FAIL with "Cannot extend final class SseWriter"
     * Expected on FIXED code: PASS
     */
    public function testOverriddenMethodsAreCallable(): void
    {
        $callback = function (): void {
            // Mock callback
        };

        $writer = new DuplicateObserverHeartbeatWriter($callback);
        
        // Test that overridden methods can be called
        $result = $writer->start();
        $this->assertInstanceOf(DuplicateObserverHeartbeatWriter::class, $result);
        
        $result = $writer->maybeHeartbeat();
        $this->assertInstanceOf(DuplicateObserverHeartbeatWriter::class, $result);
        
        $result = $writer->sendEvent('test', ['data' => 'value']);
        $this->assertInstanceOf(DuplicateObserverHeartbeatWriter::class, $result);
        
        $result = $writer->sendError('test error');
        $this->assertInstanceOf(DuplicateObserverHeartbeatWriter::class, $result);
        
        $this->assertTrue($writer->isAlive());
        
        $writer->complete();
        $this->assertNull(null); // complete() returns void
    }

    /**
     * Test that event tracking methods work correctly
     * 
     * Expected on UNFIXED code: FAIL with "Cannot extend final class SseWriter"
     * Expected on FIXED code: PASS
     */
    public function testEventTrackingMethods(): void
    {
        $callback = function (): void {
            // Mock callback
        };

        $writer = new DuplicateObserverHeartbeatWriter($callback);
        
        $writer->sendEvent('progress', ['message' => 'Processing']);
        $writer->sendEvent('progress', ['message' => 'Done']);
        $writer->sendEvent('error', ['message' => 'Error']);
        
        $this->assertSame(2, $writer->countEvents('progress'));
        $this->assertSame(1, $writer->countEvents('error'));
        
        $progressEvents = $writer->eventsByName('progress');
        $this->assertCount(2, $progressEvents);
        $this->assertSame('progress', $progressEvents[0]['event']);
        $this->assertSame('progress', $progressEvents[1]['event']);
    }
}
