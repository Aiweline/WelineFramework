<?php
declare(strict_types=1);
namespace Weline\Shipping\Service;

/** Compile audited, fuel-inclusive carrier quotes; never consume retail prices. */
final class PublicTariffTableCompiler
{
    /** @param list<array<string,mixed>> $offers */
    public function compileCountry(string $country, array $offers): array
    {
        $bounds=[];
        foreach ($offers as &$offer) {
            if (($offer['currency'] ?? '') !== 'CNY' || (int)($offer['volume_divisor'] ?? 0) !== 5000) {
                throw new \InvalidArgumentException('public_tariff_incompatible_currency_or_volume_divisor');
            }
            foreach (['source_id','source_url','product'] as $key) {
                if (trim((string)($offer[$key] ?? '')) === '') {
                    throw new \InvalidArgumentException('public_tariff_missing_provenance:' . $key);
                }
            }
            if (empty($offer['effective_date']) && empty($offer['edition'])) {
                throw new \InvalidArgumentException('public_tariff_missing_source_date_or_edition');
            }
            $rows=$offer['rows'] ?? [];
            usort($rows, static fn(array $a,array $b): int => (float)$a['upper_kg'] <=> (float)$b['upper_kg']);
            $last=0.0;
            foreach ($rows as $row) {
                $upper=(float)($row['upper_kg'] ?? 0);
                if (!is_finite($upper) || $upper <= $last) {
                    throw new \InvalidArgumentException('public_tariff_invalid_weight_bounds');
                }
                $this->micros((string)($row['cost_cny'] ?? ''));
                $bounds[(string)$upper]=$upper;
                $last=$upper;
            }
            $offer['rows']=$rows;
        }
        unset($offer);
        sort($bounds,SORT_NUMERIC);
        $brackets=[];$audit=[];$previous=0.0;
        foreach ($bounds as $upper) {
            $quote=$this->quote($offers, (float)$upper);
            $price=$quote['retail_cny'];
            $brackets[]=['min'=>$previous,'max'=>$upper,'price'=>$price];
            $audit[]=['upper_kg'=>$upper]+$quote;
            $previous=$upper;
        }
        if ($brackets === []) {throw new \InvalidArgumentException('public_tariff_no_quotes');}
        return ['country'=>strtoupper($country),'currency'=>'CNY','max_weight_kg'=>$previous,'brackets'=>$brackets,'audit'=>$audit];
    }

    /** Quote already audited fuel-inclusive source data, retaining each carrier's own brackets. */
    public function quote(array $offers, float $weightKg): array
    {
        $maximum=-1; $winner=''; $selectedZone=null; $candidates=[];
        foreach ($offers as $offer) {
            $cost=null; $billed=null; $last=0.0;
            foreach ($offer['rows'] ?? [] as $row) {
                $upper=(float)$row['upper_kg'];
                $lower=(float)($row['lower_kg'] ?? $last); $last=$upper;
                if ($weightKg > $upper + 0.00000001) {continue;}
                if ($weightKg > $lower) {
                    $cost=$this->micros((string)$row['cost_cny']); $billed=$upper;
                }
                break;
            }
            if ($cost === null && $weightKg > $last) {
                foreach ($offer['per_kg_tiers'] ?? [] as $tier) {
                    $step=(float)($tier['rounding_step_kg'] ?? 1);
                    if ($step <= 0) {throw new \InvalidArgumentException('public_tariff_invalid_rounding');}
                    $rounded=ceil(($weightKg-0.000000001)/$step)*$step;
                    if ($rounded < (float)$tier['min_kg']
                        || ($tier['max_kg'] !== null && $rounded > (float)$tier['max_kg'])) {continue;}
                    $billed=$rounded;
                    $cost=(int)round($this->micros((string)$tier['cost_per_kg_cny'])*$rounded);
                    break;
                }
            }
            if ($cost === null) {continue;}
            $id=(string)$offer['source_id'];
            $candidates[]=['source_id'=>$id,'product'=>$offer['product'],'zone'=>$offer['zone']??null,'destination_region'=>$offer['destination_region']??null,
                'quoted_upper_kg'=>$billed,'cost_cny'=>sprintf('%.6f',$cost/1000000)];
            if ($cost > $maximum) {$maximum=$cost;$winner=$id;$selectedZone=$offer['zone']??null;}
        }
        if ($maximum < 0) {throw new \Weline\Shipping\Exception\ShippingRateUnavailableException('no_bracket_match');}
        // The only markup and money rounding operation; source costs retain sub-cent precision.
        $minor=intdiv($maximum*3+10000,20000);
        return ['candidates'=>$candidates,'selected_source_id'=>$winner,'selected_zone'=>$selectedZone,
            'retail_cny'=>sprintf('%d.%02d',intdiv($minor,100),$minor%100)];
    }

    private function micros(string $value): int
    {
        if (!preg_match('/^([0-9]{1,9})(?:\.([0-9]{1,6}))?$/D',$value,$match)) {
            throw new \InvalidArgumentException('public_tariff_invalid_cost');
        }
        return (int)$match[1]*1000000+(int)str_pad($match[2]??'',6,'0');
    }
}
