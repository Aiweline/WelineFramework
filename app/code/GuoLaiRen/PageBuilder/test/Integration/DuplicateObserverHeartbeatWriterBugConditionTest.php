<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

use Closure;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Sse\SseWriter;

/**
 * Bug Condition Exploration Test for DuplicateObserverHeartbeatWriter
 * 
 * This test verifies the bug condition: DuplicateObserverHeartbeatWriter cannot extend SseWriter
 * 
 * CRITICAL: This test MUST FAIL on unfixed code - failure confirms the bug exists
 * When the fix is applied, this test MUST PASS
 * 
 * @author Kiro
 */
final class DuplicateObserverHeartbeatWriterBugConditionTest extends TestCase
{
    /**
     * Test that DuplicateObserverHeartbeatWriter can be loaded and instantiated
     * 
     * Property 1: Bug Condition - SseWriter Final Class Prevents Inheritance
     * 
     * EXPECTED OUTCOME on UNFIXED code: Test FAILS with "Cannot extend final class SseWriter"
     * EXPECTED OUTCOME on FIXED code: Test PASSES
     */
    public function testDuplicateObserverHeartbeatWriterCanBeInstantiated(): void
    {
        // Attempt to instantiate DuplicateObserverHeartbeatWriter
        $callback = static function (): void {
            // Callback for heartbeat
        };
        
        $writer = new DuplicateObserverHeartbeatWriter($callback);
        
        // Verify instance is created
        self::assertNotNull($writer);
        
        // Verify it's an instance of SseWriter
        self::assertInstanceOf(SseWriter::class, $writer);
    }
    
    /**
     * Test that DuplicateObserverHeartbeatWriter methods can be called
     * 
     * Property 1: Bug Condition - Method Overrides Work Correctly
     * 
     * EXPECTED OUTCOME on UNFIXED code: Test FAILS (class cannot be instantiated)
     * EXPECTED OUTCOME on FIXED code: Test PASSES
     */
    public function testDuplicateObserverHeartbeatWriterMethodsAreCallable(): void
    {
        $callback = static function (): void {
            // Callback for heartbeat
        };
        
        $writer = new DuplicateObserverHeartbeatWriter($callback);
        
        // Verify overridden methods are callable
        $result = $writer->start();
        self::assertSame($writer, $result);
        
        $result = $writer->sendEvent('test', ['data' => 'value']);
        self::assertSame($writer, $result);
        
        $result = $writer->maybeHeartbeat();
        self::assertSame($writer, $result);
        
        $result = $writer->isAlive();
        self::assertTrue($result);
        
        // complete() returns void
        $writer->complete(['final' => 'data']);
        
        // Verify events were captured
        self::assertGreaterThanOrEqual(1, $writer->countEvents('test'));
    }
    
    /**
     * Test that DuplicateObserverHeartbeatWriter captures events correctly
     * 
     * Property 1: Bug Condition - Event Capture Works
     * 
     * EXPECTED OUTCOME on UNFIXED code: Test FAILS (class cannot be instantiated)
     * EXPECTED OUTCOME on FIXED code: Test PASSES
     */
    public function testDuplicateObserverHeartbeatWriterCapturesEvents(): void
    {
        $callback = static function (): void {
            // Callback for heartbeat
        };
        
        $writer = new DuplicateObserverHeartbeatWriter($callback);
        
        // Send multiple events
        $writer->sendEvent('progress', ['message' => 'Starting']);
        $writer->sendEvent('progress', ['message' => 'Processing']);
        $writer->sendEvent('progress', ['message' => 'Complete']);
        
        // Verify events were captured
        self::assertSame(3, $writer->countEvents('progress'));
        
        $events = $writer->eventsByName('progress');
        self::assertCount(3, $events);
        
        // Verify event data
        self::assertSame('Starting', $events[0]['data']['message']);
        self::assertSame('Processing', $events[1]['data']['message']);
        self::assertSame('Complete', $events[2]['data']['message']);
    }
    
    /**
     * Test that DuplicateObserverHeartbeatWriter heartbeat callback is triggered
     * 
     * Property 1: Bug Condition - Heartbeat Callback Works
     * 
     * EXPECTED OUTCOME on UNFIXED code: Test FAILS (class cannot be instantiated)
     * EXPECTED OUTCOME on FIXED code: Test PASSES
     */
    public function testDuplicateObserverHeartbeatWriterHeartbeatCallbackTriggered(): void
    {
        $callbackTriggered = false;
        
        $callback = static function () use (&$callbackTriggered): void {
            $callbackTriggered = true;
        };
        
        $writer = new DuplicateObserverHeartbeatWriter($callback);
        
        // Callback should not be triggered yet
        self::assertFalse($callbackTriggered);
        
        // Call maybeHeartbeat - should trigger callback on first call
        $writer->maybeHeartbeat();
        self::assertTrue($callbackTriggered);
        
        // Reset flag
        $callbackTriggered = false;
        
        // Call maybeHeartbeat again - should NOT trigger callback (already triggered)
        $writer->maybeHeartbeat();
        self::assertFalse($callbackTriggered);
    }
}
