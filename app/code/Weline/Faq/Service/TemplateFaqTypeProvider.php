<?php

declare(strict_types=1);

namespace Weline\Faq\Service;

use Weline\Faq\Api\FaqTypeProviderInterface;

/**
 * Built-in PDP template pack FAQs: type_code=template, entity_uuid=<pack>.
 */
final class TemplateFaqTypeProvider implements FaqTypeProviderInterface
{
    public const TYPE_CODE = 'template';

    public function typeCode(): string
    {
        return self::TYPE_CODE;
    }

    public function resolveEntity(string $uuid): ?array
    {
        $uuid = strtolower(trim($uuid));
        if ($uuid === '' || !FaqTemplatePacks::isValid($uuid)) {
            return null;
        }

        return [
            'entity_id' => 0,
            'entity_uuid' => $uuid,
        ];
    }
}
