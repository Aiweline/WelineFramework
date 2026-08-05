<?php

declare(strict_types=1);

namespace Weline\Tax\Api;

/**
 * Server-side tax calculation（P3B-001）.
 * Failures MUST throw — never return tax_amount_minor=0 as soft success.
 */
interface TaxEngineInterface
{
    public const SCHEMA_VERSION = 'tax-schema-v1';
    public const ERROR_NO_RULE = 'tax_engine_no_rule';
    public const ERROR_ENGINE_DOWN = 'tax_engine_down';
    public const ERROR_INVALID_REQUEST = 'tax_engine_invalid_request';
    public const ERROR_LKG_VERSION = 'tax_engine_lkg_version_mismatch';
    public const ERROR_ROUNDING = 'tax_engine_rounding_overflow';
    public const ERROR_SCOPE = 'tax_engine_scope_invalid';
    public const ERROR_RULE_INVALID = 'tax_engine_rule_invalid';
    public const ERROR_DUPLICATE_LINE = 'tax_engine_duplicate_line';
    public const ERROR_OVERFLOW = 'tax_engine_integer_overflow';

    /**
     * @param array{
     *   website_id:int,
     *   store_id:int,
     *   currency:string,
     *   jurisdiction_key:string,
     *   rule_schema_version:string,
     *   lines:list<array{line_id:string,tax_class_code:string,taxable_amount_minor:int}>
     * } $request
     * @return array{
     *   tax_amount_minor:int,
     *   rule_schema_version:string,
     *   rule_set_hash:string,
     *   lines:list<array{line_id:string,tax_amount_minor:int,rate_bps:int,rule_version:int}>,
     *   source:string,
     *   scope_key:string
     * }
     */
    public function calculate(array $request): array;
}
