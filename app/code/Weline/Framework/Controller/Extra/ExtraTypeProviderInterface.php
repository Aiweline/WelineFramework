<?php

declare(strict_types=1);

namespace Weline\Framework\Controller\Extra;

/**
 * Extra 类型提供者（如 fpc）；升级收集时校验未知 type。
 */
interface ExtraTypeProviderInterface
{
    public const EXTENDS_RELATIVE_PREFIX = 'extends/module/weline_framework/extra/type/';

    public function type(): string;

    public function description(): string;

    /**
     * 校验并规范化声明字段。
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    public function normalize(array $attrs): array;
}
