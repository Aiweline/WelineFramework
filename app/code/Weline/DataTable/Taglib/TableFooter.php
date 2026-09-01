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
            'page-size' => false,
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
                'model', 'scope', 'show-pagination', 'page-size', 'allow-frontend',
            ]);
            if (!FrontendAccess::isAllowed($attributes, TableContext::getCurrentTableContext() ?? [])) {
                return FrontendAccess::deniedComment('t-footer');
            }
            TableContext::validateRequiredAttributes($attributes, ['model', 'scope'], 't-footer');
            $scope = (string)$attributes['scope'] . '-footer';
            $summaryHidden = filter_var($attributes['show-summary'] ?? true, FILTER_VALIDATE_BOOLEAN) ? '' : ' hidden';
            $paginationHidden = filter_var($attributes['show-pagination'] ?? true, FILTER_VALIDATE_BOOLEAN) ? '' : ' hidden';
            $pageSize = max(1, min(100, (int)($attributes['page-size'] ?? 20)));
            $pageSizeLabel = htmlspecialchars((string)__('每页显示'), ENT_QUOTES, 'UTF-8');
            $options = '';
            foreach ([10, 20, 50, 100] as $size) {
                $selected = $size === $pageSize ? ' selected' : '';
                $options .= '<option value="' . $size . '"' . $selected . '>' . $size . '</option>';
            }
            $content = (string)($tagData[2] ?? '');
            return '<tfoot class="w-datatable__footer" data-scope="' . htmlspecialchars($scope, ENT_QUOTES, 'UTF-8') . '"><tr><td colspan="100">'
                . '<div class="w-datatable__footer-content"><p class="w-datatable__summary" data-w-datatable-summary'
                . $summaryHidden . '></p><div class="w-datatable__footer-slot">' . $content . '</div>'
                . '<div class="w-datatable__pagination-controls"' . $paginationHidden . '><label class="w-datatable__page-size">'
                . '<span>' . $pageSizeLabel . '</span><output class="w-datatable__page-size-value" data-w-datatable-page-size-value>' . $pageSize . '</output>'
                . '<select class="w-select" data-w-datatable-page-size aria-label="' . $pageSizeLabel . '">' . $options . '</select></label>'
                . '<nav class="w-pagination" data-w-datatable-pagination aria-label="'
                . htmlspecialchars((string)__('分页'), ENT_QUOTES, 'UTF-8') . '"></nav></div></div>'
                . '</td></tr></tfoot>';
        };
    }

    public static function tag_self_close(): bool { return false; }
    public static function tag_self_close_with_attrs(): bool { return false; }
    public static function document(): string { return '<w:t-footer></w:t-footer>'; }
}
