<?php

declare(strict_types=1);

namespace Weline\Faq\Service;

use Weline\Faq\Api\Uri\FaqNamespace;

final class FaqScopeResolver
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

        return $base !== '' ? $base . '/' . FaqNamespace::PREFIX : '/' . FaqNamespace::PREFIX;
    }

    public function articleCanonical(string $slug): string
    {
        $path = FaqNamespace::articlePublicPath($slug);
        $base = $this->baseUrl();

        return $base !== '' ? $base . $path : $path;
    }
}
