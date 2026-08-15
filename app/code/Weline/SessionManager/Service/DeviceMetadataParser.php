<?php

declare(strict_types=1);

namespace Weline\SessionManager\Service;

use Weline\SessionManager\Data\DeviceMetadata;

final class DeviceMetadataParser
{
    public function parse(string $userAgent, string $ipAddress): DeviceMetadata
    {
        $userAgent = substr(trim($userAgent), 0, 2048);
        [$browser, $browserKnown] = $this->browser($userAgent);
        [$operatingSystem, $systemKnown] = $this->operatingSystem($userAgent);
        $browserName = preg_replace('/\s+\d+$/', '', $browser) ?: $browser;
        $deviceName = $browserKnown || $systemKnown
            ? (string)__('%{1} on %{2}', [$browserName, $operatingSystem])
            : (string)__('Unknown device');

        return new DeviceMetadata(
            deviceName: substr($deviceName, 0, 160),
            browser: substr($browser, 0, 80),
            operatingSystem: substr($operatingSystem, 0, 80),
            ipAddress: substr(trim($ipAddress), 0, 64),
        );
    }

    /** @return array{0:string,1:bool} */
    private function browser(string $userAgent): array
    {
        $patterns = [
            'Edge' => '/(?:Edg|EdgiOS|EdgA)\/([0-9]+)/i',
            'Opera' => '/(?:OPR|Opera)\/([0-9]+)/i',
            'Firefox' => '/(?:Firefox|FxiOS)\/([0-9]+)/i',
            'Chrome' => '/(?:Chrome|CriOS)\/([0-9]+)/i',
            'Safari' => '/Version\/([0-9]+).*Safari\//i',
        ];
        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $userAgent, $matches) === 1) {
                return [$name . ' ' . $matches[1], true];
            }
        }
        return [(string)__('Unknown browser'), false];
    }

    /** @return array{0:string,1:bool} */
    private function operatingSystem(string $userAgent): array
    {
        return match (true) {
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => ['iOS', true],
            str_contains($userAgent, 'Android') => ['Android', true],
            str_contains($userAgent, 'Windows') => ['Windows', true],
            str_contains($userAgent, 'Macintosh'), str_contains($userAgent, 'Mac OS X') => ['macOS', true],
            str_contains($userAgent, 'CrOS') => ['ChromeOS', true],
            str_contains($userAgent, 'Linux') => ['Linux', true],
            default => [(string)__('Unknown system'), false],
        };
    }
}
