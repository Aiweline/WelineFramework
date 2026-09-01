<?php

declare(strict_types=1);

namespace Weline\DataTable\Taglib;

use Weline\DataTable\Helper\FrontendAccess;
use Weline\DataTable\Helper\TableContext;
use Weline\DataTable\Helper\UiAssets;
use Weline\DataTable\Service\DataTableResourceRegistry;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\OwnsChildCompilationInterface;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Template;

final class Table implements TaglibInterface, OwnsChildCompilationInterface
{
    public static function name(): string
    {
        return 'd-table';
    }

    public static function tag(): bool
    {
        return true;
    }

    public static function attr(): array
    {
        return [
            'model' => true,
            'scope' => true,
            'join' => false,
            'id' => false,
            'class' => false,
            'editable' => false,
            'inline-edit' => false,
            'modal-edit' => false,
            'searchable' => false,
            'sortable' => false,
            'page-size' => false,
            'show-pagination' => false,
            'show-toolbar' => false,
            'show-config' => false,
            'height' => false,
            'width' => false,
            'isolate' => false,
            'dependencies' => false,
            'transaction' => false,
            'write-order' => false,
            'composite-write' => false,
            'allow-frontend' => false,
            'api-provider' => false,
            'resource' => false,
            'form' => false,
            'form-mode' => false,
            'form-title' => false,
            'sticky-actions' => false,
            'mode' => false,
            'selectable' => false,
            'show-actions' => false,
            'select-field' => false,
            'local-data-el' => false,
            'row-actions' => false,
            'filter-mode' => false,
            'filter-advanced' => false,
            'filter-collapsible' => false,
            'confirm-write' => false,
        ];
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function callback(): callable
    {
        return static function ($tagKey, $config, $tagData, $attributes): string {
            if (!FrontendAccess::isAllowed($attributes)) {
                return FrontendAccess::deniedComment('d-table');
            }

            $model = self::normalizeModel((string)($attributes['model'] ?? ''));
            $scope = trim((string)($attributes['scope'] ?? ''));
            if ($model === '') {
                throw new Exception(__('d-table标签必须指定model属性！'));
            }
            if ($scope === '') {
                throw new Exception(__('d-table标签必须指定scope属性！'));
            }

            $id = self::normalizeId((string)($attributes['id'] ?? ('datatable-' . substr(hash('sha256', $scope), 0, 10))));
            if (self::toBool($attributes['isolate'] ?? false)) {
                $id = self::normalizeId('datatable-scope-' . $scope);
            }

            $join = trim((string)($attributes['join'] ?? ''));
            $modelConfig = self::parseModelConfig($model);
            $joinConfig = self::parseJoinConfig($join);
            $tableClass = trim('w-table ' . self::welineClasses((string)($attributes['class'] ?? '')));
            $formMode = ($attributes['form-mode'] ?? 'modal') === 'inline' ? 'inline' : 'modal';
            $formId = self::normalizeId((string)($attributes['form'] ?? ('form-' . $id)));
            $apiProvider = self::normalizeProvider((string)($attributes['api-provider'] ?? 'datatable'));
            $mode = strtolower(trim((string)($attributes['mode'] ?? 'api')));
            if ($mode !== 'local') {
                $mode = 'api';
            }
            $selectable = self::toBool($attributes['selectable'] ?? false);
            $showActions = array_key_exists('show-actions', $attributes)
                ? self::toBool($attributes['show-actions'])
                : null;
            $selectField = trim((string)($attributes['select-field'] ?? 'id'));
            if ($selectField === '') {
                $selectField = 'id';
            }
            $localDataEl = trim((string)($attributes['local-data-el'] ?? ''));
            $rowActions = self::parseJsonArray((string)($attributes['row-actions'] ?? ''));
            $filterMode = strtolower(trim((string)($attributes['filter-mode'] ?? '')));
            $filterAdvanced = self::toBool($attributes['filter-advanced'] ?? false);
            if ($filterMode === 'advanced') {
                $filterAdvanced = true;
                $filterMode = '';
            }
            if (!in_array($filterMode, ['ajax', 'client', 'page'], true)) {
                $filterMode = $mode === 'local' ? 'client' : 'ajax';
            }
            $filterCollapsible = self::toBool($attributes['filter-collapsible'] ?? $filterAdvanced);
            $isBackend = FrontendAccess::isBackendRequest();
            $modelClasses = array_values($modelConfig['models']);
            $resource = trim((string)($attributes['resource'] ?? ''));
            if ($apiProvider === 'datatable' && $mode === 'api') {
                foreach ($modelClasses as $modelClass) {
                    DataTableResourceRegistry::assertRegisteredModel((string)$modelClass);
                }
                $resource = $resource ?: implode(',', array_values(array_filter(array_map(
                    static fn (string $modelClass): ?string => DataTableResourceRegistry::resourceForModel($modelClass),
                    $modelClasses
                ))));
                $baseCapabilities = DataTableResourceRegistry::capabilitiesForModels($modelClasses);
            } else {
                $baseCapabilities = [
                    'read' => true,
                    'create' => true,
                    'update' => true,
                    'delete' => true,
                    'export' => true,
                    'preferences' => true,
                    'composite_write' => count($modelClasses) > 1,
                ];
            }
            $editable = self::toBool($attributes['editable'] ?? false);
            $modalEdit = self::toBool($attributes['modal-edit'] ?? true);
            $capabilities = $mode === 'local'
                ? [
                    'read' => true,
                    'create' => false,
                    'update' => false,
                    'delete' => false,
                    'export' => false,
                    'preferences' => true,
                    'composite_write' => false,
                ]
                : [
                    'read' => (bool)$baseCapabilities['read'],
                    'create' => $isBackend && $modalEdit && (bool)$baseCapabilities['create'],
                    'update' => $isBackend && ($editable || $modalEdit) && (bool)$baseCapabilities['update'],
                    'delete' => $isBackend && ($editable || $modalEdit) && (bool)$baseCapabilities['delete'],
                    'export' => (bool)$baseCapabilities['export'],
                    'preferences' => $isBackend && (bool)$baseCapabilities['preferences'],
                    'composite_write' => $isBackend && (bool)$baseCapabilities['composite_write'],
                ];
            $compositeWrite = self::toBool($attributes['composite-write'] ?? $capabilities['composite_write']);
            $confirmWrite = self::toBool($attributes['confirm-write'] ?? $compositeWrite);
            $transaction = array_key_exists('transaction', $attributes)
                ? self::toBool($attributes['transaction'])
                : count($modelClasses) > 1;
            $writeOrder = trim((string)($attributes['write-order'] ?? ''));

            $tableContext = [
                'id' => $id,
                'model' => $model,
                'scope' => $scope,
                'join' => $join,
                'model_config' => $modelConfig,
                'join_config' => $joinConfig,
                'sticky-actions' => self::toBool($attributes['sticky-actions'] ?? true),
                'mode' => $mode,
                'selectable' => $selectable,
                'show-actions' => $showActions,
                'select-field' => $selectField,
                'local-data-el' => $localDataEl,
                'row-actions' => $rowActions,
                'editable' => $editable,
                'inline-edit' => self::toBool($attributes['inline-edit'] ?? true),
                'modal-edit' => $modalEdit,
                'searchable' => self::toBool($attributes['searchable'] ?? true),
                'sortable' => self::toBool($attributes['sortable'] ?? true),
                'page-size' => max(1, min(100, (int)($attributes['page-size'] ?? 20))),
                'show-pagination' => self::toBool($attributes['show-pagination'] ?? true),
                'show-toolbar' => self::toBool($attributes['show-toolbar'] ?? true),
                'show-config' => self::toBool($attributes['show-config'] ?? true),
                'allow-frontend' => self::toBool($attributes['allow-frontend'] ?? false),
                'dependencies' => trim((string)($attributes['dependencies'] ?? '')),
                'transaction' => $transaction,
                'api-provider' => $apiProvider,
                'form-mode' => $formMode,
                'form-title' => trim((string)($attributes['form-title'] ?? '')),
                'resource' => $resource,
                'filter-mode' => $filterMode,
                'filter-advanced' => $filterAdvanced,
                'filter-collapsible' => $filterCollapsible,
                'confirm-write' => $confirmWrite,
                'composite-write' => $compositeWrite,
                'write-order' => $writeOrder,
                'capabilities' => $capabilities,
            ];
            TableContext::setTableContext($scope, $tableContext);

            try {
                /** @var Template $template */
                $template = w_obj(Template::class);
                $taglib = ObjectManager::getInstance(\Weline\Framework\View\Taglib::class);
                $rawContent = (string)($tagData[2] ?? '');
                [$rawContent, $nestedFormSource, $nestedFormId] = self::extractNestedForm($rawContent, $formId);
                $manual = trim($rawContent) !== '';
                $rawContent = $manual
                    ? self::ensureRequiredTags($rawContent)
                    : self::generateDefaultTableStructure($modelConfig, $filterAdvanced, $filterCollapsible, $filterMode);
                $content = $taglib->tagReplace($template, $rawContent);

                if ($nestedFormSource !== '') {
                    $formId = $nestedFormId;
                    $formHtml = $taglib->tagReplace($template, $nestedFormSource);
                } elseif (!$capabilities['create'] && !$capabilities['update']) {
                    $formHtml = '';
                } else {
                    $formHtml = Form::renderGenerated([
                        'id' => $formId,
                        'model' => $model,
                        'scope' => $scope,
                        'mode' => 'add',
                        'form-mode' => $formMode,
                        'title' => $tableContext['form-title'],
                        'api-provider' => $apiProvider,
                        'resource' => $resource,
                        'dependencies' => $tableContext['dependencies'],
                        'transaction' => $tableContext['transaction'],
                        'write-order' => $writeOrder,
                        'composite-write' => $compositeWrite,
                        'confirm-write' => $confirmWrite,
                        'capabilities' => $capabilities,
                        'show-trigger-button' => false,
                        'allow-frontend' => $tableContext['allow-frontend'],
                    ]);
                }

                $rootHeight = self::cssLength((string)($attributes['height'] ?? ''));
                $rootWidth = self::cssLength((string)($attributes['width'] ?? ''));
                $resolvedShowActions = $showActions;
                if ($resolvedShowActions === null) {
                    $resolvedShowActions = $tableContext['editable'] || $tableContext['modal-edit'] || $rowActions !== [];
                }
                $uiConfig = [
                    'id' => $id,
                    'model' => $model,
                    'scope' => $scope,
                    'join' => $join,
                    'modelConfig' => $modelConfig,
                    'joinConfig' => $joinConfig,
                    'apiProvider' => $apiProvider,
                    'resource' => $resource,
                    'mode' => $mode,
                    'selectable' => $selectable,
                    'showActions' => (bool)$resolvedShowActions,
                    'selectField' => $selectField,
                    'localDataEl' => $localDataEl,
                    'rowActions' => $rowActions,
                    'operations' => [
                        'data' => 'data',
                        'fields' => 'fields',
                        'metadata' => 'metadata',
                        'saveConfig' => 'saveConfig',
                        'clearConfig' => 'clearConfig',
                        'saveData' => 'saveData',
                        'deleteData' => 'deleteData',
                        'exportData' => 'exportData',
                        'previewWrite' => 'previewWrite',
                        'executeWrite' => 'executeWrite',
                    ],
                    'dependencies' => $tableContext['dependencies'],
                    'transaction' => $tableContext['transaction'],
                    'editable' => $tableContext['editable'],
                    'inlineEdit' => $tableContext['inline-edit'],
                    'modalEdit' => $tableContext['modal-edit'],
                    'searchable' => $tableContext['searchable'],
                    'sortable' => $tableContext['sortable'],
                    'pageSize' => $tableContext['page-size'],
                    'showPagination' => $tableContext['show-pagination'],
                    'showToolbar' => $tableContext['show-toolbar'],
                    'showConfig' => $tableContext['show-config'],
                    'stickyActions' => $tableContext['sticky-actions'],
                    'autoGenerated' => !$manual,
                    'formId' => $formId,
                    'filterMode' => $filterMode,
                    'filterAdvanced' => $filterAdvanced,
                    'filterCollapsible' => $filterCollapsible,
                    'confirmWrite' => $confirmWrite,
                    'compositeWrite' => $compositeWrite,
                    'writeOrder' => $writeOrder,
                    'capabilities' => $capabilities,
                ];

                return UiAssets::render($template)
                    . $formHtml
                    . self::renderTable(
                        $id,
                        $tableClass,
                        $content,
                        $uiConfig,
                        $rootHeight,
                        $rootWidth,
                        $tableContext['show-toolbar'],
                        $tableContext['show-config'],
                        $formId,
                        !$manual,
                        (bool)$tableContext['sticky-actions'],
                        $selectable
                    );
            } finally {
                TableContext::popTag();
            }
        };
    }

    private static function renderTable(
        string $id,
        string $tableClass,
        string $content,
        array $config,
        string $rootHeight,
        string $rootWidth,
        bool $showToolbar,
        bool $showConfig,
        string $formId,
        bool $autoGenerated,
        bool $stickyActions = true,
        bool $selectable = false
    ): string {
        $idHtml = self::escape($id);
        $rootId = 'w-datatable-' . $idHtml;
        $configHtml = self::jsonAttribute($config);
        $classHtml = self::escape($tableClass);
        $stickyAttr = $stickyActions ? ' data-w-sticky-end' : '';
        $stickyAttr .= $selectable ? ' data-w-sticky-start' : '';
        $dimensionHtml = $rootHeight === ''
            ? ''
            : ' data-w-datatable-height="' . self::escape($rootHeight) . '"';
        $dimensionHtml .= $rootWidth === ''
            ? ''
            : ' data-w-datatable-width="' . self::escape($rootWidth) . '"';
        $toolbarHidden = $showToolbar ? '' : ' hidden';
        $capabilities = is_array($config['capabilities'] ?? null) ? $config['capabilities'] : [];
        $canCreate = (bool)($capabilities['create'] ?? false);
        $canExport = (bool)($capabilities['export'] ?? false);
        // Field visibility and column composition are browser-local display preferences.
        $canConfigure = $showConfig;
        $configButton = $canConfigure ? self::renderConfigButton($idHtml) : '';
        $autoBadge = $autoGenerated
            ? '<span class="w-badge" data-tone="info">' . self::escape((string)__('自动生成')) . '</span>'
            : '';
        $formDialogId = 'w-form-dialog-' . self::escape($formId);
        $dialogId = 'w-datatable-config-' . $idHtml;
        $columnsPanel = 'w-datatable-columns-' . $idHtml;
        $filtersPanel = 'w-datatable-filters-' . $idHtml;
        $add = self::escape((string)__('新增'));
        $refresh = self::escape((string)__('刷新'));
        $export = self::escape((string)__('导出'));
        $total = self::escape((string)__('总记录'));
        $visible = self::escape((string)__('当前显示'));
        $fieldSettings = self::escape((string)__('字段配置'));
        $close = self::escape((string)__('关闭'));
        $columns = self::escape((string)__('显示字段'));
        $filters = self::escape((string)__('筛选字段'));
        $reset = self::escape((string)__('重置'));
        $cancel = self::escape((string)__('取消'));
        $save = self::escape((string)__('保存'));
        $search = self::escape((string)__('搜索表格'));
        $addButton = $canCreate
            ? '<button type="button" class="w-button" data-size="sm" data-w-datatable-action="form.open" data-w-target="#' . $formDialogId . '"><w-icon name="plus" size="sm"></w-icon><span>' . $add . '</span></button>'
            : '';
        $exportMenu = $canExport
            ? '<details class="w-menu-root w-datatable__menu"><summary class="w-button" data-tone="neutral" data-size="sm"><w-icon name="download" size="sm"></w-icon><span>' . $export . '</span></summary><div class="w-menu w-menu__panel"><button type="button" class="w-menu__item" data-w-datatable-action="export" data-format="excel">Excel</button><button type="button" class="w-menu__item" data-w-datatable-action="export" data-format="csv">CSV</button><button type="button" class="w-menu__item" data-w-datatable-action="export" data-format="json">JSON</button></div></details>'
            : '';
        $searchForm = !empty($config['searchable'])
            ? '<form class="w-datatable__search" role="search" method="get" data-w-datatable-search-form><label class="w-visually-hidden" for="w-datatable-search-' . $idHtml . '">' . $search . '</label><w-icon name="search" size="sm"></w-icon><input id="w-datatable-search-' . $idHtml . '" class="w-input" type="search" name="search" autocomplete="off" placeholder="' . $search . '" data-w-datatable-search></form>'
            : '';

        return <<<HTML
<section id="{$rootId}" class="w-datatable" data-w-component="data-table" data-w-config="{$configHtml}"{$dimensionHtml}>
    <header class="w-datatable__toolbar"{$toolbarHidden}>
        <div class="w-cluster" data-align="center">
            <div class="w-datatable__title"><w-icon name="table" size="sm"></w-icon>{$autoBadge}</div>
            {$addButton}
            {$configButton}
            <button type="button" class="w-button" data-tone="neutral" data-size="sm" data-w-datatable-action="reload"><w-icon name="refresh" size="sm"></w-icon><span>{$refresh}</span></button>
        </div>
        {$searchForm}
        <div class="w-cluster" data-align="center">
            {$exportMenu}
            <dl class="w-datatable__stats" aria-live="polite">
                <div><dt>{$total}</dt><dd data-w-datatable-total>-</dd></div>
                <div><dt>{$visible}</dt><dd data-w-datatable-visible>-</dd></div>
            </dl>
        </div>
    </header>
    <div class="w-datatable__status" data-w-datatable-status role="status" aria-live="polite" hidden></div>
    <div class="w-table-wrap w-datatable__viewport"><table class="{$classHtml}" id="{$idHtml}"{$stickyAttr}>{$content}</table></div>
</section>
<dialog id="{$dialogId}" class="w-dialog w-datatable__config" data-size="lg" data-w-component="dialog" aria-labelledby="{$dialogId}-title">
    <header class="w-dialog__header">
        <h2 id="{$dialogId}-title"><w-icon name="settings" size="sm"></w-icon> {$fieldSettings}</h2>
        <button type="button" class="w-button" data-tone="quiet" data-size="sm" data-w-action="dialog.close" data-w-target="#{$dialogId}" aria-label="{$close}">×</button>
    </header>
    <div class="w-dialog__body">
        <div class="w-tabs" data-w-component="tabs">
            <div class="w-tabs__list" role="tablist" aria-label="{$fieldSettings}">
                <button type="button" class="w-tabs__tab" role="tab" id="{$columnsPanel}-tab" aria-controls="{$columnsPanel}" aria-selected="true">{$columns}</button>
                <button type="button" class="w-tabs__tab" role="tab" id="{$filtersPanel}-tab" aria-controls="{$filtersPanel}" aria-selected="false" tabindex="-1">{$filters}</button>
            </div>
            <section class="w-tabs__panel" id="{$columnsPanel}" role="tabpanel" aria-labelledby="{$columnsPanel}-tab"><div class="w-transfer" data-w-datatable-fields="display"></div></section>
            <section class="w-tabs__panel" id="{$filtersPanel}" role="tabpanel" aria-labelledby="{$filtersPanel}-tab" hidden><div class="w-transfer" data-w-datatable-fields="filter"></div></section>
        </div>
    </div>
    <footer class="w-dialog__footer">
        <button type="button" class="w-button" data-tone="quiet" data-w-datatable-action="config.clear">{$reset}</button>
        <button type="button" class="w-button" data-tone="neutral" data-w-action="dialog.close" data-w-target="#{$dialogId}">{$cancel}</button>
        <button type="button" class="w-button" data-w-datatable-action="config.save">{$save}</button>
    </footer>
</dialog>
HTML;
    }

    private static function renderConfigButton(string $id): string
    {
        $label = self::escape((string)__('字段配置'));
        return '<button type="button" class="w-button" data-tone="neutral" data-size="sm" data-w-datatable-action="config.open" data-w-target="#w-datatable-config-'
            . $id . '"><w-icon name="settings" size="sm"></w-icon><span>' . $label . '</span></button>';
    }

    /** @return array{0:string,1:string,2:string} */
    private static function extractNestedForm(string $content, string $defaultId): array
    {
        if (!preg_match('/<(?:w:)?d-form\b[^>]*>.*?<\/(?:w:)?d-form>/is', $content, $match)) {
            return [$content, '', $defaultId];
        }
        $source = (string)$match[0];
        $id = $defaultId;
        if (preg_match('/\bid=["\']([^"\']+)["\']/i', $source, $idMatch)) {
            $id = self::normalizeId((string)$idMatch[1]);
        }
        return [str_replace($source, '', $content), $source, $id];
    }

    private static function ensureRequiredTags(string $content): string
    {
        if (!preg_match('/<(?:w:)?t-header\b/i', $content)) {
            $content = '<w:t-header></w:t-header>' . $content;
        }
        if (!preg_match('/<(?:w:)?t-filter\b/i', $content)) {
            $content .= '<w:t-filter></w:t-filter>';
        }
        if (!preg_match('/<(?:w:)?t-body\b/i', $content)) {
            $content .= '<w:t-body></w:t-body>';
        }
        if (!preg_match('/<(?:w:)?t-footer\b/i', $content)) {
            $content .= '<w:t-footer></w:t-footer>';
        }
        return $content;
    }

    private static function generateDefaultTableStructure(
        array $modelConfig,
        bool $filterAdvanced,
        bool $filterCollapsible,
        string $filterMode
    ): string
    {
        $fields = self::modelFields($modelConfig);
        if ($fields === []) {
            $fields[] = ['name' => 'id', 'label' => 'ID', 'type' => 'number'];
        }

        $header = '';
        foreach (array_slice($fields, 0, 12) as $field) {
            $name = self::xmlAttribute((string)$field['name']);
            $header .= '<w:field belong="t-header" name="' . $name . '" sortable="true">'
                . self::escape((string)$field['label']) . '</w:field>';
        }

        $filter = '';
        $filterFields = array_values(array_filter($fields, static function (array $field): bool {
            $name = strtolower((string)$field['name']);
            return in_array($name, ['id', 'name', 'title', 'email', 'status', 'type'], true);
        }));
        foreach (array_slice($filterFields ?: $fields, 0, 4) as $field) {
            $name = self::xmlAttribute((string)$field['name']);
            $type = self::xmlAttribute(self::filterType((string)$field['name'], (string)$field['type']));
            $filter .= '<w:field belong="t-filter" name="' . $name . '" type="' . $type . '"></w:field>';
        }

        return '<w:t-header>' . $header . '</w:t-header><w:t-filter advanced="'
            . ($filterAdvanced ? 'true' : 'false') . '" collapsible="'
            . ($filterCollapsible ? 'true' : 'false') . '" filter-mode="'
            . self::xmlAttribute($filterMode) . '">' . $filter
            . '</w:t-filter><w:t-body></w:t-body><w:t-footer></w:t-footer>';
    }

    /** @return list<array{name:string,label:string,type:string}> */
    private static function modelFields(array $modelConfig): array
    {
        $result = [];
        foreach (($modelConfig['models'] ?? []) as $alias => $modelClass) {
            try {
                $model = w_obj((string)$modelClass);
                $columns = method_exists($model, 'columns') ? (array)$model->columns() : [];
                foreach ($columns as $column) {
                    $name = is_array($column)
                        ? (string)($column['Field'] ?? $column['field'] ?? $column['name'] ?? '')
                        : (string)$column;
                    if ($name === '') {
                        continue;
                    }
                    $qualified = count($modelConfig['models'] ?? []) > 1 ? $alias . '.' . $name : $name;
                    $result[] = [
                        'name' => $qualified,
                        'label' => is_array($column) ? (string)($column['Comment'] ?? $column['comment'] ?? $name) : $name,
                        'type' => is_array($column) ? (string)($column['Type'] ?? $column['type'] ?? 'text') : 'text',
                    ];
                }
            } catch (\Throwable $throwable) {
                w_log_warning('DataTable field discovery failed for ' . $modelClass . ': ' . $throwable->getMessage());
            }
        }
        return $result;
    }

    private static function filterType(string $name, string $databaseType): string
    {
        $name = strtolower($name);
        $databaseType = strtolower($databaseType);
        if (in_array($name, ['status', 'state', 'type'], true)) {
            return 'select';
        }
        if (str_contains($databaseType, 'date') || str_contains($databaseType, 'time')) {
            return 'date';
        }
        if (str_contains($databaseType, 'int') || str_contains($databaseType, 'numeric') || str_contains($databaseType, 'decimal')) {
            return 'number';
        }
        if (str_contains($name, 'email')) {
            return 'email';
        }
        return 'search';
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

    private static function parseJoinConfig(string $joinConfig): array
    {
        $result = ['joins' => []];
        foreach (array_filter(array_map('trim', explode(',', $joinConfig))) as $part) {
            if (preg_match('/^(left|right|inner|outer)\s+(.+?)\s+on\s+(.+)$/i', $part, $match)) {
                $result['joins'][] = [
                    'type' => strtoupper($match[1]),
                    'table' => trim($match[2]),
                    'condition' => trim($match[3]),
                ];
                continue;
            }
            if (preg_match('/^(left|right|inner|outer)\s+(.+)$/i', $part, $match)) {
                $result['joins'][] = [
                    'type' => strtoupper($match[1]),
                    'table' => '',
                    'condition' => trim($match[2]),
                ];
                continue;
            }
            if (preg_match('/^(.+?)\s+on\s+(.+)$/i', $part, $match)) {
                $result['joins'][] = [
                    'type' => 'INNER',
                    'table' => trim($match[1]),
                    'condition' => trim($match[2]),
                ];
                continue;
            }
            if (preg_match('/^[A-Za-z_][\w]*\.[\w]+\s*=\s*[A-Za-z_][\w]*\.[\w]+$/', $part)) {
                $result['joins'][] = [
                    'type' => 'INNER',
                    'table' => '',
                    'condition' => $part,
                ];
            }
        }
        return $result;
    }

    private static function normalizeModel(string $model): string
    {
        return str_replace('\\\\', '\\', trim($model));
    }

    private static function normalizeId(string $id): string
    {
        $id = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($id)) ?: 'datatable';
        return trim($id, '-') ?: 'datatable';
    }

    private static function normalizeProvider(string $provider): string
    {
        return preg_match('/^[a-z][a-z0-9._-]*$/', $provider) ? $provider : 'datatable';
    }

    private static function welineClasses(string $classes): string
    {
        return implode(' ', array_values(array_filter(
            preg_split('/\s+/', trim($classes)) ?: [],
            static fn (string $class): bool => preg_match('/^w-[a-z0-9_-]+$/', $class) === 1
        )));
    }

    private static function cssLength(string $value): string
    {
        $value = trim($value);
        return preg_match('/^(?:0|\d+(?:\.\d+)?(?:px|rem|em|%|vh|vw|ch))$/', $value) ? $value : '';
    }

    private static function parseJsonArray(string $raw): array
    {
        $raw = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }
        $list = array_is_list($decoded) ? $decoded : [];
        $result = [];
        foreach ($list as $item) {
            if (is_array($item)) {
                $result[] = $item;
            }
        }
        return $result;
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

    private static function xmlAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
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
Weline UI 2.0 DataTable:

<w:d-table model="Weline\DataTable\Model\TestUser" scope="users" editable="true">
    <w:t-header>
        <w:field belong="t-header" name="id" sortable="true">ID</w:field>
        <w:field belong="t-header" name="name" sortable="true">名称</w:field>
    </w:t-header>
    <w:t-filter>
        <w:field belong="t-filter" name="name" type="search"></w:field>
    </w:t-filter>
</w:d-table>

交互由 Weline.UI 的 data-table / data-table-form 组件提供，不暴露全局管理器。
DOC;
    }
}
