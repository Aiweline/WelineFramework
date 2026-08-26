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
            'show-type' => false,
            'show-hot-words' => false,
            'auto-complete' => false,
            'class' => false,
            'value' => false,
            'id' => false,
        ];
    }

    public static function callback(): callable
    {
        return static function ($tag_key, $config, $tag_data, $attributes): string {
            $registry = ObjectManager::getInstance(SearchProviderRegistry::class);
            $hotWords = ObjectManager::getInstance(HotWordsService::class);
            $types = $registry->listTypes();
            $lockedType = trim((string)($attributes['type'] ?? ''));
            $requestType = trim((string)($_GET['type'] ?? ''));
            $requestCategoryId = (int)($_GET['category_id'] ?? 0);
            $selectedType = $lockedType !== '' ? $lockedType : ($requestType !== '' ? $requestType : 'all');
            $showType = ($attributes['show-type'] ?? 'true') !== 'false';
            if ($lockedType !== '' && $lockedType !== 'all') {
                $showType = false;
            }
            $placeholder = (string)($attributes['placeholder'] ?? __('输入关键词…'));
            $query = trim((string)($attributes['value'] ?? ''));
            $showHot = ($attributes['show-hot-words'] ?? 'true') !== 'false';
            $autoComplete = ($attributes['auto-complete'] ?? 'true') !== 'false';
            $panelClass = trim((string)($attributes['class'] ?? ''));
            if ($panelClass === '') {
                $panelClass = 'header-search-panel';
            }
            $panelId = trim((string)($attributes['id'] ?? ''));
            $hot = $hotWords->resolve(8);
            $words = $hot['words'];
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
     data-autocomplete="<?= $autoComplete ? 'true' : 'false' ?>">
    <form action="/search" method="get" class="header-search-form search-form w-search-form">
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
}
