<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service\Provider;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllAiProviderContractsPass().
 */
final class AiProviderContractsTest extends TestCase
{
    public function testAllAiProviderContractsPass(): void
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
            'ProviderCooperativeStreamPump::testOpenAiStreamMethodsUseCooperativePumpExecutor' => function () {
            $this->assert_ProviderCooperativeStreamPump_testOpenAiStreamMethodsUseCooperativePumpExecutor();
        },
            'ProviderCooperativeStreamPump::testOpenAiJsonRequestCarriesExplicitLowSpeedWindowAcrossRetries' => function () {
            $this->assert_ProviderCooperativeStreamPump_testOpenAiJsonRequestCarriesExplicitLowSpeedWindowAcrossRetries();
        },
            'ProviderCooperativeStreamPump::testOpenAiStreamCarriesExplicitLowSpeedWindowToCurl' => function () {
            $this->assert_ProviderCooperativeStreamPump_testOpenAiStreamCarriesExplicitLowSpeedWindowToCurl();
        },
            'ProviderCooperativeStreamPump::testOpenAiPumpGetsHardDeadlineAndOptionalIdleHeartbeat' => function () {
            $this->assert_ProviderCooperativeStreamPump_testOpenAiPumpGetsHardDeadlineAndOptionalIdleHeartbeat();
        },
            'ProviderCooperativeStreamPump::testAnthropicStreamApiUsesCooperativePumpExecutor' => function () {
            $this->assert_ProviderCooperativeStreamPump_testAnthropicStreamApiUsesCooperativePumpExecutor();
        },
            'ProviderCooperativeStreamPump::testGeminiJsonRequestsUseCooperativePumpExecutor' => function () {
            $this->assert_ProviderCooperativeStreamPump_testGeminiJsonRequestsUseCooperativePumpExecutor();
        },
        ];
    }

private function assert_ProviderCooperativeStreamPump_testOpenAiStreamMethodsUseCooperativePumpExecutor(): void
{
    $source = $this->readProviderSource_ProviderCooperativeStreamPump('OpenAiProvider.php');
    self::assertStringContainsString('FiberTaskRunner::currentPump()', $source);
    self::assertStringContainsString('$pump->awaitChunk($handleId)', $source);
    $streamFull = $this->methodSlice_ProviderCooperativeStreamPump($source, 'public function generateStreamFull', 'public function generateStream');
    self::assertStringContainsString('$this->executeStreamCurl($ch, $consumeStreamChunk, $timeout)', $streamFull);
    self::assertStringNotContainsString('curl_exec($ch);', $streamFull);
    self::assertStringNotContainsString('CURLOPT_WRITEFUNCTION', $streamFull);
    $streamApi = $this->methodSlice_ProviderCooperativeStreamPump($source, 'private function callStreamApi', 'private function parseApiErrorResponse');
    self::assertStringContainsString('$this->executeStreamCurl($ch, $consumeStreamChunk, $timeout)', $streamApi);
    self::assertStringNotContainsString('curl_exec($ch);', $streamApi);
    self::assertStringNotContainsString('CURLOPT_WRITEFUNCTION', $streamApi);
    $jsonApi = $this->methodSlice_ProviderCooperativeStreamPump($source, 'private function callApiWithRetry', 'private function executeJsonCurl');
    self::assertStringContainsString('$this->executeJsonCurl($ch, $timeout)', $jsonApi);
    self::assertStringNotContainsString('curl_exec($ch)', $jsonApi);
    self::assertStringNotContainsString('sleep(self::RETRY_DELAY', $jsonApi);
}

private function assert_ProviderCooperativeStreamPump_testOpenAiJsonRequestCarriesExplicitLowSpeedWindowAcrossRetries(): void
{
    $source = $this->readProviderSource_ProviderCooperativeStreamPump('OpenAiProvider.php');
    self::assertStringContainsString('ProviderTimeoutPolicy::resolveRequestLowSpeedTime($params, $timeout)', $source);
    $jsonApi = $this->methodSlice_ProviderCooperativeStreamPump($source, 'private function callApiWithRetry', 'private function executeJsonCurl');
    self::assertStringContainsString('$this->initCurl($url, $apiKey, $data, $proxyInfo, $timeout, $lowSpeedTime, $connectTimeout)', $jsonApi);
    self::assertStringContainsString('$connectTimeout', $jsonApi);
    self::assertGreaterThanOrEqual(3, \substr_count($jsonApi, '$lowSpeedTime'));
}

