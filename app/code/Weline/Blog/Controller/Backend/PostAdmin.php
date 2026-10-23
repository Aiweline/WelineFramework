<?php

declare(strict_types=1);

namespace Weline\Blog\Controller\Backend;

use Weline\Blog\Model\Post as PostModel;
use Weline\Blog\Service\BlogPostAdminListPresenter;
use Weline\Blog\Service\BlogPostAdminService;
use Weline\Blog\Service\BlogPostCmsEditorService;
use Weline\Blog\Service\BlogPostSlugService;
use Weline\Blog\Service\BlogPostTranslationService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Websites\Service\AdminWebsiteScope;
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
        private readonly BlogPostTranslationService $translations,
    ) {
    }

    #[Acl('Weline_Blog::list_view', '博客文章列表', 'mdi-format-list-bulleted', '查看博客文章')]
    public function index(): string
    {
        $websiteId = AdminWebsiteScope::resolveFilterIdFromGet($this->request);
        $websiteSelect = AdminWebsiteScope::buildSelectView($websiteId);
        $status = strtolower(trim((string)$this->request->getGet('status', '')));
        if ($status !== '' && !in_array($status, $this->statuses(), true)) {
            $status = '';
        }
        $locale = trim((string)$this->request->getGet('locale', ''));
        $search = trim((string)$this->request->getGet('search', ''));
        $page = max(1, (int)$this->request->getGet('page', 1));

        $result = $this->posts->listing(
            $websiteId,
            $page,
            30,
            $status,
            $locale,
            $search,
        );

        $websiteLabels = $this->listPresenter->websiteLabelMapFromOptions($websiteSelect['options']);
        $categoryNames = [];
        foreach ($this->posts->categories($websiteId) as $category) {
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
        $summary = $this->posts->statusSummary($websiteId, $locale, $search);
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
        $editQuery = $websiteId === null ? '' : ('&website_id=' . $websiteId);
        $rowActionsJson = json_encode([
            [
                'type' => 'link',
                'label' => (string)__('编辑'),
                'hrefTemplate' => $editBaseUrl . '?post_id={post_id}' . $editQuery,
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
        $catalogQuery = [
            'space' => 'blog',
            'scope_level' => 'website',
        ];
        if ($websiteId !== null) {
            $catalogQuery['website_id'] = $websiteId;
        }
        $this->assign('catalog_category_url', $this->getUrl('weline_catalog/backend/category/index', $catalogQuery));

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
                if (!\array_key_exists('website_id', $params) || trim((string)$params['website_id']) === '') {
                    $params['website_id'] = $this->resolveFormWebsiteId([]);
                } else {
                    $params['website_id'] = max(0, (int)$params['website_id']);
                }
                $saved = $this->posts->save($params);
                if ((int)($saved['post_id'] ?? 0) > 0 && (int)($saved[PostModel::schema_fields_TRANSLATION_OF] ?? 0) === 0) {
                    try {
                        $autoFilled = $this->translations->autoFill((int)$saved['post_id']);
                        $autoCount = count((array)($autoFilled['created'] ?? [])) + count((array)($autoFilled['updated'] ?? []));
                        if ($autoCount > 0) {
                            $this->getMessageManager()->addSuccess(__('AI 已补齐 %{1} 种语言译文。', [$autoCount]));
                        }
                        if (!empty($autoFilled['simulation'])) {
                            $this->getMessageManager()->addWarning(__('当前是模拟 AI（fake）：自动补齐只生成预览，未写入数据库。'));
                        }
                        if (($autoFilled['errors'] ?? []) !== []) {
                            $this->getMessageManager()->addWarning(__('AI 自动补齐未全部成功：%{1}', [implode('; ', array_slice((array)$autoFilled['errors'], 0, 3))]));
                        }
                    } catch (\Throwable $autoException) {
                        $this->getMessageManager()->addWarning(__('AI 自动补齐失败：%{1}', [$autoException->getMessage()]));
                    }
                }
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
        $websiteSelect = AdminWebsiteScope::buildSelectView($websiteId);
        $statusLabels = $this->statusLabels();

        $cmsAvailable = $this->cmsEditor->isAvailable();
        $cmsPageId = 0;
        $themeEditorUrl = '';
        $previewUrl = '';
        if ($cmsAvailable && $post !== []) {
            try {
                $cmsPageId = $this->cmsEditor->findCmsPageId($post);
                if ($cmsPageId <= 0) {
                    $cmsPageId = $this->cmsEditor->syncCmsPageFromPost($post);
                }
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
        $this->assign('media_identity_scope', $this->resolveMediaIdentityScope($websiteSelect['options'], $websiteId));
        $this->assign('cms_available', $cmsAvailable);
        $this->assign('cms_page_id', $cmsPageId);
        $this->assign(
            'cms_editor_state',
            $cmsPageId > 0 && $post !== []
                ? $this->cmsEditor->buildEditorBridgeState($cmsPageId, $postLocale, $post)
                : [],
        );
        $this->assign('theme_editor_url', $themeEditorUrl);
        $this->assign('preview_url', $previewUrl);
        $listQuery = ['website_id' => $websiteId];
        $this->assign('list_url', $this->getUrl('blog/backend/post-admin/index', $listQuery));
        $this->assign('translation_versions', $postId > 0 ? $this->translations->versions($postId) : []);
        $this->assign('translation_target_codes', array_values(array_diff($this->translations->enabledLocales($websiteId), [$postLocale])));
        $this->assign('translation_missing_codes', $postId > 0 ? $this->translations->missingLocales($postId, $websiteId, $postLocale) : []);
        $this->assign('auto_translate_enabled', $this->translations->isAutoTranslateEnabled());
        $this->assign('translate_url', $this->getUrl('blog/backend/post-admin/post-translate'));
        $this->assign('auto_translate_url', $this->getUrl('blog/backend/post-admin/post-auto-translate'));
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

    #[Acl('Weline_Blog::save', 'AI 翻译博客文章', 'mdi-translate', '把博客文章翻译并保存为目标语言版本')]
    public function postTranslate(): string
    {
        $payload = ['success' => false, 'msg' => (string)__('翻译未执行'), 'data' => []];
        try {
            $postId = max(0, (int)$this->request->getPost('post_id', 0));
            $raw = $this->request->getPost('locales', []);
            if (!is_array($raw)) {
                $raw = preg_split('/[,;\s]+/', (string)$raw) ?: [];
            }
            $locales = [];
            foreach ($raw as $locale) {
                $locale = trim((string)$locale);
                if ($locale !== '') {
                    $locales[$locale] = $locale;
                }
            }
            $options = [
                'overwrite' => (int)$this->request->getPost('overwrite', 0) === 1,
                'as_draft' => (int)$this->request->getPost('as_draft', 0) === 1,
            ];
            $result = $this->translations->translatePost($postId, array_values($locales), $options);
            $simulated = !empty($result['simulation']);
            $success = !$simulated
                && $result['errors'] === []
                && ($result['created'] !== [] || $result['updated'] !== [] || $result['skipped'] !== []);
            if ($simulated) {
                $msg = (string)__('当前是模拟 AI（fake）：已生成预览，但结果不会写入数据库。');
            } elseif ($success) {
                $msg = (string)__('翻译完成：新建 %{1}，更新 %{2}，跳过 %{3}。', [count($result['created']), count($result['updated']), count($result['skipped'])]);
            } else {
                $msg = (string)__('翻译未全部成功：%{1}', [implode('; ', array_slice($result['errors'], 0, 3)) ?: (string)__('没有可翻译的目标语言')]);
            }
            $payload = ['success' => $success, 'msg' => $msg, 'data' => $result];
        } catch (\Throwable $exception) {
            $payload = ['success' => false, 'msg' => $exception->getMessage(), 'data' => []];
        }

        return $this->fetchJson($payload);
    }

    #[Acl('Weline_Blog::save', 'AI 自动补齐博客译文', 'mdi-translate-off', '开关：保存文章时自动补齐缺失语言译文')]
    public function postAutoTranslate(): string
    {
        $payload = ['success' => false, 'msg' => (string)__('开关未保存'), 'data' => []];
        try {
            $enabled = (int)$this->request->getPost('enabled', 0) === 1;
            $this->translations->setAutoTranslateEnabled($enabled);
            $payload = [
                'success' => true,
                'msg' => $enabled ? (string)__('已开启 AI 自动补齐') : (string)__('已关闭 AI 自动补齐'),
                'data' => ['enabled' => $enabled],
            ];
        } catch (\Throwable $exception) {
            $payload = ['success' => false, 'msg' => $exception->getMessage(), 'data' => []];
        }

        return $this->fetchJson($payload);
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
        if (\array_key_exists(PostModel::schema_fields_WEBSITE_ID, $post)) {
            return max(0, (int)$post[PostModel::schema_fields_WEBSITE_ID]);
        }

        $fromQuery = AdminWebsiteScope::resolveFilterIdFromGet($this->request);
        if ($fromQuery !== null) {
            return max(0, $fromQuery);
        }

        return max(0, $this->websiteGrants->currentWebsiteId());
    }

    /**
     * @param list<array<string,string>> $options
     */
    private function resolveMediaIdentityScope(array $options, int $websiteId): string
    {
        $value = (string)$websiteId;
        foreach ($options as $option) {
            if ((string)($option['value'] ?? '') !== $value) {
                continue;
            }
            $code = trim((string)($option['code'] ?? ''));
            if ($code !== '' && !str_contains($code, '.') && !str_contains($code, ':')) {
                return strtolower($code) . '.default.default';
            }
            break;
        }

        return 'default.default.default';
    }
}
