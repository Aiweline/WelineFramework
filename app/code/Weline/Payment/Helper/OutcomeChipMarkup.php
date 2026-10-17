<?php

declare(strict_types=1);

namespace Weline\Payment\Helper;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\Ui\IconRegistry;

/**
 * Payment outcome chip HTML without nested Template::fetch in table loops.
 */
final class OutcomeChipMarkup
{
    /**
     * @param array{tone?: string, icon?: string, label?: string, kind?: string}|null $chip
     */
    public static function render(?array $chip, string $testid = 'payment-outcome-chip'): string
    {
        $chip = \is_array($chip) ? $chip : [];
        $label = \trim((string)($chip['label'] ?? ''));
        if ($label === '') {
            return '';
        }

        $tone = \trim((string)($chip['tone'] ?? ''));
        $kind = \trim((string)($chip['kind'] ?? ''));
        $testid = \trim($testid) !== '' ? \trim($testid) : 'payment-outcome-chip';
        $esc = static fn (string $value): string => \htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        /** @var IconRegistry $icons */
        $icons = ObjectManager::getInstance(IconRegistry::class);
        $iconHtml = match ($kind) {
            'paid' => $icons->render('check-circle', 'sm'),
            'failed' => $icons->render('x-circle', 'sm'),
            'pending' => $icons->render('clock', 'sm'),
            'refunded' => $icons->render('refresh', 'sm'),
            default => '',
        };

        return '<span class="w-badge w-cluster payment-outcome-chip"'
            . ' data-align="center"'
            . ($tone !== '' ? ' data-tone="' . $esc($tone) . '"' : '')
            . ' data-kind="' . $esc($kind) . '"'
            . ' data-testid="' . $esc($testid) . '">'
            . $iconHtml
            . '<span>' . $esc($label) . '</span>'
            . '</span>';
    }
}
