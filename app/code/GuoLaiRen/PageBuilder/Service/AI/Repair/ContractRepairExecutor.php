<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Repair;

use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractPatchValidator;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractQaReportBuilder;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractType;

final class ContractRepairExecutor
{
    public function __construct(
        private readonly ?ContractPatchValidator $patchValidator = null,
        private readonly ?ContractQaReportBuilder $qaReportBuilder = null
    ) {
    }

    /**
     * @param array<string, mixed> $targetContract
     * @param array<string, mixed> $repairPatchContract
     * @return array{contract:array<string,mixed>,applied:list<array<string,mixed>>,blocked:list<array<string,mixed>>,qa_report:array<string,mixed>}
     */
    public function apply(array $targetContract, array $repairPatchContract): array
    {
        $current = $targetContract;
        $applied = [];
        $blocked = [];
        $payload = \is_array($repairPatchContract['payload'] ?? null) ? $repairPatchContract['payload'] : [];
        $candidates = \is_array($payload['patch_candidates'] ?? null) ? $payload['patch_candidates'] : [];

        foreach ($candidates as $candidate) {
            if (!\is_array($candidate)) {
                continue;
            }
            $path = \trim((string)($candidate['path'] ?? ''));
            if (!$this->isMutablePath($path, $targetContract)) {
                $blocked[] = $this->blockedCandidate($candidate, 'Path is not mutable: ' . $path);
                continue;
            }

            $next = $this->applyCandidate($current, $candidate);
            $validation = ($this->patchValidator ?? new ContractPatchValidator())->validate(
                $targetContract,
                $next,
                \is_array($targetContract['permission_matrix'] ?? null) ? $targetContract['permission_matrix'] : [],
                \is_array($targetContract['frozen_fields'] ?? null) ? \array_values(\array_map('strval', $targetContract['frozen_fields'])) : []
            );
            if (!(bool)($validation['valid'] ?? false)) {
                $blocked[] = $this->blockedCandidate(
                    $candidate,
                    \implode('; ', \array_map('strval', \is_array($validation['errors'] ?? null) ? $validation['errors'] : []))
                );
                continue;
            }

            $current = $next;
            $applied[] = $candidate;
        }

        $type = $this->extractContractType($targetContract);
        $qaReport = ($this->qaReportBuilder ?? new ContractQaReportBuilder())->build(
            $type !== '' ? [$type => $current] : [$current],
            [],
            $type !== '' ? [$type => $targetContract] : [$targetContract]
        );

        return [
            'contract' => $current,
            'applied' => $applied,
            'blocked' => $blocked,
            'qa_report' => $qaReport,
        ];
    }

    /**
     * @param array<string, mixed> $targetContract
     */
    private function isMutablePath(string $path, array $targetContract): bool
    {
        if ($path === '') {
            return false;
        }
        $mutableFields = \array_values(\array_filter(\array_map(
            'strval',
            \is_array($targetContract['mutable_fields'] ?? null) ? $targetContract['mutable_fields'] : []
        )));
        foreach ($mutableFields as $pattern) {
            $pattern = \trim($pattern);
            if ($pattern === '') {
                continue;
            }
            if ($pattern === $path || \str_starts_with($path, $pattern . '.')) {
                return true;
            }
            if (\str_ends_with($pattern, '.*')) {
                $prefix = \substr($pattern, 0, -2);
                if ($path === $prefix || \str_starts_with($path, $prefix . '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function applyCandidate(array $contract, array $candidate): array
    {
        $op = \trim((string)($candidate['op'] ?? 'set'));
        $path = \trim((string)($candidate['path'] ?? ''));
        $value = $candidate['value'] ?? null;
        if ($path === '') {
            return $contract;
        }

        if ($op === 'append') {
            $existing = $this->getPath($contract, $path);
            $list = \is_array($existing) ? \array_values($existing) : [];
            $list[] = $value;

            return $this->setPath($contract, $path, $list);
        }

        return $this->setPath($contract, $path, $value);
    }

    /**
     * @param array<string, mixed> $source
     */
    private function getPath(array $source, string $path): mixed
    {
        $current = $source;
        foreach (\explode('.', $path) as $segment) {
            if (!\is_array($current) || !\array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function setPath(array $source, string $path, mixed $value): array
    {
        $segments = \explode('.', $path);
        $cursor =& $source;
        foreach ($segments as $index => $segment) {
            if ($index === \count($segments) - 1) {
                $cursor[$segment] = $value;
                break;
            }
            if (!isset($cursor[$segment]) || !\is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor =& $cursor[$segment];
        }

        return $source;
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function blockedCandidate(array $candidate, string $reason): array
    {
        return $candidate + ['blocked_reason' => $reason];
    }

    /**
     * @param array<string, mixed> $contract
     */
    private function extractContractType(array $contract): string
    {
        $meta = \is_array($contract['contract_meta'] ?? null) ? $contract['contract_meta'] : [];

        return \trim((string)($meta['type'] ?? $contract['type'] ?? ContractType::TYPE_RENDER_DATA));
    }
}
