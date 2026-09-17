<?php

declare(strict_types=1);

namespace Weline\Product\Taglib;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\AttributeCodeCompiler;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\Template;

/**
 * 后台商品选品标签（走 product_admin Resource，不依赖业务模块自建搜索接口）。
 *
 * <w:product:admin:picker
 *     id="promotion-theme-product-picker"
 *     name="product_ids[]"
 *     selected="selectedProductsJson"
 *     website-field="website_id"
 *     mode-field="product_pick_mode"
 * />
 */
final class AdminProductPicker implements TaglibInterface
{
    public static function name(): string
    {
        return 'product:admin:picker';
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

    public static function attr(): array
    {
        return [
            'id' => true,
            'name' => false,
            'selected' => false,
            'website-field' => false,
            'store-field' => false,
            'channel-field' => false,
            'mode' => false,
            'mode-field' => false,
            'limit' => false,
            'class' => false,
            'search-url' => false,
            'cross-website' => false,
        ];
    }

    public static function callback(): callable
    {
        return static function ($tag_key, $config, $tag_data, $attributes) {
            if (empty($attributes['id'])) {
                throw new \Exception((string)\__('id属性不能为空'));
            }

            $attrs = $attributes;
            unset($attrs['id'], $attrs['class']);
            $code = AttributeCodeCompiler::attributes($attrs);

            return '<?php ' . $code . ' ?>' . "\n" . self::buildCompileMarkup($attributes);
        };
    }

    /**
     * 自闭合标签在 AST 路径下走 renderRuntimeTag，必须直接输出 HTML。
     */
    public static function runtimeCallback(): callable
    {
        return static function (
            Template $template,
            string $tagKey,
            array $attributes,
            string $content,
        ): string {
            unset($content);
            if ($tagKey !== 'tag-self-close' && $tagKey !== 'tag-self-close-with-attrs') {
                return '';
            }

            if (empty($attributes['id'])) {
                throw new \Exception((string)\__('id属性不能为空'));
            }

            return self::buildRuntimeMarkup($template, $attributes);
        };
    }

    private const ASSET_BUST = '20260916-product-picker-float5';




    /** @param array<string, mixed> $attributes */
    private static function buildCompileMarkup(array $attributes): string
    {
        $id = htmlspecialchars((string)$attributes['id'], ENT_QUOTES, 'UTF-8');
        $class = htmlspecialchars(trim('w-product-admin-picker ' . (string)($attributes['class'] ?? '')), ENT_QUOTES, 'UTF-8');
        $labelsJson = htmlspecialchars(json_encode(self::labels(), JSON_UNESCAPED_UNICODE) ?: '{}', ENT_QUOTES, 'UTF-8');
        $cssUrl = htmlspecialchars(
            self::resolveModuleStaticUrl('Weline_Product::css/backend/product-admin-picker.css') . '?v=' . self::ASSET_BUST,
            ENT_QUOTES,
        );
        $jsUrl = htmlspecialchars(
            self::resolveModuleStaticUrl('Weline_Product::js/backend/product-admin-picker.js') . '?v=' . self::ASSET_BUST,
            ENT_QUOTES,
        );
        $inner = self::buildPickerInnerHtml($id);

        return <<<HTML
<link rel="stylesheet" href="{$cssUrl}" data-no-extract="true">
<script src="{$jsUrl}" defer data-no-extract="true"></script>
<div class="{$class}" id="{$id}" data-product-admin-picker
     data-name="<?= htmlspecialchars((string)(\$Taglib__name ?? 'product_ids[]'), ENT_QUOTES, 'UTF-8') ?>"
     data-selected="<?= htmlspecialchars((string)(\$Taglib__selected ?? '[]'), ENT_QUOTES, 'UTF-8') ?>"
     data-website-field="<?= htmlspecialchars((string)(\$Taglib__website_field ?? 'website_id'), ENT_QUOTES, 'UTF-8') ?>"
     data-store-field="<?= htmlspecialchars((string)(\$Taglib__store_field ?? 'store_code'), ENT_QUOTES, 'UTF-8') ?>"
     data-channel-field="<?= htmlspecialchars((string)(\$Taglib__channel_field ?? 'channel_code'), ENT_QUOTES, 'UTF-8') ?>"
     data-mode="<?= htmlspecialchars((string)(\$Taglib__mode ?? 'multiple'), ENT_QUOTES, 'UTF-8') ?>"
     data-mode-field="<?= htmlspecialchars((string)(\$Taglib__mode_field ?? ''), ENT_QUOTES, 'UTF-8') ?>"
     data-limit="<?= (int)(\$Taglib__limit ?? 20) ?>"
     data-search-url="<?= htmlspecialchars(trim((string)(\$Taglib__search_url ?? '')), ENT_QUOTES, 'UTF-8') ?>"
     data-cross-website="<?= (!empty(\$Taglib__cross_website) || trim((string)(\$Taglib__search_url ?? '')) !== '') ? '1' : '0' ?>"
     data-labels="{$labelsJson}">
{$inner}
</div>
HTML;
    }

    /** @param array<string, mixed> $attributes */
    private static function buildRuntimeMarkup(Template $template, array $attributes): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $id = trim((string)($attributes['id'] ?? ''));
        $class = trim('w-product-admin-picker ' . trim((string)($attributes['class'] ?? '')));
        $name = self::resolveTemplateValue($template, (string)($attributes['name'] ?? ''), 'product_ids[]');
        $selected = self::resolveTemplateValue($template, (string)($attributes['selected'] ?? ''), '[]');
        $websiteField = self::resolveTemplateValue($template, (string)($attributes['website-field'] ?? ''), 'website_id');
        $storeField = self::resolveTemplateValue($template, (string)($attributes['store-field'] ?? ''), 'store_code');
        $channelField = self::resolveTemplateValue($template, (string)($attributes['channel-field'] ?? ''), 'channel_code');
        $mode = self::resolveTemplateValue($template, (string)($attributes['mode'] ?? ''), 'multiple');
        $modeField = self::resolveTemplateValue($template, (string)($attributes['mode-field'] ?? ''), '');
        $limit = max(1, min(50, (int)($attributes['limit'] ?? 20)));
        $searchUrl = self::resolveSearchUrl($template, $attributes);
        $crossWebsite = self::isTruthy($attributes['cross-website'] ?? false) || $searchUrl !== '';
        $labelsJson = $escape(json_encode(self::labels(), JSON_UNESCAPED_UNICODE) ?: '{}');
        $cssUrl = $escape(
            self::resolveModuleStaticUrl('Weline_Product::css/backend/product-admin-picker.css') . '?v=' . self::ASSET_BUST,
        );
        $jsUrl = $escape(
            self::resolveModuleStaticUrl('Weline_Product::js/backend/product-admin-picker.js') . '?v=' . self::ASSET_BUST,
        );
        $idEsc = $escape($id);
        $classEsc = $escape($class);
        $inner = self::buildPickerInnerHtml($idEsc);

        return <<<HTML
<link rel="stylesheet" href="{$cssUrl}" data-no-extract="true">
<script src="{$jsUrl}" defer data-no-extract="true"></script>
<div class="{$classEsc}" id="{$idEsc}" data-product-admin-picker
     data-name="{$escape($name)}"
     data-selected="{$escape($selected)}"
     data-website-field="{$escape($websiteField)}"
     data-store-field="{$escape($storeField)}"
     data-channel-field="{$escape($channelField)}"
     data-mode="{$escape($mode)}"
     data-mode-field="{$escape($modeField)}"
     data-limit="{$limit}"
     data-search-url="{$escape($searchUrl)}"
     data-cross-website="{$escape($crossWebsite ? '1' : '0')}"
     data-labels="{$labelsJson}">
{$inner}
</div>
HTML;
    }

