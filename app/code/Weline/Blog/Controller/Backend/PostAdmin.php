<?php

declare(strict_types=1);

namespace Weline\Blog\Controller\Backend;

use Weline\Blog\Model\Post as PostModel;
use Weline\Blog\Service\BlogPostAdminListPresenter;
use Weline\Blog\Service\BlogPostAdminService;
use Weline\Blog\Service\BlogPostCmsEditorService;
use Weline\Blog\Service\BlogPostSlugService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Websites\Service\WebsiteAclGrantService;

#[Acl('Weline_Blog::list', '博客文章', 'mdi-post-outline', '管理博客文章', 'Weline_Backend::cms_group')]
final class PostAdmin extends BackendController
{
    public function __construct(
        private readonly BlogPostAdminService $posts,
        private readonly BlogPostAdminListPresenter $listPresenter,
        private readonly BlogPostCmsEditorService $cmsEditor,
        private readonly BlogPostSlugService $slugService,
        private readonly WebsiteAclGrantService $websiteGrants,
    ) {
    }

    #[Acl('Weline_Blog::list_view', '博客文章列表', 'mdi-format-list-bulleted', '查看博客文章')]
    public function index(): string
    {
        $websiteId = $this->optionalFilterIdFromQuery('website_id');
        $status = strtolower(trim((string)$this->request->getGet('status', '')));
        if ($status !== '' && !in_array($status, $this->statuses(), true)) {
            $status = '';
        }
        $locale = trim((string)$this->request->getGet('locale', ''));
        $search = trim((string)$this->request->getGet('search', ''));
        $page = max(1, (int)$this->request->getGet('page', 1));

        $result = $this->posts->listing(
            $websiteId ?? 0,
            $page,
            30,
            $status,
            $locale,
            $search,
        );

        $websiteSelect = $this->buildWebsiteSelect($websiteId);
        $websiteOptions = json_decode($websiteSelect['options_json'], true);
        $websiteLabels = $this->listPresenter->websiteLabelMapFromOptions(
            is_array($websiteOptions) ? $websiteOptions : [],
        );
        $categoryNames = [];
        foreach ($this->posts->categories($websiteId ?? 0) as $category) {
            if (!is_array($category)) {
                continue;
            }
            $categoryId = (int)($category['category_id'] ?? 0);
            if ($categoryId <= 0) {
                continue;
            }
            $categoryNames[$categoryId] = (string)($category['name'] ?? '');
        }

        $statusLabels = $this->statusLabels();
        $summary = $this->posts->statusSummary($websiteId ?? 0, $locale, $search);
        $tableRows = [];
        foreach ($result['items'] as $post) {
            if (!is_array($post)) {
                continue;
            }
            $tableRows[] = $this->listPresenter->presentRow(
                $post,
                $categoryNames,
                $websiteLabels,
                $statusLabels,
            );
        }

        $editBaseUrl = (string)$this->getUrl('blog/backend/post-admin/form');
        $rowActionsJson = json_encode([
            [
                'type' => 'link',
                'label' => (string)__('编辑'),
                'hrefTemplate' => $editBaseUrl . '?post_id={post_id}',
                'testId' => 'blog-post-edit-button',
                'tone' => 'primary',
                'variant' => 'outline',
                'size' => 'sm',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';

        $this->assign('table_rows', $tableRows);
        $this->assign('row_actions_json', $rowActionsJson);
        $this->assign('pagination', $result['pagination']);
        $this->assign('total', $result['total']);
        $this->assign('status_summary', $summary);
        $this->assign('status_filter', $status);
        $this->assign('locale_filter', $locale);
        $this->assign('blogLocaleEmptyText', (string)__('全部语言'));
        $this->assign('search_filter', $search);
        $this->assign('website_id_filter', $websiteId === null ? '' : (string)$websiteId);
        $this->assign('allowed_statuses', $this->statuses());
        $this->assign('status_labels', $statusLabels);
        $this->assign('websiteSelectValue', $websiteSelect['value']);
        $this->assign('websiteSelectDisplay', $websiteSelect['display']);
        $this->assign('websiteSelectOptionsJson', $websiteSelect['options_json']);
        $this->assign('catalog_category_url', $this->getUrl('weline_catalog/backend/category/index', [
            'space' => 'blog',
            'scope_level' => 'website',
        ]));

        return (string)$this->fetch('Weline_Blog::templates/backend/post-admin/index.phtml');
    }

    #[Acl('Weline_Blog::save', '保存博客文章', 'mdi-content-save-outline', '创建或更新博客文章')]
    public function form(): string
    {
        $postId = max(0, (int)$this->request->getParam('post_id', 0));
        $submitted = [];
        if ($this->request->isPost()) {
            try {
                $params = $this->request->getParams();
                if (max(0, (int)($params['website_id'] ?? 0)) <= 0) {
                    $params['website_id'] = $this->resolveFormWebsiteId([]);
                }
                $saved = $this->posts->save($params);
                $this->getMessageManager()->addSuccess(__('博客文章已保存。'));
                $this->redirect($this->getUrl('blog/backend/post-admin/form', ['post_id' => (int)($saved['post_id'] ?? 0)]));
            } catch (\Throwable $exception) {
                $this->getMessageManager()->addError($exception->getMessage());
                $submitted = $this->request->getParams();
            }
        }

        $post = [];
        if ($postId > 0) {
            $model = \Weline\Framework\Manager\ObjectManager::getInstance(PostModel::class);
            $model->clearData()->reset()->load($postId);
            if ($model->getPostId() > 0) {
                $loaded = $model->getData();
                $post = is_array($loaded) ? $loaded : [];
            }
        }

        if ($submitted !== []) {
            $post = $this->mergeSubmittedPost($post, $submitted);
        }

        if (trim((string)($post[PostModel::schema_fields_AUTHOR] ?? '')) === '') {
            $post[PostModel::schema_fields_AUTHOR] = trim((string)($this->getLoginUsername() ?? ''));
        }

        $websiteId = $this->resolveFormWebsiteId($post);
        $postLocale = trim((string)($post[PostModel::schema_fields_LOCALE] ?? 'zh_Hans_CN'));
        if ($postLocale === '') {
            $postLocale = 'zh_Hans_CN';
        }
        $websiteSelect = $this->buildWebsiteSelect($websiteId);
        $statusLabels = $this->statusLabels();

        $cmsAvailable = $this->cmsEditor->isAvailable();
        $cmsPageId = 0;
        $themeEditorUrl = '';
        $previewUrl = '';
        if ($cmsAvailable && $post !== []) {
            try {
                $cmsPageId = $this->cmsEditor->syncCmsPageFromPost($post);
                if ($cmsPageId > 0) {
                    $themeEditorUrl = $this->cmsEditor->buildThemeEditorUrl($cmsPageId, $postLocale);
                    $previewUrl = $this->cmsEditor->buildPreviewUrl($cmsPageId, $postLocale);
                } elseif (trim((string)($post[PostModel::schema_fields_TITLE] ?? '')) !== '') {
                    $this->getMessageManager()->addWarning(
                        __('正文编辑器暂未就绪，请确认文章标题、Slug 与站点已保存后再刷新页面。'),
                    );
                }
            } catch (\Throwable $exception) {
                $cmsPageId = 0;
                $themeEditorUrl = '';
                $previewUrl = '';
                $this->getMessageManager()->addError(
                    __('正文编辑器加载失败：%{1}', [$exception->getMessage()]),
                );
            }
        }

        $this->assign('post', $post);
        $this->assign('post_id', $postId);
        $this->assign('postLocale', $postLocale);
        $this->assign('coverImageValue', trim((string)($post[PostModel::schema_fields_COVER_IMAGE] ?? '')));
        $this->assign('statuses', [PostModel::STATUS_DRAFT, PostModel::STATUS_PUBLISHED, PostModel::STATUS_DISABLED]);
        $this->assign('status_labels', $statusLabels);
        $this->assign('categories', $this->posts->categories($websiteId));
        $this->assign('websiteSelectValue', $websiteSelect['value']);
        $this->assign('websiteSelectDisplay', $websiteSelect['display']);
        $this->assign('websiteSelectOptionsJson', $websiteSelect['options_json']);
        $this->assign('cms_available', $cmsAvailable);
        $this->assign('cms_page_id', $cmsPageId);
        $this->assign('theme_editor_url', $themeEditorUrl);
        $this->assign('preview_url', $previewUrl);
        $this->assign('list_url', $this->getUrl('blog/backend/post-admin/index'));
        $this->assign('suggest_slug_url', $this->getUrl('blog/backend/post-admin/post-suggest-slug'));

        return (string)$this->fetch('Weline_Blog::templates/backend/post-admin/form.phtml');
    }

    #[Acl('Weline_Blog::save', 'AI 生成博客 Slug', 'mdi-robot-outline', '使用翻译 AI 生成博客 slug')]
    public function postSuggestSlug(): string
    {
        $title = trim((string)$this->request->getPost('title', ''));
        $locale = trim((string)$this->request->getPost('locale', ''));
        $useAi = (int)$this->request->getPost('use_ai', 0) === 1;
        $result = $this->slugService->suggestSlug($title, $locale, $useAi);

        return $this->fetchJson([
            'success' => true,
            'msg' => (string)__('Slug 已生成'),
            'data' => $result,
        ]);
    }

    #[Acl('Weline_Blog::delete', '删除博客文章', 'mdi-delete-outline', '删除博客文章')]
    public function postDelete(): string
    {
        try {
            $this->posts->delete(max(0, (int)$this->request->getPost('post_id', 0)));
            $this->getMessageManager()->addSuccess(__('博客文章已删除。'));
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError($exception->getMessage());
        }

        return (string)$this->redirect($this->getUrl('blog/backend/post-admin/index'));
    }

    /**
     * @return list<string>
     */
    private function statuses(): array
    {
        return [PostModel::STATUS_DRAFT, PostModel::STATUS_PUBLISHED, PostModel::STATUS_DISABLED];
    }

    /**
     * @return array<string, string>
     */
    private function statusLabels(): array
    {
        return [
            PostModel::STATUS_DRAFT => (string)__('草稿'),
            PostModel::STATUS_PUBLISHED => (string)__('已发布'),
            PostModel::STATUS_DISABLED => (string)__('已禁用'),
        ];
    }

    private function optionalFilterIdFromQuery(string $key): ?int
    {
        if (!$this->request->hasGet($key)) {
            return null;
        }

        return $this->optionalNonNegativeInt($this->request->getParameterBag()->getQuery($key, ''));
    }

    private function optionalNonNegativeInt(mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim((string)$raw);
        if ($raw === '') {
            return null;
        }
        if (!preg_match('/^-?\d+$/', $raw)) {
            return null;
        }

        return max(0, (int)$raw);
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed> $submitted
     * @return array<string,mixed>
     */
    private function mergeSubmittedPost(array $post, array $submitted): array
    {
        $fields = [
            PostModel::schema_fields_WEBSITE_ID => static fn(mixed $value): int => max(0, (int)$value),
            PostModel::schema_fields_LOCALE => static fn(mixed $value): string => trim((string)$value),
            PostModel::schema_fields_SLUG => static fn(mixed $value): string => trim((string)$value),
            PostModel::schema_fields_TITLE => static fn(mixed $value): string => trim((string)$value),
            PostModel::schema_fields_EXCERPT => static fn(mixed $value): string => trim((string)$value),
            PostModel::schema_fields_COVER_IMAGE => static fn(mixed $value): string => trim((string)$value),
            PostModel::schema_fields_AUTHOR => static fn(mixed $value): string => trim((string)$value),
            PostModel::schema_fields_KEYWORDS => static fn(mixed $value): string => trim((string)$value),
            PostModel::schema_fields_CATEGORY_ID => static fn(mixed $value): int => max(0, (int)$value),
            PostModel::schema_fields_STATUS => static fn(mixed $value): string => trim((string)$value),
        ];

        foreach ($fields as $field => $normalize) {
            if (\array_key_exists($field, $submitted)) {
                $post[$field] = $normalize($submitted[$field]);
            }
        }
        if (\array_key_exists('slug_mode', $submitted)) {
            $post['slug_mode'] = trim((string)$submitted['slug_mode']);
        }

        return $post;
    }

    /**
     * @param array<string,mixed> $post
     */
    private function resolveFormWebsiteId(array $post): int
    {
        $fromPost = (int)($post[PostModel::schema_fields_WEBSITE_ID] ?? 0);
        if ($fromPost > 0) {
            return $fromPost;
        }

        $fromQuery = $this->optionalFilterIdFromQuery('website_id');
        if ($fromQuery !== null && $fromQuery > 0) {
            return $fromQuery;
        }

        return max(0, $this->websiteGrants->currentWebsiteId());
    }

    /**
     * @return array{value:string,display:string,options_json:string}
     */
    private function buildWebsiteSelect(?int $websiteId): array
    {
        $options = [];
        try {
            $queried = w_query('websites', 'getWebsiteSelectOptions', []);
            if (is_array($queried)) {
                foreach ($queried as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $value = trim((string)($row['value'] ?? ''));
                    if ($value === '') {
                        continue;
                    }
                    $options[] = [
                        'value' => $value,
                        'label' => trim((string)($row['label'] ?? $value)),
                        'meta' => trim((string)($row['meta'] ?? '')),
                    ];
                }
            }
        } catch (\Throwable) {
            $options = [];
        }

        $value = $websiteId === null ? '' : (string)$websiteId;
        $display = '';
        foreach ($options as $option) {
            if ((string)($option['value'] ?? '') !== $value) {
                continue;
            }
            $display = trim((string)($option['label'] ?? ''));
            if ($display === '') {
                $display = '#' . $value;
            }
            break;
        }
        if ($display === '' && $value !== '') {
            $display = '#' . $value;
        }

        return [
            'value' => $value,
            'display' => $display,
            'options_json' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
        ];
    }
}
