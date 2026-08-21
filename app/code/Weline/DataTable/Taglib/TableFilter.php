<?php

declare(strict_types=1);

namespace Weline\DataTable\Taglib;

use Weline\DataTable\Helper\FrontendAccess;
use Weline\DataTable\Helper\TableContext;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Template;

final class TableFilter implements TaglibInterface
{
    public static function name(): string
    {
        return 't-filter';
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
            'searchable' => false,
            'advanced' => false,
            'collapsible' => false,
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
                'model', 'scope', 'searchable', 'allow-frontend',
            ]);
            if (!FrontendAccess::isAllowed($attributes, TableContext::getCurrentTableContext() ?? [])) {
                return FrontendAccess::deniedComment('t-filter');
            }
            TableContext::validateRequiredAttributes($attributes, ['model', 'scope'], 't-filter');
            $scope = (string)$attributes['scope'] . '-filter';
            $context = $attributes + ['type' => 't-filter', 'scope' => $scope];
            TableContext::pushChildTag('t-filter', $scope, $context);
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
                $hidden = filter_var($attributes['searchable'] ?? true, FILTER_VALIDATE_BOOLEAN) ? '' : ' hidden';
                $search = self::escape((string)__('筛选'));
                $reset = self::escape((string)__('重置'));
                return '<tbody class="w-datatable__filters" data-scope="' . self::escape($scope) . '"' . $hidden . '><tr><td colspan="100">'
                    . '<form class="w-datatable__filter" data-w-datatable-filter><div class="w-cluster">' . $content
                    . '<button type="submit" class="w-button" data-size="sm"><w-icon name="search" size="sm"></w-icon><span>' . $search . '</span></button>'
                    . '<button type="reset" class="w-button" data-tone="neutral" data-size="sm">' . $reset . '</button>'
                    . '</div></form></td></tr></tbody>';
            } finally {
                TableContext::popTag();
            }
        };
    }

    private static function defaultFields(string $modelConfig): string
    {
        $modelClass = trim((string)preg_replace('/\s+as\s+.+$/i', '', explode(',', $modelConfig)[0] ?? ''));
        $preferred = ['name', 'title', 'email', 'status', 'type', 'id'];
        $available = [];
        try {
            $model = w_obj($modelClass);
            foreach ((array)$model->columns() as $column) {
                $name = is_array($column) ? (string)($column['Field'] ?? $column['field'] ?? '') : (string)$column;
                if ($name !== '') {
                    $available[$name] = is_array($column) ? (string)($column['Type'] ?? $column['type'] ?? '') : '';
                }
            }
        } catch (\Throwable $throwable) {
            w_log_warning('DataTable filter discovery failed: ' . $throwable->getMessage());
        }

        $fields = [];
        foreach ($preferred as $name) {
            if (!array_key_exists($name, $available)) {
                continue;
            }
            $type = self::type($name, $available[$name]);
            $fields[] = '<w:field belong="t-filter" name="' . self::xml($name) . '" type="' . self::xml($type) . '"></w:field>';
            if (count($fields) >= 4) {
                break;
            }
        }
        return implode('', $fields);
    }

    private static function type(string $name, string $databaseType): string
    {
        $databaseType = strtolower($databaseType);
        return match (true) {
            in_array($name, ['status', 'state', 'type'], true) => 'select',
            str_contains($databaseType, 'date'), str_contains($databaseType, 'time') => 'date',
            str_contains($databaseType, 'int'), str_contains($databaseType, 'numeric') => 'number',
            $name === 'email' => 'email',
            default => 'search',
        };
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
        return '<w:t-filter><w:field belong="t-filter" name="name" type="search"></w:field></w:t-filter>';
    }
}
