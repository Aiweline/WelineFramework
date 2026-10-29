<?php

declare(strict_types=1);

namespace Weline\Server\Service\Provider;

use Weline\Framework\Manager\ObjectManager;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Contract\AbstractServiceProvider;
use Weline\Server\Service\Contract\ServiceCommand;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Edge\Gateway\GatewayStartupDecision;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\ServerInstanceManager;

/**
 * Project-side WLS 2.0 registration/heartbeat/fallback agent.
 *
 * The host controller and Nginx data plane are deliberately not children of
 * this provider. Stopping the project therefore cannot stop the shared gateway.
 */
final class GatewayProvider extends AbstractServiceProvider
{
    public const ROLE = ControlMessage::ROLE_GATEWAY_AGENT;
    public const PROCESS_NAME_PREFIX = 'weline-wls-gateway-agent';

    /**
     * Bounded process-local memo for the resolved-endpoint start decision.
     * The cache entry is keyed by the endpoint file's stat signature, so an
     * atomic endpoint rewrite (promotion) invalidates it immediately.
     */
    private const RESOLVED_ENDPOINT_CACHE_MAXIMUM = 32;

    /** @var array<string,array{signature:string,start:bool}> */
    private static array $resolvedEndpointStartCache = [];

    /**
     * One-shot (per process) launch-path warning when a resolved legacy edge
     * reaches buildCommand(). Logged once: a legacy station never needs a
     * per-instance/restart log flood for a defensive, fail-open start.
     */
    private static bool $resolvedLegacyStartWarningLogged = false;

    public function getRole(): string
    {
        return self::ROLE;
    }

    public function getDisplayName(): string
    {
        return 'WLS 2.0 Gateway Agent';
    }

    public function isEnabled(ServiceContext $context): bool
    {
        $effective = \strtolower(\trim((string)$context->getConfig(
            'wls.edge.mode',
            '',
        )));
        $requested = \strtolower(\trim((string)$context->getConfig(
            'wls.gateway.requested_mode',
            '',
        )));
        // Explicit/persisted pure-WLS mode is a complete gateway opt-out. In
        // particular, do not keep a "retirement-only" Agent alive: certificate
        // retirement may consult the host controller and would violate the
        // edge=wls promise that startup never touches the shared gateway.
        // Auto fallback remains a gateway tenant while WLS is serving, so its
        // Agent must stay enabled to retry registration and switch back.
        if ($requested === GatewayStartupDecision::MODE_WLS
            || ($effective !== GatewayStartupDecision::MODE_GATEWAY
                && $requested !== GatewayStartupDecision::MODE_AUTO)
        ) {
            return false;
        }
        // The raw config says "auto", but the RESOLVED edge decision for this
        // boot may be legacy (WLS 1.x managed Nginx that was never promoted).
        // A legacy endpoint is not a wls-edge/2 join tenant: its Agent could
        // only retry a registration that can never complete. Start.php writes
        // the endpoint before the Master starts children, so this reads the
        // current boot's resolved mode; anything unreadable stays fail-open.
        return self::resolvedEndpointAllowsAgent(
            \trim($context->instanceName),
        );
    }

    public function getInstanceCount(ServiceContext $context): int
    {
        return 1;
    }

    public function getPriority(): int
    {
        return 40;
    }

    public function getResurrectionPriority(): int
    {
        return 4;
    }

    public function getReloadStrategy(): string
    {
        return 'graceful';
    }

    public function requiresStartupReadyBarrier(): bool
    {
        return false;
    }

    public function isCriticalRole(): bool
    {
        return false;
    }

