<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * 博客后台管理控制器
 */

namespace GuoLaiRen\Blog\Controller\Backend;

use GuoLaiRen\Blog\Cron\AiPublish;
use GuoLaiRen\Blog\Model\Category;
use GuoLaiRen\Blog\Model\Post as PostModel;
use GuoLaiRen\Blog\Model\TrendsConfig;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Website;

#[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::blog', '博客管理', 'mdi mdi-notebook-outline', '管理博客文章')]
class Post extends BackendController
{
    private PostModel $postModel;
    private Website $websiteModel;

    public function __construct(PostModel $postModel)
    {
        $this->postModel = $postModel;
        $this->websiteModel = ObjectManager::getInstance(Website::class);
    }

    /**
     * 获取分类选项
     */
    private function getCategoryOptions(): array
    {
        $websiteId = WebsiteData::getWebsiteId();
        return Category::getFlatCategoryList(0, $websiteId);
    }

    /**
     * 获取站点列表
     */
    private function getSiteOptions(): array
    {
        $websites = $this->websiteModel->clear()
            ->order(Website::fields_NAME, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
        
        $options = [];
        foreach ($websites as $website) {
            $options[] = [
                'site_id' => $website->getWebsiteId(),
                'site_name' => $website->getName(),
                'site_url' => $website->getUrl(),
            ];
        }
        return $options;
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::blog_index', '博客列表', 'mdi mdi-view-list', '查看博客文章列表', 'GuoLaiRen_Blog::blog')]
    public function index()
    {
        $this->assign('page_title', __('博客管理'));
        $this->assign('breadcrumb_parent', __('内容管理'));
        $this->assign('breadcrumb_current', __('博客管理'));

        $listModel = clone $this->postModel;
        $listModel->clear();

        // 根据当前网站过滤
        $websiteId = WebsiteData::getWebsiteId();
        if ($websiteId) {
            $listModel->where(PostModel::fields_SITE_ID, $websiteId);
        }

        if ($keyword = $this->request->getGet('search')) {
            $keyword = "%{$keyword}%";
            $listModel->where(PostModel::fields_TITLE, $keyword, 'like')
                ->where(PostModel::fields_SLUG, $keyword, 'like', 'OR');
        }

        $posts = $listModel
            ->order(PostModel::fields_ID, 'DESC')
            ->pagination()
            ->select()
            ->fetch();

        $this->assign('posts', $posts->getItems());
        $this->assign('pagination', $posts->getPagination());
        $hasTrendSource = TrendsConfig::useSerpApi() || TrendsConfig::useOfficialApi();
        $this->assign('ai_mode_label', $hasTrendSource ? __('趋势增长词模式') : __('画像关键词兜底模式'));

        return $this->fetch('index');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::blog_create', '新建博客', 'mdi mdi-plus', '新建博客文章', 'GuoLaiRen_Blog::blog')]
    public function getCreate()
    {
        $this->assign('page_title', __('新建博客'));
        $this->assign('breadcrumb_parent', __('博客管理'));
        $this->assign('breadcrumb_current', __('新建博客'));
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('blog/backend/post/create'));
        $this->assign('post', null);
        $this->assign('categories', $this->getCategoryOptions());
        $this->assign('sites', $this->getSiteOptions());

        return $this->fetch('form');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::blog_create_post', '新建博客请求', '', '新建博客请求', 'GuoLaiRen_Blog::blog')]
    public function postCreate()
    {
        try {
            $data = $this->request->getPost();

            $title = trim((string)($data['title'] ?? ''));
            $slug  = trim((string)($data['slug'] ?? ''));

            if ($title === '' || $slug === '') {
                throw new \Exception(__('标题和URL别名不能为空'));
            }

            // 获取当前网站ID（优先使用表单提交的，否则从WebsiteData获取）
            $websiteId = (int)($data['site_id'] ?? 0);
            if (!$websiteId) {
                $websiteId = (int)(WebsiteData::getWebsiteId() ?? 0);
            }

            // 别名唯一性校验（同一网站内唯一）
            $exists = clone $this->postModel;
            $exists->clear()
                ->where(PostModel::fields_SLUG, $slug);
            if ($websiteId) {
                $exists->where(PostModel::fields_SITE_ID, $websiteId);
            }
            $exists->find()->fetch();
            if ($exists->getId()) {
                throw new \Exception(__('URL别名已存在，请更换一个'));
            }

            $status       = (int)($data['status'] ?? PostModel::STATUS_DRAFT);
            $published_at = $data['published_at'] ?? '';
            if ($status === PostModel::STATUS_PUBLISHED && $published_at === '') {
                $published_at = date('Y-m-d H:i:s');
            }

            // 获取当前网站ID（优先使用表单提交的，否则从WebsiteData获取）
            $websiteId = (int)($data['site_id'] ?? 0);
            if (!$websiteId) {
                $websiteId = (int)(WebsiteData::getWebsiteId() ?? 0);
            }

            $post = clone $this->postModel;
            $post->setData(PostModel::fields_TITLE, $title)
                ->setData(PostModel::fields_SLUG, $slug)
                ->setData(PostModel::fields_SITE_ID, $websiteId)
                ->setData(PostModel::fields_SUMMARY, (string)($data['summary'] ?? ''))
                ->setData(PostModel::fields_CONTENT, (string)($data['content'] ?? ''))
                ->setData(PostModel::fields_COVER_IMAGE, (string)($data['cover_image'] ?? ''))
                ->setData(PostModel::fields_CATEGORY_ID, (int)($data['category_id'] ?? 0))
                ->setData(PostModel::fields_AUTHOR, (string)($data['author'] ?? ''))
                ->setData(PostModel::fields_TAGS, (string)($data['tags'] ?? ''))
                ->setData(PostModel::fields_IS_FEATURED, isset($data['is_featured']) ? 1 : 0)
                ->setData(PostModel::fields_STATUS, $status)
                ->setData(PostModel::fields_PUBLISHED_AT, $published_at)
                ->save();

            MessageManager::success(__('博客文章已创建'));

            $this->redirect('blog/backend/post/index');
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
            $this->redirect('blog/backend/post/create');
        }
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::blog_edit', '编辑博客', 'mdi mdi-pencil', '编辑博客文章', 'GuoLaiRen_Blog::blog')]
    public function getEdit()
    {
        $id = (int)$this->request->getGet('id', 0);

        $post = clone $this->postModel;
        $post->clear()->load($id);

        if (!$post->getId()) {
            MessageManager::error(__('博客文章不存在'));
            $this->redirect('blog/backend/post/index');
            return;
        }

        $this->assign('page_title', __('编辑博客'));
        $this->assign('breadcrumb_parent', __('博客管理'));
        $this->assign('breadcrumb_current', __('编辑博客'));
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('blog/backend/post/edit', ['id' => $id]));
        $this->assign('post', $post);
        $this->assign('categories', $this->getCategoryOptions());
        $this->assign('sites', $this->getSiteOptions());

        return $this->fetch('form');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::blog_edit_post', '编辑博客请求', '', '编辑博客请求', 'GuoLaiRen_Blog::blog')]
    public function postEdit()
    {
        try {
            $id   = (int)$this->request->getGet('id', 0);
            $data = $this->request->getPost();

            $post = clone $this->postModel;
            $post->clear()->load($id);

            if (!$post->getId()) {
                throw new \Exception(__('博客文章不存在'));
            }

            $title = trim((string)($data['title'] ?? ''));
            $slug  = trim((string)($data['slug'] ?? ''));

            if ($title === '' || $slug === '') {
                throw new \Exception(__('标题和URL别名不能为空'));
            }

            // 获取当前网站ID（优先使用表单提交的，否则从文章现有数据或WebsiteData获取）
            $websiteId = (int)($data['site_id'] ?? 0);
            if (!$websiteId) {
                $websiteId = (int)($post->getData(PostModel::fields_SITE_ID) ?? 0);
            }
            if (!$websiteId) {
                $websiteId = (int)(WebsiteData::getWebsiteId() ?? 0);
            }

            // 别名唯一性校验（排除当前记录，同一网站内唯一）
            $exists = clone $this->postModel;
            $exists->clear()
                ->where(PostModel::fields_SLUG, $slug)
                ->where(PostModel::fields_ID, $id, '!=', 'AND');
            if ($websiteId) {
                $exists->where(PostModel::fields_SITE_ID, $websiteId);
            }
            $exists->find()->fetch();
            if ($exists->getId()) {
                throw new \Exception(__('URL别名已存在，请更换一个'));
            }

            $status       = (int)($data['status'] ?? PostModel::STATUS_DRAFT);
            $published_at = $data['published_at'] ?? '';
            if ($status === PostModel::STATUS_PUBLISHED && $published_at === '') {
                $published_at = date('Y-m-d H:i:s');
            }

            $post->setData(PostModel::fields_TITLE, $title)
                ->setData(PostModel::fields_SLUG, $slug)
                ->setData(PostModel::fields_SITE_ID, $websiteId)
                ->setData(PostModel::fields_SUMMARY, (string)($data['summary'] ?? ''))
                ->setData(PostModel::fields_CONTENT, (string)($data['content'] ?? ''))
                ->setData(PostModel::fields_COVER_IMAGE, (string)($data['cover_image'] ?? ''))
                ->setData(PostModel::fields_CATEGORY_ID, (int)($data['category_id'] ?? 0))
                ->setData(PostModel::fields_AUTHOR, (string)($data['author'] ?? ''))
                ->setData(PostModel::fields_TAGS, (string)($data['tags'] ?? ''))
                ->setData(PostModel::fields_IS_FEATURED, isset($data['is_featured']) ? 1 : 0)
                ->setData(PostModel::fields_STATUS, $status)
                ->setData(PostModel::fields_PUBLISHED_AT, $published_at)
                ->save();

            MessageManager::success(__('博客文章已保存'));

            $this->redirect('blog/backend/post/index');
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
            $this->redirect('blog/backend/post/index');
        }
    }

    /**
     * 手动触发 AI 生成 SEO 文章（SSE 实时推送进度，GET 供 EventSource 使用）
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::blog_trigger_ai', '手动生成SEO文章', 'mdi mdi-robot', '手动触发 AI 生成 SEO 博客文章', 'GuoLaiRen_Blog::blog')]
    public function getTriggerAiPublishSse(): void
    {
        // SSE 流式生成可能较耗时，取消 PHP 执行时间限制并防止客户端断开中止脚本
        @set_time_limit(0);
        @ignore_user_abort(true);

        // 使用框架内置的 SseWriter，兼容 WLS 与 FPM 模式
        $sse = new \Weline\Framework\Http\Sse\SseWriter();
        $sse->start();

        try {
            /** @var AiPublish $cron */
            $cron = ObjectManager::getInstance(AiPublish::class);
            $cron->execute(function (string $event, array $data) use ($sse) {
                $sse->sendEvent($event, $data);
            });
        } catch (\Throwable $e) {
            $sse->sendEvent('error', ['message' => $e->getMessage()]);
        }

        // 注意：不要在此处 exit，WlsRuntime 会根据 SseContext 判断并避免重复输出响应
    }

    /**
     * 手动触发 AI 生成 SEO 文章（AJAX 一次性返回，保留兼容）
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::blog_trigger_ai', '手动生成SEO文章', 'mdi mdi-robot', '手动触发 AI 生成 SEO 博客文章', 'GuoLaiRen_Blog::blog')]
    public function postTriggerAiPublish(): string
    {
        try {
            $hasTrendSource = TrendsConfig::useSerpApi() || TrendsConfig::useOfficialApi();
            $modeText = $hasTrendSource ? __('趋势增长词模式') : __('画像关键词兜底模式');
            /** @var AiPublish $cron */
            $cron = ObjectManager::getInstance(AiPublish::class);
            $result = $cron->execute();

            $message = __('当前模式：%{mode}；%{result}', ['mode' => $modeText, 'result' => $result]);
            if (str_contains($result, '0 篇')) {
                $hint = AiPublish::getZeroPublishHint();
                $message .= "\n" . $hint;
            }

            return json_encode([
                'success' => true,
                'mode' => $modeText,
                'message' => $message,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode([
                'success' => false,
                'message' => __('执行失败：%{error}', ['error' => $e->getMessage()]),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * 审批发布草稿文章
     */
    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::blog_publish', '审批发布', 'mdi mdi-check-decagram', '审批并发布草稿文章', 'GuoLaiRen_Blog::blog')]
    public function getPublish(): void
    {
        try {
            $id = (int)$this->request->getGet('id', 0);

            $post = clone $this->postModel;
            $post->clear()->load($id);

            if (!$post->getId()) {
                throw new \Exception(__('博客文章不存在'));
            }

            if ((int)$post->getData(PostModel::fields_STATUS) === PostModel::STATUS_PUBLISHED) {
                throw new \Exception(__('该文章已经是发布状态'));
            }

            $post->setData(PostModel::fields_STATUS, PostModel::STATUS_PUBLISHED)
                ->setData(PostModel::fields_PUBLISHED_AT, date('Y-m-d H:i:s'))
                ->save();

            MessageManager::success(__('文章已审批发布'));
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
        }

        $this->redirect('blog/backend/post/index');
    }

    #[\Weline\Framework\Acl\Acl('GuoLaiRen_Blog::blog_delete', '删除博客', 'mdi mdi-delete', '删除博客文章', 'GuoLaiRen_Blog::blog')]
    public function delete()
    {
        try {
            $id = (int)$this->request->getGet('id', 0);

            $post = clone $this->postModel;
            $post->clear()->load($id);

            if (!$post->getId()) {
                throw new \Exception(__('博客文章不存在'));
            }

            $post->delete()->fetch();
            MessageManager::success(__('博客文章已删除'));
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
        }

        $this->redirect('blog/backend/post/index');
    }
}