private function assert_ProviderCooperativeStreamPump_testOpenAiStreamCarriesExplicitLowSpeedWindowToCurl(): void
{
    $source = $this->readProviderSource_ProviderCooperativeStreamPump('OpenAiProvider.php');
    $stream = $this->methodSlice_ProviderCooperativeStreamPump($source, 'public function generateStream', 'private function resolveStructuredReasoningJsonFallback');
    self::assertStringContainsString('ProviderTimeoutPolicy::resolveStreamLowSpeedTime($params, $timeout)', $stream);
    self::assertStringContainsString('$streamLowSpeedTime', $stream);
    self::assertStringContainsString('$streamLowSpeedTime,', $stream);
}

private function assert_ProviderCooperativeStreamPump_testOpenAiPumpGetsHardDeadlineAndOptionalIdleHeartbeat(): void
{
    $source = $this->readProviderSource_ProviderCooperativeStreamPump('OpenAiProvider.php');
    self::assertStringContainsString('$pump->register($ch, $timeout > 0 ? (float)$timeout : null)', $source);
    self::assertStringContainsString("(bool)(\$params['emit_empty_heartbeat'] ?? false)", $source);
    self::assertStringContainsString('configureEmptyStreamHeartbeat($ch, $callback)', $source);
    self::assertStringContainsString("callback('')", $source);
}

private function assert_ProviderCooperativeStreamPump_testAnthropicStreamApiUsesCooperativePumpExecutor(): void
{
    $source = $this->readProviderSource_ProviderCooperativeStreamPump('AnthropicProvider.php');
    self::assertStringContainsString('FiberTaskRunner::currentPump()', $source);
    self::assertStringContainsString('$pump->awaitChunk($handleId)', $source);
    $streamApi = $this->methodSlice_ProviderCooperativeStreamPump($source, 'private function callStreamApi', 'private function executeStreamCurl');
    self::assertStringContainsString('$this->executeStreamCurl($ch, $consumeStreamChunk)', $streamApi);
    self::assertStringNotContainsString('curl_exec($ch);', $streamApi);
    self::assertStringNotContainsString('CURLOPT_WRITEFUNCTION', $streamApi);
    $jsonApi = $this->methodSlice_ProviderCooperativeStreamPump($source, 'private function callApiWithRetry', 'private function executeJsonCurl');
    self::assertStringContainsString('$this->executeJsonCurl($ch)', $jsonApi);
    self::assertStringNotContainsString('curl_exec($ch)', $jsonApi);
    self::assertStringNotContainsString('sleep(self::RETRY_DELAY', $jsonApi);
}

private function assert_ProviderCooperativeStreamPump_testGeminiJsonRequestsUseCooperativePumpExecutor(): void
{
    $source = $this->readProviderSource_ProviderCooperativeStreamPump('GeminiProvider.php');
    self::assertStringContainsString('FiberTaskRunner::currentPump()', $source);
    self::assertStringContainsString('$pump->awaitChunk($handleId)', $source);
    $requestJson = $this->methodSlice_ProviderCooperativeStreamPump($source, 'private function requestJson', 'private function executeJsonCurl');
    self::assertStringContainsString('$this->executeJsonCurl($ch)', $requestJson);
    self::assertStringNotContainsString('curl_exec($ch)', $requestJson);
    self::assertStringNotContainsString('sleep(self::RETRY_DELAY', $requestJson);
}

private function readProviderSource_ProviderCooperativeStreamPump(string $fileName): string
{
    $path = __DIR__ . '/../../../../Service/Provider/' . $fileName;
    $source = \file_get_contents($path);
    self::assertIsString($source);
    return $source;
}

private function methodSlice_ProviderCooperativeStreamPump(string $source, string $startNeedle, string $endNeedle): string
{
    $start = \strpos($source, $startNeedle);
    self::assertIsInt($start, 'Missing method start marker: ' . $startNeedle);
    $end = \strpos($source, $endNeedle, $start + \strlen($startNeedle));
    self::assertIsInt($end, 'Missing method end marker: ' . $endNeedle);
    return \substr($source, $start, $end - $start);
}
}
