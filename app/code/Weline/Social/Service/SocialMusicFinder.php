<?php

declare(strict_types=1);

namespace Weline\Social\Service;

class SocialMusicFinder
{
    public const DEFAULT_ENDPOINT = 'https://api.openverse.org/v1/audio/';
    public const DEFAULT_LIMIT = 5;
    public const MAX_LIMIT = 20;

    private SocialHttpClient $http;

    /** @var callable|null */
    private $sourceProvider;

    public function __construct(?SocialHttpClient $http = null, ?callable $sourceProvider = null)
    {
        $this->http = $http ?? new SocialHttpClient();
        $this->sourceProvider = $sourceProvider;
    }

    /**
     * @return array<int, array{title:string,url:string,license:string,attribution:string,source:string}>
     */
    public function search(string $query, int $limit = self::DEFAULT_LIMIT): array
    {
        $query = \trim($query);
        if ($query === '') {
            return [];
        }

        $limit = $this->normalizeLimit($limit);
        try {
            $items = $this->sourceProvider !== null
                ? \call_user_func($this->sourceProvider, $query, $limit)
                : $this->fetchRemote($query, $limit);
        } catch (\Throwable) {
            return [];
        }

        return $this->filterLicensed(\is_array($items) ? $items : [], $limit);
    }

    /**
     * 人工确认曲目许可，通过时返回可写入素材台账的确认时间。
     *
     * @return array{ok:bool,error:string,license_confirmed_at?:string}
     */
    public function confirmLicense(array $item, int $userId): array
    {
        $license = $this->stringValue($item['license'] ?? '');
        $sourceUrl = $this->stringValue($item['source_url'] ?? '');
        $attribution = $this->stringValue($item['attribution'] ?? '');
        if ($license === '' || $sourceUrl === '' || $attribution === '') {
            return ['ok' => false, 'error' => 'music_unlicensed'];
        }

        return [
            'ok' => true,
            'error' => '',
            'license_confirmed_at' => \date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array{title:string,url:string,license:string,attribution:string,source:string}>
     */
    public function filterLicensed(array $items, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = $this->normalizeLimit($limit);
        $tracks = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $title = $this->stringValue($item['title'] ?? '');
            $url = $this->stringValue($item['url'] ?? '');
            $license = $this->stringValue($item['license'] ?? ($item['license_code'] ?? ''));
            $licenseVersion = $this->stringValue($item['license_version'] ?? '');
            $attribution = $this->stringValue($item['attribution'] ?? ($item['attribution_text'] ?? ''));
            $creator = $this->stringValue($item['creator'] ?? ($item['artist'] ?? ''));
            $landingUrl = $this->stringValue($item['foreign_landing_url'] ?? ($item['source_url'] ?? ''));

            if ($title === '' || $license === '' || !$this->isHttpUrl($url)) {
                continue;
            }
            if ($licenseVersion !== '' && !\str_contains($license, $licenseVersion)) {
                $license .= ' ' . $licenseVersion;
            }
            if ($attribution === '' && $creator !== '') {
                $attribution = $creator . ' (' . $license . ')';
            }
            if ($attribution === '') {
                continue;
            }

            $source = $this->domainOf($landingUrl !== '' ? $landingUrl : $url);
            if ($source === '') {
                continue;
            }

            $tracks[] = [
                'title' => $title,
                'url' => $url,
                'license' => $license,
                'attribution' => $attribution,
                'source' => $source,
            ];
            if (\count($tracks) >= $limit) {
                break;
            }
        }

        return $tracks;
    }

    /**
     * @return array<int, mixed>
     */
    private function fetchRemote(string $query, int $limit): array
    {
        $endpoint = \trim((string)(\getenv('SOCIAL_MUSIC_SEARCH_URL') ?: ''));
        if ($endpoint === '') {
            $endpoint = self::DEFAULT_ENDPOINT;
        }
        if (!$this->isHttpUrl($endpoint)) {
            return [];
        }

        $params = [
            'q' => $query,
            'page_size' => $limit,
        ];
        if ($endpoint === self::DEFAULT_ENDPOINT) {
            $params['license_type'] = 'commercial';
        }

        $response = $this->http->get($endpoint, $params);
        if (($response['ok'] ?? false) !== true) {
            return [];
        }

        $results = $response['json']['results'] ?? [];

        return \is_array($results) ? $results : [];
    }

    private function normalizeLimit(int $limit): int
    {
        return \max(1, \min(self::MAX_LIMIT, $limit));
    }

    private function stringValue(mixed $value): string
    {
        return \is_string($value) || \is_int($value) || \is_float($value)
            ? \trim((string)$value)
            : '';
    }

    private function isHttpUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $scheme = \strtolower((string)\parse_url($url, PHP_URL_SCHEME));
        $host = (string)\parse_url($url, PHP_URL_HOST);

        return \in_array($scheme, ['http', 'https'], true) && $host !== '';
    }

    private function domainOf(string $url): string
    {
        if (\str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }
        $host = \parse_url($url, PHP_URL_HOST);
        if (!\is_string($host) || $host === '') {
            return '';
        }

        return \preg_replace('/^www\./i', '', \strtolower($host)) ?: \strtolower($host);
    }
}
