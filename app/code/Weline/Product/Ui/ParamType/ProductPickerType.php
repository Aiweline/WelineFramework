<?php

declare(strict_types=1);

namespace Weline\Product\Ui\ParamType;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\Template;
use Weline\Widget\Ui\ParamType\AbstractParamType;

/**
 * Widget 参数：后台商品选品（复用 &lt;w:product:admin:picker&gt; 行为，回填 product_ids）。
 * 侧栏仅触发壳；搜索与结果在主题 Weline.UI.dialog 悬浮床中。
 */
final class ProductPickerType extends AbstractParamType
{
    private const ASSET_BUST = '20260916-product-picker-float5';




    public function getTypeCode(): string
    {
        return 'product_picker';
    }

    public function getHtml(string $key, array $param, mixed $value, int|string $layoutId = '', array $attrs = []): string
    {
        unset($attrs);
        $fieldId = $this->generateFieldId($key, $layoutId);
        $pickerId = $fieldId . '_picker';
        $commaIds = $this->normalizeCommaIds($value ?? $this->getDefaultValue($param) ?? '');
        $selectedJson = htmlspecialchars(
            json_encode($this->selectedRowsFromCommaIds($commaIds), JSON_UNESCAPED_UNICODE) ?: '[]',
            ENT_QUOTES,
            'UTF-8'
        );
        $labelsJson = htmlspecialchars(json_encode($this->pickerLabels(), JSON_UNESCAPED_UNICODE) ?: '{}', ENT_QUOTES, 'UTF-8');
        $cssUrl = htmlspecialchars($this->versionedStaticUrl('Weline_Product::css/backend/product-admin-picker.css', self::ASSET_BUST), ENT_QUOTES, 'UTF-8');
        $jsUrl = htmlspecialchars($this->versionedStaticUrl('Weline_Product::js/backend/product-admin-picker.js', self::ASSET_BUST), ENT_QUOTES, 'UTF-8');
        $help = htmlspecialchars((string)__('搜索并选择已发布商品；选中后回填产品 ID。留空则使用产品来源。'), ENT_QUOTES, 'UTF-8');
        $open = htmlspecialchars((string)__('选择商品'), ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars((string)__('选择商品'), ENT_QUOTES, 'UTF-8');
        $close = htmlspecialchars((string)__('关闭'), ENT_QUOTES, 'UTF-8');
        $done = htmlspecialchars((string)__('完成'), ENT_QUOTES, 'UTF-8');
        $searchLabel = htmlspecialchars((string)__('搜索商品'), ENT_QUOTES, 'UTF-8');
        $placeholder = htmlspecialchars((string)__('名称或 SKU'), ENT_QUOTES, 'UTF-8');
        $searchButton = htmlspecialchars((string)__('搜索'), ENT_QUOTES, 'UTF-8');
        $defaultHint = htmlspecialchars((string)__('打开后默认展示已发布商品，可输入关键词缩小范围。'), ENT_QUOTES, 'UTF-8');
        $keyEsc = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
        $fieldIdEsc = htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8');
        $pickerIdEsc = htmlspecialchars($pickerId, ENT_QUOTES, 'UTF-8');
        $valueEsc = htmlspecialchars($commaIds, ENT_QUOTES, 'UTF-8');
        $limit = max(1, min(50, (int)($param['limit'] ?? 20)));

        $inputHtml = <<<HTML
<link rel="stylesheet" href="{$cssUrl}" data-no-extract="true">
<script src="{$jsUrl}" defer data-no-extract="true"></script>
<div class="w-param-product-picker" data-w-component="product-admin-picker">
    <input type="hidden" id="{$fieldIdEsc}" name="{$keyEsc}" value="{$valueEsc}" data-product-picker-sync>
    <div class="w-product-admin-picker" id="{$pickerIdEsc}" data-product-admin-picker
         data-name=""
         data-sync-input="{$fieldIdEsc}"
         data-selected="{$selectedJson}"
         data-website-field="__weline_product_picker_no_website__"
         data-default-website-id="0"
         data-mode="multiple"
         data-limit="{$limit}"
         data-cross-website="1"
         data-picker-js="{$jsUrl}"
         data-picker-css="{$cssUrl}"
         data-labels="{$labelsJson}">
        <p class="w-product-admin-picker__help">{$help}</p>
        <div class="w-product-admin-picker__shell w-cluster" data-align="center" data-justify="start">
            <button type="button" class="w-button" data-tone="primary" data-variant="outline" data-size="sm" data-product-admin-picker-open>{$open}</button>
        </div>
        <div class="w-product-admin-picker__selected" data-product-admin-picker-selected></div>
        <dialog class="w-dialog w-product-admin-picker__dialog" data-product-admin-picker-dialog
                aria-labelledby="{$pickerIdEsc}-dialog-title" data-w-component="dialog" data-state="closed"
                data-size="lg" data-w-closable="true" data-w-backdrop="dismissible">
            <header class="w-dialog__header">
                <h2 class="w-dialog__title" id="{$pickerIdEsc}-dialog-title">{$title}</h2>
                <button type="button" class="w-button" data-w-action="dialog.close" data-w-close
                        data-tone="quiet" data-size="sm" aria-label="{$close}">{$close}</button>
            </header>
            <div class="w-dialog__body w-product-admin-picker__dialog-body">
                <div class="w-stack" data-gap="md">
                    <div>
                        <label class="w-field__label" for="{$pickerIdEsc}-keyword">{$searchLabel}</label>
                        <div class="w-cluster">
                            <input id="{$pickerIdEsc}-keyword" class="w-input" type="search" maxlength="120" placeholder="{$placeholder}" data-product-admin-picker-keyword>
                            <button class="w-button" type="button" data-tone="neutral" data-product-admin-picker-search>{$searchButton}</button>
                        </div>
                        <p class="w-product-admin-picker__hint w-text" data-tone="muted">{$defaultHint}</p>
                    </div>
                    <div class="w-product-admin-picker__results" data-product-admin-picker-results aria-live="polite">
                        <p class="w-text" data-tone="muted">{$defaultHint}</p>
                    </div>
                </div>
            </div>
            <footer class="w-dialog__footer">
                <button type="button" class="w-button" data-tone="primary" data-product-admin-picker-done>{$done}</button>
            </footer>
        </dialog>
    </div>
</div>
HTML;

        $param = array_merge($param, ['i18n' => false, 'translatable' => false]);

        return $this->wrapField($key, $param, $inputHtml, $layoutId);
    }

    public function validate(mixed $value, array $param): bool
    {
        $normalized = $this->normalizeCommaIds($value);
        if (!empty($param['required']) && $normalized === '') {
            return false;
        }

        return true;
    }

    public function processValue(mixed $value, array $param): mixed
    {
        unset($param);

        return $this->normalizeCommaIds($value);
    }

    public function getDefaultValue(array $param): mixed
    {
        return $this->normalizeCommaIds($param['default'] ?? '');
    }

    private function normalizeCommaIds(mixed $value): string
    {
        if (is_array($value)) {
            $ids = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    $id = (int)($item['product_id'] ?? $item['id'] ?? 0);
                } else {
                    $id = (int)$item;
                }
                if ($id > 0) {
                    $ids[] = $id;
                }
            }

            return implode(',', array_values(array_unique($ids)));
        }

