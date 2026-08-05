<?php
declare(strict_types=1);

namespace Weline\Server\Service\Edge;

use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\ServerInstanceManager;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;
use Weline\Server\Service\Edge\Gateway\ProjectServingManifestStore;

/**
 * Immutable launch binding shared by Master, Orchestrator and TLS providers.
 *
 * The mutable endpoint and current pointer are never read by a Worker. The
 * Master resolves one exact content-addressed generation, validates its full
 * launch fence, then serializes only these four scalars into child argv.
 */
final class ServingManifestRuntimeFence
{
    /**
     * Publish and attach the exact native-WLS serving generation for one
     * already-authoritative Master context. This operation must precede every
     * TLS child start, including an in-process full-restart epoch advance.
     */
    public static function publishForContext(ServiceContext $context): ServiceContext
    {
        if (!$context->sslEnabled
            || \strtolower(\trim((string)$context->getConfig(
                'wls.edge.adapter',
                '',
            ))) !== EdgeAdapterInterface::NAME_WLS
        ) {
            return $context;
        }
        $instanceGeneration = (int)$context->getConfig(
            'wls.gateway.instance_generation',
            0,
        );
        if ($instanceGeneration < 1) {
            throw new \RuntimeException(
                'Native WLS TLS requires a monotonic project instance generation.',
            );
        }
        $publication = (new GatewayRegistrationBuilder())->buildServingManifest(
            $context->instanceName,
        );
        $fence = self::fromPublication(
            $publication,
            $context->instanceName,
            $instanceGeneration,
            $context->masterPid,
            $context->epoch,
        );
        $file = (new ServerInstanceManager())->getInstanceFile($context->instanceName);
        if (!ServerInstanceManager::updateJsonFileAtomically(
            $file,
            static function (array $endpoint) use ($context, $fence, $publication): array {
                $gateway = \is_array($endpoint['gateway'] ?? null)
                    ? $endpoint['gateway']
                    : [];
                if ((int)($endpoint['master_pid'] ?? 0) !== $context->masterPid
                    || (int)($endpoint['master_epoch'] ?? 0) !== $context->epoch
                    || (int)($gateway['instance_generation'] ?? 0)
                        !== $fence['instance_generation']
                ) {
                    throw new \RuntimeException(
                        'Native WLS serving manifest endpoint generation changed during publication.',
                    );
                }
                $gateway['serving_manifest_path'] = $fence['path'];
                $gateway['serving_manifest_generation'] = $fence['generation'];
                $gateway['serving_manifest_digest'] = $fence['digest'];
                $gateway['serving_manifest_converged'] = (bool)(
                    $publication['converged'] ?? false
                );
                unset($gateway[NativeServingManifestStartupRecovery::CONFIG_KEY]);
                unset($gateway['tls_serving_quarantine']);
                $endpoint['gateway'] = $gateway;
                return $endpoint;
            },
        )) {
            throw new \RuntimeException(
                'Native WLS serving manifest endpoint fence could not be published.',
            );
        }

        return self::withContext($context, $fence);
    }

    /** @return array{path:string,generation:int,digest:string,instance_generation:int} */
    public static function fromPublication(
        array $publication,
        string $instanceId,
        int $instanceGeneration,
        int $masterPid,
        int $masterEpoch,
    ): array {
        $path = (string)($publication['path'] ?? '');
        $generation = (int)($publication['generation'] ?? 0);
        $digest = \strtolower(\trim((string)($publication['digest'] ?? '')));
        if ($instanceGeneration < 1 || $masterPid < 1 || $masterEpoch < 1) {
            throw new \RuntimeException('WLS serving manifest launch fence is incomplete.');
        }
        $bound = (new ProjectServingManifestStore())->readBound(
            $path,
            $generation,
            $digest,
            [
                'instance_id' => $instanceId,
                'instance_generation' => $instanceGeneration,
                'master_pid' => $masterPid,
                'master_epoch' => $masterEpoch,
            ],
        );

        return [
            'path' => (string)$bound['path'],
            'generation' => (int)$bound['generation'],
            'digest' => (string)$bound['digest'],
            'instance_generation' => $instanceGeneration,
        ];
    }

