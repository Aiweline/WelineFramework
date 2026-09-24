<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

/**
 * Resolve payment method icon URLs: SystemConfig override beats provider default.
 */
final class PaymentMethodIconResolver
{
    public const CONFIG_KEY_ICON = 'icon';
    public const CONFIG_KEY_ICON_URL = 'icon_url';

    /**
     * @param array<string, mixed> $displayMetadata
     * @param array<string, mixed> $runtimeConfig
     * @return array<string, mixed>
     */
    public function apply(array $displayMetadata, array $runtimeConfig = []): array
    {
        $override = $this->firstNonEmptyString([
            $runtimeConfig[self::CONFIG_KEY_ICON] ?? null,
            $runtimeConfig[self::CONFIG_KEY_ICON_URL] ?? null,
        ]);
        $provider = $this->firstNonEmptyString([
            $displayMetadata['icon_url'] ?? null,
            $displayMetadata['icon'] ?? null,
        ]);
        $raw = $override !== '' ? $override : $provider;
        $url = $this->toPublicUrl($raw);

        $displayMetadata['icon_url'] = $url;
        $displayMetadata['icon'] = $url;
        $displayMetadata['icon_raw'] = $raw;
        $displayMetadata['icon_source'] = $override !== '' ? 'config' : ($provider !== '' ? 'provider' : 'missing');

        return $displayMetadata;
    }

    public function extractProviderIcon(array $displayMetadata): string
    {
        return $this->firstNonEmptyString([
            $displayMetadata['icon_url'] ?? null,
            $displayMetadata['icon'] ?? null,
        ]);
    }

    public function toPublicUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('#^(https?:)?//#i', $value) === 1) {
            return $value;
        }

        if (str_contains($value, '::')) {
            [$module, $relative] = explode('::', $value, 2);
            $module = trim($module);
            $relative = ltrim(str_replace('\\', '/', trim($relative)), '/');
            $relative = (string) preg_replace('#^statics/#', '', $relative);
            if ($module === '' || $relative === '') {
                return '';
            }

            return $this->resolveModuleStaticUrl($module, $relative);
        }

        if (str_starts_with($value, '/')) {
            if (preg_match('#^/([^/]+)/([^/]+)/view/statics/(.+)$#', $value, $m) === 1) {
                return $this->resolveModuleStaticUrl($m[1] . '_' . $m[2], $m[3]);
            }

            return $value;
        }

        $relative = ltrim(str_replace('\\', '/', $value), '/');
        foreach (['pub/media/', 'media/'] as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                $relative = substr($relative, \strlen($prefix));
                break;
            }
        }
        $relative = ltrim($relative, '/');
        if ($relative === '') {
            return '';
        }

        $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if ($ext === 'svg') {
            return '/pub/media/' . $relative;
        }
        if (\in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            return '/media/image/' . $relative;
        }

        return '/pub/media/' . $relative;
    }

    private function resolveModuleStaticUrl(string $module, string $relative): string
    {
        $modulePath = $module . '::' . ltrim($relative, '/');
        try {
            /** @var \Weline\Framework\View\Template $template */
            $template = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\View\Template::class);
            $url = trim((string) $template->fetchTagSource(
                \Weline\Framework\View\Data\DataInterface::dir_type_STATICS,
                $modulePath
            ));
            if ($url !== '') {
                return $url;
            }
        } catch (\Throwable) {
            // Soft: fall through to flat PROD-safe URL.
        }

        // Never emit DEV-shaped /Vendor/Module/view/statics/ (PROD 404).
        return '/static/' . str_replace('_', '/', $module) . '/' . ltrim($relative, '/');
    }

    /**
     * @param list<mixed> $candidates
     */
    private function firstNonEmptyString(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
