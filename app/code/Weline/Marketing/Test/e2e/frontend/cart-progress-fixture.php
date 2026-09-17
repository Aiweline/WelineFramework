<?php

declare(strict_types=1);

/**
 * Ch5 店面满额进度 / 新客礼：渲染部件 HTML 供 Playwright 断言。
 *
 * stdin JSON: {"action":"render_cart_progress"|"render_welcome_gift"|"registry", ...}
 * stdout JSON only.
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Widget\Service\WidgetData;
use Weline\Widget\Service\WidgetPreviewService;
use Weline\Widget\Taglib\Widget;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

/** @return array<string, mixed> */
function mkt_progress_input(): array
{
    $raw = file_get_contents('php://stdin');
    $data = json_decode($raw === false ? '' : $raw, true);

    return is_array($data) ? $data : [];
}

/** @param array<string, mixed> $payload */
function mkt_progress_out(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit($code);
}

/**
 * @param array<string, mixed> $config
 */
function mkt_progress_render_widget(string $type, string $name, array $config = []): string
{
    /** @var WidgetData $widgetData */
    $widgetData = ObjectManager::getInstance(WidgetData::class);
    $widget = $widgetData->getWidget($type, $name);
    if (!is_array($widget) || $widget === []) {
        throw new RuntimeException("widget not found: {$type}/{$name}");
    }

    $reflection = new ReflectionClass(Widget::class);
    $method = $reflection->getMethod('renderWidget');
    $method->setAccessible(true);
    $html = $method->invokeArgs(null, [$widget, $config]);
    /** @var WidgetPreviewService $preview */
    $preview = ObjectManager::getInstance(WidgetPreviewService::class);

    return $preview->sanitizePreviewHtml(is_string($html) ? $html : '');
}

try {
    $input = mkt_progress_input();
    $action = (string)($input['action'] ?? 'render_cart_progress');

    if ($action === 'registry') {
        $generated = BP . '/generated/widgets.php';
        $widgetPhp = BP . '/app/code/Weline/Marketing/extends/module/Weline_Widget/Weline_Marketing/widget.php';
        $tpl = BP . '/app/code/Weline/Marketing/view/templates/frontend/widgets/cart-progress.phtml';
        $welcomeTpl = BP . '/app/code/Weline/Marketing/view/templates/frontend/widgets/welcome-gift.phtml';
        mkt_progress_out([
            'ok' => true,
            'generated_has_cart_progress' => is_file($generated) && str_contains((string)file_get_contents($generated), "'cart-progress'"),
            'widget_declares_slot' => is_file($widgetPhp) && str_contains((string)file_get_contents($widgetPhp), 'cart-summary-discount'),
            'cart_progress_testid' => is_file($tpl) && str_contains((string)file_get_contents($tpl), 'data-testid="marketing-cart-progress"'),
            'welcome_testid' => is_file($welcomeTpl) && str_contains((string)file_get_contents($welcomeTpl), 'data-testid="marketing-welcome-gift"'),
        ]);
    }

    if ($action === 'render_welcome_gift') {
        $html = mkt_progress_render_widget('content', 'welcome-gift', []);
        mkt_progress_out([
            'ok' => true,
            'html' => $html,
            'has_testid' => str_contains($html, 'data-testid="marketing-welcome-gift"'),
        ]);
    }

    if ($action === 'build_progress') {
        /** @var \Weline\Marketing\Service\MarketingCartProgressService $svc */
        $svc = ObjectManager::getInstance(\Weline\Marketing\Service\MarketingCartProgressService::class);
        $built = $svc->build(
            (float)($input['current'] ?? 40),
            (float)($input['threshold'] ?? 99),
            (string)($input['currency'] ?? 'CNY'),
        );
        mkt_progress_out([
            'ok' => true,
            'build' => $built,
        ]);
    }

    if ($action === 'render_cart_progress') {
        // Widget params 白名单无 current：店面由购物车小计注入；预览默认空车 current=0。
        $config = [
            'title' => (string)($input['title'] ?? '满额进度'),
            'threshold' => (float)($input['threshold'] ?? 99),
            'currency' => (string)($input['currency'] ?? 'CNY'),
            'show_welcome' => array_key_exists('show_welcome', $input) ? (bool)$input['show_welcome'] : true,
            'welcome_label' => (string)($input['welcome_label'] ?? '新客礼'),
            'welcome_url' => (string)($input['welcome_url'] ?? '/account/register'),
        ];
        $html = mkt_progress_render_widget('content', 'cart-progress', $config);
        mkt_progress_out([
            'ok' => true,
            'html' => $html,
            'has_testid' => str_contains($html, 'data-testid="marketing-cart-progress"'),
            'has_welcome' => str_contains($html, 'data-testid="marketing-welcome-gift-link"'),
            'config' => $config,
        ]);
    }

    mkt_progress_out(['ok' => false, 'error' => 'unknown action: ' . $action], 1);
} catch (Throwable $e) {
    mkt_progress_out(['ok' => false, 'error' => $e->getMessage()], 1);
}
