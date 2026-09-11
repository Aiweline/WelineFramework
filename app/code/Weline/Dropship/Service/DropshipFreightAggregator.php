<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Dropship\Interface\DropshipFreightProviderInterface;

/**
 * Single shell contributor that aggregates provider freight quotes (L7).
 */
class DropshipFreightAggregator
{
    public function __construct(private readonly DropshipChannelManager $channels)
    {
    }

    /**
     * @param list<string> $providerCodes
     * @param array<string, mixed> $request
     * @return list<array<string, mixed>>
     */
    public function quoteForProviders(array $providerCodes, array $request): array
    {
        $segments = [];
        $total = 0;
        $currency = 'USD';
        foreach ($providerCodes as $code) {
            $p = $this->channels->getProvider($code);
            if (!$p instanceof DropshipFreightProviderInterface) {
                continue;
            }
            foreach ($p->quoteFreight($request) as $opt) {
                $segments[] = array_merge($opt, ['provider_code' => $code]);
                $total += (int)($opt['amount_minor'] ?? 0);
                $currency = (string)($opt['currency'] ?? $currency);
            }
        }

        return [
            'segments' => $segments,
            'total_minor' => $total,
            'currency' => $currency,
        ];
    }
}
