<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Style;

use Weline\Ai\Interface\AdapterStyleBindingInterface;
use Weline\Ai\Model\AiStyle;
use Weline\Ai\Service\AdapterScanner;
use Weline\Ai\Service\SkillStyleTrace;
use Weline\Framework\Manager\ObjectManager;

final class AdapterStyleResolver
{
    public function __construct(
        private readonly ?AdapterScanner $adapterScanner = null,
        private readonly ?StyleRegistry $styleRegistry = null,
        private readonly ?AdapterStyleRepository $bindingRepository = null,
        private readonly ?StyleNormalizer $normalizer = null
    ) {
    }

    /**
     * @return list<string>
     */
    public function getDefaultStyleCodes(string $adapterCode): array
    {
        $adapter = $this->adapterScanner()->getAdapter($adapterCode);
        if (!$adapter instanceof AdapterStyleBindingInterface) {
            return [];
        }

        return $this->normalizer()->normalizeCodeList($adapter->getDefaultStyleCodes());
    }

    /**
     * @return array{matched:bool,item:array<string,mixed>|null,score:int,matched_keywords:list<string>,reason:string,source:string}
     */
    public function resolvePreferredStyle(string $adapterCode, string $title, string $brief, int $adminId): array
    {
        $auto = $this->styleRegistry()->matchStyle($title, $brief, $adminId);
        if (!empty($auto['matched']) && \is_array($auto['item'] ?? null)) {
            $this->logSkillStyleTrace('style_binding.resolved', [
                'adapter' => $adapterCode,
                'source' => 'auto',
                'style_code' => (string)($auto['item']['code'] ?? ''),
                'style_version' => (int)($auto['item']['version'] ?? 0),
                'score' => (int)($auto['score'] ?? 0),
                'matched_keywords' => $auto['matched_keywords'] ?? [],
            ]);
            return $auto + ['source' => 'auto'];
        }

        $styles = $this->styleRegistry()->listAvailableStyles($adminId, false);
        $manualCodes = $this->bindingRepository()->listActiveStyleCodes($adapterCode);
        foreach ($manualCodes as $code) {
            $style = $styles[$code] ?? null;
            if (!\is_array($style) || (string)($style['status'] ?? '') !== AiStyle::STATUS_ACTIVE) {
                continue;
            }
            $this->logSkillStyleTrace('style_binding.resolved', [
                'adapter' => $adapterCode,
                'source' => 'manual',
                'style_code' => $code,
                'style_version' => (int)($style['version'] ?? 0),
            ]);
            return [
                'matched' => true,
                'item' => $style,
                'score' => 0,
                'matched_keywords' => [],
                'reason' => '适配器绑定风格：' . (string)($style['name'] ?? $code),
                'source' => 'manual',
            ];
        }

        foreach ($this->getDefaultStyleCodes($adapterCode) as $code) {
            $style = $styles[$code] ?? null;
            if (!\is_array($style) || (string)($style['status'] ?? '') !== AiStyle::STATUS_ACTIVE) {
                continue;
            }
            $this->logSkillStyleTrace('style_binding.resolved', [
                'adapter' => $adapterCode,
                'source' => 'default',
                'style_code' => $code,
                'style_version' => (int)($style['version'] ?? 0),
            ]);
            return [
                'matched' => true,
                'item' => $style,
                'score' => 0,
                'matched_keywords' => [],
                'reason' => '适配器默认风格：' . (string)($style['name'] ?? $code),
                'source' => 'default',
            ];
        }

        $this->logSkillStyleTrace('style_binding.unmatched', [
            'adapter' => $adapterCode,
            'admin_id' => $adminId,
            'auto_reason' => (string)($auto['reason'] ?? ''),
        ]);
        return [
            'matched' => false,
            'item' => null,
            'score' => 0,
            'matched_keywords' => [],
            'reason' => (string)($auto['reason'] ?? '未命中垂直风格，使用通用设计方向。'),
            'source' => '',
        ];
    }

