<?php

declare(strict_types=1);

namespace Weline\Tax\Api;

/**
 * Country-driven buyer tax-identity field schema for checkout embeds.
 */
interface TaxIdentitySchemaProviderInterface
{
    /**
     * @param array<string,mixed> $address billing (or shipping when billing same)
     * @return array{
     *   visibility: 'hidden'|'optional'|'recommended'|'required',
     *   tax_id_type: string,
     *   label: string,
     *   pattern: string,
     *   country_code: string,
     *   fields: list<array{code:string,label:string,required:bool}>
     * }
     */
    public function schemaForAddress(array $address): array;
}
