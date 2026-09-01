<?php

declare(strict_types=1);

namespace Weline\Eav\Taglib;

use Weline\Eav\Model\EavEntity;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Framework\View\Template;

/**
 * 嵌入 EAV 管理器，可按实体 code 限定范围。
 *
 * 用法：
 * <eav:manager entity-code="product" />
 * <eav:manager entity-code="product" compact="true" />
 */
final class EavManager implements TaglibInterface
{
    public static function name(): string
    {
        return 'eav:manager';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return [
            'entity-code' => false,
            'compact' => false,
            'mode' => false,
            'product-id' => false,
            'lock-set' => false,
            'selected-set-id' => false,
            'structure-scope' => false,
        ];
    }

    public static function callback(): callable
    {
        return static function ($tagKey, $config, $tagData, $attributes): string {
            if ($tagKey !== 'tag-self-close-with-attrs' && $tagKey !== 'tag-self-close') {
                return '';
            }

            $entityCode = strtolower(trim((string)($attributes['entity-code'] ?? '')));
            $entityScope = null;
            $entityScopeError = '';

            if ($entityCode !== '') {
                try {
                    $entityScope = self::resolveEntityScope($entityCode);
                } catch (\InvalidArgumentException $exception) {
                    $entityScopeError = $exception->getMessage();
                }
            }

            $compact = ($attributes['compact'] ?? 'false') !== 'false';
            $mode = strtolower(trim((string)($attributes['mode'] ?? 'manage')));
            if ($mode === '') {
                $mode = 'manage';
            }
            $productId = max(0, (int)($attributes['product-id'] ?? 0));
            $lockSet = in_array(strtolower(trim((string)($attributes['lock-set'] ?? 'false'))), ['1', 'true', 'yes'], true);
            $selectedSetId = max(0, (int)($attributes['selected-set-id'] ?? 0));
            $structureScope = strtolower(trim((string)($attributes['structure-scope'] ?? 'catalog')));
            if ($structureScope === '') {
                $structureScope = 'catalog';
            }

            /** @var Template $template */
            $template = ObjectManager::getInstance(Template::class);

            return $template->fetch('Weline_Eav::templates/Backend/Manager/surface.phtml', [
                'entityScope' => $entityScope,
                'entityScopeError' => $entityScopeError,
                'compact' => $compact,
                'mode' => $mode,
                'productId' => $productId,
                'lockSet' => $lockSet,
                'selectedSetId' => $selectedSetId,
                'structureScope' => $structureScope,
            ]);
        };
    }

    /**
     * @return array{code:string,entityId:int,name:string}
     */
    private static function resolveEntityScope(string $entityCode): array
    {
        /** @var EavEntity $entityModel */
        $entityModel = ObjectManager::getInstance(EavEntity::class);
        $query = clone $entityModel;
        $query->loadLocalDescription();
        $query->where(EavEntity::schema_fields_code, $entityCode);
        $entity = $query->find()->fetchArray();
        if ($entity === []) {
            throw new \InvalidArgumentException((string)__('实体不存在: %1', [$entityCode]));
        }

        return [
            'code' => (string)($entity['code'] ?? $entityCode),
            'entityId' => (int)($entity['eav_entity_id'] ?? 0),
            'name' => (string)($entity['local_name'] ?? $entity['name'] ?? $entity['code'] ?? $entityCode),
        ];
    }

    public static function tag_self_close(): bool
    {
        return true;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    public static function parent(): ?string
    {
        return null;
    }

    public static function document(): string
    {
        return htmlspecialchars(
            '<h3><code>&lt;eav:manager entity-code="product" mode="select-set" compact="true" /&gt;</code></h3>'
            . '<p>嵌入 EAV 管理器，支持 manage / select-set / select-attribute 模式。</p>',
            ENT_NOQUOTES,
        );
    }
}
