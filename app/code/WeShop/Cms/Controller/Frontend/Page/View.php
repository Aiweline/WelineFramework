<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace WeShop\Cms\Controller\Frontend\Page;

use WeShop\Cms\Service\CmsPageService;
use WeShop\Frontend\Controller\BaseController;

class View extends BaseController
{
    protected ?string $layoutType = 'cms';

    public function __construct(
        private readonly CmsPageService $cmsPageService
    ) {
    }

    public function index(): string
    {
        $identifier = (string) $this->request->getParam('identifier', '');
        $pageId = (int) $this->request->getParam('id', 0);

        $page = null;
        if ($pageId > 0) {
            $page = $this->cmsPageService->getById($pageId);
        } elseif ($identifier !== '') {
            $page = $this->cmsPageService->getByIdentifier($identifier);
        }

        if (!$page || !$page->isEnabled()) {
            $this->getMessageManager()->addError(__('Page not found.'));
            $this->redirect('/');
            return '';
        }

        $this->assign('page', [
            'id' => $page->getId(),
            'title' => $page->getTitle(),
            'identifier' => $page->getIdentifier(),
            'content' => $page->getContent(),
            'content_heading' => $page->getContentHeading(),
            'meta_title' => $page->getMetaTitle() ?: $page->getTitle(),
            'meta_description' => $page->getMetaDescription(),
            'meta_keywords' => $page->getMetaKeywords(),
        ]);

        $this->assign('meta', array_merge(
            (array) ($this->_data['meta'] ?? []),
            [
                'title' => $page->getMetaTitle() ?: $page->getTitle(),
                'description' => $page->getMetaDescription(),
                'keywords' => $page->getMetaKeywords(),
                'layoutType' => $this->layoutType,
            ]
        ));

        $layoutPath = $this->getLayoutPath('cms', $this->layoutVariant);
        return $this->fetch($layoutPath);
    }
}
