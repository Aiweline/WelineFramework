<?php

declare(strict_types=1);

namespace Weline\Framework\Architecture;

use Weline\Framework\Architecture\Module\ModuleGraphValidator;
use Weline\Framework\Module\Manifest\ModuleManifest;
use Weline\Framework\Module\Manifest\ModuleManifestReader;

final class ArchitectureAnalyzer
{
    private const CORE_MODULE = 'Weline_Framework';
    private const REFERENCE_PHP_CLASS = 'php_class';
    private const REFERENCE_DYNAMIC_CLASS = 'dynamic_class';
    private const REFERENCE_MODULE_RESOURCE = 'module_resource';
    private const BLOCKING_WAIT_ALLOWLIST = [
        '/Framework/Runtime/SchedulerSystem.php',
        '/Server/Service/MasterCleanupBootstrap.php',
    ];

    public function __construct(
        private readonly ModuleManifestReader $manifestReader = new ModuleManifestReader(),
        private readonly ModuleGraphValidator $graphValidator = new ModuleGraphValidator(),
        private readonly ComposerMetadataValidator $composerValidator = new ComposerMetadataValidator(),
    ) {
    }

    public function analyze(string $modulesRoot, bool $allowLegacy = false): Report
    {
        $findings = [];
        try {
            $manifests = $this->manifestReader->readAll($modulesRoot, $allowLegacy);
        } catch (\Throwable $exception) {
            return new Report([
                new Finding('manifest.invalid', $exception->getMessage()),
            ], ['modules' => 0, 'php_files' => 0, 'references' => 0]);
        }

        foreach ($manifests as $manifest) {
            if (!$manifest->authoritative) {
                $findings[] = new Finding(
                    'manifest.missing',
                    "{$manifest->name} must define etc/module.php.",
                    $this->relativePath($manifest->path . '/etc/module.php', $modulesRoot),
                );
            }
        }

        foreach ($this->graphValidator->validate($manifests) as $error) {
            $findings[] = new Finding('dependency.declared_graph', $error);
        }
        array_push($findings, ...$this->composerValidator->validate($manifests, $modulesRoot));

        $actualGraph = [];
        $phpFileCount = 0;
        $referenceCount = 0;
        foreach ($manifests as $manifest) {
            foreach ($this->phpFiles($manifest->path) as $file) {
                ++$phpFileCount;
                $source = @file_get_contents($file);
                if (!is_string($source) || $source === '') {
                    continue;
                }

                foreach ($this->extractReferences($source, $file) as [$reference, $line, $referenceKind]) {
                    $target = $this->moduleFromClass($reference);
                    if ($target === null || $target === $manifest->name) {
                        continue;
                    }
                    ++$referenceCount;
                    $actualGraph[$manifest->name][$target] = true;
                    $relative = $this->relativePath($file, $modulesRoot);

                    if ($manifest->name === self::CORE_MODULE && $target !== self::CORE_MODULE) {
                        $findings[] = new Finding(
                            'dependency.framework_reverse',
                            "Framework must not reference {$reference}.",
                            $relative,
                            $line,
                        );
                        continue;
                    }
                    if ($target !== self::CORE_MODULE && !$manifest->declares($target)) {
                        $findings[] = new Finding(
                            'dependency.undeclared',
                            "{$manifest->name} references {$target} without requires/optional declaration.",
                            $relative,
                            $line,
                        );
                    }
                    if (
                        $target !== self::CORE_MODULE
                        && $referenceKind !== self::REFERENCE_MODULE_RESOURCE
                        && !$this->isPublicApiReference($reference)
                    ) {
                        $findings[] = new Finding(
                            'dependency.internal_api',
                            "Cross-module reference must target {$target}\\Api\\*: {$reference}.",
                            $relative,
                            $line,
                        );
                    }
                }

                foreach ($this->blockingCalls($source, $file) as [$call, $line]) {
                    $findings[] = new Finding(
                        'runtime.blocking_wait',
                        "Request-reachable code calls native {$call}(); use the deadline-aware scheduler or Queue.",
                        $this->relativePath($file, $modulesRoot),
                        $line,
                    );
                }
            }
        }

        foreach ($this->actualCycles($actualGraph) as $cycle) {
            $findings[] = new Finding(
                'dependency.actual_cycle',
                'Actual module reference cycle: ' . implode(' -> ', $cycle) . '.',
            );
        }

        $findings = $this->deduplicate($findings);
        usort($findings, static fn(Finding $a, Finding $b): int => [
            $a->rule, $a->file, $a->line, $a->message,
        ] <=> [
            $b->rule, $b->file, $b->line, $b->message,
        ]);

        return new Report($findings, [
            'modules' => count($manifests),
            'php_files' => $phpFileCount,
            'references' => $referenceCount,
        ]);
    }

