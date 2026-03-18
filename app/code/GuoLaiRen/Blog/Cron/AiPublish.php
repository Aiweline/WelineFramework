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
use Weline\Ai\Adapter\ArticleGenerationAdapter;
use Weline\Ai\Service\ArticleGenerationService;
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
        $errors = []; // 收集错误信息
        $skipReasons = []; // 与定时任务日志一致：每次跳过发文的明确原因（供 SSE / 返回文案）
        $locale = TrendsConfig::get(TrendsConfig::KEY_DEFAULT_LANGUAGE, 'en_US');
        $asDraft = TrendsConfig::publishAsDraft();
        $modeText = $hasTrendSource ? __('趋势增长词模式') : __('画像关键词兜底模式');

        if ($onProgress) {
            $onProgress('start', [
                'message' => __('当前模式：%{mode}，待处理配额：%{count}', ['mode' => $modeText, 'count' => count($quotas)]),
                'mode' => $modeText,
                'quotas_count' => count($quotas),
            ]);
        }

        if (empty($quotas)) {
            $this->recordSkip($onProgress, $skipReasons, 'no_quotas', []);
        }

        foreach ($quotas as $quota) {
            $siteId = (int)$quota->getData(TrendSiteQuota::schema_fields_SITE_ID);
            $profileId = (int)$quota->getData(TrendSiteQuota::schema_fields_PROFILE_ID);
            $perDay = (int)$quota->getData(TrendSiteQuota::schema_fields_ARTICLES_PER_DAY);
            $categoryId = (int)$quota->getData(TrendSiteQuota::schema_fields_DEFAULT_CATEGORY_ID);

            if ($categoryId <= 0) {
                $this->recordSkip($onProgress, $skipReasons, 'no_category', ['site_id' => $siteId, 'profile_id' => $profileId]);
                continue;
            }

            $cat = ObjectManager::getInstance(Category::class);
            $cat->clear()->load($categoryId);
            if (!$cat->getId()) {
                $this->recordSkip($onProgress, $skipReasons, 'category_missing', ['site_id' => $siteId, 'profile_id' => $profileId, 'category_id' => $categoryId]);
                continue;
            }
            if ((int)$cat->getData(Category::schema_fields_SITE_ID) !== $siteId) {
                $this->recordSkip($onProgress, $skipReasons, 'category_wrong_site', [
                    'site_id' => $siteId,
                    'profile_id' => $profileId,
                    'category_id' => $categoryId,
                    'category_site_id' => (int)$cat->getData(Category::schema_fields_SITE_ID),
                ]);
                continue;
            }

            $postModel = ObjectManager::getInstance(PostModel::class);
            $already = $postModel->clear()
                ->where(PostModel::schema_fields_SITE_ID, $siteId)
                ->where(PostModel::schema_fields_TREND_PROFILE_ID, $profileId)
                ->where(PostModel::schema_fields_CREATED_AT, $todayStart, '>=')
                ->count();
            $need = max(0, $perDay - $already);
            if ($need <= 0) {
                $this->recordSkip($onProgress, $skipReasons, 'quota_full', [
                    'site_id' => $siteId,
                    'profile_id' => $profileId,
                    'per_day' => $perDay,
                    'already' => $already,
                ]);
                continue;
            }

            if ($onProgress) {
                $onProgress('quota_info', ['site_id' => $siteId, 'profile_id' => $profileId, 'per_day' => $perDay, 'already' => $already, 'need' => $need, 'category_id' => $categoryId]);
            }

            if ($hasTrendSource) {
                $usedKeywords = $this->getUsedKeywordsForQuota($siteId, $profileId);
                /** @var TrendingKeywordLog $logModel */
                $logModel = ObjectManager::getInstance(TrendingKeywordLog::class);
                $allLogs = $logModel->clear()
                    ->where(TrendingKeywordLog::schema_fields_PROFILE_ID, $profileId)
                    ->where(TrendingKeywordLog::schema_fields_USED_AT, null, 'IS')
                    ->order(TrendingKeywordLog::schema_fields_ID, 'ASC')
                    ->limit(max($need * 3, 100))
                    ->select()
                    ->fetch()
                    ->getItems();
                $logs = [];
                $seenKw = [];
                foreach ($allLogs as $log) {
                    $kw = (string)$log->getData(TrendingKeywordLog::schema_fields_KEYWORD);
                    if (isset($usedKeywords[$kw]) || isset($seenKw[$kw])) {
                        continue;
                    }
                    $seenKw[$kw] = true;
                    $logs[] = $log;
                    if (count($logs) >= $need) {
                        break;
                    }
                }

                $idx = 0;
                $total = count($logs);
                if ($need > 0 && $total === 0) {
                    $this->recordSkip($onProgress, $skipReasons, 'trend_no_unused', ['site_id' => $siteId, 'profile_id' => $profileId, 'need' => $need]);
                }
                foreach ($logs as $log) {
                    $keyword = (string)$log->getData(TrendingKeywordLog::schema_fields_KEYWORD);
                    $logId = (int)$log->getData(TrendingKeywordLog::schema_fields_ID);
                    if ($onProgress) {
                        $onProgress('article_start', ['keyword' => $keyword, 'index' => $idx + 1, 'total' => $total]);
                    }
                    try {
                        if ($this->publishByKeyword($keyword, $siteId, $categoryId, $profileId, $locale, $asDraft)) {
                            $log->setData(TrendingKeywordLog::schema_fields_USED_AT, date('Y-m-d H:i:s'))->save();
                            $published++;
                            if ($onProgress) {
                                $onProgress('article_done', ['keyword' => $keyword, 'published' => $published]);
                            }
                        }
                    } catch (\Throwable $e) {
                        $errorMsg = $this->extractCleanError($e->getMessage());
                        $errors[] = $errorMsg;
                        if ($onProgress) {
                            $onProgress('article_error', ['keyword' => $keyword, 'error' => $errorMsg]);
                        }
                        trigger_error(
                            __('Blog AI 发文失败（关键词 %{keyword}，log_id %{log_id}）：%{error}', [
                                'keyword' => $keyword,
                                'log_id' => (string)$logId,
                                'error' => $errorMsg,
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
                if (!$profile->getId()) {
                    $this->recordSkip($onProgress, $skipReasons, 'profile_missing', ['site_id' => $siteId, 'profile_id' => $profileId]);
                    continue;
                }
                if ((int)$profile->getData(TrendProfile::schema_fields_IS_ACTIVE) !== 1) {
                    $this->recordSkip($onProgress, $skipReasons, 'profile_inactive', [
                        'site_id' => $siteId,
                        'profile_id' => $profileId,
                        'is_active' => $profile->getData(TrendProfile::schema_fields_IS_ACTIVE),
                    ]);
                    continue;
                }

                $rawKeywords = array_values(array_unique($profile->getKeywordsArray()));
                if (empty($rawKeywords)) {
                    $this->recordSkip($onProgress, $skipReasons, 'profile_no_keywords', ['site_id' => $siteId, 'profile_id' => $profileId]);
                    continue;
                }
                $usedKeywords = $this->getUsedKeywordsForQuota($siteId, $profileId);
                $unused = array_values(array_diff($rawKeywords, array_keys($usedKeywords)));
                if ($unused === []) {
                    // 兜底模式：关键词都曾发过文时仍按每日配额发文，复用关键词池（slug/时间不同，避免「有配额却 0 篇」）
                    if ($onProgress) {
                        $onProgress('fallback_reuse_keywords', [
                            'message' => __('该画像关键词均已用于发文，本次按配额复用关键词继续生成。'),
                            'profile_id' => $profileId,
                            'need' => $need,
                        ]);
                    }
                    $keywords = [];
                    $n = count($rawKeywords);
                    for ($i = 0; $i < $need; $i++) {
                        $keywords[] = $rawKeywords[$i % $n];
                    }
                } else {
                    $keywords = array_slice($unused, 0, $need);
                }
                $total = count($keywords);
                if ($onProgress && $total > 0) {
                    $onProgress('fallback_keywords', ['profile_id' => $profileId, 'keywords_count' => $total, 'keywords' => $keywords]);
                }

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
                        $errorMsg = $this->extractCleanError($e->getMessage());
                        $errors[] = $errorMsg;
                        if ($onProgress) {
                            $onProgress('article_error', ['keyword' => $keyword, 'error' => $errorMsg]);
                        }
                        trigger_error(
                            __('Blog AI 发文失败（关键词 %{keyword}，log_id %{log_id}）：%{error}', [
                                'keyword' => (string)$keyword,
                                'log_id' => 'fallback',
                                'error' => $errorMsg,
                            ]),
                            E_USER_WARNING
                        );
                        continue;
                    }
                }
            }
        }

        $result = __('自动发文完成：本次发布 %{count} 篇', ['count' => $published]);
        $skipUnique = array_values(array_unique($skipReasons));
        $errorUnique = array_values(array_unique($errors));
        $hintBlock = '';

        if ($published === 0) {
            $lines = [];
            if ($skipUnique !== []) {
                $lines = $skipUnique;
            }
            if ($errorUnique !== []) {
                $lines[] = __('生成异常：') . implode('；', $errorUnique);
            }
            if ($lines === []) {
                $lines[] = self::getZeroPublishHint();
            }
            $hintBlock = implode("\n", $lines);
            $result .= "\n" . $hintBlock;
        } elseif ($errorUnique !== []) {
            $hintBlock = __('部分关键词生成失败：') . implode('；', $errorUnique);
            $result .= "\n" . $hintBlock;
        }

        if ($onProgress) {
            $onProgress('done', [
                'published' => $published,
                'result' => $result,
                'hint' => $hintBlock !== '' ? $hintBlock : null,
                'errors' => $errorUnique,
                'skip_reasons' => $skipUnique,
            ]);
        }
        return $result;
    }
    
    /**
     * 获取该站点+画像下已用于生成文章的关键词（用于排重，避免同一关键词重复生成）
     *
     * @return array<string, true> 关键词 => true，便于 in_array 判断
     */
    private function getUsedKeywordsForQuota(int $siteId, int $profileId): array
    {
        $postModel = ObjectManager::getInstance(PostModel::class);
        $items = $postModel->clear()
            ->where(PostModel::schema_fields_SITE_ID, $siteId)
            ->where(PostModel::schema_fields_TREND_PROFILE_ID, $profileId)
            ->where(PostModel::schema_fields_SOURCE_KEYWORD, null, 'IS NOT NULL')
            ->fields(PostModel::schema_fields_SOURCE_KEYWORD)
            ->select()
            ->fetch()
            ->getItems();
        $used = [];
        foreach ($items as $row) {
            $kw = trim((string)$row->getData(PostModel::schema_fields_SOURCE_KEYWORD));
            if ($kw !== '') {
                $used[$kw] = true;
            }
        }
        return $used;
    }

    /**
     * 清理错误信息中的 ANSI 颜色代码
     */
    private function extractCleanError(string $message): string
    {
        // 移除 ANSI 颜色代码 (如 \033[34m 或 [34m)
        return trim(preg_replace('/\x1b\[[0-9;]*m|\[[0-9;]*m/', '', $message));
    }

    /**
     * 记录跳过原因（与 cron 返回文案一致），并推送到 SSE：skip 事件带 message 字段
     *
     * @param callable|null $onProgress
     * @param list<string> $skipReasons
     * @param array<string, mixed> $ctx
     */
    private function recordSkip(?callable $onProgress, array &$skipReasons, string $code, array $ctx): void
    {
        $siteId = (int)($ctx['site_id'] ?? 0);
        $profileId = (int)($ctx['profile_id'] ?? 0);
        $loc = ($siteId > 0 || $profileId > 0)
            ? ' ' . __('[站点#%{s} 画像#%{p}]', ['s' => (string)$siteId, 'p' => (string)$profileId])
            : '';
        $msg = match ($code) {
            'no_quotas' => __('请先在「趋势站点配额」中配置：站点、画像、每日篇数、默认分类。'),
            'no_category' => __('配额未设置默认分类') . $loc,
            'category_missing' => __('默认分类不存在（请检查配额中的分类 ID）') . $loc
                . ($ctx['category_id'] ?? null ? ' [category_id=' . (int)$ctx['category_id'] . ']' : ''),
            'category_wrong_site' => __('默认分类不属于该站点，请重新选择分类') . $loc,
            'quota_full' => __('今日该配额已发满，明日再试') . $loc,
            'profile_missing' => __('画像不存在') . $loc,
            'profile_inactive' => __('请启用画像后再发文') . $loc,
            'profile_no_keywords' => __('请在该画像中配置关键词') . $loc,
            'trend_no_unused' => __('当前无未使用的增长词，请先运行「趋势同步」或明日再试') . $loc,
            default => $code . $loc,
        };
        if (!in_array($msg, $skipReasons, true)) {
            $skipReasons[] = $msg;
        }
        if ($onProgress !== null) {
            $onProgress('skip', array_merge(['reason' => $code, 'message' => $msg], $ctx));
        }
    }

    /**
     * 当本次发布 0 篇时，返回友好说明（供前端 Toast 展示）
     */
    public static function getZeroPublishHint(): string
    {
        // 首先检查 AI 模型配置
        $aiHint = self::checkAiModelConfig();
        if ($aiHint) {
            return $aiHint;
        }
        
        $quotaModel = ObjectManager::getInstance(TrendSiteQuota::class);
        $quotas = $quotaModel->clear()->select()->fetch()->getItems();
        if (empty($quotas)) {
            return __('请先在「趋势站点配额」中配置：站点、画像、每日篇数、默认分类。');
        }
        $hasTrendSource = TrendsConfig::useSerpApi() || TrendsConfig::useOfficialApi();
        $todayStart = date('Y-m-d 00:00:00');
        $hints = [];
        foreach ($quotas as $quota) {
            $siteId = (int)$quota->getData(TrendSiteQuota::schema_fields_SITE_ID);
            $profileId = (int)$quota->getData(TrendSiteQuota::schema_fields_PROFILE_ID);
            $perDay = (int)$quota->getData(TrendSiteQuota::schema_fields_ARTICLES_PER_DAY);
            $categoryId = (int)$quota->getData(TrendSiteQuota::schema_fields_DEFAULT_CATEGORY_ID);
            if ($categoryId <= 0) {
                $hints[] = __('配额未设置默认分类');
                continue;
            }
            $cat = ObjectManager::getInstance(Category::class);
            $cat->clear()->load($categoryId);
            if (!$cat->getId() || (int)$cat->getData(Category::schema_fields_SITE_ID) !== $siteId) {
                $hints[] = __('默认分类不存在或不属于该站点');
                continue;
            }
            $postModel = ObjectManager::getInstance(PostModel::class);
            $already = $postModel->clear()
                ->where(PostModel::schema_fields_SITE_ID, $siteId)
                ->where(PostModel::schema_fields_TREND_PROFILE_ID, $profileId)
                ->where(PostModel::schema_fields_CREATED_AT, $todayStart, '>=')
                ->count();
            $need = max(0, $perDay - $already);
            if ($need <= 0) {
                $hints[] = __('今日该配额已发满，明日再试');
                continue;
            }
            if ($hasTrendSource) {
                $logModel = ObjectManager::getInstance(TrendingKeywordLog::class);
                $logsCount = $logModel->clear()
                    ->where(TrendingKeywordLog::schema_fields_PROFILE_ID, $profileId)
                    ->where(TrendingKeywordLog::schema_fields_USED_AT, null, 'IS')
                    ->count();
                if ($logsCount <= 0) {
                    $hints[] = __('当前无未使用的增长词，请先运行「趋势同步」或明日再试');
                }
            } else {
                $profile = ObjectManager::getInstance(TrendProfile::class);
                $profile->clear()->load($profileId);
                if (!$profile->getId()) {
                    $hints[] = __('画像不存在');
                } elseif ((int)$profile->getData(TrendProfile::schema_fields_IS_ACTIVE) !== 1) {
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
    
    /**
     * 检查 AI 模型配置是否正常（与 ArticleGenerationService 使用相同的解析顺序）
     * @return string|null 返回错误提示（含 HTML 链接），如果正常则返回 null
     */
    private static function checkAiModelConfig(): ?string
    {
        try {
            $scenarioCode = 'pagebuilder_article_generation'; // 与 ArticleGenerationService 使用的场景一致
            $defaultModelManager = ObjectManager::getInstance(\Weline\Ai\Service\DefaultModelManager::class);
            /** @var \Weline\Ai\Model\AiModel $aiModel */
            $aiModel = ObjectManager::getInstance(\Weline\Ai\Model\AiModel::class);

            // 与 AiService::selectModel 相同顺序：1.场景适配器 2.场景默认 3.全局默认 4.IS_DEFAULT 标记
            $model = null;

            $adapterScanner = ObjectManager::getInstance(\Weline\Ai\Service\AdapterScanner::class);
            $adapterModelCode = $adapterScanner->getDefaultModelCodeForAdapter($scenarioCode);
            if ($adapterModelCode) {
                $model = $aiModel->clear()->where(\Weline\Ai\Model\AiModel::schema_fields_MODEL_CODE, $adapterModelCode)
                    ->where(\Weline\Ai\Model\AiModel::schema_fields_IS_ACTIVE, 1)->find()->fetch();
            }
            if (!$model || !$model->getId()) {
                $model = $defaultModelManager->getDefaultModel($scenarioCode);
            }
            if (!$model || !$model->getId()) {
                $model = $defaultModelManager->getDefaultModel(\Weline\Ai\Service\DefaultModelManager::SERVICE_TYPE_DEFAULT);
            }
            if (!$model || !$model->getId()) {
                $model = $aiModel->clear()->where(\Weline\Ai\Model\AiModel::schema_fields_IS_ACTIVE, 1)
                    ->where(\Weline\Ai\Model\AiModel::schema_fields_IS_DEFAULT, 1)->find()->fetch();
            }

            if ($model && $model->getId()) {
                return null;
            }

            $urlBuilder = ObjectManager::getInstance(\Weline\Framework\Http\Url::class);
            $adapterUrl = $urlBuilder->getBackendUrl('ai/backend/adapter');
            $configUrl = $urlBuilder->getBackendUrl('ai/backend/defaultmodel');
            $modelListUrl = $urlBuilder->getBackendUrl('ai/backend/model');
            $providerUrl = $urlBuilder->getBackendUrl('ai/backend/provider');
            $linkHtml = '<div class="ai-config-links" style="margin-top: 10px;">'
                . '<a href="' . $adapterUrl . '" class="btn btn-sm btn-primary me-2" target="_blank">'
                . '<i class="mdi mdi-puzzle me-1"></i>' . __('场景适配器') . '</a>'
                . '<a href="' . $configUrl . '" class="btn btn-sm btn-outline-secondary me-2" target="_blank">'
                . '<i class="mdi mdi-star-settings me-1"></i>' . __('配置默认模型') . '</a>'
                . '<a href="' . $modelListUrl . '" class="btn btn-sm btn-outline-secondary me-2" target="_blank">'
                . '<i class="mdi mdi-robot me-1"></i>' . __('模型列表') . '</a>'
                . '<a href="' . $providerUrl . '" class="btn btn-sm btn-outline-secondary" target="_blank">'
                . '<i class="mdi mdi-account-key me-1"></i>' . __('供应商账户') . '</a>'
                . '</div>';

            return __('未配置 AI 默认模型（可在「场景适配器」中为文章生成适配器设置默认模型，或在「默认模型配置」中配置）') . $linkHtml;
        } catch (\Throwable $e) {
            return __('AI 模型配置检查失败：%{1}', [$e->getMessage()]);
        }
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

        // 检测生成错误，抛出异常让调用方捕获并记录
        if (!empty($article['_error'])) {
            throw new \RuntimeException($article['_error_message'] ?? __('文章生成失败'));
        }

        if (empty($article['title']) || empty($article['content'])) {
            throw new \RuntimeException(
                __('AI 返回的标题或正文为空，请检查模型、提示词或关键词是否过短。')
            );
        }

        $slug = $this->uniqueSlug($article['title'], $siteId);
        $post = ObjectManager::getInstance(PostModel::class);
        $post->setData(PostModel::schema_fields_SITE_ID, $siteId)
            ->setData(PostModel::schema_fields_CATEGORY_ID, $categoryId)
            ->setData(PostModel::schema_fields_TITLE, $article['title'])
            ->setData(PostModel::schema_fields_SLUG, $slug)
            ->setData(PostModel::schema_fields_SUMMARY, $article['summary'] ?? '')
            ->setData(PostModel::schema_fields_CONTENT, $article['content'])
            ->setData(PostModel::schema_fields_AUTHOR, RandomAuthorName::generate())
            ->setData(PostModel::schema_fields_STATUS, $asDraft ? PostModel::STATUS_DRAFT : PostModel::STATUS_PUBLISHED)
            ->setData(PostModel::schema_fields_TREND_PROFILE_ID, $profileId)
            ->setData(PostModel::schema_fields_SOURCE_KEYWORD, $keyword)
            ->setData(PostModel::schema_fields_PUBLISHED_AT, $asDraft ? null : date('Y-m-d H:i:s'))
            ->setData(PostModel::schema_fields_VIEW_COUNT, 0)
            ->setData(PostModel::schema_fields_IS_FEATURED, 0)
            ->save();

        return true;
    }

    private function generateArticle(string $keyword, string $locale): array
    {
        /** @var ArticleGenerationService $articleService */
        $articleService = ObjectManager::getInstance(ArticleGenerationService::class);
        
        return $articleService->generateBlogArticle($keyword, $locale, [
            'article_type' => ArticleGenerationAdapter::ARTICLE_TYPE_BLOG,
            'style' => ArticleGenerationAdapter::STYLE_PROFESSIONAL,
            'length' => ArticleGenerationAdapter::LENGTH_MEDIUM,
            'include_seo' => true,
            'is_backend' => true,
        ]);
    }

    private function uniqueSlug(string $title, int $siteId): string
    {
        $base = preg_replace('/[^a-z0-9\-]/', '-', strtolower($title));
        $base = trim(preg_replace('/-+/', '-', $base), '-') ?: 'post';
        $slug = $base . '-' . date('YmdHis');
        $post = ObjectManager::getInstance(PostModel::class);
        $exists = $post->clear()
            ->where(PostModel::schema_fields_SLUG, $slug)
            ->where(PostModel::schema_fields_SITE_ID, $siteId)
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
