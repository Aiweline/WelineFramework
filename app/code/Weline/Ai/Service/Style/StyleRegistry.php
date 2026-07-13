<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Style;

use Weline\Ai\Api\StyleProviderInterface;
use Weline\Ai\Model\AiStyle;
use Weline\Framework\App\Env;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;

final class StyleRegistry
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $moduleStyleCache = null;

    public function __construct(
        private readonly ?StyleRepository $repository = null,
        private readonly ?StyleNormalizer $normalizer = null
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function listAvailableStyles(int $adminId = 0, bool $includeInactive = false): array
    {
        $styles = $this->collectModuleStyles();
        try {
            $dbStyles = $this->repository()->listByCode($adminId, true);
        } catch (\Throwable $throwable) {
            $dbStyles = [];
            if (\function_exists('w_log_error')) {
                w_log_error('AI style DB catalog unavailable: ' . $throwable->getMessage());
            }
        }
        foreach ($dbStyles as $code => $style) {
            if (isset($styles[$code])) {
                continue;
            }
            if (!$includeInactive && (string)($style['status'] ?? '') !== AiStyle::STATUS_ACTIVE) {
                continue;
            }
            $styles[$code] = $style;
        }

        if (!$includeInactive) {
            foreach ($styles as $code => $style) {
                if ((string)($style['status'] ?? '') !== AiStyle::STATUS_ACTIVE) {
                    unset($styles[$code]);
                }
            }
        }

        \ksort($styles);
        return $styles;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStyle(string $code, int $adminId = 0, bool $includeInactive = true): array
    {
        try {
            $code = $this->normalizer()->normalizeCode($code, false);
        } catch (\InvalidArgumentException) {
            $code = \trim($code);
        }

        $styles = $this->listAvailableStyles($adminId, $includeInactive);
        if ($code !== '' && isset($styles[$code])) {
            return $styles[$code];
        }

        return [
            'id' => 0,
            'admin_user_id' => 0,
            'code' => $code,
            'name' => $code,
            'description' => '',
            'source_type' => 'missing',
            'source' => 'missing',
            'status' => 'missing',
            'version' => 0,
            'exists' => false,
            'readonly' => true,
            'selectable' => false,
        ];
    }

    /**
     * @return array{matched:bool,item:array<string,mixed>|null,score:int,matched_keywords:list<string>,reason:string}
     */
    public function matchStyle(string $title, string $brief, int $adminId = 0): array
    {
        $haystack = $this->normalizer()->lowerForMatch($title . "\n" . $brief);
        if (\trim($haystack) === '') {
            return [
                'matched' => false,
                'item' => null,
                'score' => 0,
                'matched_keywords' => [],
                'reason' => '标题和一句话描述为空，未自动套用垂直风格。',
            ];
        }

        $best = null;
        $bestScore = 0;
        $bestKeywords = [];
        foreach ($this->listAvailableStyles($adminId, false) as $style) {
            $keywords = $this->normalizer()->normalizeStringList($style['match_keywords'] ?? []);
            $score = 0;
            $hits = [];
            foreach ($keywords as $keyword) {
                $needle = $this->normalizer()->lowerForMatch($keyword);
                if ($needle === '' || !$this->keywordMatchesPositiveIntent($haystack, $needle)) {
                    continue;
                }
                $hits[] = $keyword;
                $score += \max(1, \min(6, (int)\ceil(\strlen($needle) / 4)));
            }
            if ($score > $bestScore) {
                $best = $style;
                $bestScore = $score;
                $bestKeywords = $hits;
            }
        }

        if (!\is_array($best) || $bestScore <= 0) {
            return [
                'matched' => false,
                'item' => null,
                'score' => 0,
                'matched_keywords' => [],
                'reason' => '未命中垂直风格，使用通用设计方向。',
            ];
        }

        return [
            'matched' => true,
            'item' => $best,
            'score' => $bestScore,
            'matched_keywords' => $bestKeywords,
            'reason' => '自动推荐：命中关键词 ' . \implode('、', \array_slice($bestKeywords, 0, 6)) . '。',
        ];
    }

    private function keywordMatchesPositiveIntent(string $haystack, string $needle): bool
    {
        $quoted = \preg_quote($needle, '/');
        $pattern = \preg_match('/^[a-z0-9]+$/i', $needle) === 1
            ? '/(?<![a-z0-9])' . $quoted . '(?![a-z0-9])/iu'
            : '/' . $quoted . '/iu';
        if (\preg_match_all($pattern, $haystack, $matches, \PREG_OFFSET_CAPTURE) < 1) {
            return false;
        }

        foreach ($matches[0] as $match) {
            $position = (int)($match[1] ?? 0);
            if (!$this->isNegatedKeywordOccurrence($haystack, $position)) {
                return true;
            }
        }

        return false;
    }

    private function isNegatedKeywordOccurrence(string $haystack, int $bytePosition): bool
    {
        $start = \max(0, $bytePosition - 140);
        $prefix = \substr($haystack, $start, $bytePosition - $start);
        $prefix = (string)\preg_replace('/^.*[.;!?。！？\r\n]/u', '', $prefix);

        return \preg_match(
            '/(?:\b(?:avoid|exclude|excluding|without|no|not|never|forbid|forbidden|do\s+not|don\'t)\b|禁止|避免|不要|不得|排除|不是|非|勿|请勿)[^.;!?。！？\r\n]{0,140}$/iu',
            $prefix
        ) === 1;
    }

    public function isReservedCode(string $code, int $adminId = 0): bool
    {
        try {
            $code = $this->normalizer()->normalizeCode($code, true);
        } catch (\InvalidArgumentException) {
            return false;
        }

        if (isset($this->collectModuleStyles()[$code])) {
            return true;
        }

        try {
            $dbStyle = $this->repository()->findArrayByCode($code, $adminId);
        } catch (\Throwable) {
            $dbStyle = null;
        }
        if (!\is_array($dbStyle)) {
            return false;
        }

        return \in_array((string)($dbStyle['source_type'] ?? ''), [AiStyle::SOURCE_SYSTEM, AiStyle::SOURCE_MODULE, AiStyle::SOURCE_BUILTIN], true);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function collectModuleStyles(): array
    {
        if ($this->moduleStyleCache !== null) {
            return $this->moduleStyleCache;
        }

        $styles = [];
        foreach ($this->collectProviderFiles() as $providerInfo) {
            $provider = $this->loadProvider((string)$providerInfo['file']);
            if (!$provider) {
                continue;
            }
            foreach ($provider->listStyles() as $rawStyle) {
                if (!\is_array($rawStyle)) {
                    continue;
                }
                try {
                    $style = $this->normalizeProviderStyle($rawStyle, (string)$providerInfo['module']);
                } catch (\Throwable $throwable) {
                    if (\function_exists('w_log_error')) {
                        w_log_error('AI style provider returned invalid style: ' . $throwable->getMessage());
                    }
                    continue;
                }
                $code = (string)$style['code'];
                if ($code !== '' && !isset($styles[$code])) {
                    $styles[$code] = $style;
                }
            }
        }

        \ksort($styles);
        return $this->moduleStyleCache = $styles;
    }

    /**
     * @return list<array{file:string,module:string}>
     */
    private function collectProviderFiles(): array
    {
        $files = [];
        $seen = [];
        $moduleList = $this->getModuleList();
        $candidateModules = [];

        $extendedBy = [];
        try {
            $extendedBy = ExtendsData::getExtendedBy('Weline_Ai') ?: [];
        } catch (\Throwable) {
            $extendedBy = [];
        }
        foreach (\array_keys($extendedBy) as $moduleName) {
            $candidateModules[$moduleName] = true;
        }
        foreach (\array_keys($moduleList) as $moduleName) {
            $candidateModules[$moduleName] = true;
        }

        foreach (\array_keys($candidateModules) as $moduleName) {
            $basePath = (string)($moduleList[$moduleName]['base_path'] ?? '');
            if ($basePath === '') {
                continue;
            }
            $dir = \rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR
                . 'extends' . DIRECTORY_SEPARATOR
                . 'module' . DIRECTORY_SEPARATOR
                . 'Weline_Ai' . DIRECTORY_SEPARATOR
                . 'Style';
            if (!\is_dir($dir)) {
                continue;
            }
            foreach ((@\glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: []) as $file) {
                $real = (string)\realpath((string)$file);
                if ($real === '' || isset($seen[$real])) {
                    continue;
                }
                $seen[$real] = true;
                $files[] = ['file' => $real, 'module' => $moduleName];
            }
        }

        return $files;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getModuleList(): array
    {
        try {
            $modules = Env::getInstance()->getModuleList();
            return \is_array($modules) ? $modules : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function loadProvider(string $file): ?StyleProviderInterface
    {
        if (!\is_file($file)) {
            return null;
        }
        require_once $file;
        $content = (string)@\file_get_contents($file);
        if ($content === '') {
            return null;
        }
        if (\preg_match('/namespace\s+([^;]+);/m', $content, $namespaceMatches) !== 1
            || \preg_match('/(?:final\s+)?class\s+(\w+)/m', $content, $classMatches) !== 1) {
            return null;
        }

        $className = '\\' . \trim((string)$namespaceMatches[1]) . '\\' . \trim((string)$classMatches[1]);
        if (!\class_exists($className)) {
            return null;
        }

        try {
            $instance = ObjectManager::getInstance($className);
        } catch (\Throwable) {
            $instance = new $className();
        }

        return $instance instanceof StyleProviderInterface ? $instance : null;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalizeProviderStyle(array $raw, string $moduleName): array
    {
        $code = $this->normalizer()->normalizeCode((string)($raw['code'] ?? ''), true);
        $sourceType = (string)($raw['source_type'] ?? AiStyle::SOURCE_MODULE);
        if (!\in_array($sourceType, [AiStyle::SOURCE_SYSTEM, AiStyle::SOURCE_MODULE, AiStyle::SOURCE_BUILTIN], true)) {
            $sourceType = AiStyle::SOURCE_MODULE;
        }
        $normalized = $this->normalizer()->normalizeStylePayload($raw);

        return [
            'id' => 0,
            'admin_user_id' => 0,
            'code' => $code,
            'name' => \trim((string)($raw['name'] ?? $code)),
            'description' => \trim((string)($raw['description'] ?? '')),
            'source_type' => $sourceType,
            'source' => (string)($raw['source'] ?? ('module:' . $moduleName)),
            'source_module' => (string)($raw['source_module'] ?? $moduleName),
            'industry_tags' => $normalized['industry_tags'] ?? [],
            'match_keywords' => $normalized['match_keywords'] ?? [],
            'visual_keywords' => $normalized['visual_keywords'] ?? [],
            'color_system' => $normalized['color_system'] ?? [],
            'layout_patterns' => $normalized['layout_patterns'] ?? [],
            'image_strategy' => $normalized['image_strategy'] ?? [],
            'cta_style' => \trim((string)($raw['cta_style'] ?? '')),
            'forbidden_patterns' => $normalized['forbidden_patterns'] ?? [],
            'block_rules' => $normalized['block_rules'] ?? [],
            'qa_rules' => $normalized['qa_rules'] ?? [],
            'example_refs' => $normalized['example_refs'] ?? [],
            'supplemental_prompt' => \trim((string)($raw['supplemental_prompt'] ?? '')),
            'version' => \max(1, (int)($raw['version'] ?? 1)),
            'status' => AiStyle::STATUS_ACTIVE,
            'readonly' => true,
            'selectable' => true,
            'exists' => true,
        ];
    }

    private function repository(): StyleRepository
    {
        return $this->repository ?? ObjectManager::getInstance(StyleRepository::class);
    }

    private function normalizer(): StyleNormalizer
    {
        return $this->normalizer ?? new StyleNormalizer();
    }
}
