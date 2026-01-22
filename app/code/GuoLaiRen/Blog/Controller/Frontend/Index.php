<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * 博客前台列表控制器
 */

namespace GuoLaiRen\Blog\Controller\Frontend;

use GuoLaiRen\Blog\Model\Post;
use Weline\Framework\App\Controller\FrontendController;

class Index extends FrontendController
{
    private Post $postModel;

    public function __construct(Post $postModel)
    {
        $this->postModel = $postModel;
    }

    /**
     * 博客文章列表
     *
     * 路由示例：/blog/frontend/index/index?page=1
     */
    public function index()
    {
        $page     = (int)($this->request->getGet('page', 1));
        $page     = $page > 0 ? $page : 1;
        $pageSize = 10;

        $listModel = clone $this->postModel;
        $listModel->clear()
            ->where(Post::fields_STATUS, Post::STATUS_PUBLISHED)
            ->order(Post::fields_PUBLISHED_AT, 'DESC')
            ->page($page, $pageSize);

        $collection = $listModel->select()->fetch();
        $items      = $collection->getItems();

        $baseUrl = $this->request->getUrlBuilder()->getFrontendUrl('blog/frontend/post/view');

        $posts = [];
        foreach ($items as $item) {
            /** @var Post $item */
            $slug = (string)$item->getData(Post::fields_SLUG);
            $detailUrl = $baseUrl . '?slug=' . urlencode($slug);

            $posts[] = [
                'id'           => (int)$item->getId(),
                'title'        => (string)$item->getData(Post::fields_TITLE),
                'summary'      => (string)$item->getData(Post::fields_SUMMARY),
                'slug'         => $slug,
                'url'          => $detailUrl,
                'cover_image'  => (string)$item->getData(Post::fields_COVER_IMAGE),
                'published_at' => (string)$item->getData(Post::fields_PUBLISHED_AT),
            ];
        }

        $this->assign('posts', $posts);
        $this->assign('current_page', $page);
        $this->assign('page_size', $pageSize);
        $this->assign('has_more', count($posts) === $pageSize);

        return $this->fetch('index');
    }
}
