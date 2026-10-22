<?php

declare(strict_types=1);

namespace Weline\Feed\Service\Channel;

use Weline\Feed\Api\Data\FeedDefinition;
use Weline\Feed\Api\Data\FeedDocument;
use Weline\Feed\Api\Data\FeedItem;
use Weline\Feed\Api\FeedChannelInterface;

/**
 * Shared skip-and-diagnose path. A blank body is reserved for "every row was invalid".
 */
abstract class AbstractFeedChannel implements FeedChannelInterface
{
    abstract public function displayName(): string;

    abstract public function connectionDescription(): string;

    /**
     * @param iterable<FeedItem> $items
     */
    public function render(FeedDefinition $definition, iterable $items): FeedDocument
    {
        $rows = [];
        $skipped = 0;
        foreach ($items as $item) {
            if (!$item instanceof FeedItem) {
                continue;
            }
            if ($this->reject($item)) {
                $skipped++;
                continue;
            }
            $rows[] = $item;
        }
        if ($rows === [] && $skipped > 0) {
            return new FeedDocument($this->contentType(), $this->filenameSuffix(), '', 'items_skipped', 0);
        }

        $diagnostic = $skipped > 0 ? 'items_skipped' : '';

        return new FeedDocument(
            $this->contentType(),
            $this->filenameSuffix(),
            $this->build($definition, $rows),
            $this->documentDiagnostic($definition, $diagnostic),
            count($rows),
        );
    }

    public function renderSample(FeedDefinition $definition): FeedDocument
    {
        return $this->render($definition, [$this->sampleItem($definition)]);
    }

    /**
     * @param list<FeedItem> $rows
     */
    abstract protected function build(FeedDefinition $definition, array $rows): string;

    protected function documentDiagnostic(FeedDefinition $definition, string $diagnostic): string
    {
        return $diagnostic;
    }

    /**
     * @return list<string>
     */
    protected function extraRequired(FeedItem $item): array
    {
        return [];
    }

    protected function reject(FeedItem $item): bool
    {
        if (trim($item->id) === '' || trim($item->title) === '' || trim($item->link) === '' || trim($item->imageLink) === '') {
            return true;
        }
        if (!in_array($item->availability, ['in_stock', 'out_of_stock'], true)) {
            return true;
        }
        if ($item->priceMinor <= 0) {
            return true;
        }
        if (!$this->absoluteHttp($item->link) || !$this->absoluteHttp($item->imageLink)) {
            return true;
        }
        foreach ($this->extraRequired($item) as $value) {
            if (trim($value) === '') {
                return true;
            }
        }

        return false;
    }

    protected function money(int $minor, string $currency, bool $withCurrency): string
    {
        $negative = $minor < 0;
        $minor = abs($minor);
        $major = intdiv($minor, 100);
        $cents = $minor % 100;
        $amount = $major . '.' . str_pad((string)$cents, 2, '0', STR_PAD_LEFT);
        if ($negative) {
            $amount = '-' . $amount;
        }
        $currency = strtoupper(trim($currency));
        if (!$withCurrency || $currency === '') {
            return $amount;
        }

        return $amount . ' ' . $currency;
    }

    protected function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    protected function absoluteHttp(string $value): bool
    {
        return str_starts_with($value, 'https://') || str_starts_with($value, 'http://');
    }

    protected function origin(string $link): string
    {
        $parts = parse_url($link);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return 'https://shop.example/';
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin . '/';
    }

    protected function sampleItem(FeedDefinition $definition): FeedItem
    {
        $currency = trim($definition->currency) !== '' ? trim($definition->currency) : 'USD';

        return new FeedItem(
            id: 'sample-offer',
            productId: 1,
            globalOfferUuid: 'sample-offer',
            groupId: '1',
            sku: 'sample-sku',
            title: 'Sample catalog offer',
            description: 'Fixed sample offer for feed preview',
            brand: 'Sample Brand',
            link: 'https://shop.example/product/sample?color=red&offer=sample-offer',
            imageLink: 'https://shop.example/media/sample.jpg',
            additionalImageLinks: [],
            availability: 'in_stock',
            currency: $currency,
            priceMinor: 1999,
            salePriceMinor: null,
            gtin: null,
            mpn: null,
            attributes: [],
        );
    }
}
