<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;

final class BlogReviewI18nContractTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function requiredReviewKeys(): array
    {
        return [
            '博客评论',
            '支持文字、图片与视频，内容审核后公开。',
            '正在加载评论...',
            '评论加载失败，请稍后重试。',
            '暂时还没有已审核的评论，欢迎分享第一条真实体验。',
            '条评论',
            '写评论',
            '撰写图文 / 视频评论',
            '分享您的阅读感受',
            '正在加载评论表单...',
            '支持匿名或登录评论；媒体与扩展字段会和评论一起进入审核。',
            '支持的评论媒体',
            '最多 6 张图片',
            '最多 2 个视频',
            '文字评论',
            '评论图片',
            '请填写评论内容。',
            '标题（选填）',
            '评论内容',
            '请至少填写 10 个字符，分享您的阅读感受。',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function loadCsv(string $path): array
    {
        self::assertFileExists($path);
        $rows = [];
        $handle = fopen($path, 'rb');
        self::assertIsResource($handle);
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = str_getcsv($line);
            if (count($parts) < 2) {
                continue;
            }
            $rows[trim((string)$parts[0])] = trim((string)$parts[1]);
        }
        fclose($handle);

        return $rows;
    }

    public function testBlogReviewKeysHaveEnglishTranslations(): void
    {
        $en = $this->loadCsv(dirname(__DIR__, 3) . '/i18n/en_US.csv');
        foreach ($this->requiredReviewKeys() as $key) {
            self::assertArrayHasKey($key, $en, 'Missing en_US key: ' . $key);
            self::assertNotSame($key, $en[$key], 'Untranslated en_US key: ' . $key);
            self::assertDoesNotMatchRegularExpression('/[\x{4e00}-\x{9fff}]/u', $en[$key], 'Chinese remains in en_US for: ' . $key);
        }
    }

    public function testBlogReviewKeysHaveChineseTranslations(): void
    {
        $zh = $this->loadCsv(dirname(__DIR__, 3) . '/i18n/zh_Hans_CN.csv');
        foreach ($this->requiredReviewKeys() as $key) {
            self::assertArrayHasKey($key, $zh, 'Missing zh_Hans_CN key: ' . $key);
            self::assertSame($key, $zh[$key], 'zh_Hans_CN should keep source text for: ' . $key);
        }
    }
}
