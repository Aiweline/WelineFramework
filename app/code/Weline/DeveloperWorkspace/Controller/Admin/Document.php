<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\DeveloperWorkspace\Controller\Admin;

use Weline\Backend\Model\BackendUser;
use Weline\DeveloperWorkspace\Model\Document\Catalog;
use Weline\DeveloperWorkspace\Model\Document\Translation;
use Weline\DeveloperWorkspace\Service\Document\DocumentTranslationConfigService;
use Weline\DeveloperWorkspace\Service\Document\DocumentTranslationReadService;
use Weline\DeveloperWorkspace\Service\Document\DocumentTranslationTaskService;
use Weline\Ai\Model\AiModel;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locale;
use Weline\DeveloperWorkspace\Model\ModelService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Exception;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Uploader;

use function PHPUnit\Framework\matches;

#[Acl(
    'Weline_DeveloperWorkspace::dev-document',
     '开发文档管理', 
     'fa fa-list-alt',
      '管理开发文档',
      'Weline_DeveloperWorkspace::dev-document-manager')]
class Document extends \Weline\Framework\App\Controller\BackendController
{
    private Url $url;

    public function __construct(
        Url $url
    ) {
        $this->url = $url;
    }

    private function jsonResponse(array $response): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');
        $this->request->getResponse()->setHttpResponseCode(200);
        return json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    #[Acl('Weline_DeveloperWorkspace::dev-document-manager', '文档列表', 'fa fa-list-alt')]
    public function index()
    {
        // 获取分类树（只获取激活的分类）
        /**@var Catalog $catalogModel */
        $catalogModel = ObjectManager::getInstance(Catalog::class);
        $catalogs = $catalogModel->getTree('pid');
        
        // 确保返回数组格式
        if (!is_array($catalogs)) {
            $catalogs = [];
        }
        
        $this->assign('catalogs', $catalogs);
        
        // 获取选中的分类ID
        $categoryId = $this->request->getParam('category_id');
        
        $documentModel = ModelService::getDocumentModel();
        $query = $documentModel->joinModel(Catalog::class, 'catalog', 'main_table.category_id=catalog.id')
                               ->fields('main_table.*,main_table.id as doc_id,catalog.*,catalog.id as c_id,catalog.name as c_name');
        
        // 如果指定了分类，则过滤
        if ($categoryId) {
            $query->where('main_table.category_id', $this->getCategoryFilterIds((int)$categoryId), 'in');
        }
        
        $documents = $query->pagination(
                           intval($this->request->getParam('page', 1)),
                           intval($this->request->getParam('pageSize', 10)),
                           $this->request->getParams()
                       )->order('doc_id', 'desc')->select()->fetch();
        $this->assign('documents', $documents->getItems());
        $this->assign('pagination', $documentModel->getPagination());
        $this->assign('selectedCategoryId', $categoryId);
        $this->assign('documentLocales', $this->getSupportedDocumentLocales());
        $this->assign('currentDocumentLocale', $this->getRequestLocale());
        return $this->fetch();
    }

    #[Acl('Weline_DeveloperWorkspace::document_config', '文档管理配置', 'mdi mdi-translate')]
    public function config()
    {
        /** @var DocumentTranslationConfigService $configService */
        $configService = ObjectManager::getInstance(DocumentTranslationConfigService::class);
        /** @var DocumentTranslationTaskService $taskService */
        $taskService = ObjectManager::getInstance(DocumentTranslationTaskService::class);

        $this->assign('config', $configService->getConfig());
        $this->assign('locales', $this->getInstalledDocumentLocales());
        $this->assign('overview', $taskService->getOverview());
        $this->assign('textModels', $taskService->getTextModels());
        $title = str_starts_with(str_replace('-', '_', (string)\w_env('user.lang', '')), 'en_')
            ? 'Document Management Config'
            : (string)__('文档管理配置');
        $this->assign('title', $title);

        return $this->fetch('Weline_DeveloperWorkspace::templates/Admin/Document/config');
    }

