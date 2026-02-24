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
use GuoLaiRen\Blog\Model\TrendProfile;
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

    /**
     * @param callable|null $onProgress 可选，用于 SSE 实时推送。签名 function(string $event, array $data): void
     */
    public function execute(?callable $onProgress = null): string
    {
        /** @var TrendSiteQuota $quotaModel */
        $quotaModel = ObjectManager::getInstance(TrendSiteQuota::class);
        $quotas = $quotaModel->clear()->select()->fetch()->getItems();
        $hasTrendSource = TrendsConfig::useSerpApi() || TrendsConfig::useOfficialApi();
        $todayStart = date('Y-m-d 00:00:00');
        $published = 0;
        $locale = TrendsConfig::get(TrendsConfig::KEY_DEFAULT_LANGUAGE, 'en_US');
        $asDraft = TrendsConfig::publishAsDraft();
        $modeText = $hasTrendSource ? __('趋势增长词模式') : __('画像关键词兜底模式');

        if ($onProgress) {
            $onProgress('start', ['mode' => $modeText, 'quotas_count' => count($quotas)]);
        }

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

            if ($hasTrendSource) {
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

                $idx = 0;
                $total = count($logs);
                foreach ($logs as $log) {
                    $keyword = (string)$log->getData(TrendingKeywordLog::fields_KEYWORD);
                    $logId = (int)$log->getData(TrendingKeywordLog::fields_ID);
                    if ($onProgress) {
                        $onProgress('article_start', ['keyword' => $keyword, 'index' => $idx + 1, 'total' => $total]);
                    }
                    try {
                        if ($this->publishByKeyword($keyword, $siteId, $categoryId, $profileId, $locale, $asDraft)) {
                            $log->setData(TrendingKeywordLog::fields_USED_AT, date('Y-m-d H:i:s'))->save();
                            $published++;
                            if ($onProgress) {
                                $onProgress('article_done', ['keyword' => $keyword, 'published' => $published]);
                            }
                        }
                    } catch (\Throwable $e) {
                        if ($onProgress) {
                            $onProgress('article_error', ['keyword' => $keyword, 'error' => $e->getMessage()]);
                        }
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
                    $idx++;
                }
            } else {
                /** @var TrendProfile $profile */
                $profile = ObjectManager::getInstance(TrendProfile::class);
                $profile->clear()->load($profileId);
                if (!$profile->getId() || (int)$profile->getData(TrendProfile::fields_IS_ACTIVE) !== 1) {
                    continue;
                }

                $keywords = array_values(array_unique($profile->getKeywordsArray()));
                if (empty($keywords)) {
                    continue;
                }
                $keywords = array_slice($keywords, 0, $need);
                $total = count($keywords);

                foreach ($keywords as $idx => $keyword) {
                    if ($onProgress) {
                        $onProgress('article_start', ['keyword' => $keyword, 'index' => $idx + 1, 'total' => $total]);
                    }
                    try {
                        if ($this->publishByKeyword($keyword, $siteId, $categoryId, $profileId, $locale, $asDraft)) {
                            $published++;
                            if ($onProgress) {
                                $onProgress('article_done', ['keyword' => $keyword, 'published' => $published]);
                            }
                        }
                    } catch (\Throwable $e) {
                        if ($onProgress) {
                            $onProgress('article_error', ['keyword' => $keyword, 'error' => $e->getMessage()]);
                        }
                        trigger_error(
                            __('Blog AI 发文失败（关键词 %{keyword}，log_id %{log_id}）：%{error}', [
                                'keyword' => (string)$keyword,
                                'log_id' => 'fallback',
                                'error' => $e->getMessage(),
                            ]),
                            E_USER_WARNING
                        );
                        continue;
                    }
                }
            }
        }

        $result = __('自动发文完成：本次发布 %{count} 篇', ['count' => $published]);
        if ($onProgress) {
            $onProgress('done', ['published' => $published, 'result' => $result]);
        }
        return $result;
    }

    /**
     * 当本次发布 0 篇时，返回友好说明（供前端 Toast 展示）
     */
    public static function getZeroPublishHint(): string
    {
        $quotaModel = ObjectManager::getInstance(TrendSiteQuota::class);
        $quotas = $quotaModel->clear()->select()->fetch()->getItems();
        if (empty($quotas)) {
            return __('请先在「趋势站点配额」中配置：站点、画像、每日篇数、默认分类。');
        }
        $hasTrendSource = TrendsConfig::useSerpApi() || TrendsConfig::useOfficialApi();
        $todayStart = date('Y-m-d 00:00:00');
        $hints = [];
        foreach ($quotas as $quota) {
            $siteId = (int)$quota->getData(TrendSiteQuota::fields_SITE_ID);
            $profileId = (int)$quota->getData(TrendSiteQuota::fields_PROFILE_ID);
            $perDay = (int)$quota->getData(TrendSiteQuota::fields_ARTICLES_PER_DAY);
            $categoryId = (int)$quota->getData(TrendSiteQuota::fields_DEFAULT_CATEGORY_ID);
            if ($categoryId <= 0) {
                $hints[] = __('配额未设置默认分类');
                continue;
            }
            $cat = ObjectManager::getInstance(Category::class);
            $cat->clear()->load($categoryId);
            if (!$cat->getId() || (int)$cat->getData(Category::fields_SITE_ID) !== $siteId) {
                $hints[] = __('默认分类不存在或不属于该站点');
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
                $hints[] = __('今日该配额已发满，明日再试');
                continue;
            }
            if ($hasTrendSource) {
                $logModel = ObjectManager::getInstance(TrendingKeywordLog::class);
                $logsCount = $logModel->clear()
                    ->where(TrendingKeywordLog::fields_PROFILE_ID, $profileId)
                    ->where(TrendingKeywordLog::fields_USED_AT, null, 'IS')
                    ->count();
                if ($logsCount <= 0) {
                    $hints[] = __('当前无未使用的增长词，请先运行「趋势同步」或明日再试');
                }
            } else {
                $profile = ObjectManager::getInstance(TrendProfile::class);
                $profile->clear()->load($profileId);
                if (!$profile->getId()) {
                    $hints[] = __('画像不存在');
                } elseif ((int)$profile->getData(TrendProfile::fields_IS_ACTIVE) !== 1) {
                    $hints[] = __('请启用画像');
                } else {
                    $keywords = $profile->getKeywordsArray();
                    if (empty($keywords)) {
                        $hints[] = __('请在该画像中配置关键词');
                    }
                }
            }
        }
        if (!empty($hints)) {
            return __('可能原因：') . implode('；', array_unique($hints));
        }
        return __('请检查 AI 服务配置或稍后重试。');
    }

    private function publishByKeyword(
        string $keyword,
        int $siteId,
        int $categoryId,
        int $profileId,
        string $locale,
        bool $asDraft
    ): bool {
        $article = $this->generateArticle($keyword, $locale);
        if (empty($article['title']) || empty($article['content'])) {
            return false;
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

        return true;
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
