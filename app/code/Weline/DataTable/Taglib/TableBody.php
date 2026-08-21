<?php

declare(strict_types=1);

namespace Weline\DataTable\Taglib;

use Weline\DataTable\Helper\FrontendAccess;
use Weline\DataTable\Helper\TableContext;
use Weline\Framework\Taglib\TaglibInterface;

final class TableBody implements TaglibInterface
{
    public static function name(): string { return 't-body'; }
    public static function tag(): bool { return true; }
    public static function attr(): array
    {
        return ['model' => false, 'scope' => false, 'editable' => false, 'allow-frontend' => false];
    }
    public static function tag_start(): bool { return true; }
    public static function tag_end(): bool { return true; }
    public static function parent(): ?string { return 'd-table'; }

    public static function callback(): callable
    {
        return static function ($tagKey, $config, $tagData, $attributes): string {
            $attributes = TableContext::inheritTableAttributes($attributes, (string)($attributes['scope'] ?? ''), [
                'model', 'scope', 'editable', 'allow-frontend',
            ]);
            if (!FrontendAccess::isAllowed($attributes, TableContext::getCurrentTableContext() ?? [])) {
                return FrontendAccess::deniedComment('t-body');
            }
            TableContext::validateRequiredAttributes($attributes, ['model', 'scope'], 't-body');
            $scope = (string)$attributes['scope'] . '-body';
            $content = (string)($tagData[2] ?? '');
            return '<tbody class="w-datatable__body" data-scope="'
                . htmlspecialchars($scope, ENT_QUOTES, 'UTF-8') . '">' . $content . '</tbody>';
        };
    }

    public static function tag_self_close(): bool { return false; }
    public static function tag_self_close_with_attrs(): bool { return false; }
    public static function document(): string { return '<w:t-body></w:t-body>'; }
}
