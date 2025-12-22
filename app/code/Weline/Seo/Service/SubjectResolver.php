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
use Weline\Seo\Model\SeoSubject;

/**
 * 主体解析服务
 * 
 * 根据事件数据或ID解析主体，生成或更新 SeoSubject 记录
 * 
 * @package Weline_Seo
 */
class SubjectResolver
{
    private ObjectManager $objectManager;
    private FeedRegistryService $feedRegistryService;

    public function __construct(
        ObjectManager $objectManager,
        FeedRegistryService $feedRegistryService
    ) {
        $this->objectManager = $objectManager;
        $this->feedRegistryService = $feedRegistryService;
    }

    /**
     * 从事件数据解析并创建/更新 SEO 主体
     * 
     * @param string $subjectType 主体类型
     * @param int $subjectId 主体ID
     * @param mixed $subjectObject 主体对象（可选）
     * @return SeoSubject
     */
    public function resolve(string $subjectType, int $subjectId, $subjectObject = null): SeoSubject
    {
        /** @var SeoSubject $seoSubject */
        $seoSubject = $this->objectManager->getInstance(SeoSubject::class);
        $seoSubject->findOrCreate($subjectType, $subjectId);

        // 收集 Feed 数据
        $feeds = $this->feedRegistryService->collectFeeds($subjectType, $subjectId, [
            'subject' => $subjectObject,
        ]);

        // 合并 Feed 数据（优先使用第一个 Feed）
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
        }

        $seoSubject->save();

        return $seoSubject;
    }
}