        $raw = trim((string)$value);
        if ($raw === '') {
            return '';
        }
        $ids = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $part) {
            $id = (int)trim((string)$part);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return implode(',', array_values(array_unique($ids)));
    }

    /**
     * @return list<array{product_id:int,website_id:int,name:string,sku:string,image:string,price_label:string}>
     */
    private function selectedRowsFromCommaIds(string $commaIds): array
    {
        $ids = [];
        if ($commaIds !== '') {
            foreach (explode(',', $commaIds) as $part) {
                $id = (int)$part;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            $ids = array_values(array_unique($ids));
        }
        if ($ids === []) {
            return [];
        }

        $byId = [];
        try {
            /** @var \Weline\Product\Service\ProductAdminReadService $reader */
            $reader = ObjectManager::getInstance(\Weline\Product\Service\ProductAdminReadService::class);
            $locale = '';
            try {
                if (function_exists('__current_lang')) {
                    $locale = trim((string)\__current_lang());
                }
            } catch (\Throwable) {
                $locale = '';
            }
            if ($locale === '' && !empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
                // Keep empty: service falls back to zh/en before arbitrary locales.
                $locale = '';
            }
            $filters = ['product_ids' => $ids];
            if ($locale !== '') {
                $filters['locale'] = $locale;
                $filters['locale_code'] = $locale;
            }
            foreach ($reader->search(0, $filters) as $row) {
                $productId = (int)($row['product_id'] ?? 0);
                if ($productId <= 0) {
                    continue;
                }
                $skus = is_array($row['skus'] ?? null) ? $row['skus'] : [];
                $media = is_array($row['main_media'] ?? null) ? $row['main_media'] : [];
                $image = trim((string)($media['preview_url'] ?? $media['display_url'] ?? ''));
                $priceLabel = self::formatPriceLabel(is_array($row['prices'] ?? null) ? $row['prices'] : []);
                $byId[$productId] = [
                    'product_id' => $productId,
                    'website_id' => (int)($row['website_id'] ?? 0),
                    'name' => trim((string)($row['name'] ?? '')),
                    'sku' => trim((string)($row['sku'] ?? ($skus[0] ?? ''))),
                    'image' => $image,
                    'price_label' => $priceLabel,
                ];
            }
        } catch (\Throwable) {
            $byId = [];
        }

        $rows = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $row = $byId[$id];
                if ($row['name'] === '') {
                    $row['name'] = $row['sku'] !== '' ? $row['sku'] : ('#' . $id);
                }
                $rows[] = $row;
                continue;
            }
            $rows[] = [
                'product_id' => $id,
                'website_id' => 0,
                'name' => '#' . $id,
                'sku' => '',
                'image' => '',
                'price_label' => '',
            ];
        }

        return $rows;
    }

    /** @return array<string, string> */
    private function pickerLabels(): array
    {
        return [
            'search' => (string)__('搜索'),
            'remove' => (string)__('移除'),
            'add' => (string)__('添加'),
            'open' => (string)__('选择商品'),
            'done' => (string)__('完成'),
            'dialogTitle' => (string)__('选择商品'),
            'loading' => (string)__('正在加载已发布商品…'),
            'empty' => (string)__('暂无可选商品，请检查发布状态或更换关键词。'),
            'selectedTitle' => (string)__('已选商品'),
            'name' => (string)__('名称'),
            'scopeRequired' => (string)__('选品前请先选择 Website（0 为默认网站，可正常选品）。'),
            'website' => (string)__('网站'),
            'image' => (string)__('图'),
            'price' => (string)__('价格'),
        ];
    }

    /**
     * @param list<array<string, mixed>> $prices
     */
    private static function formatPriceLabel(array $prices): string
    {
        $preferred = ['CNY', 'USD', 'EUR', 'GBP'];
        $pick = null;
        foreach ($preferred as $currency) {
            foreach ($prices as $candidate) {
                if (!is_array($candidate) || !empty($candidate['cleared'])) {
                    continue;
                }
                if (strtoupper(trim((string)($candidate['currency'] ?? ''))) !== $currency) {
                    continue;
                }
                if (!isset($candidate['amount_minor']) || $candidate['amount_minor'] === '' || $candidate['amount_minor'] === null) {
                    continue;
                }
                $pick = $candidate;
                break 2;
            }
        }
        if ($pick === null) {
            foreach ($prices as $candidate) {
                if (!is_array($candidate) || !empty($candidate['cleared'])) {
                    continue;
                }
                if (!isset($candidate['amount_minor']) || $candidate['amount_minor'] === '' || $candidate['amount_minor'] === null) {
                    continue;
                }
                $pick = $candidate;
                break;
            }
        }
        if ($pick === null) {
            return '';
        }
        $minor = (int)$pick['amount_minor'];
        $currency = strtoupper(trim((string)($pick['currency'] ?? 'CNY')));
        $symbol = match ($currency) {
            'CNY', 'RMB', 'JPY' => '¥',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => ($currency !== '' ? $currency . ' ' : ''),
        };

        return $symbol . number_format($minor / 100, 2, '.', '');
    }

    private function staticUrl(string $source): string
    {
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);

        return (string)$template->fetchTagSource(DataInterface::dir_type_STATICS, $source);
    }

    private function versionedStaticUrl(string $source, string $bust): string
    {
        $url = trim($this->staticUrl($source));
        if ($url === '') {
            return '';
        }
        $sep = str_contains($url, '?') ? '&' : '?';

        return $url . $sep . 'v=' . rawurlencode($bust);
    }
}
