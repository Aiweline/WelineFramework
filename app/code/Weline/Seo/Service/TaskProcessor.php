<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoTask;
use Weline\Seo\Model\SeoSubject;
use Weline\Seo\Model\SeoKeyword;
use Weline\Seo\Service\EventDispatcher;
use Weline\Seo\Model\SeoAccount;
use Weline\Seo\Service\SearchEngineAdapterRegistry;

/**
 * SEO 任务处理器
 * 
 * 统一处理各种SEO任务
 * 
 * @package Weline_Seo
 */
class TaskProcessor
{
    private ObjectManager $objectManager;
    private SubjectResolver $subjectResolver;
    private KeywordExtractorService $keywordExtractor;
    private FeedRegistryService $feedRegistryService;
    private EventDispatcher $eventDispatcher;
    private SearchEngineAdapterRegistry $adapterRegistry;

    public function __construct(
        ObjectManager $objectManager,
        SubjectResolver $subjectResolver,
        KeywordExtractorService $keywordExtractor,
        FeedRegistryService $feedRegistryService,
        EventDispatcher $eventDispatcher,
        SearchEngineAdapterRegistry $adapterRegistry
    ) {
        $this->objectManager = $objectManager;
        $this->subjectResolver = $subjectResolver;
        $this->keywordExtractor = $keywordExtractor;
        $this->feedRegistryService = $feedRegistryService;
        $this->eventDispatcher = $eventDispatcher;
        $this->adapterRegistry = $adapterRegistry;
    }

    /**
     * 处理任务
     * 
     * @param SeoTask $task
     * @return bool 是否处理成功
     */
    public function process(SeoTask $task): bool
    {
        try {
            $taskType = $task->getTaskType();
            
            switch ($taskType) {
                case SeoTask::TASK_TYPE_FEED_GENERATE:
                    return $this->processFeedGenerate($task);
                    
                case SeoTask::TASK_TYPE_PUSH_URLS:
                    return $this->processPushUrls($task);
                    
                case SeoTask::TASK_TYPE_KEYWORD_EXTRACT:
                    return $this->processKeywordExtract($task);
                    
                default:
                    $task->markError("未知的任务类型: {$taskType}");
                    return false;
            }
        } catch (\Exception $e) {
            $task->markError($e->getMessage());
            return false;
        }
    }

    /**
     * 处理Feed生成任务
     * 
     * @param SeoTask $task
     * @return bool
     */
    private function processFeedGenerate(SeoTask $task): bool
    {
        $payload = $task->getPayloadArray();
        $subjectType = $task->getSubjectType();
        $subjectId = $task->getSubjectId();

        // 解析并创建/更新 SEO 主体
        $seoSubject = $this->subjectResolver->resolve($subjectType, $subjectId);

        // 从Feed Providers收集数据
        $feeds = $this->feedRegistryService->collectFeeds($subjectType, $subjectId, [
            'payload' => $payload,
        ]);

        // 合并Feed数据更新主体
        if (!empty($feeds)) {
            $feed = reset($feeds);
            
            if (isset($feed['url'])) {
                $seoSubject->setUrl($feed['url']);
            }
            if (isset($feed['title'])) {
                $seoSubject->setTitle($feed['title']);
            }
            if (isset($feed['description'])) {
                $seoSubject->setDescription($feed['description']);
            }
            if (isset($feed['meta_data']['locale'])) {
                $seoSubject->setLocale($feed['meta_data']['locale']);
            }
            
            $seoSubject->save();

            // 分发SEO主体创建事件
            $this->eventDispatcher->dispatchSubjectCreated(
                $seoSubject->getId(),
                $subjectType,
                $subjectId,
                [
                    'url' => $seoSubject->getUrl(),
                    'title' => $seoSubject->getTitle(),
                    'description' => $seoSubject->getDescription(),
                    'locale' => $seoSubject->getLocale(),
                ]
            );

            // 提取并保存关键词
            $texts = [];
            if ($seoSubject->getDescription()) {
                $texts[] = $seoSubject->getDescription();
            }
            if ($seoSubject->getTitle()) {
                $texts[] = $seoSubject->getTitle();
            }
            if (isset($feed['keywords']) && is_array($feed['keywords'])) {
                // 从Feed中获取关键词
                foreach ($feed['keywords'] as $keyword) {
                    $this->saveKeyword($seoSubject->getId(), $keyword, SeoKeyword::SOURCE_EXTRACTED);
                }
            }

            $allText = implode(' ', $texts);
            if (!empty($allText)) {
                $keywords = $this->keywordExtractor->extract($allText);
                if (!empty($keywords)) {
                    $this->keywordExtractor->saveKeywords(
                        $seoSubject->getId(),
                        $keywords,
                        SeoKeyword::SOURCE_EXTRACTED
                    );
                    
                    // 分发关键词提取完成事件
                    $this->eventDispatcher->dispatchKeywordsExtracted(
                        $seoSubject->getId(),
                        $keywords,
                        SeoKeyword::SOURCE_EXTRACTED
                    );
                }
            }
        } else {
            // 如果没有Feed Provider，从payload中提取
            $texts = [];
            if (!empty($payload['description'])) {
                $texts[] = $payload['description'];
            }
            if (!empty($payload['meta_title'])) {
                $texts[] = $payload['meta_title'];
            }
            if (!empty($payload['meta_description'])) {
                $texts[] = $payload['meta_description'];
            }
            if (!empty($payload['meta_keywords'])) {
                $texts[] = $payload['meta_keywords'];
            }

            $allText = implode(' ', $texts);
            if (!empty($allText)) {
                $keywords = $this->keywordExtractor->extract($allText);
                if (!empty($keywords)) {
                    $this->keywordExtractor->saveKeywords(
                        $seoSubject->getId(),
                        $keywords,
                        SeoKeyword::SOURCE_EXTRACTED
                    );
                    
                    // 分发关键词提取完成事件
                    $this->eventDispatcher->dispatchKeywordsExtracted(
                        $seoSubject->getId(),
                        $keywords,
                        SeoKeyword::SOURCE_EXTRACTED
                    );
                }
            }
        }

        $task->markDone('Feed生成成功');
        return true;
    }

