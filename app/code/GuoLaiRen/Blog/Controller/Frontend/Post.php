<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * 博客前台详情控制器
 */

namespace GuoLaiRen\Blog\Controller\Frontend;

use GuoLaiRen\Blog\Model\Post as PostModel;
use Weline\Framework\App\Controller\FrontendController;

class Post extends FrontendController
{
    private PostModel $postModel;

    public function __construct(PostModel $postModel)
    {
        $this->postModel = $postModel;
    }

    /**
     * 博客文章详情
     *
     * 路由示例：/blog/frontend/post/view?slug=my-first-blog-post
     */
    public function view()
    {
        $slug = (string)$this->request->getGet('slug', '');

        if (empty($slug)) {
            $this->redirect(404);
            return;
        }

        $post = clone $this->postModel;
        $post->clear()
            ->where(PostModel::fields_SLUG, $slug)
            ->where(PostModel::fields_STATUS, PostModel::STATUS_PUBLISHED)
            ->find()
            ->fetch();

        if (!$post->getId()) {
            $this->redirect(404);
            return;
        }

        $this->assign('post', $post);
        $this->assign('title', $post->getData(PostModel::fields_TITLE));

        return $this->fetch('view');
    }
}
