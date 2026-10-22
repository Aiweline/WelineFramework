<?php

declare(strict_types=1);

namespace Weline\Feed\Service\Channel;

use Weline\Feed\Api\Data\FeedDefinition;
use Weline\Feed\Api\Data\FeedItem;

/**
 * CSV or TSV product catalog. Column names are the platform's public field names.
 */
abstract class DelimitedCatalogChannel extends AbstractFeedChannel
{
    abstract protected function separator(): string;

    /**
     * @return list<string>
     */
    abstract protected function columns(): array;

    abstract protected function cell(FeedItem $item, FeedDefinition $definition, string $column): string;

    public function contentType(): string
    {
        return $this->separator() === "\t"
            ? 'text/tab-separated-values; charset=utf-8'
            : 'text/csv; charset=utf-8';
    }

    public function filenameSuffix(): string
    {
        return $this->separator() === "\t" ? 'tsv' : 'csv';
    }

    protected function optionalCategoryColumn(): string
    {
        $shared = ['meta_catalog', 'tiktok_catalog', 'pinterest_catalog', 'snapchat_catalog', 'criteo'];

        return in_array($this->channelCode(), $shared, true) ? 'google_product_category' : '';
    }

    protected function build(FeedDefinition $definition, array $rows): string
    {
        $separator = $this->separator();
        $columns = $this->columns();
        $extra = $this->optionalCategoryColumn();
        $useExtra = $extra !== '' && $this->rowsHaveCategory($rows);
        if ($useExtra) {
            $columns[] = $extra;
        }
        $lines = [implode($separator, $columns)];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($this->columns() as $column) {
                $cells[] = $this->escape($this->cell($row, $definition, $column), $separator);
            }
            if ($useExtra) {
                $cells[] = $this->escape(trim((string)$row->channelCategory), $separator);
            }
            $lines[] = implode($separator, $cells);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<FeedItem> $rows
     */
    private function rowsHaveCategory(array $rows): bool
    {
        foreach ($rows as $row) {
            if (trim((string)$row->channelCategory) !== '') {
                return true;
            }
        }

        return false;
    }

    protected function priced(FeedItem $item, FeedDefinition $definition): string
    {
        $currency = trim($item->currency) !== '' ? $item->currency : $definition->currency;

        return $this->money($item->priceMinor, $currency, true);
    }

    private function escape(string $value, string $separator): string
    {
        if (!str_contains($value, $separator) && !str_contains($value, '"') && !str_contains($value, "\n")) {
            return $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }
}