    /** @return array{path:string,generation:int,digest:string,instance_generation:int} */
    public static function fromContext(ServiceContext $context): array
    {
        return self::validate([
            'path' => $context->getConfig('wls.serving_manifest_path', ''),
            'generation' => $context->getConfig('wls.serving_manifest_generation', 0),
            'digest' => $context->getConfig('wls.serving_manifest_digest', ''),
            'instance_generation' => $context->getConfig(
                'wls.serving_instance_generation',
                0,
            ),
        ]);
    }

    /**
     * @param array{path:string,generation:int,digest:string,instance_generation:int} $fence
     */
    public static function withContext(ServiceContext $context, array $fence): ServiceContext
    {
        $fence = self::validate($fence);
        $env = $context->envConfig;
        $env['wls'] = \is_array($env['wls'] ?? null) ? $env['wls'] : [];
        $env['wls']['serving_manifest_path'] = $fence['path'];
        $env['wls']['serving_manifest_generation'] = $fence['generation'];
        $env['wls']['serving_manifest_digest'] = $fence['digest'];
        $env['wls']['serving_instance_generation'] = $fence['instance_generation'];
        if (\is_array($env['wls']['gateway'] ?? null)) {
            unset($env['wls']['gateway'][NativeServingManifestStartupRecovery::CONFIG_KEY]);
        }

        return new ServiceContext(
            instanceName: $context->instanceName,
            epoch: $context->epoch,
            controlPort: $context->controlPort,
            masterPid: $context->masterPid,
            host: $context->host,
            mainPort: $context->mainPort,
            sslEnabled: $context->sslEnabled,
            sslCert: $context->sslCert,
            sslKey: $context->sslKey,
            runtimeSelection: $context->runtimeSelection,
            daemon: $context->daemon,
            debug: $context->debug,
            controlToken: $context->controlToken,
            windowMode: $context->windowMode,
            envConfig: $env,
            httpRedirectPort: $context->httpRedirectPort,
            workerCount: $context->workerCount,
            workerBasePort: $context->workerBasePort,
            workerPort: $context->workerPort,
            publicHost: $context->publicHost,
            masterLeaseFile: $context->masterLeaseFile,
            masterToken: $context->masterToken,
        );
    }

    /** @return list<string> */
    public static function workerArguments(ServiceContext $context): array
    {
        $fence = self::fromContext($context);
        $gatewayGeneration = (int)$context->getConfig(
            'wls.gateway.instance_generation',
            0,
        );
        if ($gatewayGeneration > 0
            && $gatewayGeneration !== $fence['instance_generation']
        ) {
            throw new \RuntimeException(
                'Gateway and serving manifest instance generations do not match.',
            );
        }

        return [
            '--serving-manifest=' . $fence['path'],
            '--serving-manifest-generation=' . $fence['generation'],
            '--serving-manifest-digest=' . $fence['digest'],
            '--serving-instance-generation=' . $fence['instance_generation'],
        ];
    }

    /** @return array{path:string,generation:int,digest:string,instance_generation:int} */
    private static function validate(array $fence): array
    {
        $path = \trim((string)($fence['path'] ?? ''));
        $generation = (int)($fence['generation'] ?? 0);
        $digest = \strtolower(\trim((string)($fence['digest'] ?? '')));
        $instanceGeneration = (int)($fence['instance_generation'] ?? 0);
        $absolute = PHP_OS_FAMILY === 'Windows'
            ? \preg_match('/\A(?:[A-Za-z]:[\\\\\/]|\\\\\\\\[^\\\\\/]+[\\\\\/][^\\\\\/]+)/D', $path) === 1
            : \str_starts_with($path, '/');
        if (!$absolute
            || \strlen($path) > 4096
            || \str_contains($path, "\0")
            || $generation < 1
            || $instanceGeneration < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
        ) {
            throw new \RuntimeException('WLS serving manifest runtime fence is invalid.');
        }

        return [
            'path' => $path,
            'generation' => $generation,
            'digest' => $digest,
            'instance_generation' => $instanceGeneration,
        ];
    }
}
