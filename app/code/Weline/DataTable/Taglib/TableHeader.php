<?php

declare(strict_types=1);

namespace Weline\DataTable\Taglib;

use Weline\DataTable\Helper\FrontendAccess;
use Weline\DataTable\Helper\TableContext;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Template;

final class TableHeader implements TaglibInterface
{
    public static function name(): string
    {
        return 't-header';
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
            'sortable' => false,
            'allow-frontend' => false,
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

    public static function parent(): ?string
    {
        return 'd-table';
    }

    public static function callback(): callable
    {
        return static function ($tagKey, $config, $tagData, $attributes): string {
            $attributes = TableContext::inheritTableAttributes($attributes, (string)($attributes['scope'] ?? ''), [
                'model', 'scope', 'sortable', 'allow-frontend',
            ]);
            if (!FrontendAccess::isAllowed($attributes, TableContext::getCurrentTableContext() ?? [])) {
                return FrontendAccess::deniedComment('t-header');
            }
            TableContext::validateRequiredAttributes($attributes, ['model', 'scope'], 't-header');
            $scope = (string)$attributes['scope'] . '-header';
            $context = $attributes + ['type' => 't-header', 'scope' => $scope];
            TableContext::pushChildTag('t-header', $scope, $context);
            try {
                $content = (string)($tagData[2] ?? '');
                if (trim($content) === '') {
                    $content = self::defaultFields((string)$attributes['model']);
                }
                if (str_contains($content, '<w:')) {
                    /** @var Template $template */
                    $template = w_obj(Template::class);
                    $content = ObjectManager::getInstance(\Weline\Framework\View\Taglib::class)->tagReplace($template, $content);
                }
                return '<thead class="w-datatable__head" data-scope="' . self::escape($scope) . '"><tr>' . $content . '</tr></thead>';
            } finally {
                TableContext::popTag();
            }
        };
    }

    private static function defaultFields(string $modelConfig): string
    {
        $modelClass = trim((string)preg_replace('/\s+as\s+.+$/i', '', explode(',', $modelConfig)[0] ?? ''));
        $fields = [];
        try {
            $model = w_obj($modelClass);
            foreach ((array)$model->columns() as $column) {
                $name = is_array($column) ? (string)($column['Field'] ?? $column['field'] ?? '') : (string)$column;
                if ($name === '') {
                    continue;
                }
                $label = is_array($column) ? (string)($column['Comment'] ?? $column['comment'] ?? $name) : $name;
                $fields[] = '<w:field belong="t-header" name="' . self::xml($name) . '" sortable="true">'
                    . self::escape($label) . '</w:field>';
                if (count($fields) >= 12) {
                    break;
                }
            }
        } catch (\Throwable $throwable) {
            w_log_warning('DataTable header discovery failed: ' . $throwable->getMessage());
        }
        return implode('', $fields ?: ['<w:field belong="t-header" name="id" sortable="true">ID</w:field>']);
    }

    private static function xml(string $value): string
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

    public static function document(): string
    {
        return '<w:t-header><w:field belong="t-header" name="id" sortable="true">ID</w:field></w:t-header>';
    }
}
