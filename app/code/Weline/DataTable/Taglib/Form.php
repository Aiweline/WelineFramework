<?php

declare(strict_types=1);

namespace Weline\DataTable\Taglib;

use Weline\DataTable\Helper\FrontendAccess;
use Weline\DataTable\Helper\TableContext;
use Weline\DataTable\Helper\UiAssets;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\OwnsChildCompilationInterface;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Template;

final class Form implements TaglibInterface, OwnsChildCompilationInterface
{
    public static function name(): string
    {
        return 'd-form';
    }

    public static function tag(): bool
    {
        return true;
    }

    public static function attr(): array
    {
        return [
            'model' => false,
            'scope' => false,
            'id' => false,
            'mode' => false,
            'record_id' => false,
            'title' => false,
            'form-mode' => false,
            'form-title' => false,
            'show-trigger-button' => false,
            'button-text' => false,
            'button-icon' => false,
            'allow-frontend' => false,
            'api-provider' => false,
            'dependencies' => false,
            'transaction' => false,
            'for' => false,
            'class' => false,
            'layout' => false,
            'auto_fields' => false,
            'exclude_fields' => false,
            'include_fields' => false,
        ];
    }

    public static function tag_start(): bool
    {
        return true;
    }

    public static function tag_end(): bool
    {
        return true;
    }

    public static function callback(): callable
    {
        return static function ($tagKey, $config, $tagData, $attributes): string {
            $tableContext = self::getTableContext() ?? [];
            if (!FrontendAccess::isAllowed($attributes, $tableContext)) {
                return FrontendAccess::deniedComment('d-form');
            }

            $attributes = self::inherit($attributes, $tableContext);
            $model = self::normalizeModel((string)($attributes['model'] ?? ''));
            if ($model === '') {
                throw new Exception(__('d-form标签必须指定model属性，或放在d-table内部。'));
            }

            $scope = trim((string)($attributes['scope'] ?? 'form')) ?: 'form';
            $id = self::normalizeId((string)($attributes['id'] ?? (
                !empty($tableContext['id']) ? 'form-' . $tableContext['id'] : 'w-form-' . substr(hash('sha256', $scope . $model), 0, 10)
            )));
            $attributes['id'] = $id;
            $attributes['model'] = $model;
            $attributes['scope'] = $scope;

            $formContext = [
                'type' => 'd-form',
                'scope' => $scope,
                'model' => $model,
                'attributes' => $attributes,
                'form-mode' => self::formMode($attributes['form-mode'] ?? 'modal'),
                'is-inside-table' => $tableContext !== [],
            ];
            TableContext::pushChildTag('d-form', $scope, $formContext);

            try {
                /** @var Template $template */
                $template = w_obj(Template::class);
                $content = (string)($tagData[2] ?? '');
                if (trim($content) !== '') {
                    $taglib = ObjectManager::getInstance(\Weline\Framework\View\Taglib::class);
                    $content = $taglib->tagReplace($template, $content);
                }
                return self::renderGenerated($attributes, $content, $template);
            } finally {
                TableContext::popTag();
            }
        };
    }

