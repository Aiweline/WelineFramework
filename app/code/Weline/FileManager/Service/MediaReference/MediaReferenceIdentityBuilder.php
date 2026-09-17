<?php

declare(strict_types=1);

namespace Weline\FileManager\Service\MediaReference;

/**
 * Builds MediaReferenceIdentity.v1 path + tags. Prefer w_scope() over hand-built paths.
 */
final class MediaReferenceIdentityBuilder
{
    /** @var array<string, string> root => default identity code key */
    private const CODE_KEYS = [
        'product' => 'sku',
        'product_brand' => 'brand',
        'product_supplier' => 'supplier',
        'theme' => 'theme',
        'widget' => 'theme',
        'blog' => 'post',
        'catalog' => 'category',
        'config' => 'key',
        'smtp' => 'mail',
        'website' => 'website',
        'cms' => 'page',
        'eav' => 'attribute',
    ];

    public function __construct(
        private readonly MediaReferenceScopeResolver $scopes,
    ) {
    }

    /**
     * @param array<string, mixed> $slot
     */
    public function build(?string $scope, string $type, string $code, array $slot = []): MediaReferenceIdentity
    {
        $type = strtolower(trim($type));
        $code = trim($code);
        if ($type === '' || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', $type)) {
            throw new \InvalidArgumentException('媒体引用类型无效。');
        }
        if ($code === '' || str_contains($code, ':')) {
            throw new \InvalidArgumentException('媒体引用 code 不能为空且不能含冒号。');
        }

        $resolvedScope = trim((string)($scope ?? ''));
        if ($resolvedScope === '') {
            $resolvedScope = (string)($this->scopes->fromContext() ?? '');
        }
        if ($resolvedScope === '') {
            throw new \InvalidArgumentException('媒体引用缺少 scope：Web/Ambient 需有上下文，CLI 必须显式传入 storage_scope。');
        }
        $resolvedScope = $this->scopes->assertStorageScope($resolvedScope);

        $codeKey = trim((string)($slot['code_key'] ?? ''));
        if ($codeKey === '') {
            $codeKey = self::CODE_KEYS[$type] ?? 'code';
        }
        if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $codeKey)) {
            throw new \InvalidArgumentException('媒体引用 code_key 无效。');
        }

        $slotClean = [];
        foreach ($slot as $k => $v) {
            if (!is_string($k) || $k === 'code_key') {
                continue;
            }
            if (!is_scalar($v) && $v !== null) {
                continue;
            }
            $ks = strtolower(trim($k));
            $vs = trim((string)$v);
            if ($ks === '' || $vs === '' || str_contains($vs, ':')) {
                continue;
            }
            if (in_array($ks, ['scope', 'root', 'type'], true)) {
                continue;
            }
            $slotClean[$ks] = $vs;
        }

        $segments = [$type, $codeKey . ':' . $code, 'scope:' . $resolvedScope];
        foreach (['kind', 'role', 'field', 'component', 'layout', 'option', 'locale', 'instance', 'index', 'ns', 'axis'] as $ordered) {
            if (isset($slotClean[$ordered])) {
                $segments[] = $ordered . ':' . $slotClean[$ordered];
                unset($slotClean[$ordered]);
            }
        }
        ksort($slotClean);
        foreach ($slotClean as $k => $v) {
            $segments[] = $k . ':' . $v;
        }

        $path = implode(':', $segments);
        $tags = [
            'root' => $type,
            'scope' => $resolvedScope,
            $codeKey => $code,
        ];
        foreach ($segments as $segment) {
            if (!str_contains($segment, ':')) {
                continue;
            }
            [$k, $v] = explode(':', $segment, 2);
            $tags[$k] = $v;
        }

        return new MediaReferenceIdentity(
            root: $type,
            scope: $resolvedScope,
            code: $code,
            path: $path,
            tags: $tags,
            slot: $slot,
            codeKey: $codeKey,
        );
    }
}
