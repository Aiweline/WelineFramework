<?php

declare(strict_types=1);

namespace Weline\Faq\Service;

use Weline\Faq\Api\FaqTypeProviderInterface;

/**
 * Built-in site hub FAQs: type_code=site, entity_uuid=site.
 */
final class SiteFaqTypeProvider implements FaqTypeProviderInterface
{
    public const ENTITY_UUID = 'site';

    public function typeCode(): string
    {
        return 'site';
    }

    public function resolveEntity(string $uuid): ?array
    {
        $uuid = strtolower(trim($uuid));
        if ($uuid === '' || $uuid === self::ENTITY_UUID) {
            return [
                'entity_id' => 0,
                'entity_uuid' => self::ENTITY_UUID,
            ];
        }

        return null;
    }
}
