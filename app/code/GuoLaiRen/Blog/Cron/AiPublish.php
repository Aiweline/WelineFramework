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
        $locale = TrendsConfig::get(TrendsConfig::KEY_DEFAULT_LANGUAGE, 'en_US');
        $asDraft = TrendsConfig::publishAsDraft();
        $modeText = $hasTrendSource ? __('趋势增长词模式') : __('画像关键词兜底模式');

        if ($onProgress) {
            $onProgress('start', ['mode' => $modeText, 'quotas_count' => count($quotas)]);
        }

        if (empty($quotas)) {
            if ($onProgress) {
                $onProgress('skip', ['reason' => '无配额记录']);
            }
        }

        foreach ($quotas as $quota) {
            $siteId = (int)$quota->getData(TrendSiteQuota::fields_SITE_ID);
            $profileId = (int)$quota->getData(TrendSiteQuota::fields_PROFILE_ID);
            $perDay = (int)$quota->getData(TrendSiteQuota::fields_ARTICLES_PER_DAY);
            $categoryId = (int)$quota->getData(TrendSiteQuota::fields_DEFAULT_CATEGORY_ID);

            if ($categoryId <= 0) {
                if ($onProgress) {
                    $onProgress('skip', ['reason' => '配额未设置默认分类', 'site_id' => $siteId, 'profile_id' => $profileId]);
                }
                continue;
            }

            $cat = ObjectManager::getInstance(Category::class);
            $cat->clear()->load($categoryId);
            if (!$cat->getId()) {
                if ($onProgress) {
                    $onProgress('skip', ['reason' => '默认分类不存在', 'category_id' => $categoryId]);
                }
                continue;
            }
            if ((int)$cat->getData(Category::fields_SITE_ID) !== $siteId) {
                if ($onProgress) {
                    $onProgress('skip', ['reason' => '默认分类不属于该站点', 'category_id' => $categoryId, 'category_site_id' => $cat->getData(Category::fields_SITE_ID), 'quota_site_id' => $siteId]);
                }
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
                if ($onProgress) {
                    $onProgress('skip', ['reason' => '今日已发满', 'site_id' => $siteId, 'profile_id' => $profileId, 'per_day' => $perDay, 'already' => $already]);
                }
                continue;
            }

            if ($onProgress) {
                $onProgress('quota_info', ['site_id' => $siteId, 'profile_id' => $profileId, 'per_day' => $perDay, 'already' => $already, 'need' => $need, 'category_id' => $categoryId]);
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
                    if ($onProgress) {
                        $onProgress('skip', ['reason' => '画像不存在', 'profile_id' => $profileId]);
                    }
                    continue;
                }
                if ((int)$profile->getData(TrendProfile::fields_IS_ACTIVE) !== 1) {
                    if ($onProgress) {
                        $onProgress('skip', ['reason' => '画像未启用', 'profile_id' => $profileId, 'is_active' => $profile->getData(TrendProfile::fields_IS_ACTIVE)]);
                    }
                    continue;
                }

                $keywords = array_values(array_unique($profile->getKeywordsArray()));
                if (empty($keywords)) {
                    if ($onProgress) {
                        $onProgress('skip', ['reason' => '画像无关键词', 'profile_id' => $profileId, 'raw_keywords' => $profile->getData(TrendProfile::fields_KEYWORDS)]);
                    }
                    continue;
                }
                $keywords = array_slice($keywords, 0, $need);
                $total = count($keywords);
                
                if ($onProgress) {
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
        
        // 如果发布 0 篇且有错误，附加错误提示
        if ($published === 0 && !empty($errors)) {
            $uniqueErrors = array_unique($errors);
            $hint = __('可能原因：') . implode('；', $uniqueErrors);
            $result .= "\n" . $hint;
        } elseif ($published === 0) {
            $result .= "\n" . self::getZeroPublishHint();
        }
        
        if ($onProgress) {
            $onProgress('done', [
                'published' => $published,
                'result' => $result,
                'errors' => array_unique($errors)
            ]);
        }
        return $result;
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
    
    /**
     * 检查 AI 模型配置是否正常
     * @return string|null 返回错误提示（含 HTML 链接），如果正常则返回 null
     */
    private static function checkAiModelConfig(): ?string
    {
        try {
            /** @var \Weline\Ai\Service\DefaultModelManager $defaultModelManager */
            $defaultModelManager = ObjectManager::getInstance(\Weline\Ai\Service\DefaultModelManager::class);
            
            // 检查全局默认模型
            $globalDefault = $defaultModelManager->getDefaultModelForService(
                \Weline\Ai\Service\DefaultModelManager::SERVICE_TYPE_DEFAULT
            );
            
            // 生成配置链接
            /** @var \Weline\Framework\Http\Url $urlBuilder */
            $urlBuilder = ObjectManager::getInstance(\Weline\Framework\Http\Url::class);
            $configUrl = $urlBuilder->getBackendUrl('ai/backend/defaultmodel');
            $modelListUrl = $urlBuilder->getBackendUrl('ai/backend/model');
            $providerUrl = $urlBuilder->getBackendUrl('ai/backend/provider');
            
            $linkHtml = '<div class="ai-config-links" style="margin-top: 10px;">'
                . '<a href="' . $configUrl . '" class="btn btn-sm btn-primary me-2" target="_blank">'
                . '<i class="mdi mdi-star-settings me-1"></i>' . __('配置默认模型') . '</a>'
                . '<a href="' . $modelListUrl . '" class="btn btn-sm btn-outline-secondary me-2" target="_blank">'
                . '<i class="mdi mdi-robot me-1"></i>' . __('模型列表') . '</a>'
                . '<a href="' . $providerUrl . '" class="btn btn-sm btn-outline-secondary" target="_blank">'
                . '<i class="mdi mdi-account-key me-1"></i>' . __('供应商账户') . '</a>'
                . '</div>';
            
            if (!$globalDefault || !$globalDefault->getId()) {
                return __('未配置 AI 默认模型') . $linkHtml;
            }
            
            // 检查模型是否有效
            /** @var \Weline\Ai\Model\AiModel $aiModel */
            $aiModel = ObjectManager::getInstance(\Weline\Ai\Model\AiModel::class);
            $modelCode = $globalDefault->getData(\Weline\Ai\Model\AiDefaultModel::fields_MODEL_CODE);
            $model = $aiModel->clear()->where(\Weline\Ai\Model\AiModel::fields_MODEL_CODE, $modelCode)->find()->fetch();
            
            if (!$model->getId()) {
                return __('AI 默认模型配置无效（模型代码 %{1} 不存在）', [$modelCode]) . $linkHtml;
            }
            
            if ((int)$model->getData(\Weline\Ai\Model\AiModel::fields_IS_ACTIVE) !== 1) {
                return __('AI 默认模型 "%{1}" 未激活', [$model->getData(\Weline\Ai\Model\AiModel::fields_NAME)]) . $linkHtml;
            }
            
            return null;
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
