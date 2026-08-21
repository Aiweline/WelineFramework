<?php

declare(strict_types=1);

namespace Weline\Theme\Taglib;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\AttributeCodeCompiler;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Theme\Service\Ui\IconRegistry;

final class IconPicker implements TaglibInterface
{
    public static function name(): string
    {
        return 'theme:icon-picker';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return [
            'id' => true,
            'name' => true,
            'value' => false,
            'placeholder' => false,
            'clearable' => false,
            'class' => false,
            'disabled' => false,
            'required' => false,
        ];
    }

    public static function callback(): callable
    {
        return static function ($tagKey, $config, $tagData, $attributes): string {
            $code = AttributeCodeCompiler::attributes($attributes);
            $clearable = in_array((string)($attributes['clearable'] ?? 'false'), ['1', 'true'], true);
            $disabled = in_array((string)($attributes['disabled'] ?? 'false'), ['1', 'true'], true);
            $required = in_array((string)($attributes['required'] ?? 'false'), ['1', 'true'], true);
            $placeholder = htmlspecialchars((string)($attributes['placeholder'] ?? __('选择图标')), ENT_QUOTES, 'UTF-8');
            $searchLabel = htmlspecialchars((string)__('搜索图标'), ENT_QUOTES, 'UTF-8');
            $clearLabel = htmlspecialchars((string)__('清空'), ENT_QUOTES, 'UTF-8');
            $listLabel = htmlspecialchars((string)__('可用图标'), ENT_QUOTES, 'UTF-8');

            $registry = ObjectManager::getInstance(IconRegistry::class);
            $options = [];
            foreach ($registry->names() as $iconName) {
                $safeName = htmlspecialchars($iconName, ENT_QUOTES, 'UTF-8');
                $options[] = '<button type="button" class="w-icon-picker__option" role="option" data-w-icon-value="'
                    . $safeName . '" aria-label="' . $safeName . '">'
                    . $registry->render($iconName, 'sm')
                    . '<span class="w-visually-hidden">' . $safeName . '</span></button>';
            }

            $disabledAttribute = $disabled ? ' disabled aria-disabled="true"' : '';
            $requiredAttribute = $required ? ' required' : '';
            $clearButton = $clearable
                ? '<button type="button" class="w-icon-picker__clear" data-w-icon-clear aria-label="' . $clearLabel . '">'
                    . $registry->render('close', 'xs') . '</button>'
                : '';

            return implode("\n", [
                '<?php ' . $code . ' ?>',
                '<div id="<?= htmlspecialchars((string)$Taglib__id, ENT_QUOTES, \'UTF-8\') ?>" class="w-icon-picker <?= htmlspecialchars((string)($Taglib__class ?? \'\'), ENT_QUOTES, \'UTF-8\') ?>" data-w-component="icon-picker">',
                '  <input type="hidden" data-w-icon-input name="<?= htmlspecialchars((string)$Taglib__name, ENT_QUOTES, \'UTF-8\') ?>" value="<?= htmlspecialchars((string)($Taglib__value ?? \'\'), ENT_QUOTES, \'UTF-8\') ?>"' . $requiredAttribute . '>',
                '  <button type="button" class="w-icon-picker__trigger" data-w-icon-trigger aria-haspopup="listbox" aria-expanded="false"' . $disabledAttribute . '>',
                '    <span class="w-icon-picker__preview" data-w-icon-preview aria-hidden="true"></span>',
                '    <span class="w-icon-picker__text" data-w-icon-text>' . $placeholder . '</span>',
                '    ' . $registry->render('chevron-down', 'xs', '', 'w-icon-picker__chevron'),
                '  </button>',
                $clearButton,
                '  <div class="w-icon-picker__panel" data-w-icon-panel data-state="closed" hidden>',
                '    <label class="w-visually-hidden" for="<?= htmlspecialchars((string)$Taglib__id, ENT_QUOTES, \'UTF-8\') ?>-search">' . $searchLabel . '</label>',
                '    <input id="<?= htmlspecialchars((string)$Taglib__id, ENT_QUOTES, \'UTF-8\') ?>-search" class="w-input" type="search" data-w-icon-search placeholder="' . $searchLabel . '" autocomplete="off">',
                '    <div class="w-icon-picker__list" data-w-icon-list role="listbox" aria-label="' . $listLabel . '">',
                implode("\n", $options),
                '    </div>',
                '    <p class="w-icon-picker__empty" data-w-icon-empty hidden>' . htmlspecialchars((string)__('没有匹配的图标'), ENT_QUOTES, 'UTF-8') . '</p>',
                '  </div>',
                '</div>',
            ]);
        };
    }

    public static function document(): string
    {
        return '<w:theme:icon-picker id="menu-icon" name="icon" value="settings" clearable="true" />';
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
}
