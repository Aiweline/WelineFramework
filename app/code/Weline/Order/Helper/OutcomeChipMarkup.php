<?php

declare(strict_types=1);

namespace Weline\Order\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\Ui\IconRegistry;

/**
 * Backend order outcome chip HTML without nested Template::fetch in table loops.
 */
final class OutcomeChipMarkup
{
    /**
     * @param array{tone?: string, icon?: string, label?: string, kind?: string}|null $chip
     */
    public static function render(?array $chip, string $testid = 'order-outcome-chip'): string
    {
        $chip = \is_array($chip) ? $chip : [];
        $label = \trim((string)($chip['label'] ?? ''));
        if ($label === '') {
            return '';
        }

        $tone = \trim((string)($chip['tone'] ?? ''));
        $kind = \trim((string)($chip['kind'] ?? ''));
        $testid = \trim($testid) !== '' ? \trim($testid) : 'order-outcome-chip';
        $esc = static fn (string $value): string => \htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        /** @var IconRegistry $icons */
        $icons = ObjectManager::getInstance(IconRegistry::class);
        $iconHtml = match ($kind) {
            'success' => $icons->render('check-circle', 'sm'),
            'failed' => $icons->render('x-circle', 'sm'),
            'processing' => $icons->render('clock', 'sm'),
            'cancelled' => $icons->render('close', 'sm'),
            default => '',
        };

        return '<span class="w-badge w-cluster"'
            . ' data-align="center"'
            . ' style="--w-gap:var(--weline-space-1);"'
            . ($tone !== '' ? ' data-tone="' . $esc($tone) . '"' : '')
            . ' data-kind="' . $esc($kind) . '"'
            . ' data-testid="' . $esc($testid) . '">'
            . $iconHtml
            . '<span>' . $esc($label) . '</span>'
            . '</span>';
    }
}
