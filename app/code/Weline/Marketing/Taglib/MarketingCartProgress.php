<?php

declare(strict_types=1);

namespace Weline\Marketing\Taglib;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Template;
use Weline\Marketing\Service\MarketingCartProgressService;

/**
 * <marketing-cart-progress current=".." threshold=".." currency="CNY" label="免邮" />
 */
final class MarketingCartProgress implements TaglibInterface
{
    public static function name(): string
    {
        return 'marketing-cart-progress';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function tag_self_close(): bool
    {
        return true;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    public static function parent(): ?string
    {
        return null;
    }

    public static function document(): string
    {
        return '购物车满额进度条：current / threshold / currency / label';
    }

    public static function attr(): array
    {
        return [
            'current' => false,
            'threshold' => false,
            'currency' => false,
            'label' => false,
            'class' => false,
        ];
    }

    public static function callback(): callable
    {
        return static function ($tagKey, $config, $tagData, $attributes): string {
            return '<?php echo \\Weline\\Marketing\\Taglib\\MarketingCartProgress::render('
                . '(float)(' . self::phpAttr($attributes, 'current', '0') . '), '
                . '(float)(' . self::phpAttr($attributes, 'threshold', '0') . '), '
                . '(string)(' . self::phpAttr($attributes, 'currency', "''") . '), '
                . '(string)(' . self::phpAttr($attributes, 'label', "''") . '), '
                . '(string)(' . self::phpAttr($attributes, 'class', "''") . ')'
                . '); ?>';
        };
    }

    public static function runtimeCallback(): callable
    {
        return static function (
            Template $template,
            string $tagKey,
            array $attributes,
            string $content,
        ): string {
            unset($template, $content);
            if ($tagKey !== 'tag-self-close' && $tagKey !== 'tag-self-close-with-attrs') {
                return '';
            }

            return self::render(
                (float)self::attrValue($attributes, 'current', '0'),
                (float)self::attrValue($attributes, 'threshold', '0'),
                (string)self::attrValue($attributes, 'currency', ''),
                (string)self::attrValue($attributes, 'label', ''),
                (string)self::attrValue($attributes, 'class', ''),
            );
        };
    }

    public static function render(
        float $current,
        float $threshold,
        string $currency = '',
        string $label = '',
        string $class = '',
    ): string {
        /** @var MarketingCartProgressService $svc */
        $svc = ObjectManager::getInstance(MarketingCartProgressService::class);
        $progress = $svc->progress(
            ['subtotal' => $current, 'currency' => $currency],
            [['threshold' => $threshold, 'label' => $label]],
        );
        if ($progress === null) {
            return '';
        }
        $pct = (int)\round(((float)$progress['progress']) * 100);
        $remaining = (float)$progress['remaining'];
        $met = !empty($progress['met']);
        $labelText = $label !== '' ? $label : (string)($progress['label'] ?? '');
        $msg = $met
            ? ($labelText !== '' ? $labelText : (string)\__('已达成'))
            : \sprintf('%s %s%.2f', (string)\__('还差'), $currency !== '' ? $currency . ' ' : '', $remaining);
        $classAttr = \trim('w-mkt-cart-progress ' . $class);

        return '<div class="' . \htmlspecialchars($classAttr, ENT_QUOTES, 'UTF-8') . '" data-testid="marketing-cart-progress">'
            . '<div class="w-mkt-cart-progress__bar" role="progressbar" aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100">'
            . '<span class="w-mkt-cart-progress__fill" style="width:' . $pct . '%;"></span></div>'
            . '<p class="w-mkt-cart-progress__label">' . \htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
            . '</div>';
    }

    /** @param array<string, mixed> $attributes */
    private static function phpAttr(array $attributes, string $key, string $defaultExpr): string
    {
        $raw = $attributes[$key] ?? null;
        if ($raw === null || $raw === '') {
            return $defaultExpr;
        }
        if (\is_string($raw) && \str_starts_with($raw, '$')) {
            return $raw;
        }

        return var_export((string)$raw, true);
    }

    /** @param array<string, mixed> $attributes */
    private static function attrValue(array $attributes, string $key, string $default): string
    {
        $v = $attributes[$key] ?? $default;

        return \is_scalar($v) ? (string)$v : $default;
    }
}
