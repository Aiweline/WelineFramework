<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Provider\GatewayProvider;
use Weline\Server\Service\Runtime\EffectiveTopology;
use Weline\Server\Service\Runtime\RequestedTopology;
use Weline\Server\Service\Runtime\RuntimeSelection;

/**
 * task-6 缺陷 B contract test: provider start decision for a RESOLVED endpoint.
 *
 * Frozen contract (Lead 2026-10-30; buildCommand semantics superseded by the
 * architect P0 + B2 decision):
 *
 *   // app/code/Weline/Server/Service/Provider/GatewayProvider.php
 *   public static function shouldStartForResolvedEndpoint(array $endpoint): bool;
 *     -> FALSE only when the resolved endpoint says the project is still on the
 *        managed legacy edge:
 *          strtolower(trim($endpoint['gateway']['mode'] ?? '')) === 'legacy'
 *          || strtolower(trim($endpoint['gateway']['serving_mode'] ?? '')) === 'legacy'
 *        everything else (missing key / non-array / empty modes / auto /
 *        gateway / wls / fallback_wls) -> TRUE (fail-open).
 *
 *   isEnabled()  -> PRIMARY start gate: resolved legacy => false.
 *   buildCommand() -> MUST NOT THROW for a resolved legacy endpoint (B2). The
 *     child process batch (ServiceOrchestrator::startProvidersBatch) has no
 *     per-provider catch, so throwing would bubble into a startup failure and
 *     take the whole instance down. The implementation logs a ONE-SHOT throttled
 *     warning and still returns the Agent command (fail-open; the Agent-side
 *     resolved-edge gate suppresses every desired-state build).
 *     Explicit pure-WLS opt-out still throws its original LogicException.
 *
 * Why: a resolved `legacy` edge is a WLS 1.x managed-Nginx install that was
 * never promoted; its Agent can never finish registration (no schema-6 startup
 * lease) but keeps retrying. `auto` during startup must stay enabled (it
 * retries and switches back), a missing/unreadable endpoint stays fail-open,
 * and legacy -> gateway promote rewrites the mode, so the Agent becomes
 * startable again in the next master generation.
 */
final class GatewayProviderResolvedEndpointDecisionTest extends TestCase
{
    private const AGENT = GatewayProvider::class;