    #[Acl('Weline_DeveloperWorkspace::document_config_save', '保存文档管理配置', 'mdi mdi-content-save')]
    public function postConfig()
    {
        try {
            /** @var DocumentTranslationConfigService $configService */
            $configService = ObjectManager::getInstance(DocumentTranslationConfigService::class);
            /** @var DocumentTranslationTaskService $taskService */
            $taskService = ObjectManager::getInstance(DocumentTranslationTaskService::class);

            $post = $this->request->getPost();
            $locales = [];
            foreach ((array)($post['target_locales'] ?? []) as $locale) {
                $locale = trim((string)$locale);
                if ($locale === '') {
                    continue;
                }
                $locales[$locale] = [
                    'enabled' => 1,
                    'ai_enabled' => !empty($post['ai_enabled'][$locale]) ? 1 : 0,
                ];
            }

            $sourceLocale = DocumentTranslationConfigService::SOURCE_LOCALE;
            $locales[$sourceLocale] = ['enabled' => 1, 'ai_enabled' => 0];
            $configService->saveConfig([
                'enabled' => !empty($post['enabled']) ? 1 : 0,
                'source_locale' => $sourceLocale,
                'locales' => $locales,
                'scopes' => [
                    'documents' => !empty($post['scope_documents']) ? 1 : 0,
                    'api_documents' => !empty($post['scope_api_documents']) ? 1 : 0,
                    'catalogs' => !empty($post['scope_catalogs']) ? 1 : 0,
                ],
                'batch_size' => (int)($post['batch_size'] ?? 10),
                'max_retries' => (int)($post['max_retries'] ?? 3),
                'fallback_policy' => (string)($post['fallback_policy'] ?? 'source'),
                'daily_token_limit' => (int)($post['daily_token_limit'] ?? 0),
                'monthly_token_limit' => (int)($post['monthly_token_limit'] ?? 0),
                'max_document_tokens' => (int)($post['max_document_tokens'] ?? 12000),
                'show_translation_status' => !empty($post['show_translation_status']) ? 1 : 0,
            ]);
            $taskService->saveModelBinding(trim((string)($post['model_code'] ?? '')));

            $response = $this->success(__('配置已保存'));
        } catch (\Throwable $throwable) {
            $response = $this->error($throwable->getMessage());
        }

        return $this->jsonResponse($response);
    }

    #[Acl('Weline_DeveloperWorkspace::document_config_scan_adapter', '扫描文档翻译适配器', 'mdi mdi-magnify-scan')]
    public function postTranslationScan()
    {
        try {
            $result = ObjectManager::getInstance(DocumentTranslationTaskService::class)->scanAdapter();
            $response = $this->success(__('扫描完成'), $result);
        } catch (\Throwable $throwable) {
            $response = $this->error($throwable->getMessage());
        }

        return $this->jsonResponse($response);
    }

    #[Acl('Weline_DeveloperWorkspace::document_config_run_translation', '执行文档翻译任务', 'mdi mdi-play')]
    public function postTranslationRun()
    {
        try {
            /** @var DocumentTranslationTaskService $service */
            $service = ObjectManager::getInstance(DocumentTranslationTaskService::class);
            $enqueue = $service->enqueueMissingAndStale();
            $run = $service->processBatch();
            $response = $this->success(__('任务已执行'), ['enqueue' => $enqueue, 'run' => $run]);
        } catch (\Throwable $throwable) {
            $response = $this->error($throwable->getMessage());
        }

        return $this->jsonResponse($response);
    }

    #[Acl('Weline_DeveloperWorkspace::document_config_retry_translation', '重试失败文档翻译任务', 'mdi mdi-replay')]
    public function postTranslationRetry()
    {
        try {
            $count = ObjectManager::getInstance(DocumentTranslationTaskService::class)->retryFailed();
            $response = $this->success(__('失败任务已重置'), ['count' => $count]);
        } catch (\Throwable $throwable) {
            $response = $this->error($throwable->getMessage());
        }

        return $this->jsonResponse($response);
    }

    #[Acl('Weline_DeveloperWorkspace::document_config_test_adapter', '测试文档翻译适配器', 'mdi mdi-test-tube')]
    public function postTranslationTest()
    {
        try {
            $result = ObjectManager::getInstance(DocumentTranslationTaskService::class)->testAdapter();
            $response = $this->success(__('适配器测试完成'), $result);
        } catch (\Throwable $throwable) {
            $response = $this->error($throwable->getMessage());
        }

        return $this->jsonResponse($response);
    }

    #[Acl('Weline_DeveloperWorkspace::dev-document-manager-delete', '文档删除', 'fa fa-delete')]
    public function postDelete()
    {
        $id = $this->request->getParam('id');
        try {
            ModelService::getDocumentModel()->load($id)->delete();
            return $this->fetchJson($this->success());
        } catch (\Exception $exception) {
            return $this->fetchJson($this->exception($exception));
        }
    }

