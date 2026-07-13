<?php

declare(strict_types=1);

namespace Weline\Framework\View\Cache;

final class TemplateCachePolicyRegistry
{
    private const DEFAULT_REGISTRY_FILE = BP . 'generated' . DS . 'framework' . DS . 'template_cache_policies.php';

    /** @var array<string, true> */
    private array $requestHooks = [];
    /** @var array<string, true> */
    private array $diagnosticHooks = [];
    /** @var array<string, array<string, mixed>> */
    private array $aggregateHooks = [];
    /** @var array<string, array<string, mixed>> */
    private array $outputFiles = [];
    /** @var array<string, array{complete:bool,digest:string,outputs:list<string>}> */
    private array $aggregateManifests = [];
    private string $digest = 'missing';
    private string $hookManifestDigest = 'missing';
    private bool $loaded = false;

    public function __construct(
        private readonly ?string $registryFile = null,
    ) {
    }

    public function isRequestCacheable(string $hook): bool
    {
        $this->load();
        return isset($this->requestHooks[$hook]);
    }

    public function isDiagnostic(string $hook): bool
    {
        $this->load();
        return isset($this->diagnosticHooks[$hook]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function aggregate(string $hook): ?array
    {
        $this->load();
        $policy = $this->aggregateHooks[$hook] ?? null;
        if ($policy === null) {
            return null;
        }
        $manifest = $this->aggregateManifests[$hook] ?? null;
        if (
            ($policy['require_all_outputs_cacheable'] ?? true)
            && (!\is_array($manifest) || !($manifest['complete'] ?? false))
        ) {
            return null;
        }

        return $policy;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function output(string $file): ?array
    {
        $this->load();
        return $this->outputFiles[$file] ?? null;
    }

    public function digest(): string
    {
        $this->load();
        return $this->digest;
    }

    public function aggregateDigest(string $hook): string
    {
        $this->load();
        $manifestDigest = (string)($this->aggregateManifests[$hook]['digest'] ?? 'missing');
        return \hash('sha256', $this->digest . '|' . $this->hookManifestDigest . '|' . $manifestDigest);
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $file = $this->registryFile ?? self::DEFAULT_REGISTRY_FILE;
        if (!\is_file($file)) {
            $this->loaded = true;
            return;
        }
        try {
            $registry = require $file;
        } catch (\Throwable) {
            $this->loaded = true;
            return;
        }
        if (!\is_array($registry) || ($registry['format'] ?? null) !== TemplateCachePolicyCompiler::FORMAT_VERSION) {
            $this->loaded = true;
            return;
        }

        $requestHooks = [];
        foreach ((array)($registry['request_hooks'] ?? []) as $hook) {
            if (\is_string($hook) && $hook !== '') {
                $requestHooks[$hook] = true;
            }
        }
        $diagnosticHooks = [];
        foreach ((array)($registry['diagnostic_hooks'] ?? []) as $hook) {
            if (\is_string($hook) && $hook !== '') {
                $diagnosticHooks[$hook] = true;
            }
        }
        $aggregateHooks = \is_array($registry['aggregate_hooks'] ?? null)
            ? $registry['aggregate_hooks']
            : [];
        $outputFiles = \is_array($registry['output_files'] ?? null)
            ? $registry['output_files']
            : [];
        $aggregateManifests = \is_array($registry['aggregate_manifests'] ?? null)
            ? $registry['aggregate_manifests']
            : [];

        $this->requestHooks = $requestHooks;
        $this->diagnosticHooks = $diagnosticHooks;
        $this->aggregateHooks = $aggregateHooks;
        $this->outputFiles = $outputFiles;
        $this->aggregateManifests = $aggregateManifests;
        $this->digest = (string)($registry['digest'] ?? 'missing');
        $this->hookManifestDigest = (string)($registry['hook_manifest_digest'] ?? 'missing');
        $this->loaded = true;
    }
}
