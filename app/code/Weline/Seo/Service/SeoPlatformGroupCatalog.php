<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

/**
 * SEO account platform picker groups (UI tabs). One account still binds one platform.
 */
final class SeoPlatformGroupCatalog
{
    /**
     * Ordered groups. Unknown platform codes fall into `other`.
     *
     * @return list<array{id:string,label:string,hint:string,codes:list<string>}>
     */
    public static function groups(): array
    {
        return [
            [
                'id' => 'recommended',
                'label' => '推荐接入',
                'hint' => '有官方 API / 最常绑定：Google、Bing、百度、IndexNow',
                'codes' => ['google', 'bing', 'baidu', 'indexnow'],
            ],
            [
                'id' => 'indexnow',
                'label' => 'IndexNow 生态',
                'hint' => 'IndexNow 参与方：自动 URL 通知，常与 Sitemap 配合',
                'codes' => ['yandex', 'naver', 'seznam', 'yep', 'internetarchive', 'amazonbot'],
            ],
            [
                'id' => 'china',
                'label' => '中国站长',
                'hint' => '国内搜索：多数为生成 Sitemap 后到站长平台手动提交',
                'codes' => ['360', 'sogou', 'shenma', 'toutiao', 'quark'],
            ],
            [
                'id' => 'privacy',
                'label' => '隐私 / 独立',
                'hint' => '隐私向与独立引擎：通常仅生成 Sitemap',
                'codes' => [
                    'duckduckgo', 'brave', 'qwant', 'ecosia', 'startpage', 'swisscows',
                    'mojeek', 'you', 'kagi', 'metager', 'gibiru',
                ],
            ],
            [
                'id' => 'regional',
                'label' => '区域 / 其它',
                'hint' => '区域引擎与其它目录型平台',
                'codes' => ['yahoo', 'daum', 'coccoc', 'petal', 'mailru', 'rambler', 'aol', 'ask'],
            ],
        ];
    }

    private static function tr(string $text): string
    {
        return \function_exists('__') ? (string)__($text) : $text;
    }

    /**
     * @param array<string, array<string, mixed>> $platforms
     * @return list<array{id:string,label:string,hint:string,platforms:list<array{code:string,info:array<string,mixed>}>}>
     */
    public function build(array $platforms): array
    {
        $remaining = [];
        foreach ($platforms as $code => $info) {
            $remaining[strtolower((string)$code)] = is_array($info) ? $info : [];
        }

        $built = [];
        foreach (self::groups() as $group) {
            $items = [];
            foreach ($group['codes'] as $code) {
                $code = strtolower((string)$code);
                if (!array_key_exists($code, $remaining)) {
                    continue;
                }
                $items[] = [
                    'code' => $code,
                    'info' => $remaining[$code],
                ];
                unset($remaining[$code]);
            }
            if ($items === []) {
                continue;
            }
            $built[] = [
                'id' => (string)$group['id'],
                'label' => self::tr((string)$group['label']),
                'hint' => self::tr((string)$group['hint']),
                'platforms' => $items,
            ];
        }

        if ($remaining !== []) {
            $items = [];
            foreach ($remaining as $code => $info) {
                $items[] = [
                    'code' => (string)$code,
                    'info' => $info,
                ];
            }
            $built[] = [
                'id' => 'other',
                'label' => self::tr('未分组'),
                'hint' => self::tr('尚未归类的平台'),
                'platforms' => $items,
            ];
        }

        return $built;
    }

    public function groupIdForPlatform(string $platformCode, array $platforms): string
    {
        $platformCode = strtolower(trim($platformCode));
        foreach ($this->build($platforms) as $group) {
            foreach ($group['platforms'] as $item) {
                if (($item['code'] ?? '') === $platformCode) {
                    return (string)$group['id'];
                }
            }
        }

        return (string)(($this->build($platforms)[0]['id'] ?? 'recommended'));
    }
}