    /**
     * 处理URL推送任务
     * 
     * @param SeoTask $task
     * @return bool
     */
    private function processPushUrls(SeoTask $task): bool
    {
        $payload = $task->getPayloadArray();
        $urls = $payload['urls'] ?? [];
        $provider = (string)($payload['provider'] ?? '');
        $accountId = (int)($payload['account_id'] ?? 0);
        $scope = (string)($payload['scope'] ?? '');
        $module = (string)($payload['module'] ?? '');

        if (empty($urls)) {
            $task->markError('URL列表为空');
            return false;
        }

        if ($provider === '' || $accountId <= 0) {
            $task->markError('缺少provider或account_id，无法推送URL');
            return false;
        }

        /** @var SeoAccount $accountModel */
        $accountModel = $this->objectManager->getInstance(SeoAccount::class);
        $account = $accountModel->reset()->load($accountId);

        if (!$account->getId() || !$account->isActive()) {
            $task->markError('SEO账户不可用或未启用，account_id=' . $accountId);
            return false;
        }

        $adapter = $this->adapterRegistry->getAdapter($provider);
        if ($adapter === null) {
            $task->markError('未找到对应的搜索引擎适配器: ' . $provider);
            return false;
        }

        $options = [
            'scope' => $scope,
            'module' => $module,
            'account' => [
                'id' => $account->getId(),
                'scope' => $account->getData(SeoAccount::fields_SCOPE),
                'module' => $account->getData(SeoAccount::fields_MODULE),
                'provider' => $account->getData(SeoAccount::fields_PROVIDER),
                'name' => $account->getData(SeoAccount::fields_NAME),
            ],
            'config' => $account->getConfigArray(),
        ];

        $result = $adapter->pushUrls($urls, $options);

        if (!($result['success'] ?? false)) {
            $message = (string)($result['message'] ?? 'URL推送失败');
            $task->markError($message);
            return false;
        }

        $message = (string)($result['message'] ?? 'URL推送成功');
        $task->markDone($message);
        return true;
    }

    /**
     * 处理关键词提取任务
     * 
     * @param SeoTask $task
     * @return bool
     */
    private function processKeywordExtract(SeoTask $task): bool
    {
        $payload = $task->getPayloadArray();
        $subjectId = $task->getSubjectId();
        $text = $payload['text'] ?? '';

        if (empty($text)) {
            $task->markError('文本内容为空');
            return false;
        }

        $keywords = $this->keywordExtractor->extract($text);
        if (!empty($keywords)) {
            $this->keywordExtractor->saveKeywords(
                $subjectId,
                $keywords,
                SeoKeyword::SOURCE_EXTRACTED
            );
        }

        $task->markDone('关键词提取成功，共提取 ' . count($keywords) . ' 个关键词');
        return true;
    }

    /**
     * 保存关键词
     * 
     * @param int $subjectId
     * @param string $keyword
     * @param string $source
     * @return void
     */
    private function saveKeyword(int $subjectId, string $keyword, string $source): void
    {
        /** @var SeoKeyword $keywordModel */
        $keywordModel = $this->objectManager->getInstance(SeoKeyword::class);
        
        $keywordModel->reset()
            ->where(SeoKeyword::fields_SUBJECT_ID, $subjectId)
            ->where(SeoKeyword::fields_KEYWORD, $keyword)
            ->find()
            ->fetch();
        
        if (!$keywordModel->getId()) {
            $keywordModel->reset()
                ->setSubjectId($subjectId)
                ->setKeyword($keyword)
                ->setSource($source)
                ->setStatus(SeoKeyword::STATUS_ENABLED)
                ->setPriority(0)
                ->save();
        }
    }
}