    private static function buildPickerInnerHtml(string $idEsc): string
    {
        $help = htmlspecialchars((string)\__('通过 product_admin 搜索已发布商品；全站活动（Website=全部）可跨站搜索，结果会标注所属网站。'), ENT_QUOTES, 'UTF-8');
        $open = htmlspecialchars((string)\__('选择商品'), ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars((string)\__('选择商品'), ENT_QUOTES, 'UTF-8');
        $close = htmlspecialchars((string)\__('关闭'), ENT_QUOTES, 'UTF-8');
        $done = htmlspecialchars((string)\__('完成'), ENT_QUOTES, 'UTF-8');
        $searchLabel = htmlspecialchars((string)\__('搜索商品'), ENT_QUOTES, 'UTF-8');
        $placeholder = htmlspecialchars((string)\__('名称或 SKU'), ENT_QUOTES, 'UTF-8');
        $searchButton = htmlspecialchars((string)\__('搜索'), ENT_QUOTES, 'UTF-8');
        $defaultHint = htmlspecialchars((string)\__('打开后默认展示已发布商品，可输入关键词缩小范围。'), ENT_QUOTES, 'UTF-8');

        return <<<HTML
    <p class="w-product-admin-picker__help">{$help}</p>
    <div class="w-product-admin-picker__shell w-cluster" data-align="center" data-justify="start">
        <button type="button" class="w-button" data-tone="primary" data-variant="outline" data-size="sm" data-product-admin-picker-open>{$open}</button>
    </div>
    <div class="w-product-admin-picker__selected" data-product-admin-picker-selected></div>
    <dialog class="w-dialog w-product-admin-picker__dialog" data-product-admin-picker-dialog
            aria-labelledby="{$idEsc}-dialog-title" data-w-component="dialog" data-state="closed"
            data-size="lg" data-w-closable="true" data-w-backdrop="dismissible">
        <header class="w-dialog__header">
            <h2 class="w-dialog__title" id="{$idEsc}-dialog-title">{$title}</h2>
            <button type="button" class="w-button" data-w-action="dialog.close" data-w-close
                    data-tone="quiet" data-size="sm" aria-label="{$close}">{$close}</button>
        </header>
        <div class="w-dialog__body w-product-admin-picker__dialog-body">
            <div class="w-stack" data-gap="md">
                <div>
                    <label class="w-field__label" for="{$idEsc}-keyword">{$searchLabel}</label>
                    <div class="w-cluster">
                        <input id="{$idEsc}-keyword" class="w-input" type="search" maxlength="120" placeholder="{$placeholder}" data-product-admin-picker-keyword>
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
HTML;
    }

    /** @return array<string, string> */
    private static function labels(): array
    {
        return [
            'search' => (string)\__('搜索'),
            'remove' => (string)\__('移除'),
            'add' => (string)\__('添加'),
            'open' => (string)\__('选择商品'),
            'done' => (string)\__('完成'),
            'dialogTitle' => (string)\__('选择商品'),
            'loading' => (string)\__('正在加载已发布商品…'),
            'empty' => (string)\__('当前范围暂无可选商品，请检查 website / 店铺范围或商品发布状态。'),
            'selectedTitle' => (string)\__('已选商品'),
            'name' => (string)\__('名称'),
            'scopeRequired' => (string)\__('选品前请先选择 Website（0 为默认网站，可正常选品）。'),
            'website' => (string)\__('网站'),
            'image' => (string)\__('图'),
            'price' => (string)\__('价格'),
        ];
    }

    private static function resolveTemplateValue(Template $template, string $raw, string $default = ''): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $default;
        }