    /** @var list<string> fixture endpoint files created by this test */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $path) {
            if (\is_file($path) && !\is_link($path)) {
                @\unlink($path);
            }
        }
        $this->fixtures = [];
    }

    private function shouldStart(array $endpoint): bool
    {
        self::assertTrue(
            \method_exists(self::AGENT, 'shouldStartForResolvedEndpoint'),
            'GatewayProvider::shouldStartForResolvedEndpoint() must exist',
        );

        return self::AGENT::shouldStartForResolvedEndpoint($endpoint);
    }

    /** A resolved legacy edge mode must not start the Gateway Agent. */
    public function testLegacyGatewayModeDoesNotStart(): void
    {
        self::assertFalse($this->shouldStart([
            'gateway' => ['mode' => 'legacy', 'serving_mode' => ''],
        ]));
    }

    /** A legacy serving_mode is just as decisive as the legacy mode field. */
    public function testLegacyServingModeDoesNotStart(): void
    {
        self::assertFalse($this->shouldStart([
            'gateway' => ['mode' => 'auto', 'serving_mode' => 'legacy'],
        ]));
        self::assertFalse($this->shouldStart([
            'gateway' => ['serving_mode' => 'legacy'],
        ]));
    }

    /** Detection is case-insensitive and whitespace-trimmed. */
    public function testLegacyDetectionIsCaseInsensitiveAndTrimmed(): void
    {
        foreach (['LEGACY', ' legacy ', "\tLeGaCy\n"] as $legacy) {
            self::assertFalse(
                $this->shouldStart(['gateway' => ['mode' => $legacy]]),
                'mode=' . \json_encode($legacy) . ' must be recognised as legacy',
            );
            self::assertFalse(
                $this->shouldStart(['gateway' => ['serving_mode' => $legacy]]),
                'serving_mode=' . \json_encode($legacy) . ' must be recognised as legacy',
            );
        }
    }

    /**
     * Fail-open: missing/non-array/empty endpoint state keeps the historical
     * start behaviour (never silently disables a legitimate Agent).
     */
    public function testMissingOrMalformedEndpointFailsOpen(): void
    {
        $cases = [
            'empty endpoint' => [],
            'gateway missing' => ['instance' => 'default', 'other' => []],
            'gateway null' => ['gateway' => null],
            'gateway string' => ['gateway' => 'legacy'],
            'gateway scalar' => ['gateway' => 1],
            'empty gateway' => ['gateway' => []],
            'empty modes' => ['gateway' => ['mode' => '', 'serving_mode' => '']],
            'whitespace modes' => ['gateway' => ['mode' => '   ', 'serving_mode' => "\t"]],
        ];
        foreach ($cases as $label => $endpoint) {
            self::assertTrue(
                $this->shouldStart($endpoint),
                $label . ' must fail open (start the Agent)',
            );
        }
    }

    /** auto/gateway/wls/native/fallback_wls resolved modes keep the Agent enabled. */
    public function testNonLegacyModesStillStart(): void
    {
        foreach (['auto', 'gateway', 'wls', 'native', 'fallback_wls', 'edge'] as $mode) {
            self::assertTrue(
                $this->shouldStart(['gateway' => ['mode' => $mode]]),
                'mode=' . $mode . ' must not disable the Agent here',
            );
            self::assertTrue(
                $this->shouldStart(['gateway' => ['serving_mode' => $mode]]),
                'serving_mode=' . $mode . ' must not disable the Agent here',
            );
        }
    }

    /**
     * `auto` during startup must not be mistaken for legacy (mis-kill guard) —
     * it is the tenant that retries registration and can switch back.
     */
    public function testAutoStartupIsNotMisKilled(): void
    {
        $startupEndpoint = [
            'gateway' => [
                'requested_mode' => 'auto',
                'mode' => 'auto',
                'serving_mode' => 'wls',
                'join_backend' => null,
                'native_edge' => null,
            ],
            'instances' => ['default' => ['status' => 'STARTING']],
        ];
        self::assertTrue($this->shouldStart($startupEndpoint));
    }

    /**
     * legacy -> gateway promote rewrites the resolved mode, so the very same
     * decision function starts the Agent again in the next master generation.
     */
    public function testPromotionRestoresStartability(): void
    {
        $legacy = ['gateway' => ['mode' => 'legacy', 'serving_mode' => 'legacy']];
        self::assertFalse($this->shouldStart($legacy));

        $promoted = ['gateway' => ['mode' => 'gateway', 'serving_mode' => 'wls']];
        self::assertTrue($this->shouldStart($promoted), 'promoted endpoint must be startable');
    }

    /**
     * B2 behaviour: isEnabled() is the PRIMARY gate. With a persisted legacy
     * endpoint it must be false even when the raw config says gateway/auto;
     * with a fallback_wls endpoint it must stay true.
     */
    public function testIsEnabledRejectsLegacyEndpointAndKeepsFallbackWls(): void
    {
        $legacy = 'tester-provider-legacy-' . \bin2hex(\random_bytes(4));
        $fallback = 'tester-provider-fallback-' . \bin2hex(\random_bytes(4));
        $this->writeEndpointFixture($legacy, ['mode' => 'legacy', 'serving_mode' => 'legacy']);
        $this->writeEndpointFixture($fallback, ['mode' => 'auto', 'serving_mode' => 'fallback_wls']);

        $provider = new GatewayProvider();

        // legacy resolved endpoint => not enabled (both raw-config shapes).
        self::assertFalse(
            $provider->isEnabled($this->context($legacy, 'gateway', '')),
            'a resolved legacy endpoint must disable the provider',
        );
        self::assertFalse(
            $provider->isEnabled($this->context($legacy, 'auto', 'auto')),
            'requested auto must not override a resolved legacy endpoint',
        );

        // fallback_wls endpoint => enabled, so the auto tenant keeps retrying.
        self::assertTrue(
            $provider->isEnabled($this->context($fallback, 'gateway', '')),
            'a fallback_wls endpoint must keep the provider enabled',
        );
        self::assertTrue(
            $provider->isEnabled($this->context($fallback, 'auto', 'auto')),
            'requested auto + fallback_wls must stay enabled',
        );
    }

    /**
     * B2 behaviour: buildCommand() must NOT throw for a resolved legacy
     * endpoint (no per-provider catch in the child batch). It logs a one-shot
     * throttled warning and still returns the Agent command (fail-open).
     */
    public function testBuildCommandDoesNotThrowForResolvedLegacyEndpoint(): void
    {
        $legacy = 'tester-provider-launch-' . \bin2hex(\random_bytes(4));
        $this->writeEndpointFixture($legacy, ['mode' => 'legacy', 'serving_mode' => 'legacy']);
        $provider = new GatewayProvider();
        $context = $this->context($legacy, 'gateway', '');

        self::assertFalse($provider->isEnabled($context), 'isEnabled() remains the primary gate');

        $command = $provider->buildCommand(1, $context);
        self::assertContains('server:gateway:agent', $command->arguments);
        self::assertContains('--daemon', $command->arguments);
        self::assertContains('--instance-name=' . $legacy, $command->arguments);
        self::assertContains('--worker-id=1', $command->arguments);
        self::assertNotNull($command->processName);
        self::assertStringContainsString('weline-wls-gateway-agent', (string)$command->processName);
    }

    /** Explicit pure-WLS opt-out must still throw its original message. */
    public function testBuildCommandStillThrowsForExplicitPureWls(): void
    {
        $provider = new GatewayProvider();
        $name = 'tester-provider-wls-' . \bin2hex(\random_bytes(4));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Explicit pure WLS mode must not start the WLS Gateway Agent.',
        );
        $provider->buildCommand(1, $this->context($name, 'wls', 'wls'));
    }

    /**
     * Source-contract lock (repo style) for B2:
     *   1. explicit pure-WLS opt-out still throws, and runs BEFORE the
     *      resolved-endpoint decision;
     *   2. the resolved-legacy branch no longer throws (the old hard-reject
     *      message is gone) — it logs a one-shot warning and falls through to
     *      return the command (fail-open);
     *   3. the persisted endpoint read stays fail-open when it yields no array.
     */
    public function testBuildCommandResolvedLegacyIsFailOpenAndLogs(): void
    {
        $class = new \ReflectionClass(self::AGENT);
        $source = (string)\file_get_contents((string)$class->getFileName());
        self::assertNotSame('', $source);

        self::assertStringContainsString(
            'public static function shouldStartForResolvedEndpoint(array $endpoint): bool',
            $source,
            'the frozen static decision signature must be present',
        );
        self::assertStringContainsString('getRawInstanceData', $source);
        self::assertMatchesRegularExpression(
            '/is_array\(\$endpoint\)\s*[\r\n]+\s*\|\|\s*self::shouldStartForResolvedEndpoint/s',
            $source,
            'a null/missing endpoint must be treated as "start" (fail-open)',
        );

        $buildCommand = $class->getMethod('buildCommand');
        $lines = \file((string)$buildCommand->getFileName());
        self::assertIsArray($lines);
        $body = \implode('', \array_slice(
            $lines,
            $buildCommand->getStartLine() - 1,
            $buildCommand->getEndLine() - $buildCommand->getStartLine() + 1,
        ));

        // 1) explicit pure-WLS still throws, and before the resolved decision.
        $explicitWls = \strpos($body, 'Explicit pure WLS mode must not start the WLS Gateway Agent.');
        $resolvedDecision = \strpos($body, 'resolvedEndpointAllowsAgent');
        if ($resolvedDecision === false) {
            $resolvedDecision = \strpos($body, 'shouldStartForResolvedEndpoint');
        }
        self::assertIsInt($explicitWls, 'the explicit pure-WLS opt-out must stay in buildCommand()');
        self::assertIsInt($resolvedDecision, 'buildCommand() must consult the resolved-endpoint guard');
        self::assertLessThan(
            $resolvedDecision,
            $explicitWls,
            'the explicit pure-WLS check must run BEFORE the resolved-endpoint decision',
        );

        // 2) B2: resolved legacy logs instead of throwing — the old hard-reject
        // message must be gone; pure-WLS keeps its own throw above.
        self::assertStringNotContainsString(
            'Resolved legacy edge mode must not start the WLS Gateway Agent.',
            $source,
            'B2: resolved legacy must not throw; the old hard-reject message must be removed',
        );
        self::assertStringContainsString(
            'WlsLogger::warning_(',
            $body,
            'B2: the resolved-legacy path must log a throttled warning',
        );
        self::assertStringContainsString(
            'agent started defensively (fail-open)',
            $source,
            'B2: the warning must explain the defensive fail-open launch',
        );
        self::assertStringContainsString(
            'return new ServiceCommand(',
            $body,
            'B2: buildCommand() must still return a command (fail-open, never throws)',
        );
        self::assertStringContainsString(
            'resolvedLegacyStartWarningLogged',
            $body,
            'B2: the warning must be one-shot throttled per process',
        );
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<string,mixed> $gateway */
    private function writeEndpointFixture(string $instanceName, array $gateway): void
    {
        $directory = \rtrim(Env::varDir(), '/\\') . \DIRECTORY_SEPARATOR
            . 'server' . \DIRECTORY_SEPARATOR . 'instances' . \DIRECTORY_SEPARATOR;
        if (!\is_dir($directory)) {
            self::markTestSkipped(
                'local var/server/instances is required for the endpoint fixture',
            );
        }
        $path = $directory . $instanceName . '.json';
        $encoded = \json_encode([
            'schema' => 'unit-endpoint-fixture/1',
            'instance' => $instanceName,
            'gateway' => $gateway,
        ], \JSON_THROW_ON_ERROR);
        self::assertNotFalse(@\file_put_contents($path, $encoded, LOCK_EX));
        \clearstatcache(true, $path);
        $this->fixtures[] = $path;
    }

    private function context(
        string $instanceName,
        string $edgeMode = 'gateway',
        string $requestedMode = '',
    ): ServiceContext {
        return new ServiceContext(
            instanceName: $instanceName,
            epoch: 3,
            controlPort: 19091,
            masterPid: 12345,
            host: '127.0.0.1',
            mainPort: 19502,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: new RuntimeSelection(
                requestedTopology: RequestedTopology::Auto,
                effectiveTopology: EffectiveTopology::Direct,
                source: 'unit',
                osFamily: \PHP_OS_FAMILY,
                eventLoopDriver: 'stream_select',
                sslEngine: 'stream',
                listenerMode: 'reuseport',
                policyCompatible: true,
                reasonCodes: ['unit'],
                reason: 'Unit-test runtime selection.',
            ),
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [
                'wls' => [
                    'edge' => ['adapter' => 'wls', 'mode' => $edgeMode],
                    'gateway' => ['requested_mode' => $requestedMode],
                ],
            ],
            controlToken: 'unit-control-token',
        );
    }
}