    /**
     * @return \Generator<int, string>
     */
    private function phpFiles(string $modulePath): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($modulePath, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (preg_match('#/(?:Test|tests?|doc|generated|vendor)/#i', $path) === 1) {
                continue;
            }
            yield $path;
        }
    }

    /**
     * @return list<array{string, int, string}>
     */
    private function extractReferences(string $source, string $file): array
    {
        $references = [];
        $normalizedFile = \str_replace('\\', '/', $file);
        $skipModuleResourceStrings = \str_ends_with($normalizedFile, '/etc/module.php')
            || \str_ends_with($normalizedFile, '/register.php');
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                continue;
            }
            [$type, $text, $line] = $token;
            if (in_array($type, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $class = ltrim($text, '\\');
                if (str_starts_with($class, 'Weline\\')) {
                    $references[] = [$class, $line, self::REFERENCE_PHP_CLASS];
                }
                continue;
            }
            if (!\in_array($type, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }

            $value = $this->stringTokenValue($type, $text);
            $trimmedValue = \trim($value);
            if (\preg_match('/^\\\\?(Weline\\\\[A-Za-z0-9_]+(?:\\\\[A-Za-z0-9_]+)+)$/', $trimmedValue, $matches) === 1) {
                $references[] = [$matches[1], $line, self::REFERENCE_DYNAMIC_CLASS];
            }
            if (
                !$skipModuleResourceStrings
                && \preg_match('/^Weline_([A-Za-z0-9]+)(?:::[^\\s]+)?$/', $trimmedValue, $moduleMatches) === 1
            ) {
                $references[] = [
                    'Weline\\' . $moduleMatches[1] . '\\__ModuleResource',
                    $line,
                    self::REFERENCE_MODULE_RESOURCE,
                ];
            }
        }

        return $references;
    }

    private function stringTokenValue(int $type, string $text): string
    {
        if ($type !== T_CONSTANT_ENCAPSED_STRING) {
            return \stripcslashes($text);
        }

        $quote = $text[0] ?? '';
        $value = \substr($text, 1, -1);
        if ($quote === "'") {
            return \str_replace(['\\\\', "\\'"], ['\\', "'"], $value);
        }
        return \stripcslashes($value);
    }

    private function moduleFromClass(string $class): ?string
    {
        $parts = explode('\\', ltrim($class, '\\'));
        if (($parts[0] ?? '') !== 'Weline' || !isset($parts[1])) {
            return null;
        }
        return 'Weline_' . $parts[1];
    }

    private function isPublicApiReference(string $class): bool
    {
        $parts = explode('\\', ltrim($class, '\\'));
        return ($parts[2] ?? '') === 'Api';
    }

    /**
     * @return list<array{string, int}>
     */
    private function blockingCalls(string $source, string $file): array
    {
        $calls = [];
        $tokens = token_get_all($source);
        $requestReachable = preg_match('/namespace\\s+[^;]+\\\\(?:Controller|Service|Api|Observer|Router|Runtime)\\b/', $source) === 1;
        $normalizedFile = str_replace('\\', '/', $file);
        $explicitlyNonRequest = array_any(
            self::BLOCKING_WAIT_ALLOWLIST,
            static fn(string $suffix): bool => str_ends_with($normalizedFile, $suffix),
        );
        if (!$requestReachable || $explicitlyNonRequest) {
            return [];
        }
        foreach ($tokens as $index => $token) {
            if (!\is_array($token)) {
                continue;
            }

            $call = null;
            if ($token[0] === T_STRING && \in_array(\strtolower($token[1]), ['sleep', 'usleep'], true)) {
                $call = \strtolower($token[1]);
            } elseif (
                \defined('T_NAME_FULLY_QUALIFIED')
                && $token[0] === T_NAME_FULLY_QUALIFIED
                && \in_array(\strtolower(\ltrim($token[1], '\\')), ['sleep', 'usleep'], true)
            ) {
                $call = \strtolower(\ltrim($token[1], '\\'));
            }
            if ($call === null) {
                continue;
            }

            $previous = $this->previousMeaningfulToken($tokens, $index);
            if ($previous === '::' || $previous === '->') {
                continue;
            }
            $calls[] = [$call, $token[2]];
        }
        return $calls;
    }

    /**
     * @param list<array|string> $tokens
     */
    private function previousMeaningfulToken(array $tokens, int $index): array|string|null
    {
        for ($position = $index - 1; $position >= 0; --$position) {
            $token = $tokens[$position];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return is_array($token) ? $token[1] : $token;
        }
        return null;
    }

    /**
     * @param array<string, array<string, true>> $graph
     * @return list<list<string>>
     */
    private function actualCycles(array $graph): array
    {
        $manifests = [];
        $modules = array_unique(array_merge(array_keys($graph), ...array_map('array_keys', array_values($graph))));
        foreach ($modules as $module) {
            $manifests[$module] = new ModuleManifest(
                $module,
                '0.0.0',
                array_fill_keys(array_keys($graph[$module] ?? []), '*'),
                [],
                [],
                '',
            );
        }
        return $this->graphValidator->findCycles($manifests);
    }

    /**
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    private function deduplicate(array $findings): array
    {
        $unique = [];
        foreach ($findings as $finding) {
            $key = implode('|', [$finding->rule, $finding->file, $finding->line, $finding->message]);
            $unique[$key] = $finding;
        }
        return array_values($unique);
    }

    private function relativePath(string $path, string $modulesRoot): string
    {
        $root = rtrim(str_replace('\\', '/', realpath($modulesRoot) ?: $modulesRoot), '/');
        $normalized = str_replace('\\', '/', $path);
        return str_starts_with($normalized, $root . '/') ? substr($normalized, strlen($root) + 1) : $normalized;
    }
}