    #[Acl('Weline_DeveloperWorkspace::dev-document-manager-edit', '文档编辑', 'fa fa-edit')]
    public function edit()
    {
        $this->redirect($this->url->getBackendUrl('dev/tool/admin/document/add', $this->request->getParams()));
    }

    #[Acl('Weline_DeveloperWorkspace::dev-document-manager-add', '文档添加', 'fa fa-plus')]
    public function add()
    {
        // 分类（只获取激活的分类）
        /**@var Catalog $catalogModel */
        $catalogModel = ObjectManager::getInstance(Catalog::class);
        $catalogs     = $catalogModel->getTree('pid');
        $this->assign('catalogs', $catalogs);
        # 作者
        /**@var BackendUser $adminUserModel */
        $adminUserModel = ObjectManager::getInstance(BackendUser::class);
        $this->assign('users', $adminUserModel->select()->fetch()->getItems());
        # 如果是编辑,不是就返回空 文档
        $this->assign('document', ModelService::getDocumentModel()->load($this->request->getParam('id', 0)));
        return $this->fetch();
    }

    #[
        Acl('Weline_DeveloperWorkspace::dev-document-manager-save', '文档保存', 'fa fa-save'),
    ]
    public function postPost()
    {
        # 保存
        /**@var \Weline\DeveloperWorkspace\Model\Document $documentModel */
        $documentModel = ObjectManager::getInstance(\Weline\DeveloperWorkspace\Model\Document::class);
        try {
            $pre_msg = __('添加');
            if ($this->request->getPost('id')) {
                $pre_msg = __('修改');
            }
            $data            = $this->request->getPost();
            $data['content'] = htmlspecialchars($data['content']);
            $documentModel->save($data);
            $this->getMessageManager()->addSuccess($pre_msg . '文档成功！ID:' . $documentModel->getId());
        } catch (\Exception $exception) {
            $this->exception($exception);
        }
        $this->redirect($this->_url->getBackendUrl('dev/tool/admin/document'));
    }

    #[Acl('Weline_DeveloperWorkspace::dev-document-manager-upload', '文档上传', 'fa fa-upload')]
    public function postUpload()
    {
        $uploader = new Uploader();
        $paths    = $uploader->saveFiles('Weline_DeveloperWorkspace', 'document', 'wyswyg');
        if (!isset($paths[0])) {
            throw new Exception(__('文件上传失败！'));
        }
        return $this->fetchJson(['location' => $paths[0]]);
    }

    #[Acl('Weline_DeveloperWorkspace::dev-document-manager-view', '文档查看', 'fa fa-eye')]
    public function getView()
    {
        $id = $this->request->getParam('id');
        if (!$id) {
            return $this->fetchJson($this->error(__('文档ID不能为空')));
        }
        try {
            $document = ModelService::getDocumentModel()->load($id);
            if (!$document->getId()) {
                return $this->fetchJson($this->error(__('文档不存在')));
            }
            // 获取分类信息
            $catalog = ObjectManager::getInstance(Catalog::class)->load($document->getCategoryId());
            // 获取作者信息
            // 如果是自动导入的文档，优先使用模块名作为作者
            $authorName = __('未知');
            if ($document->isAutoImported() && $document->getModuleName()) {
                $authorName = $document->getModuleName();
            } else {
                $author = null;
                if ($document->getAuthorId()) {
                    $author = ObjectManager::getInstance(BackendUser::class)->load($document->getAuthorId());
                    if ($author && $author->getId()) {
                        $authorName = $author->getData('username');
                    }
                }
            }
            // 获取文档内容并清理HTML注释
            /** @var DocumentTranslationReadService $translationReadService */
            $translationReadService = ObjectManager::getInstance(DocumentTranslationReadService::class);
            $view = $translationReadService->getDocumentView($document, $this->getRequestLocale(), true, true);
            $content = $this->cleanHtmlComments((string)($view['content'] ?? ''));
            
            return $this->fetchJson($this->success(__('获取成功'), [
                'id' => $document->getId(),
                'title' => $view['title'] ?? $document->getTitle(),
                'summary' => $view['summary'] ?? $document->getData('summary'),
                'content' => $content,
                'category' => $catalog->getName(),
                'author' => $authorName,
                'create_time' => $document->getData('create_time'),
                'update_time' => $document->getData('update_time'),
                'module_name' => $document->getModuleName(),
                'file_path' => $document->getFilePath(),
                'file_name' => $document->getFileName(),
                'is_auto_imported' => $document->isAutoImported(),
                'locale' => $view['locale'] ?? $this->getRequestLocale(),
                'source_locale' => $view['source_locale'] ?? DocumentTranslationConfigService::SOURCE_LOCALE,
                'is_translated' => $view['is_translated'] ?? false,
                'translation_status' => $view['translation_status'] ?? Translation::STATUS_MISSING,
            ]));
        } catch (\Exception $exception) {
            return $this->fetchJson($this->exception($exception));
        }
    }

    #[Acl('Weline_DeveloperWorkspace::dev-document-manager-list', '文档列表API', 'fa fa-list')]
    public function getList()
    {
        $categoryId = $this->request->getParam('category_id');
        $page = intval($this->request->getParam('page', 1));
        $pageSize = intval($this->request->getParam('pageSize', 10));
        $locale = $this->getRequestLocale();
        
        try {
            $documentModel = ModelService::getDocumentModel();
            $query = $documentModel->joinModel(Catalog::class, 'catalog', 'main_table.category_id=catalog.id')
                                   ->fields('main_table.*,main_table.id as doc_id,catalog.*,catalog.id as c_id,catalog.name as c_name');
            
            // 如果指定了分类，则过滤
            if ($categoryId) {
                $query->where('main_table.category_id', $this->getCategoryFilterIds((int)$categoryId), 'in');
            }
            
            $documents = $query->pagination($page, $pageSize, $this->request->getParams())
                               ->order('doc_id', 'desc')
                               ->select()
                               ->fetch();
            
            /** @var DocumentTranslationReadService $translationReadService */
            $translationReadService = ObjectManager::getInstance(DocumentTranslationReadService::class);
            $items = [];
            foreach ($documents->getItems() as $doc) {
                // 如果是自动导入的文档，使用模块名作为作者
                $authorName = '';
                $isAutoImported = (bool)$doc->getData('is_auto_imported');
                if ($isAutoImported && $doc->getData('module_name')) {
                    $authorName = $doc->getData('module_name');
                } else {
                    $authorId = $doc->getData('author_id');
                    if ($authorId) {
                        $author = ObjectManager::getInstance(BackendUser::class)->load($authorId);
                        if ($author && $author->getId()) {
                            $authorName = $author->getData('username');
                        }
                    }
                }
                
                $sourceDocument = ObjectManager::make(\Weline\DeveloperWorkspace\Model\Document::class)
                    ->load((int)$doc->getData('doc_id'));
                $view = $sourceDocument && $sourceDocument->getId()
                    ? $translationReadService->getDocumentView($sourceDocument, $locale, false, true)
                    : [];

                $items[] = [
                    'doc_id' => $doc->getData('doc_id'),
                    'title' => $view['title'] ?? $doc->getData('title'),
                    'summary' => $view['summary'] ?? $doc->getData('summary'),
                    'c_name' => $doc->getData('c_name'),
                    'c_id' => $doc->getData('c_id'),
                    'author_id' => $doc->getData('author_id'),
                    'author' => $authorName ?: __('未知'),
                    'create_time' => $doc->getData('create_time'),
                    'update_time' => $doc->getData('update_time'),
                    'module_name' => $doc->getData('module_name'),
                    'is_auto_imported' => $isAutoImported,
                    'locale' => $view['locale'] ?? $locale,
                    'is_translated' => $view['is_translated'] ?? false,
                    'translation_status' => $view['translation_status'] ?? Translation::STATUS_MISSING,
                ];
            }
            
            return $this->fetchJson($this->success(__('获取成功'), [
                'items' => $items,
                'pagination' => $documentModel->getPagination(),
            ]));
        } catch (\Exception $exception) {
            return $this->fetchJson($this->exception($exception));
        }
    }
    
    /**
     * 清理HTML注释
     * 
     * @param string|null $content 原始内容
     * @return string 清理后的内容
     */
    private function getRequestLocale(): string
    {
        $locale = trim((string)$this->request->getParam('locale', ''));
        if ($locale === '') {
            $locale = (string)\w_env('user.lang', DocumentTranslationConfigService::SOURCE_LOCALE);
        }

        /** @var DocumentTranslationConfigService $configService */
        $configService = ObjectManager::getInstance(DocumentTranslationConfigService::class);
        $locale = $configService->normalizeLocale($locale);
        if ($configService->isSupportedLocale($locale)) {
            return $locale;
        }

        $config = $configService->getConfig();
        return $configService->normalizeLocale(
            (string)($config['source_locale'] ?? DocumentTranslationConfigService::SOURCE_LOCALE)
        );
    }

    private function getInstalledDocumentLocales(): array
    {
        try {
            /** @var Locale $localeModel */
            $localeModel = ObjectManager::getInstance(Locale::class);
            /** @var I18n $i18n */
            $i18n = ObjectManager::getInstance(I18n::class);
            $displayLocaleCode = $this->getRequestLocale();
            $rows = $localeModel->clear()
                ->where(Locale::schema_fields_IS_INSTALL, 1)
                ->order(Locale::schema_fields_CODE, 'ASC')
                ->select()
                ->fetchArray();

            $result = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                $code = trim((string)($row[Locale::schema_fields_CODE] ?? ''));
                if ($code === '') {
                    continue;
                }
                $name = (string)$i18n->getLocaleName($code, $displayLocaleCode);
                $result[] = [
                    'code' => $code,
                    'name' => $name !== '' ? $name : $code,
                ];
            }

            return $result ?: [
                ['code' => DocumentTranslationConfigService::SOURCE_LOCALE, 'name' => '中文'],
                ['code' => 'en_US', 'name' => 'English'],
            ];
        } catch (\Throwable) {
            return [
                ['code' => DocumentTranslationConfigService::SOURCE_LOCALE, 'name' => '中文'],
                ['code' => 'en_US', 'name' => 'English'],
            ];
        }
    }

    private function getSupportedDocumentLocales(): array
    {
        /** @var DocumentTranslationConfigService $configService */
        $configService = ObjectManager::getInstance(DocumentTranslationConfigService::class);
        $supportedCodes = $configService->getSupportedLocales();
        $installedLocales = $this->getInstalledDocumentLocales();
        $installedByCode = [];

        foreach ($installedLocales as $locale) {
            $code = (string)($locale['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $installedByCode[$code] = $locale;
        }

        $result = [];
        foreach ($supportedCodes as $code) {
            $code = trim((string)$code);
            if ($code === '') {
                continue;
            }
            $result[] = $installedByCode[$code] ?? [
                'code' => $code,
                'name' => $code,
            ];
        }

        return $result ?: $installedLocales;
    }

    private function cleanHtmlComments(?string $content): string
    {
        if (empty($content)) {
            return '';
        }
        
        // 清理HTML注释（包括单行和多行注释）
        // 匹配格式：<!-- ... --> 或 <!-- ... \n ... -->
        $cleanedContent = preg_replace('/<!--[\s\S]*?-->/', '', $content ?? '');
        
        // preg_replace 可能返回 null，需要处理
        if ($cleanedContent === null) {
            $cleanedContent = $content ?? '';
        }
        
        // 清理后可能产生多余的空行，移除连续的空行（保留单个空行）
        $cleanedContent = preg_replace('/\n{3,}/', "\n\n", $cleanedContent ?? '');
        
        // preg_replace 可能返回 null，需要处理
        if ($cleanedContent === null) {
            $cleanedContent = '';
        }
        
        // 清理首尾空白
        $cleanedContent = trim($cleanedContent ?? '');
        
        return $cleanedContent;
    }

    /**
     * 父分类筛选时包含当前分类和全部后代分类。
     *
     * @return int[]
     */
    private function getCategoryFilterIds(int $categoryId): array
    {
        if ($categoryId <= 0) {
            return [];
        }

        /** @var Catalog $catalogModel */
        $catalogModel = ObjectManager::getInstance(Catalog::class);
        $rows = $catalogModel->clear()
            ->where(Catalog::schema_fields_is_active, 1)
            ->select()
            ->fetchArray();

        if (!is_array($rows) || $rows === []) {
            return [$categoryId];
        }

        $childrenByParent = [];
        foreach ($rows as $row) {
            $id = (int)($row[Catalog::schema_fields_ID] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $parentId = (int)($row[Catalog::schema_fields_PID] ?? 0);
            $childrenByParent[$parentId][] = $id;
        }

        $ids = [];
        $stack = [$categoryId];
        while ($stack) {
            $currentId = (int) array_pop($stack);
            if ($currentId <= 0 || isset($ids[$currentId])) {
                continue;
            }

            $ids[$currentId] = $currentId;
            foreach ($childrenByParent[$currentId] ?? [] as $childId) {
                if (!isset($ids[$childId])) {
                    $stack[] = (int)$childId;
                }
            }
        }

        return array_values($ids);
    }
}
