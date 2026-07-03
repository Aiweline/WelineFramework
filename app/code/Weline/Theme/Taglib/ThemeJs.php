<?php

declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Taglib\TaglibInterface;

class ThemeJs implements TaglibInterface
{
    /**
     * @inheritDoc
     */
    public static function name(): string
    {
        return 'theme:js';
    }

    /**
     * @inheritDoc
     */
    public static function tag(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public static function attr(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public static function tag_start(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function tag_end(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            // 框架约定：tag_data[0]=rawTag, [1]=rawAttributes/内联内容, [2]=子内容
            // 若 [1] 被误当作路径（含 :: 或 /），不得作为 HTML 属性输出，并优先当作 content
            $raw1 = trim((string)($tag_data[1] ?? ''));
            $raw2 = trim((string)($tag_data[2] ?? ''));
            $looksLikePath = $raw1 !== '' && (str_contains($raw1, '::') || str_contains($raw1, '/'));

            if ($tag_key === 'tag') {
                $content = $raw2 !== '' ? $raw2 : $raw1;
                $attrs = (!$looksLikePath && $raw1 !== '') ? $raw1 : '';
            } elseif ($tag_key === '@tag()' || $tag_key === '@tag{}') {
                $content = $raw1;
                $attrs = '';
            } else {
                $content = $raw2 !== '' ? $raw2 : $raw1;
                $attrs = '';
            }

            if (empty($content)) {
                return '';
            }

            $contentPhp = self::buildRuntimeStringExpression($content);
            $attrsPhp = var_export($attrs ? ' ' . trim($attrs) : '', true);

            return "<?php \$__themeJsSrc = \$this->fetchTagSource(\\Weline\\Framework\\View\\Data\\DataInterface::dir_type_THEME, {$contentPhp});"
                . " if (\$__themeJsSrc !== '') { echo '<script' . {$attrsPhp} . ' src=\\''"
                . " . htmlspecialchars((string)\$__themeJsSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')"
                . " . '\\'></script>'; } ?>";
        };
    }

    private static function buildRuntimeStringExpression(string $content): string
    {
        $segments = [];
        $offset = 0;
        $pattern = '/<\?(?:php\s+echo|=)\s*(.*?)\s*;?\s*\?>/s';

        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $index => $match) {
                [$fullTag, $position] = $match;
                $literal = substr($content, $offset, $position - $offset);
                if ($literal !== '') {
                    $segments[] = var_export($literal, true);
                }

                $expression = trim((string)$matches[1][$index][0]);
                if ($expression !== '') {
                    $segments[] = '(string)(' . $expression . ')';
                }

                $offset = $position + strlen($fullTag);
            }
        }

        $tail = substr($content, $offset);
        if ($tail !== '') {
            $segments[] = var_export($tail, true);
        }

        return $segments ? implode(' . ', $segments) : "''";
    }

    /**
     * @inheritDoc
     */
    public static function tag_self_close(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function tag_self_close_with_attrs(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function parent(): ?string
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public static function document(): string
    {
        return '主题JavaScript文件标签，用于加载theme目录下的JS文件';
    }
}
