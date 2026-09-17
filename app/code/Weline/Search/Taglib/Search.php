<?php

declare(strict_types=1);

namespace Weline\Search\Taglib;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Template;
use Weline\Search\Service\HotWordsService;
use Weline\Search\Service\SearchProviderRegistry;

/**
 * P1 header search: GET /search, Registry type dropdown, hot words, autocomplete.
 * Markup/classes align with Theme header-search Amazon bar CSS.
 */
final class Search implements TaglibInterface
{
    public static function name(): string
    {
        return 'search';
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
            'placeholder' => false,
            'type' => false,
            'area' => false,
            'show-type' => false,
            'show-hot-words' => false,
            'auto-complete' => false,
            'navigate-hits' => false,
            'class' => false,
            'value' => false,
            'id' => false,
            'action' => false,
        ];
    }

    public static function callback(): callable
    {
        return static function ($tag_key, $config, $tag_data, $attributes): string {
            $registry = ObjectManager::getInstance(SearchProviderRegistry::class);
            $hotWords = ObjectManager::getInstance(HotWordsService::class);
            $area = strtolower(trim((string)($attributes['area'] ?? 'frontend')));
            if ($area === '') {
                $area = 'frontend';
            }
            $types = $registry->listTypes(area: $area);
            $lockedType = trim((string)($attributes['type'] ?? ''));
            $requestType = trim((string)($_GET['type'] ?? ''));
            $requestCategoryId = (int)($_GET['category_id'] ?? 0);
            $selectedType = $lockedType !== '' ? $lockedType : ($requestType !== '' ? $requestType : 'all');
            $showType = ($attributes['show-type'] ?? 'true') !== 'false';
            if ($lockedType !== '' && $lockedType !== 'all') {
                $showType = false;
            }
            // 属性里写中文源串；此处再 __()，避免 Taglib 运行时属性拿不到 @lang 烘焙结果。
            $placeholderRaw = trim((string)($attributes['placeholder'] ?? '输入关键词…'));
            if ($placeholderRaw === '') {
                $placeholderRaw = '输入关键词…';
            }
            $placeholder = (string)__($placeholderRaw);
            $query = trim((string)($attributes['value'] ?? ''));
            $showHot = ($attributes['show-hot-words'] ?? ($area === 'backend' ? 'false' : 'true')) !== 'false';
            $autoComplete = ($attributes['auto-complete'] ?? 'true') !== 'false';
            $navigateHits = ($attributes['navigate-hits'] ?? ($area === 'backend' ? 'true' : 'false')) !== 'false';
            $panelClass = trim((string)($attributes['class'] ?? ''));
            if ($panelClass === '') {
                $panelClass = $area === 'backend' ? 'w-backend-topbar-search' : 'header-search-panel';
            }
            $panelId = trim((string)($attributes['id'] ?? ''));
            $formAction = trim((string)($attributes['action'] ?? ''));
            if ($formAction === '') {
                $formAction = $area === 'backend' ? '#' : '/search';
            }
            $hot = $area === 'frontend' ? $hotWords->resolve(8) : ['words' => []];
            $words = is_array($hot['words'] ?? null) ? $hot['words'] : [];
            $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
            $typeMenuId = ($panelId !== '' ? $panelId : 'header-search-panel') . '-type-menu';
            $typeDropdown = '';
            if ($showType) {
                $typeDropdown = ObjectManager::getInstance(Template::class)->fetch(
                    'Weline_Theme::theme/frontend/partials/search/type-dropdown.phtml',
                    [
                        'search_types' => $types,
                        'selected_type' => $selectedType,
                        'selected_category_id' => $requestCategoryId,
                        'menu_id' => $typeMenuId,
                    ],
                );
            }

            ob_start();
            ?>
<div class="<?= $esc($panelClass) ?> w-search-root"<?= $panelId !== '' ? ' id="' . $esc($panelId) . '"' : '' ?>
     data-w-search
     data-search-area="<?= $esc($area) ?>"
     data-navigate-hits="<?= $navigateHits ? 'true' : 'false' ?>"
     data-autocomplete="<?= $autoComplete ? 'true' : 'false' ?>">
    <form action="<?= $esc($formAction) ?>" method="get" class="header-search-form search-form w-search-form"<?= $area === 'backend' ? ' data-w-search-backend-form="1"' : '' ?>>
        <input type="hidden" name="area" value="<?= $esc($area) ?>">
        <?php if (!$showType && $lockedType !== '' && $lockedType !== 'all'): ?>
            <input type="hidden" name="type" value="<?= $esc($lockedType) ?>">
        <?php elseif ($showType): ?>
            <?= $typeDropdown ?>
        <?php endif; ?>
        <div class="search-input-wrapper w-search-input-wrap">
            <input type="search"
                   name="q"
                   value="<?= $esc($query) ?>"
                   maxlength="255"
                   placeholder="<?= $esc($placeholder) ?>"
                   autocomplete="off"
                   class="search-input w-search-input">
        </div>
        <button type="submit"
                class="search-submit-btn search-button w-search-submit"
                title="<?= $esc((string)__('搜索')) ?>"
                aria-label="<?= $esc((string)__('搜索')) ?>"></button>
        <?php if ($autoComplete): ?>
            <div class="search-suggestions w-search-suggestions" hidden>
                <div class="suggestion-list w-search-suggestion-list"></div>
            </div>
        <?php endif; ?>
    </form>
    <?php if ($showHot && $words !== []): ?>
        <div class="header-search-hot-words hot-words w-search-hot-words" data-hot-words>
            <span class="header-search-hot-label hot-label w-search-hot-label"><?= $esc((string)__('热搜')) ?>:</span>
            <?php foreach ($words as $word): ?>
                <a href="/search?q=<?= rawurlencode($word) ?>"
                   class="header-search-hot-word hot-word w-search-hot-word"><?= $esc($word) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
            <?php
            return (string)ob_get_clean();
        };
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
        $document = <<<'HTML'
<h3><code>&lt;w:search&gt;</code> 使用文档</h3>
<p>渲染万能搜索栏。支持 <code>area</code>（<code>frontend</code>/<code>backend</code>）、类型选择、热搜词、自动补全，以及后台 <code>navigate-hits</code> 直达命中 URL。业务通过 <code>Searcher</code> 扩展按 area 注册。</p>
HTML;

        return \htmlspecialchars($document, ENT_NOQUOTES);
    }
}
