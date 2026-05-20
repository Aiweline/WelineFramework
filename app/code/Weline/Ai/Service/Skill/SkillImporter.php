<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Skill;

use Weline\Ai\Model\AiSkill;
use Weline\Framework\Manager\ObjectManager;

final class SkillImporter
{
    private const MAX_URL_BYTES = 262144;
    private const TIMEOUT_SECONDS = 8;
    private const MAX_REDIRECTS = 3;

    public function __construct(
        private readonly ?SkillRepository $repository = null,
        private readonly ?SkillRegistry $registry = null,
        private readonly ?SkillNormalizer $normalizer = null
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function importFromUrl(string $url): array
    {
        $url = \trim($url);
        $body = $this->fetchUrl($url);
        $data = $this->parseSkillPayload($body, $url);
        $code = $this->normalizer()->normalizeCode((string)($data['code'] ?? ''));
        if ($this->registry()->isReservedCode($code)) {
            throw new \InvalidArgumentException('Skill code "' . $code . '" conflicts with a system/module skill.');
        }
        if ($this->repository()->findByCode($code)) {
            throw new \InvalidArgumentException('Skill code "' . $code . '" already exists. Import it with a new code or version.');
        }

        $data['code'] = $code;
        $data['status'] = AiSkill::STATUS_PENDING;
        $data['source_type'] = AiSkill::SOURCE_IMPORT_URL;
        $data['source_url'] = $url;
        $skill = $this->repository()->saveFromArray($data, AiSkill::SOURCE_IMPORT_URL);

        return $this->repository()->modelToArray($skill);
    }

    /**
     * @return array<string,mixed>
     */
    public function parseSkillPayload(string $payload, string $source = ''): array
    {
        $payload = \str_replace(["\r\n", "\r"], "\n", $payload);
        $trimmed = \trim($payload);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('Imported skill payload is empty.');
        }

        $json = \json_decode($trimmed, true);
        if (\is_array($json) && (string)($json['format'] ?? '') === 'ai_skill_package_v1') {
            return [
                'code' => (string)($json['code'] ?? ''),
                'name' => (string)($json['name'] ?? $json['code'] ?? ''),
                'description' => (string)($json['description'] ?? ''),
                'body' => (string)($json['body'] ?? ''),
                'source_platform' => (string)($json['source_platform'] ?? ''),
                'version' => (string)($json['version'] ?? ''),
            ];
        }

        $frontmatter = $this->parseFrontmatter($payload);
        if ($frontmatter !== []) {
            $body = $this->stripFrontmatter($payload);
            return [
                'code' => (string)($frontmatter['code'] ?? $this->deriveCode($frontmatter['name'] ?? '', $source)),
                'name' => (string)($frontmatter['name'] ?? $frontmatter['code'] ?? 'Imported skill'),
                'description' => (string)($frontmatter['description'] ?? ''),
                'body' => $body,
                'source_platform' => (string)($frontmatter['source_platform'] ?? ''),
                'version' => (string)($frontmatter['version'] ?? ''),
            ];
        }

        $name = $this->deriveMarkdownTitle($payload) ?: 'Imported skill';
        return [
            'code' => $this->deriveCode($name, $source),
            'name' => $name,
            'description' => '',
            'body' => $payload,
            'source_platform' => '',
            'version' => '',
        ];
    }