    /**
     * Shared renderer used by d-table for its generated add/edit form.
     *
     * @param array<string,mixed> $attributes
     */
    public static function renderGenerated(array $attributes, string $content = '', ?Template $template = null): string
    {
        $template ??= w_obj(Template::class);
        $model = self::normalizeModel((string)($attributes['model'] ?? ''));
        $scope = trim((string)($attributes['scope'] ?? 'form')) ?: 'form';
        $id = self::normalizeId((string)($attributes['id'] ?? ('w-form-' . substr(hash('sha256', $scope . $model), 0, 10))));
        $mode = ($attributes['mode'] ?? 'add') === 'edit' ? 'edit' : 'add';
        $formMode = self::formMode($attributes['form-mode'] ?? 'modal');
        $title = trim((string)($attributes['form-title'] ?? $attributes['title'] ?? ''));
        if ($title === '') {
            $title = $mode === 'edit' ? (string)__('编辑记录') : (string)__('新增记录');
        }
        $apiProvider = self::normalizeProvider((string)($attributes['api-provider'] ?? 'datatable'));
        $autoFields = array_key_exists('auto_fields', $attributes)
            ? self::toBool($attributes['auto_fields'])
            : true;
        $showTrigger = array_key_exists('show-trigger-button', $attributes)
            ? self::toBool($attributes['show-trigger-button'])
            : $formMode === 'modal' && $mode === 'add' && self::getTableContext() === null;
        $class = trim('w-form ' . self::welineClasses((string)($attributes['class'] ?? '')));
        $layout = in_array(($attributes['layout'] ?? 'vertical'), ['vertical', 'horizontal', 'grid'], true)
            ? (string)$attributes['layout']
            : 'vertical';
        $includeFields = self::fieldList($attributes['include_fields'] ?? '');
        $excludeFields = self::fieldList($attributes['exclude_fields'] ?? '');
        $modelConfig = self::parseModelConfig($model);
        $dialogId = 'w-form-dialog-' . $id;
        $config = [
            'id' => $id,
            'dialogId' => $dialogId,
            'model' => $model,
            'scope' => $scope,
            'mode' => $mode,
            'recordId' => (string)($attributes['record_id'] ?? ''),
            'formMode' => $formMode,
            'autoFields' => $autoFields,
            'excludeFields' => $excludeFields,
            'includeFields' => $includeFields,
            'apiProvider' => $apiProvider,
            'operations' => [
                'formFields' => 'formFields',
                'formRecord' => 'formRecord',
                'create' => 'create',
                'update' => 'update',
                'saveData' => 'saveData',
            ],
            'dependencies' => trim((string)($attributes['dependencies'] ?? '')),
            'transaction' => self::toBool($attributes['transaction'] ?? false),
            'modelConfig' => $modelConfig,
        ];

        $markup = self::renderMarkup(
            $id,
            $dialogId,
            $title,
            $class,
            $layout,
            $formMode,
            $content,
            $autoFields,
            $config
        );

        if ($showTrigger) {
            $buttonText = self::escape((string)($attributes['button-text'] ?? __('新增')));
            $buttonIcon = self::normalizeIcon((string)($attributes['button-icon'] ?? 'plus'));
            $markup .= '<button type="button" class="w-button" data-w-action="dialog.open" data-w-target="#'
                . self::escape($dialogId) . '"><w-icon name="' . self::escape($buttonIcon)
                . '" size="sm"></w-icon><span>' . $buttonText . '</span></button>';
        }

        return UiAssets::render($template, ['data-table-form']) . $markup;
    }

    private static function renderMarkup(
        string $id,
        string $dialogId,
        string $title,
        string $class,
        string $layout,
        string $formMode,
        string $content,
        bool $autoFields,
        array $config
    ): string {
        $idHtml = self::escape($id);
        $dialogIdHtml = self::escape($dialogId);
        $titleHtml = self::escape($title);
        $classHtml = self::escape($class);
        $layoutHtml = self::escape($layout);
        $configHtml = self::jsonAttribute($config);
        $loading = self::escape((string)__('正在加载字段…'));
        $cancel = self::escape((string)__('取消'));
        $reset = self::escape((string)__('重置'));
        $save = self::escape((string)__('保存'));
        $close = self::escape((string)__('关闭'));
        $autoFieldsHtml = $autoFields
            ? '<div class="w-datatable-form__auto-fields" data-w-datatable-form-auto><div class="w-skeleton" role="status">' . $loading . '</div></div>'
            : '';
        $form = <<<HTML
<form id="{$idHtml}" class="{$classHtml}" data-layout="{$layoutHtml}" autocomplete="off">
    <div class="w-datatable-form__fields" data-w-datatable-form-fields>{$content}{$autoFieldsHtml}</div>
    <div class="w-datatable-form__message" data-w-datatable-form-message role="status" aria-live="polite" hidden></div>
</form>
HTML;

        if ($formMode === 'inline') {
            return <<<HTML
<section class="w-card w-datatable-form" data-w-component="data-table-form" data-w-config="{$configHtml}">
    <header class="w-card__header"><h2 class="w-card__title">{$titleHtml}</h2></header>
    <div class="w-card__body">{$form}</div>
    <footer class="w-card__footer w-cluster" data-justify="end">
        <button type="reset" class="w-button" data-tone="neutral" form="{$idHtml}">{$reset}</button>
        <button type="submit" class="w-button" form="{$idHtml}">{$save}</button>
    </footer>
</section>
HTML;
        }

        return <<<HTML
<dialog id="{$dialogIdHtml}" class="w-dialog w-datatable-form" data-size="lg" data-w-component="dialog data-table-form" data-w-config="{$configHtml}" aria-labelledby="{$dialogIdHtml}-title">
    <header class="w-dialog__header">
        <h2 id="{$dialogIdHtml}-title" data-w-datatable-form-title>{$titleHtml}</h2>
        <button type="button" class="w-button" data-tone="quiet" data-size="sm" data-w-action="dialog.close" data-w-target="#{$dialogIdHtml}" aria-label="{$close}">×</button>
    </header>
    <div class="w-dialog__body">{$form}</div>
    <footer class="w-dialog__footer">
        <button type="button" class="w-button" data-tone="neutral" data-w-action="dialog.close" data-w-target="#{$dialogIdHtml}">{$cancel}</button>
        <button type="reset" class="w-button" data-tone="quiet" form="{$idHtml}">{$reset}</button>
        <button type="submit" class="w-button" form="{$idHtml}">{$save}</button>
    </footer>
</dialog>
HTML;
    }

