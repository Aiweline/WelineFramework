<?php

declare(strict_types=1);

/**
 * CLI fixture: qty-tier total-save formula + template wiring.
 * stdout JSON for Playwright.
 */
require dirname(__DIR__, 2) . '/Unit/bootstrap.php';

try {
    $catalogMinor = 2194;
    $ladder = [
        5 => 1974,
        10 => 1894,
        15 => 1814,
        30 => 1755,
    ];
    $rows = [];
    foreach ($ladder as $qty => $amount) {
        $unitSave = $catalogMinor > $amount ? ($catalogMinor - $amount) : 0;
        $totalSave = $unitSave * $qty;
        $rows[] = [
            'qty' => $qty,
            'amount_minor' => $amount,
            'unit_save_minor' => $unitSave,
            'total_save_minor' => $totalSave,
        ];
    }

    $tiersSrc = (string)file_get_contents(
        dirname(__DIR__, 3) . '/view/templates/frontend/partials/qty-tiers.phtml'
    );
    $cssSrc = (string)file_get_contents(
        dirname(__DIR__, 3) . '/view/statics/css/b2b-storefront.css'
    );
    $templateWired = str_contains($tiersSrc, '省 %{1} %{2}')
        && str_contains($tiersSrc, '每件低 %{1} %{2}')
        && str_contains($cssSrc, '--weline-theme-success')
        && str_contains($tiersSrc, 'b2b-qty-tiers__delta')
        && str_contains($tiersSrc, 'data-unit-save-minor')
        && str_contains($tiersSrc, '起订 %{1} · 步进 %{2}')
        && str_contains($tiersSrc, 'data-total-save-minor')
        && str_contains($tiersSrc, '$saveVsRetail * $qty')
        && str_contains($tiersSrc, "'CNY', 'RMB' => '¥'");
    $cssWired = str_contains($cssSrc, '.b2b-qty-tiers__save')
        && str_contains($cssSrc, '.b2b-qty-tiers__delta')
        && str_contains($cssSrc, '.b2b-qty-tiers__item.is-save');

    // Expected totals: 5→1100, 10→3000, 15→5700, 30→13170
    $expectedTotals = [1100, 3000, 5700, 13170];
    // Expected unit vs retail: 220, 300, 380, 439
    $expectedUnits = [220, 300, 380, 439];
    $formulaOk = true;
    $unitOk = true;
    foreach ($rows as $i => $row) {
        if ((int)$row['total_save_minor'] !== (int)$expectedTotals[$i]) {
            $formulaOk = false;
        }
        if ((int)$row['unit_save_minor'] !== (int)$expectedUnits[$i]) {
            $unitOk = false;
        }
    }

    $ok = $templateWired && $cssWired && $formulaOk && $unitOk;

    echo json_encode([
        'ok' => $ok,
        'template_wired' => $templateWired,
        'css_wired' => $cssWired,
        'formula_ok' => $formulaOk,
        'unit_vs_retail_ok' => $unitOk,
        'sample_10_total_save_minor' => $rows[1]['total_save_minor'] ?? 0,
        'sample_5_unit_save_minor' => $rows[0]['unit_save_minor'] ?? 0,
        'rows' => $rows,
    ], JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}