    public function buildCommand(int $instanceId, ServiceContext $context): ServiceCommand
    {
        $instanceName = \trim($context->instanceName);
        if ($instanceName === '') {
            throw new \LogicException('WLS Gateway Agent requires an instance name.');
        }
        $effective = \strtolower(\trim((string)$context->getConfig(
            'wls.edge.mode',
            '',
        )));
        $requested = \strtolower(\trim((string)$context->getConfig(
            'wls.gateway.requested_mode',
            '',
        )));
        if ($requested === GatewayStartupDecision::MODE_WLS
            || ($effective === GatewayStartupDecision::MODE_WLS
                && $requested !== GatewayStartupDecision::MODE_AUTO)
        ) {
            throw new \LogicException(
                'Explicit pure WLS mode must not start the WLS Gateway Agent.'
            );
        }
        if (!self::resolvedEndpointAllowsAgent($instanceName)) {
            // A resolved legacy edge is not a join tenant, but this method must
            // still return a command: startProvidersBatch() has no per-provider
            // catch (ServiceOrchestrator.php:4170-4316), so throwing here would
            // bubble into handleStartupFailure()/requestStop('startup_failure')
            // and take the whole instance down. isEnabled() is the primary
            // gate; if a narrow resolved-endpoint race let the provider
            // through, the Agent-side resolved-edge gating still suppresses
            // every desired-state build, so a defensive agent costs no CPU.
            if (!self::$resolvedLegacyStartWarningLogged) {
                self::$resolvedLegacyStartWarningLogged = true;
                WlsLogger::warning_(
                    '[GatewayProvider] resolved legacy edge mode reached the WLS Gateway '
                    . 'Agent launch path (instance=' . $instanceName
                    . '); agent started defensively (fail-open), the Agent-side '
                    . 'resolved-edge gate suppresses desired-state builds'
                );
            }
        }
        $arguments = [
            'server:gateway:agent',
            '--daemon',
            '--instance-name=' . $instanceName,
            '--control-port=' . $context->controlPort,
            '--master-pid=' . $context->masterPid,
            '--worker-id=' . $instanceId,
        ];
        return new ServiceCommand(
            script: BP . 'bin' . DS . 'w',
            arguments: $arguments,
            processName: MasterProcess::buildScopedProcessName(
                self::PROCESS_NAME_PREFIX,
                $instanceName,
            ),
        );
    }

    /**
     * A resolved legacy edge is not a wls-edge/2 join tenant: the Agent there
     * can only retry a registration that the startup lease can never satisfy.
     * Missing / empty / non-legacy endpoint data stays fail-open so a torn or
     * not-yet-written endpoint never disables a legitimate gateway Agent.
     *
     * Callers: isEnabled() uses this as the primary start gate.
     * buildCommand() never throws for this decision — the child-service batch
     * has no per-provider catch, so it logs once (per process) and launches
     * defensively; the Agent-side resolved-edge gate then suppresses every
     * desired-state build.
     *
     * @param array<string,mixed> $endpoint
     */
    public static function shouldStartForResolvedEndpoint(array $endpoint): bool
    {
        $gateway = \is_array($endpoint['gateway'] ?? null)
            ? $endpoint['gateway']
            : [];
        foreach (['mode', 'serving_mode'] as $key) {
            $value = $gateway[$key] ?? null;
            if (!\is_scalar($value)) {
                continue;
            }
            if (\strtolower(\trim((string)$value))
                === GatewayStartupDecision::MODE_LEGACY
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve the start decision from the persisted project endpoint, with a
     * bounded process-local memo keyed by the endpoint file's stat signature.
     * Any read failure fails open (the Agent starts, as before this change).
     */
    private static function resolvedEndpointAllowsAgent(string $instanceName): bool
    {
        if ($instanceName === '') {
            return true;
        }
        try {
            $instances = ObjectManager::getInstance(ServerInstanceManager::class);
            $file = $instances->getInstanceFile($instanceName);
            \clearstatcache(true, $file);
            $stat = @\stat($file);
            $signature = \is_array($stat)
                ? ((int)($stat['ino'] ?? 0)) . ':'
                    . (int)($stat['mtime'] ?? 0) . ':'
                    . (int)($stat['size'] ?? 0)
                : 'missing';
            $cached = self::$resolvedEndpointStartCache[$instanceName] ?? null;
            if (\is_array($cached)
                && (string)($cached['signature'] ?? '') === $signature
            ) {
                return ($cached['start'] ?? false) === true;
            }
            $endpoint = $instances->getRawInstanceData($instanceName);
            $start = !\is_array($endpoint)
                || self::shouldStartForResolvedEndpoint($endpoint);
            if (\count(self::$resolvedEndpointStartCache)
                >= self::RESOLVED_ENDPOINT_CACHE_MAXIMUM
            ) {
                self::$resolvedEndpointStartCache = [];
            }
            self::$resolvedEndpointStartCache[$instanceName] = [
                'signature' => $signature,
                'start' => $start,
            ];

            return $start;
        } catch (\Throwable) {
            // Fail-open: a torn/unreadable endpoint must never turn legacy
            // startup or an explicit promotion into a hard failure.
            return true;
        }
    }
}
