<?php

declare(strict_types=1);

namespace Weline\Server\Service\Policy;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\Policy\RuntimePolicyBundle;

final class RuntimePolicyControlService
{
    public function __construct(
        private readonly RuntimePolicyCompiler $compiler = new RuntimePolicyCompiler(),
        private readonly RuntimePolicyStore $store = new RuntimePolicyStore(),
        private readonly RuntimePolicyValidator $validator = new RuntimePolicyValidator(),
    ) {
    }

    /**
     * @return array{valid:bool,errors:list<string>,source:string,bundle:array<string,mixed>}
     */
    public function check(
        string $topology = 'both',
        ?string $instance = null,
        array $compileContext = [],
    ): array
    {
        // A staged bundle is allowed to select startup policy, but it must not
        // mask a missing/corrupt compile-time provider registry.
        $this->compiler->assertProviderRegistryReady();

        $compileContext = $this->resolveCompileContext($instance, $compileContext);
        $compiledBundle = $this->compiler->compile(
            $topology,
            $instance !== null && $instance !== '' ? ['instance' => $instance] : [],
            [],
            $compileContext,
        );
        $bundle = $compiledBundle;
        $source = 'compiled';
        if ($instance !== null && $instance !== '') {
            // A deliberately staged bundle is the next startup candidate.  The
            // active bundle describes the running generation and must not hide
            // configuration/provider changes from a new startup preflight.
            $stagedBundle = $this->store->staged($instance);
            if ($stagedBundle !== null && \hash_equals($compiledBundle->digest, $stagedBundle->digest)) {
                $bundle = $stagedBundle;
                $source = 'staged';
            }
        }
        $errors = $this->validator->validate($bundle, $topology === 'both' ? null : $topology);
        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'source' => $source,
            'bundle' => $bundle->toArray(),
        ];
    }

    /**
     * Publish the policy selected by startup preflight before any new Worker is
     * created.  This is the zero-target form of the normal two-phase publish:
     * once Workers exist, live updates must go through the Orchestrator ACK
     * barrier instead of this control-plane shortcut.
     */
    public function activateForStart(
        string $instance,
        string $topology = 'both',
        array $compileContext = [],
    ): RuntimePolicyBundle
    {
        $result = $this->check($topology, $instance, $compileContext);
        if (!$result['valid']) {
            throw new \RuntimeException(
                'Runtime policy startup preflight failed: ' . \implode('; ', $result['errors'])
            );
        }

        $bundle = RuntimePolicyBundle::fromArray($result['bundle']);
        $this->store->stage($instance, $bundle);
        $this->store->activate($instance, $bundle->digest);
        return $bundle;
    }

    public function compile(
        string $instance,
        string $topology = 'both',
        array $compileContext = [],
    ): RuntimePolicyBundle
    {
        $compileContext = $this->resolveCompileContext($instance, $compileContext);
        $bundle = $this->compiler->compile($topology, ['instance' => $instance], [], $compileContext);
        $this->validator->assertValid($bundle, $topology === 'both' ? null : $topology);
        $this->store->save($instance, $bundle);
        return $bundle;
    }

    public function stage(
        string $instance,
        ?string $digest = null,
        string $topology = 'both',
        array $compileContext = [],
    ): RuntimePolicyBundle
    {
        $compileContext = $this->resolveCompileContext($instance, $compileContext);
        if ($digest !== null && $digest !== '') {
            $bundle = $this->store->load($instance, $digest);
            $this->assertHostContextCompatible($bundle, $instance, $topology, $compileContext);
        } else {
            $bundle = $this->compile($instance, $topology, $compileContext);
        }
        $this->validator->assertValid($bundle, $topology === 'both' ? null : $topology);
        $this->store->stage($instance, $bundle);
        return $bundle;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $instance): array
    {
        return $this->store->status($instance);
    }

    public function prepareRollback(string $instance, ?string $digest = null): RuntimePolicyBundle
    {
        $state = $this->store->state($instance);
        $target = $digest !== null && $digest !== ''
            ? $digest
            : (string)($state['previous_digest'] ?? '');
        if ($target === '') {
            throw new \RuntimeException('No previous runtime policy bundle is available for rollback.');
        }
        $bundle = $this->store->load($instance, $target);
        $this->assertHostContextCompatible(
            $bundle,
            $instance,
            $bundle->topology,
            $this->resolveCompileContext($instance, []),
        );
        $this->store->stageDigest($instance, $target);
        return $bundle;
    }

    public function activate(string $instance, string $digest): array
    {
        $bundle = $this->store->load($instance, $digest);
        $this->assertHostContextCompatible(
            $bundle,
            $instance,
            $bundle->topology,
            $this->resolveCompileContext($instance, []),
        );
        return $this->store->activate($instance, $digest);
    }

    public function rollback(string $instance, ?string $digest = null): array
    {
        $state = $this->store->state($instance);
        $target = $digest !== null && $digest !== ''
            ? $digest
            : (string)($state['previous_digest'] ?? '');
        if ($target === '') {
            throw new \RuntimeException('No previous runtime policy bundle is available for rollback.');
        }
        $bundle = $this->store->load($instance, $target);
        $this->assertHostContextCompatible(
            $bundle,
            $instance,
            $bundle->topology,
            $this->resolveCompileContext($instance, []),
        );
        return $this->store->rollback($instance, $target);
    }

    /**
     * The caller-owned final config is authoritative for Start. Policy CLI
     * calls do not have that in memory, so they recover only the three public
     * host fields from saved/endpoint control-plane records. Workers never use
     * this resolver and never read these files on the request path.
     *
     * @param array<string, mixed> $compileContext
     * @return array<string, mixed>
     */
    private function resolveCompileContext(?string $instance, array $compileContext): array
    {
        if ($compileContext !== []) {
            return $this->hostFields($compileContext);
        }
        $instance = \trim((string)$instance);
        if ($instance === ''
            || \strlen($instance) > 128
            || \preg_match('/^[A-Za-z0-9._-]+$/D', $instance) !== 1
        ) {
            return [];
        }

        $endpoint = $this->readControlPlaneConfig(
            Env::VAR_DIR . 'server' . DS . 'instances' . DS . $instance . '.json',
        );
        $saved = $this->readControlPlaneConfig(
            Env::VAR_DIR . 'server' . DS . 'config' . DS . $instance . '.json',
        );
        return \array_replace($this->hostFields($endpoint), $this->hostFields($saved));
    }

    /**
     * @return array<string, mixed>
     */
    private function readControlPlaneConfig(string $path): array
    {
        if (!\is_file($path) || \is_link($path)) {
            return [];
        }
        $raw = @\file_get_contents($path);
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        $data = \json_decode($raw, true);
        return \is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function hostFields(array $config): array
    {
        $fields = [];
        foreach (['host', 'public_host', 'ssl_domain'] as $key) {
            $value = $config[$key] ?? null;
            if (\is_scalar($value) && \trim((string)$value) !== '') {
                $fields[$key] = (string)$value;
            }
        }
        return $fields;
    }

    /**
     * An old bundle may still be useful for rollback, but it may not re-open
     * Host policy after an instance domain changed. Recompilation is required
     * whenever the immutable Host context no longer matches.
     *
     * @param array<string, mixed> $compileContext
     */
    private function assertHostContextCompatible(
        RuntimePolicyBundle $bundle,
        string $instance,
        string $topology,
        array $compileContext,
    ): void {
        $expected = $this->compiler->compile(
            $topology,
            ['instance' => $instance],
            [],
            $compileContext,
        );
        $expectedDigest = (string)($expected->metadata['host_policy_context_digest'] ?? '');
        $actualDigest = (string)($bundle->metadata['host_policy_context_digest'] ?? '');
        if ($expectedDigest === ''
            || $actualDigest === ''
            || !\hash_equals($expectedDigest, $actualDigest)
            || empty($bundle->metadata['host_policy_strict'])
        ) {
            throw new \RuntimeException(
                'Runtime policy bundle Host context does not match the current instance config; recompile it first.',
            );
        }
    }
}