    /**
     * @param list<string> $temporaryStyleCodes
     * @return array{items:list<array<string,mixed>>,default_style_codes:list<string>,manual_style_codes:list<string>,warnings:list<string>}
     */
    public function buildStyleCatalog(string $adapterCode, array $temporaryStyleCodes = [], int $adminId = 0, bool $includeInactive = false): array
    {
        $styles = $this->styleRegistry()->listAvailableStyles($adminId, $includeInactive);
        $defaultCodes = $this->getDefaultStyleCodes($adapterCode);
        $manualCodes = $this->bindingRepository()->listActiveStyleCodes($adapterCode);
        $temporaryCodes = $this->normalizer()->normalizeCodeList($temporaryStyleCodes);
        $warnings = [];
        $itemsByCode = [];

        foreach ($styles as $code => $style) {
            $default = \in_array($code, $defaultCodes, true);
            $manual = \in_array($code, $manualCodes, true);
            $temporary = \in_array($code, $temporaryCodes, true);
            $source = $default ? 'default' : ($manual ? 'manual' : ($temporary ? 'temporary' : ''));
            $itemsByCode[$code] = $this->decorate($style, $default, $manual, $temporary, $source);
        }

        foreach ($defaultCodes as $code) {
            if (isset($itemsByCode[$code])) {
                continue;
            }
            $itemsByCode[$code] = $this->decorate([
                'code' => $code,
                'name' => $code,
                'description' => '',
                'status' => 'missing',
                'source' => '',
                'source_type' => 'missing',
                'exists' => false,
                'readonly' => true,
            ], true, false, false, 'default');
            $warnings[] = 'Default adapter style "' . $code . '" is missing.';
        }

        \ksort($itemsByCode);
        $result = [
            'items' => \array_values($itemsByCode),
            'default_style_codes' => $defaultCodes,
            'manual_style_codes' => $manualCodes,
            'warnings' => $warnings,
        ];
        $this->logSkillStyleTrace('style_catalog.built', [
            'adapter' => $adapterCode,
            'default_style_codes' => $defaultCodes,
            'manual_style_codes' => $manualCodes,
            'temporary_style_codes' => $temporaryCodes,
            'item_count' => \count($result['items']),
            'warnings' => $warnings,
        ]);

        return $result;
    }

    /**
     * @param array<string,mixed> $style
     * @return array<string,mixed>
     */
    private function decorate(array $style, bool $default, bool $manual, bool $temporary, string $bindingSource): array
    {
        $status = (string)($style['status'] ?? 'active');
        $exists = !empty($style['exists']);
        $style['adapter_default'] = $default;
        $style['manual'] = $manual;
        $style['temporary'] = $temporary;
        $style['binding_source'] = $bindingSource;
        $style['selectable'] = $exists && $status === AiStyle::STATUS_ACTIVE;
        $style['readonly'] = !empty($style['readonly']) || \in_array((string)($style['source_type'] ?? ''), [AiStyle::SOURCE_SYSTEM, AiStyle::SOURCE_MODULE, AiStyle::SOURCE_BUILTIN], true);

        return $style;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logSkillStyleTrace(string $event, array $context = []): void
    {
        SkillStyleTrace::log($event, $context);
    }

    private function adapterScanner(): AdapterScanner
    {
        return $this->adapterScanner ?? ObjectManager::getInstance(AdapterScanner::class);
    }

    private function styleRegistry(): StyleRegistry
    {
        return $this->styleRegistry ?? ObjectManager::getInstance(StyleRegistry::class);
    }

    private function bindingRepository(): AdapterStyleRepository
    {
        return $this->bindingRepository ?? ObjectManager::getInstance(AdapterStyleRepository::class);
    }

    private function normalizer(): StyleNormalizer
    {
        return $this->normalizer ?? new StyleNormalizer();
    }
}
