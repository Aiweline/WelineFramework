<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Controller\Api\Stream;

final class StreamTest extends TestCase
{
    public function testPersistentProviderIdIsPreserved(): void
    {
        $normalized = $this->normalize([
            'id' => '123',
            'event' => 'progress',
            'data' => ['step' => 3],
        ]);

        self::assertSame('progress', $normalized['event']);
        self::assertSame(['step' => 3], $normalized['data']);
        self::assertSame(123, $normalized['id']);
        self::assertTrue($normalized['has_id']);
        self::assertFalse($normalized['control']);
    }

    public function testSequenceAliasIsPreservedForDurableProviderEvents(): void
    {
        $normalized = $this->normalize([
            'sequence' => 9,
            'event' => 'log',
            'data' => ['line' => 'ready'],
        ]);

        self::assertSame(9, $normalized['id']);
        self::assertTrue($normalized['has_id']);
    }

    public function testControlEventsAreFlaggedForIdlessTransportFrames(): void
    {
        $normalized = $this->normalize([
            'id' => 18,
            'event' => 'runtime_reset',
            'control' => true,
            'data' => ['reason' => 'compacted'],
        ]);

        self::assertTrue($normalized['control']);
        self::assertSame(18, $normalized['id']);
    }

    public function testInvalidPersistentIdFallsBackToLegacyAutoId(): void
    {
        $normalized = $this->normalize([
            'id' => 'not-a-sequence',
            'event' => 'progress',
            'data' => ['step' => 3],
        ]);

        self::assertNull($normalized['id']);
        self::assertFalse($normalized['has_id']);
    }

    public function testTransportHeartbeatMarkerIsRecognizedWithoutBecomingABusinessEvent(): void
    {
        $reflection = new \ReflectionClass(Stream::class);
        /** @var Stream $stream */
        $stream = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('isTransportHeartbeat');

        self::assertTrue($method->invoke($stream, ['transport' => 'heartbeat']));
        self::assertFalse($method->invoke($stream, ['event' => 'heartbeat']));
        self::assertFalse($method->invoke($stream, 'heartbeat'));
    }

    public function testRuntimeTaskChannelDoesNotUseGenericTransportTerminalMarkers(): void
    {
        $reflection = new \ReflectionClass(Stream::class);
        /** @var Stream $stream */
        $stream = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('isRuntimeTaskChannel');

        self::assertTrue($method->invoke($stream, 'runtime_task.events'));
        self::assertFalse($method->invoke($stream, 'legacy.events'));
    }

    public function testRuntimeStreamSourceEmitsRotateInsteadOfDoneOnSubscriptionEnd(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Framework/Controller/Api/Stream.php'
        );
        self::assertStringContainsString("sendControlEvent('runtime_rotate'", $source);
        self::assertMatchesRegularExpression(
            "/if \(\\\$isRuntimeTaskStream\) \{[^}]*runtime_rotate/s",
            $source,
        );
        self::assertDoesNotMatchRegularExpression(
            "/if \(\\\$isRuntimeTaskStream\) \{[^}]*sendControlEvent\('done'/s",
            $source,
        );
    }

    public function testBackendWorkerApiStopsReconnectingOnAttestationFailure(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Backend/view/statics/js/weline-api.js'
        );
        self::assertStringContainsString('isNonRetryableAuthorityError(error)', $source);
        self::assertStringContainsString('dispatchAuthorityFailure(error)', $source);
        self::assertStringContainsString("normalized === 'backend_attestation_invalid'", $source);
        self::assertStringContainsString('this.markTerminal();', $source);
        self::assertStringContainsString("scheduleReconnect({ immediate: true })", $source);
        self::assertStringContainsString("reason: immediate ? 'runtime_rotate' : 'transport'", $source);
        self::assertDoesNotMatchRegularExpression(
            "/eventType === 'runtime_rotate'[\s\S]{0,280}createEvent\('error'/",
            $source,
        );
    }

    public function testBackendWorkerAttestationTtlCoversLongRealtimeActions(): void
    {
        $session = (string)\file_get_contents(
            BP . 'app/code/Weline/Framework/Service/Query/FrontendWorkerSessionService.php'
        );
        $provider = (string)\file_get_contents(
            BP . 'app/code/Weline/Backend/Integration/Framework/FrontendWorkerBackendAttestationProvider.php'
        );
        $runtimeProvider = (string)\file_get_contents(
            BP . 'app/code/Weline/Framework/Extends/module/Weline_Framework/Query/ResumableTaskQueryProvider.php'
        );
        self::assertMatchesRegularExpression('/private const SESSION_TTL = 7200;/', $session);
        self::assertMatchesRegularExpression('/private const BINDING_TTL = 7200;/', $provider);
        self::assertStringContainsString('slideBackendSession', $session);
        self::assertStringContainsString('SLIDE_WHEN_REMAINING_SECONDS', $provider);
        self::assertMatchesRegularExpression('/private const DEFAULT_SUBSCRIPTION_SECONDS = 240;/', $runtimeProvider);
        self::assertStringContainsString('function replaceActive(', (string)\file_get_contents(
            BP . 'app/code/Weline/Framework/Service/Query/Store/FrontendWorkerCredentialTransactionInterface.php'
        ));
    }

    public function testTransportAuthFailuresUseTransportErrorEventInsteadOfBusinessFailed(): void
    {
        $reflection = new \ReflectionClass(Stream::class);
        /** @var Stream $stream */
        $stream = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('isTransportFailure');

        self::assertTrue($method->invoke($stream, 401, 'auth_error'));
        self::assertTrue($method->invoke($stream, 503, 'worker_store_unavailable'));
        self::assertTrue($method->invoke($stream, 401, 'AUTH_ERROR'));
        self::assertFalse($method->invoke($stream, 500, 'business_error'));
        self::assertFalse($method->invoke($stream, 422, 'validation_error'));
    }

    /** @return array{event:string, data:mixed, id:int|null, has_id:bool, control:bool} */
    private function normalize(array $event): array
    {
        $reflection = new \ReflectionClass(Stream::class);
        /** @var Stream $stream */
        $stream = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('normalizeStreamEvent');

        /** @var array{event:string, data:mixed, id:int|null, has_id:bool, control:bool} $normalized */
        $normalized = $method->invoke($stream, $event);

        return $normalized;
    }
}
