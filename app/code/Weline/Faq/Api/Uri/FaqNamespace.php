<?php

declare(strict_types=1);

namespace Weline\Faq\Api\Uri;

final class FaqNamespace
{
    public const PREFIX = 'faq';

    public static function isFaqIdentifier(string $identifier): bool
    {
        $identifier = trim(strtolower($identifier), '/ ');
        if ($identifier === self::PREFIX) {
            return true;
        }

        return str_starts_with($identifier, self::PREFIX . '/');
    }

    /**
     * @return array{owner:string,reason:string}
     */
    public static function skipPayload(): array
    {
        return [
            'owner' => 'Weline_Faq',
            'reason' => 'faq_namespace',
        ];
    }

    public static function articlePublicPath(string $slug): string
    {
        $slug = trim(strtolower($slug), '/ ');

        return $slug === '' ? '/' . self::PREFIX : '/' . self::PREFIX . '/' . $slug;
    }
}