        $data = $template->getData();
        if (is_array($data) && array_key_exists($raw, $data)) {
            $value = $data[$raw];
            if (is_scalar($value) || $value === null) {
                return (string)$value;
            }
        }

        if ($raw === '[]' || str_starts_with($raw, '[') || str_starts_with($raw, '{')) {
            return $raw;
        }

        return $raw;
    }

    /** @param array<string, mixed> $attributes */
    private static function resolveSearchUrl(Template $template, array $attributes): string
    {
        $searchUrl = trim((string)($attributes['search-url'] ?? $attributes['search_url'] ?? ''));
        if ($searchUrl !== '' && !str_starts_with($searchUrl, '@admin-url')) {
            return $searchUrl;
        }

        $assigned = self::resolveTemplateValue($template, 'promotionThemeProductSearchUrl');
        if ($assigned !== '') {
            return $assigned;
        }

        try {
            return trim((string)$template->getUrl('promotion/backend/theme/searchProducts'));
        } catch (\Throwable) {
            return '';
        }
    }

    private static function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
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
        return htmlspecialchars(
            '<h3><code>&lt;w:product:admin:picker&gt;</code></h3>'
            . '<p>后台商品选品：走 <code>product_admin.search</code> 或自定义 <code>search-url</code>，输出 <code>product_ids[]</code> 与 <code>product_website_ids[]</code>。</p>',
            ENT_NOQUOTES
        );
    }

    private static function resolveModuleStaticUrl(string $source): string
    {
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);

        return (string)$template->fetchTagSource(DataInterface::dir_type_STATICS, $source);
    }
}
