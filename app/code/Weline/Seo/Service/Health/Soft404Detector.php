<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Health;

/**
 * 软 404 判定：用本站真实 404 页做指纹，再对比 200 响应。
 *
 * 默认只报告不自动处理，阈值可通过配置覆盖。
 */
final class Soft404Detector
{
    /** @var list<string> */
    private const DEFAULT_MARKERS = [
        '页面不存在',
        '页面未找到',
        '找不到页面',
        '内容不存在',
        'not found',
        'page not found',
        '404',
        'no longer available',
        'this page could not be found',
    ];

    /**
     * 从真实 404 探测结果生成指纹。
     *
     * @param array<string, mixed> $probe
     * @return array{status:int, title:string, text_length:int, marker_hits:list<string>}
     */
    public function buildFingerprint(array $probe): array
    {
        $signals = $this->signals($probe);
        $markers = $this->markerHits($signals['text'], self::DEFAULT_MARKERS);

        return [
            'status' => (int)($probe['status'] ?? 0),
            'title' => $signals['title'],
            'text_length' => mb_strlen($signals['text']),
            'marker_hits' => $markers,
        ];
    }

    /**
     * @param array<string, mixed> $probe
     * @param array<string, mixed>|null $fingerprint
     * @param array{markers?:list<string>, min_text_length?:int} $config
     * @return array{matched:bool, reason:string, markers:list<string>}
     */
    public function match(array $probe, ?array $fingerprint, array $config = []): array
    {
        $status = (int)($probe['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            return ['matched' => false, 'reason' => 'status is not 2xx', 'markers' => []];
        }

        $signals = $this->signals($probe);
        $markers = $this->markerHits($signals['text'], $this->markers($config));
        $minTextLength = max(0, (int)($config['min_text_length'] ?? 200));

        $fingerprintTitle = (string)($fingerprint['title'] ?? '');
        if ($fingerprintTitle !== '' && $signals['title'] !== ''
            && mb_strtolower($signals['title']) === mb_strtolower($fingerprintTitle)
        ) {
            return ['matched' => true, 'reason' => 'title matches 404 template', 'markers' => $markers];
        }

        if ($markers === []) {
            return ['matched' => false, 'reason' => 'no 404 marker', 'markers' => []];
        }

        $text = $signals['text'];
        $textLength = mb_strlen($text);
        $fingerprintLength = (int)($fingerprint['text_length'] ?? 0);
        $nearFingerprint = $fingerprintLength > 0
            && $textLength >= max(0, (int)floor($fingerprintLength * 0.6))
            && $textLength <= max($minTextLength, (int)ceil($fingerprintLength * 2.5));
        $shortEnough = $textLength < max($minTextLength, 3000);
        $markerNearTop = $this->markerNearTop($text, $markers);

        if ($nearFingerprint || ($shortEnough && $markerNearTop)) {
            return [
                'matched' => true,
                'reason' => $nearFingerprint ? 'text size matches 404 template' : '404 marker near top of short page',
                'markers' => $markers,
            ];
        }

        return ['matched' => false, 'reason' => 'marker present but page looks substantial', 'markers' => $markers];
    }

    /**
     * @param array<string, mixed> $probe
     * @return array{title:string, text:string, noindex:bool, canonical:string, canonical_mismatch:bool}
     */
    private function signals(array $probe): array
    {
        $body = (string)($probe['body'] ?? '');
        $finalUrl = (string)($probe['final_url'] ?? '');

        return HtmlSignals::extract($body, $finalUrl);
    }

    /** @param list<string> $markers @return list<string> */
    private function markerHits(string $text, array $markers): array
    {
        $text = mb_strtolower($text);
        $hits = [];
        foreach ($markers as $marker) {
            $marker = mb_strtolower(trim((string)$marker));
            if ($marker === '') {
                continue;
            }
            if (mb_strpos($text, $marker) !== false) {
                $hits[] = $marker;
            }
        }

        return $hits;
    }

    /** @param list<string> $markers */
    private function markerNearTop(string $text, array $markers): bool
    {
        $head = mb_substr(mb_strtolower($text), 0, 2000);
        foreach ($markers as $marker) {
            if ($marker !== '' && mb_strpos($head, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /** @param array{markers?:list<string>} $config @return list<string> */
    private function markers(array $config): array
    {
        $markers = $config['markers'] ?? [];
        if (!is_array($markers) || $markers === []) {
            return self::DEFAULT_MARKERS;
        }

        return array_values(array_filter(array_map(
            static fn ($marker): string => trim((string)$marker),
            $markers
        ), static fn (string $marker): bool => $marker !== ''));
    }
}