    /** @param array<string,mixed> $attributes @param array<string,mixed> $tableContext */
    private static function inherit(array $attributes, array $tableContext): array
    {
        foreach ([
            'model',
            'scope',
            'api-provider',
            'dependencies',
            'transaction',
            'allow-frontend',
        ] as $name) {
            if ((!isset($attributes[$name]) || $attributes[$name] === '') && isset($tableContext[$name])) {
                $attributes[$name] = $tableContext[$name];
            }
        }
        if (empty($attributes['id']) && !empty($tableContext['id'])) {
            $attributes['id'] = 'form-' . $tableContext['id'];
        }
        return $attributes;
    }

    private static function getTableContext(): ?array
    {
        return TableContext::getCurrentTableContext();
    }

    /** @return list<string> */
    private static function fieldList(mixed $value): array
    {
        if (is_array($value)) {
            $values = $value;
        } else {
            $values = explode(',', (string)$value);
        }
        return array_values(array_unique(array_filter(array_map(
            static fn ($field): string => trim((string)$field),
            $values
        ))));
    }

    private static function parseModelConfig(string $modelConfig): array
    {
        $result = ['models' => [], 'main_model' => '', 'aliases' => []];
        foreach (array_filter(array_map('trim', explode(',', $modelConfig))) as $part) {
            if (preg_match('/^(.+?)\s+as\s+([A-Za-z][A-Za-z0-9_]*)$/i', $part, $match)) {
                $modelClass = trim($match[1]);
                $alias = $match[2];
            } else {
                $modelClass = trim($part);
                $alias = basename(str_replace('\\', '/', $modelClass));
            }
            if ($modelClass === '') {
                continue;
            }
            $result['models'][$alias] = $modelClass;
            $result['aliases'][$modelClass] = $alias;
            $result['main_model'] = $result['main_model'] ?: $modelClass;
        }
        return $result;
    }

    private static function formMode(mixed $value): string
    {
        return $value === 'inline' ? 'inline' : 'modal';
    }

    private static function normalizeModel(string $model): string
    {
        return str_replace('\\\\', '\\', trim($model));
    }

    private static function normalizeId(string $id): string
    {
        $id = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($id)) ?: 'w-form';
        return trim($id, '-') ?: 'w-form';
    }

    private static function normalizeProvider(string $provider): string
    {
        return preg_match('/^[a-z][a-z0-9._-]*$/', $provider) ? $provider : 'datatable';
    }

    private static function normalizeIcon(string $icon): string
    {
        $icon = strtolower(trim($icon));
        return preg_match('/^[a-z][a-z0-9-]*$/', $icon) && !preg_match('/^(?:mdi|fa|fas|far|fab|ri)-/', $icon)
            ? $icon
            : 'plus';
    }

    private static function welineClasses(string $classes): string
    {
        return implode(' ', array_values(array_filter(
            preg_split('/\s+/', trim($classes)) ?: [],
            static fn (string $class): bool => preg_match('/^w-[a-z0-9_-]+$/', $class) === 1
        )));
    }

    private static function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function jsonAttribute(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return self::escape($json === false ? '{}' : $json);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public static function tag_self_close(): bool
    {
        return false;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return false;
    }

    public static function parent(): ?string
    {
        return null;
    }

    public static function document(): string
    {
        return <<<'DOC'
Weline UI 2.0 DataTable 表单：

<w:d-form model="Weline\DataTable\Model\TestUser" scope="user-form" form-mode="modal">
    <w:field belong="d-form" name="name" required="true">姓名</w:field>
</w:d-form>

表单使用原生 Constraint Validation 和 Weline.Api，弹层由 Weline.UI.dialog 管理。
DOC;
    }
}