    private function fetchUrl(string $url, int $redirects = 0): string
    {
        if ($redirects > self::MAX_REDIRECTS) {
            throw new \InvalidArgumentException('Too many redirects while importing skill URL.');
        }
        $this->assertPublicHttpUrl($url);

        $context = \stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT_SECONDS,
                'ignore_errors' => true,
                'follow_location' => 0,
                'header' => "Accept: text/markdown,text/plain,application/json\r\n",
            ],
        ]);
        $handle = @\fopen($url, 'rb', false, $context);
        if (!$handle) {
            throw new \RuntimeException('Unable to open skill import URL.');
        }

        $meta = \stream_get_meta_data($handle);
        $headers = $this->normalizeHeaders((array)($meta['wrapper_data'] ?? []));
        $status = (int)($headers[':status'] ?? 0);
        if ($status >= 300 && $status < 400 && !empty($headers['location'])) {
            \fclose($handle);
            $next = $this->resolveRedirectUrl($url, (string)$headers['location']);
            return $this->fetchUrl($next, $redirects + 1);
        }
        if ($status >= 400) {
            \fclose($handle);
            throw new \RuntimeException('Skill import URL returned HTTP ' . $status . '.');
        }

        $contentType = \strtolower((string)($headers['content-type'] ?? ''));
        if ($contentType !== ''
            && !\str_contains($contentType, 'text/plain')
            && !\str_contains($contentType, 'text/markdown')
            && !\str_contains($contentType, 'text/x-markdown')
            && !\str_contains($contentType, 'application/json')) {
            \fclose($handle);
            throw new \InvalidArgumentException('Skill import URL must return Markdown, plain text, or JSON.');
        }

        $body = '';
        while (!\feof($handle)) {
            $body .= (string)\fread($handle, 8192);
            if (\strlen($body) > self::MAX_URL_BYTES) {
                \fclose($handle);
                throw new \InvalidArgumentException('Skill import URL exceeds 256KB.');
            }
        }
        \fclose($handle);

        return $body;
    }

    private function assertPublicHttpUrl(string $url): void
    {
        $parts = \parse_url($url);
        $scheme = \strtolower((string)($parts['scheme'] ?? ''));
        $host = \trim((string)($parts['host'] ?? ''));
        if (!\in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \InvalidArgumentException('Only http/https skill import URLs are allowed.');
        }
        if (\in_array(\strtolower($host), ['localhost'], true) || \str_ends_with(\strtolower($host), '.local')) {
            throw new \InvalidArgumentException('Localhost and local network skill import URLs are not allowed.');
        }

        $ips = [];
        if (\filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $resolved = @\gethostbynamel($host);
            if (\is_array($resolved)) {
                $ips = $resolved;
            }
        }
        if ($ips === []) {
            throw new \InvalidArgumentException('Skill import URL host could not be resolved.');
        }
        foreach ($ips as $ip) {
            if (!\filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \InvalidArgumentException('Skill import URL must resolve to a public IP address.');
            }
        }
    }

    /**
     * @param list<string> $rawHeaders
     * @return array<string,string|int>
     */
    private function normalizeHeaders(array $rawHeaders): array
    {
        $headers = [];
        foreach ($rawHeaders as $header) {
            $header = (string)$header;
            if (\preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $m) === 1) {
                $headers[':status'] = (int)$m[1];
                continue;
            }
            $pos = \strpos($header, ':');
            if ($pos === false) {
                continue;
            }
            $key = \strtolower(\trim(\substr($header, 0, $pos)));
            $headers[$key] = \trim(\substr($header, $pos + 1));
        }

        return $headers;
    }

    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        if (\parse_url($location, PHP_URL_SCHEME)) {
            return $location;
        }
        $base = \parse_url($baseUrl);
        $scheme = (string)($base['scheme'] ?? 'https');
        $host = (string)($base['host'] ?? '');
        $port = isset($base['port']) ? ':' . (string)$base['port'] : '';
        if (\str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $port . $location;
        }
        $path = (string)($base['path'] ?? '/');
        $dir = \rtrim(\str_replace('\\', '/', \dirname($path)), '/');
        return $scheme . '://' . $host . $port . ($dir === '' ? '' : $dir) . '/' . $location;
    }

    /**
     * @return array<string,string>
     */
    private function parseFrontmatter(string $payload): array
    {
        $trimmed = \ltrim($payload);
        if (!\str_starts_with($trimmed, '---')) {
            return [];
        }
        $end = \strpos($trimmed, "\n---", 3);
        if ($end === false) {
            return [];
        }

        $front = \substr($trimmed, 3, $end - 3);
        $result = [];
        foreach (\explode("\n", \str_replace(["\r\n", "\r"], "\n", $front)) as $line) {
            if (\preg_match('/^([a-zA-Z_][a-zA-Z0-9_-]*)\s*:\s*(.*)$/', \trim($line), $m) === 1) {
                $result[(string)$m[1]] = $this->stripQuotes((string)$m[2]);
            }
        }

        return $result;
    }

    private function stripFrontmatter(string $payload): string
    {
        $trimmed = \ltrim(\str_replace(["\r\n", "\r"], "\n", $payload));
        $end = \strpos($trimmed, "\n---", 3);
        return $end === false ? $payload : \ltrim(\substr($trimmed, $end + 4));
    }

    private function stripQuotes(string $value): string
    {
        $value = \trim($value);
        if ((\str_starts_with($value, '"') && \str_ends_with($value, '"'))
            || (\str_starts_with($value, "'") && \str_ends_with($value, "'"))) {
            return \substr($value, 1, -1);
        }

        return $value;
    }

    private function deriveMarkdownTitle(string $payload): string
    {
        if (\preg_match('/^\s*#\s+(.+)$/m', $payload, $m) === 1) {
            return \trim((string)$m[1]);
        }

        return '';
    }

    private function deriveCode(string $name, string $source): string
    {
        $candidate = $name !== '' ? $name : 'imported-skill';
        try {
            return $this->normalizer()->normalizeCode($candidate);
        } catch (\InvalidArgumentException) {
            return 'imported-' . \substr(\sha1($source !== '' ? $source : $candidate), 0, 12);
        }
    }

    private function repository(): SkillRepository
    {
        return $this->repository ?? ObjectManager::getInstance(SkillRepository::class);
    }

    private function registry(): SkillRegistry
    {
        return $this->registry ?? ObjectManager::getInstance(SkillRegistry::class);
    }

    private function normalizer(): SkillNormalizer
    {
        return $this->normalizer ?? new SkillNormalizer();
    }
}
