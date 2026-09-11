<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Security;

use Weline\Framework\Extends\ExtendsData;

/**
 * Aggregates Extends CSP providers into an application-default floor.
 *
 * Path: extends/module/Weline_Framework/Security/Csp/*.php
 * Floor = SecurityHeaderDefaults ∪ Extends contributions (immutable at Scope).
 */
final class CspSourceContributionRegistry
{
    private ?string $extendsPolicy = null;

    private ?string $appDefaultPolicy = null;

    /**
     * @param list<CspSourceContribution>|null $forcedContributions test double only
     */
    public function __construct(
        private readonly ContentSecurityPolicyNormalizer $normalizer = new ContentSecurityPolicyNormalizer(),
        private readonly ?array $forcedContributions = null,
    ) {
    }

    /**
     * Canonical CSP fragment from Extends providers only (may be empty).
     */
    public function aggregatePolicy(): string
    {
        // Do not permanently cache an empty fragment: Extends providers (e.g. Captcha)
        // may resolve after the first early call during bootstrap. Caching "" dropped
        // recaptcha.net / vendor hosts from every subsequent response CSP and caused
        // Google Enterprise BROWSER_ERROR tokens on otherwise valid local hosts.
        if ($this->extendsPolicy !== null && $this->extendsPolicy !== '') {
            return $this->extendsPolicy;
        }

        $merged = [];
        foreach ($this->contributions() as $contribution) {
            foreach ($contribution->directives as $directive => $sources) {
                $name = \strtolower(\trim((string)$directive));
                if ($name === '' || !\is_array($sources)) {
                    continue;
                }
                foreach ($sources as $src) {
                    $src = \trim((string)$src);
                    if ($src === '') {
                        continue;
                    }
                    $merged[$name][$src] = true;
                }
            }
        }

        $directives = [];
        foreach ($merged as $name => $sourceMap) {
            $list = \array_keys($sourceMap);
            \sort($list, \SORT_STRING);
            $directives[$name] = $list;
        }

        $policy = $this->normalizer->stringify($directives);
        if ($policy !== '') {
            $this->extendsPolicy = $policy;
            $this->appDefaultPolicy = null;
        }

        return $policy;
    }

    /**
     * Application-default CSP floor from Extends only (immutable at Scope).
     * Framework SecurityHeaderDefaults remain in Env baseline but Scope may tighten them;
     * Extends contributions are always re-applied and cannot be removed.
     */
    public function appDefaultPolicy(): string
    {
        if ($this->appDefaultPolicy !== null && $this->appDefaultPolicy !== '') {
            return $this->appDefaultPolicy;
        }

        $policy = $this->aggregatePolicy();
        if ($policy !== '') {
            $this->appDefaultPolicy = $policy;
        }

        return $policy;
    }

    /**
     * @return list<array{module:string,policy:string}>
     */
    public function contributionSummaries(): array
    {
        $out = [];
        foreach ($this->loadExtendsProviders() as $row) {
            $policy = $this->normalizer->stringify($this->directivesFromContribution($row['contribution']));
            if ($policy === '') {
                continue;
            }
            $out[] = [
                'module' => $row['module'],
                'policy' => $policy,
            ];
        }

        return $out;
    }

    /**
     * @return list<CspSourceContribution>
     */
    private function contributions(): array
    {
        // ObjectManager may materialize optional `?array $forcedContributions = null` as [].
        // Treat empty as "not forced" so Extends Captcha CSP still loads; otherwise
        // response CSP permanently misses recaptcha.net and Google mints BROWSER_ERROR tokens.
        if ($this->forcedContributions !== null && $this->forcedContributions !== []) {
            return $this->forcedContributions;
        }

        $list = [];
        foreach ($this->loadExtendsProviders() as $row) {
            $list[] = $row['contribution'];
        }

        return $list;
    }

    /**
     * @return list<array{module:string,contribution:CspSourceContribution}>
     */
    private function loadExtendsProviders(): array
    {
        $rows = [];
        try {
            $extendedBy = ExtendsData::getExtendedBy('Weline_Framework');
        } catch (\Throwable) {
            return [];
        }

        $prefix = CspSourceContributionProviderInterface::EXTENDS_RELATIVE_PREFIX;
        foreach ($extendedBy as $extensions) {
            if (!\is_array($extensions)) {
                continue;
            }
            foreach ($extensions as $extension) {
                if (!\is_array($extension)) {
                    continue;
                }
                if (($extension['source_module_status'] ?? true) === false) {
                    continue;
                }
                $relativePath = \str_replace('\\', '/', (string)($extension['relative_path'] ?? ''));
                if (!\str_starts_with(\strtolower($relativePath), $prefix)) {
                    continue;
                }
                $className = $this->resolveClassName($extension);
                $sourceFile = (string)($extension['source_file'] ?? '');
                if ($className === null) {
                    continue;
                }
                if (!\class_exists($className, false) && $sourceFile !== '' && \is_file($sourceFile)) {
                    require_once $sourceFile;
                }
                if (!\class_exists($className)
                    || !\is_subclass_of($className, CspSourceContributionProviderInterface::class)
                ) {
                    continue;
                }
                $reflection = new \ReflectionClass($className);
                $constructor = $reflection->getConstructor();
                if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                    continue;
                }
                /** @var CspSourceContributionProviderInterface $provider */
                $provider = $reflection->newInstance();
                $rows[] = [
                    'module' => (string)($extension['source_module'] ?? ''),
                    'contribution' => $provider->contribution(),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $extension
     */
    private function resolveClassName(array $extension): ?string
    {
        $fromScan = \trim((string)($extension['class_name'] ?? ''));
        if ($fromScan !== '') {
            return $fromScan;
        }

        $sourceFile = (string)($extension['source_file'] ?? '');
        if ($sourceFile === '' || !\is_file($sourceFile)) {
            return null;
        }
        $content = \file_get_contents($sourceFile);
        if ($content === false) {
            return null;
        }
        $namespace = null;
        if (\preg_match('/namespace\s+([^;]+);/', $content, $matches) === 1) {
            $namespace = \trim((string)$matches[1]);
        }
        $class = null;
        if (\preg_match('/class\s+(\w+)/', $content, $matches) === 1) {
            $class = (string)$matches[1];
        }
        if ($namespace !== null && $class !== null) {
            return $namespace . '\\' . $class;
        }

        return null;
    }

    /**
     * @return array<string, list<string>>
     */
    private function directivesFromContribution(CspSourceContribution $contribution): array
    {
        $directives = [];
        foreach ($contribution->directives as $directive => $sources) {
            $name = \strtolower(\trim((string)$directive));
            if ($name === '' || !\is_array($sources)) {
                continue;
            }
            $list = [];
            foreach ($sources as $src) {
                $src = \trim((string)$src);
                if ($src !== '') {
                    $list[] = $src;
                }
            }
            if ($list !== []) {
                \sort($list, \SORT_STRING);
                $directives[$name] = \array_values(\array_unique($list));
            }
        }

        return $directives;
    }
}
