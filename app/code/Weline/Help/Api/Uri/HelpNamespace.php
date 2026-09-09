<?php

declare(strict_types=1);

namespace Weline\Help\Api\Uri;

final class HelpNamespace
{
    public const PREFIX = 'help';
    public const FAQ_ALIAS = 'faq';

    public static function isHelpIdentifier(string $identifier): bool
    {
        $identifier = trim(strtolower($identifier), '/ ');
        if ($identifier === self::PREFIX || $identifier === self::FAQ_ALIAS) {
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
            'owner' => 'Weline_Help',
            'reason' => 'help_namespace',
        ];
    }

    public static function articlePublicPath(string $slug): string
    {
        $slug = trim(strtolower($slug), '/ ');

        return $slug === '' ? '/' . self::PREFIX : '/' . self::PREFIX . '/' . $slug;
    }
}
