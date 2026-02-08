<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * 读取增长词，调用 AI 生成文章并发布到站点
 */

namespace GuoLaiRen\Blog\Cron;

use GuoLaiRen\Blog\Helper\RandomAuthorName;
use GuoLaiRen\Blog\Model\Category;
use GuoLaiRen\Blog\Model\Post as PostModel;
use GuoLaiRen\Blog\Model\TrendingKeywordLog;
use GuoLaiRen\Blog\Model\TrendsConfig;
use GuoLaiRen\Blog\Model\TrendSiteQuota;
use Weline\Ai\Service\AiService;
use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;

class AiPublish implements CronTaskInterface
{
    public function name(): string
    {
        return __('Blog AI 自动发文');
    }

    public function execute_name(): string
    {
        return 'blog_ai_publish';
    }

    public function tip(): string
    {
        return __('按站点+画像配额，从增长词记录中取词，调用 AI 生成标题+摘要+正文并写入博客（草稿或直接发布）');
    }

    public function cron_time(): string
    {
        return '0 8,20 * * *';
    }

    public function execute(): string
    {
        /** @var TrendSiteQuota $quotaModel */
        $quotaModel = ObjectManager::getInstance(TrendSiteQuota::class);
        $quotas = $quotaModel->clear()->select()->fetch()->getItems();
        $todayStart = date('Y-m-d 00:00:00');
        $published = 0;

        foreach ($quotas as $quota) {
            $siteId = (int)$quota->getData(TrendSiteQuota::fields_SITE_ID);
            $profileId = (int)$quota->getData(TrendSiteQuota::fields_PROFILE_ID);
            $perDay = (int)$quota->getData(TrendSiteQuota::fields_ARTICLES_PER_DAY);
            $categoryId = (int)$quota->getData(TrendSiteQuota::fields_DEFAULT_CATEGORY_ID);

            if ($categoryId <= 0) {
                continue;
            }

            $cat = ObjectManager::getInstance(Category::class);
            $cat->clear()->load($categoryId);
            if (!$cat->getId() || (int)$cat->getData(Category::fields_SITE_ID) !== $siteId) {
                continue;
            }

            $postModel = ObjectManager::getInstance(PostModel::class);
            $already = $postModel->clear()
                ->where(PostModel::fields_SITE_ID, $siteId)
                ->where(PostModel::fields_TREND_PROFILE_ID, $profileId)
                ->where(PostModel::fields_CREATED_AT, $todayStart, '>=')
                ->count();
            $need = max(0, $perDay - $already);
            if ($need <= 0) {
                continue;
            }

            /** @var TrendingKeywordLog $logModel */
            $logModel = ObjectManager::getInstance(TrendingKeywordLog::class);
            $logs = $logModel->clear()
                ->where(TrendingKeywordLog::fields_PROFILE_ID, $profileId)
                ->where(TrendingKeywordLog::fields_USED_AT, null, 'IS')
                ->order(TrendingKeywordLog::fields_ID, 'ASC')
                ->limit($need)
                ->select()
                ->fetch()
                ->getItems();

            $locale = TrendsConfig::get(TrendsConfig::KEY_DEFAULT_LANGUAGE, 'en_US');
            $asDraft = TrendsConfig::publishAsDraft();

            foreach ($logs as $log) {
                $keyword = $log->getData(TrendingKeywordLog::fields_KEYWORD);
                $logId = (int)$log->getData(TrendingKeywordLog::fields_ID);

                try {
                    $article = $this->generateArticle($keyword, $locale);
                    if (empty($article['title']) || empty($article['content'])) {
                        continue;
                    }

                    $slug = $this->uniqueSlug($article['title'], $siteId);
                    $post = ObjectManager::getInstance(PostModel::class);
                    $post->setData(PostModel::fields_SITE_ID, $siteId)
                        ->setData(PostModel::fields_CATEGORY_ID, $categoryId)
                        ->setData(PostModel::fields_TITLE, $article['title'])
                        ->setData(PostModel::fields_SLUG, $slug)
                        ->setData(PostModel::fields_SUMMARY, $article['summary'] ?? '')
                        ->setData(PostModel::fields_CONTENT, $article['content'])
                        ->setData(PostModel::fields_AUTHOR, RandomAuthorName::generate())
                        ->setData(PostModel::fields_STATUS, $asDraft ? PostModel::STATUS_DRAFT : PostModel::STATUS_PUBLISHED)
                        ->setData(PostModel::fields_TREND_PROFILE_ID, $profileId)
                        ->setData(PostModel::fields_PUBLISHED_AT, $asDraft ? null : date('Y-m-d H:i:s'))
                        ->setData(PostModel::fields_VIEW_COUNT, 0)
                        ->setData(PostModel::fields_IS_FEATURED, 0)
                        ->save();

                    $log->setData(TrendingKeywordLog::fields_USED_AT, date('Y-m-d H:i:s'))->save();
                    $published++;
                } catch (\Throwable $e) {
                    trigger_error(
                        __('Blog AI 发文失败（关键词 %{keyword}，log_id %{log_id}）：%{error}', [
                            'keyword' => $keyword,
                            'log_id' => (string)$logId,
                            'error' => $e->getMessage(),
                        ]),
                        E_USER_WARNING
                    );
                    continue;
                }
            }
        }

        return __('自动发文完成：本次发布 %{count} 篇', ['count' => $published]);
    }

    private function generateArticle(string $keyword, string $locale): array
    {
        $prompt = <<<PROMPT
请根据以下关键词生成一篇博客文章。严格按 JSON 输出，不要其他说明。格式：
{"title":"文章标题","summary":"200字以内摘要","content":"正文HTML内容"}

关键词：{$keyword}

要求：标题包含关键词；摘要简明；正文 500-800 字，分段用 <p>，可带小标题。
PROMPT;

        /** @var AiService $ai */
        $ai = ObjectManager::getInstance(AiService::class);
        $response = $ai->generate(
            $prompt,
            null,
            null,
            $locale,
            [],
            null,
            true
        );

        $decoded = json_decode(trim($response), true);
        if (is_array($decoded) && isset($decoded['title']) && isset($decoded['content'])) {
            return [
                'title' => (string)$decoded['title'],
                'summary' => (string)($decoded['summary'] ?? ''),
                'content' => (string)$decoded['content'],
            ];
        }

        return [
            'title' => $keyword . ' - ' . date('Y-m-d'),
            'summary' => '',
            'content' => '<p>' . htmlspecialchars($response) . '</p>',
        ];
    }

    private function uniqueSlug(string $title, int $siteId): string
    {
        $base = preg_replace('/[^a-z0-9\-]/', '-', strtolower($title));
        $base = trim(preg_replace('/-+/', '-', $base), '-') ?: 'post';
        $slug = $base . '-' . date('YmdHis');
        $post = ObjectManager::getInstance(PostModel::class);
        $exists = $post->clear()
            ->where(PostModel::fields_SLUG, $slug)
            ->where(PostModel::fields_SITE_ID, $siteId)
            ->find()
            ->fetch();
        if ($exists->getId()) {
            $slug = $base . '-' . uniqid();
        }
        return $slug;
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 60;
    }
}
