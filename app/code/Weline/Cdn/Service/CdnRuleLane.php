<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

/**
 * CDN ApiRule 边缘计划车道（收集打标 / plan 分流）。
 *
 * predicate_fidelity：BinQuery 等带 method / marker / custom cache_key 的保真细规则。
 * path_packable：普通 @Cdn 路径级注解，可进 AnnotationRulePacker path-OR。
 */
final class CdnRuleLane
{
    public const PREDICATE_FIDELITY = 'predicate_fidelity';
    public const PATH_PACKABLE = 'path_packable';

    public static function normalize(?string $lane): string
    {
        $value = \trim((string)$lane);
        if ($value === self::PREDICATE_FIDELITY) {
            return self::PREDICATE_FIDELITY;
        }

        return self::PATH_PACKABLE;
    }
}
