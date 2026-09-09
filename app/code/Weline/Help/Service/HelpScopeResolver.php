<?php

declare(strict_types=1);

namespace Weline\Help\Service;

use Weline\Help\Api\Uri\HelpNamespace;

final class HelpScopeResolver
{
    public function baseUrl(): string
    {
        try {
            $url = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Http\Url::class);
            $base = rtrim((string)$url->getBaseUrl(), '/');

            return $base !== '' ? $base : '';
        } catch (\Throwable) {
            return '';
        }
    }

    public function hubCanonical(): string
    {
        $base = $this->baseUrl();

        return $base !== '' ? $base . '/' . HelpNamespace::PREFIX : '/' . HelpNamespace::PREFIX;
    }

    public function articleCanonical(string $slug): string
    {
        $path = HelpNamespace::articlePublicPath($slug);
        $base = $this->baseUrl();

        return $base !== '' ? $base . $path : $path;
    }
}
