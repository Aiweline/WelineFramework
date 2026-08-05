<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service\Provider;

use PHPUnit\Framework\TestCase;

final class ProviderCooperativeStreamPumpContractTest extends TestCase
{
    public function testOpenAiStreamMethodsUseCooperativePumpExecutor(): void
    {
        $source = $this->readProviderSource('OpenAiProvider.php');

        self::assertStringContainsString('FiberTaskRunner::currentPump()', $source);
        self::assertStringContainsString('$pump->awaitChunk($handleId)', $source);

        $streamFull = $this->methodSlice($source, 'public function generateStreamFull', 'public function generateStream');
        self::assertStringContainsString('$this->executeStreamCurl($ch, $consumeStreamChunk, $timeout)', $streamFull);
        self::assertStringNotContainsString('curl_exec($ch);', $streamFull);
        self::assertStringNotContainsString('CURLOPT_WRITEFUNCTION', $streamFull);

        $streamApi = $this->methodSlice($source, 'private function callStreamApi', 'private function parseApiErrorResponse');
        self::assertStringContainsString('$this->executeStreamCurl($ch, $consumeStreamChunk, $timeout)', $streamApi);
        self::assertStringNotContainsString('curl_exec($ch);', $streamApi);
        self::assertStringNotContainsString('CURLOPT_WRITEFUNCTION', $streamApi);

        $jsonApi = $this->methodSlice($source, 'private function callApiWithRetry', 'private function executeJsonCurl');
        self::assertStringContainsString('$this->executeJsonCurl($ch, $timeout)', $jsonApi);
        self::assertStringNotContainsString('curl_exec($ch)', $jsonApi);
        self::assertStringNotContainsString('sleep(self::RETRY_DELAY', $jsonApi);
    }

    public function testOpenAiJsonRequestCarriesExplicitLowSpeedWindowAcrossRetries(): void
    {
        $source = $this->readProviderSource('OpenAiProvider.php');
        self::assertStringContainsString(
            'ProviderTimeoutPolicy::resolveRequestLowSpeedTime($params, $timeout)',
            $source
        );

        $jsonApi = $this->methodSlice($source, 'private function callApiWithRetry', 'private function executeJsonCurl');
        self::assertStringContainsString(
            '$this->initCurl($url, $apiKey, $data, $proxyInfo, $timeout, $lowSpeedTime)',
            $jsonApi
        );
        self::assertGreaterThanOrEqual(3, \substr_count($jsonApi, '$lowSpeedTime'));
    }

    public function testOpenAiStreamCarriesExplicitLowSpeedWindowToCurl(): void
    {
        $source = $this->readProviderSource('OpenAiProvider.php');
        $stream = $this->methodSlice($source, 'public function generateStream', 'private function resolveStructuredReasoningJsonFallback');

        self::assertStringContainsString(
            'ProviderTimeoutPolicy::resolveStreamLowSpeedTime($params, $timeout)',
            $stream
        );
        self::assertStringContainsString('$streamLowSpeedTime', $stream);
        self::assertStringContainsString('$streamLowSpeedTime,', $stream);
    }

    public function testOpenAiPumpGetsHardDeadlineAndOptionalIdleHeartbeat(): void
    {
        $source = $this->readProviderSource('OpenAiProvider.php');

        self::assertStringContainsString(
            '$pump->register($ch, $timeout > 0 ? (float)$timeout : null)',
            $source
        );
        self::assertStringContainsString(
            "(bool)(\$params['emit_empty_heartbeat'] ?? false)",
            $source
        );
        self::assertStringContainsString('configureEmptyStreamHeartbeat($ch, $callback)', $source);
        self::assertStringContainsString("callback('')", $source);
    }

    public function testAnthropicStreamApiUsesCooperativePumpExecutor(): void
    {
        $source = $this->readProviderSource('AnthropicProvider.php');

        self::assertStringContainsString('FiberTaskRunner::currentPump()', $source);
        self::assertStringContainsString('$pump->awaitChunk($handleId)', $source);

        $streamApi = $this->methodSlice($source, 'private function callStreamApi', 'private function executeStreamCurl');
        self::assertStringContainsString('$this->executeStreamCurl($ch, $consumeStreamChunk)', $streamApi);
        self::assertStringNotContainsString('curl_exec($ch);', $streamApi);
        self::assertStringNotContainsString('CURLOPT_WRITEFUNCTION', $streamApi);

        $jsonApi = $this->methodSlice($source, 'private function callApiWithRetry', 'private function executeJsonCurl');
        self::assertStringContainsString('$this->executeJsonCurl($ch)', $jsonApi);
        self::assertStringNotContainsString('curl_exec($ch)', $jsonApi);
        self::assertStringNotContainsString('sleep(self::RETRY_DELAY', $jsonApi);
    }

    public function testGeminiJsonRequestsUseCooperativePumpExecutor(): void
    {
        $source = $this->readProviderSource('GeminiProvider.php');

        self::assertStringContainsString('FiberTaskRunner::currentPump()', $source);
        self::assertStringContainsString('$pump->awaitChunk($handleId)', $source);

        $requestJson = $this->methodSlice($source, 'private function requestJson', 'private function executeJsonCurl');
        self::assertStringContainsString('$this->executeJsonCurl($ch)', $requestJson);
        self::assertStringNotContainsString('curl_exec($ch)', $requestJson);
        self::assertStringNotContainsString('sleep(self::RETRY_DELAY', $requestJson);
    }

    private function readProviderSource(string $fileName): string
    {
        $path = __DIR__ . '/../../../../Service/Provider/' . $fileName;
        $source = \file_get_contents($path);
        self::assertIsString($source);

        return $source;
    }

    private function methodSlice(string $source, string $startNeedle, string $endNeedle): string
    {
        $start = \strpos($source, $startNeedle);
        self::assertIsInt($start, 'Missing method start marker: ' . $startNeedle);
        $end = \strpos($source, $endNeedle, $start + \strlen($startNeedle));
        self::assertIsInt($end, 'Missing method end marker: ' . $endNeedle);

        return \substr($source, $start, $end - $start);
    }
}
