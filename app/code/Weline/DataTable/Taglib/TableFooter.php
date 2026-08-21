<?php

declare(strict_types=1);

namespace Weline\DataTable\Taglib;

use Weline\DataTable\Helper\FrontendAccess;
use Weline\DataTable\Helper\TableContext;
use Weline\Framework\Taglib\TaglibInterface;

final class TableFooter implements TaglibInterface
{
    public static function name(): string { return 't-footer'; }
    public static function tag(): bool { return true; }
    public static function attr(): array
    {
        return [
            'model' => false,
            'scope' => false,
            'show-pagination' => false,
            'show-summary' => false,
            'allow-frontend' => false,
        ];
    }
    public static function tag_start(): bool { return true; }
    public static function tag_end(): bool { return true; }
    public static function parent(): ?string { return 'd-table'; }

    public static function callback(): callable
    {
        return static function ($tagKey, $config, $tagData, $attributes): string {
            $attributes = TableContext::inheritTableAttributes($attributes, (string)($attributes['scope'] ?? ''), [
                'model', 'scope', 'show-pagination', 'allow-frontend',
            ]);
            if (!FrontendAccess::isAllowed($attributes, TableContext::getCurrentTableContext() ?? [])) {
                return FrontendAccess::deniedComment('t-footer');
            }
            TableContext::validateRequiredAttributes($attributes, ['model', 'scope'], 't-footer');
            $scope = (string)$attributes['scope'] . '-footer';
            $summaryHidden = filter_var($attributes['show-summary'] ?? true, FILTER_VALIDATE_BOOLEAN) ? '' : ' hidden';
            $paginationHidden = filter_var($attributes['show-pagination'] ?? true, FILTER_VALIDATE_BOOLEAN) ? '' : ' hidden';
            $content = (string)($tagData[2] ?? '');
            return '<tfoot class="w-datatable__footer" data-scope="' . htmlspecialchars($scope, ENT_QUOTES, 'UTF-8') . '"><tr><td colspan="100">'
                . '<div class="w-datatable__footer-content"><p class="w-datatable__summary" data-w-datatable-summary'
                . $summaryHidden . '></p><div class="w-datatable__footer-slot">' . $content . '</div>'
                . '<nav class="w-pagination" data-w-datatable-pagination aria-label="'
                . htmlspecialchars((string)__('分页'), ENT_QUOTES, 'UTF-8') . '"' . $paginationHidden . '></nav></div>'
                . '</td></tr></tfoot>';
        };
    }

    public static function tag_self_close(): bool { return false; }
    public static function tag_self_close_with_attrs(): bool { return false; }
    public static function document(): string { return '<w:t-footer></w:t-footer>'; }
}
