<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Controller\Backend;

use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoSubject;
use Weline\Seo\Model\SeoKeyword;
use Weline\Seo\Model\SeoSuggestion;
use Weline\Seo\Service\SuggestionService;

/**
 * SEO 嵌入式管理控制器
 * 
 * 提供可嵌入其他模块的 SEO 管理界面
 * 支持 module 和 scope 参数过滤，实现多态管理
 * 
 * @package Weline_Seo
 */
#[Acl('Weline_Seo::seo_embed', 'SEO嵌入式管理', 'mdi-view-compact-outline', 'SEO嵌入式管理界面', 'Weline_Seo::seo_management')]
class Embed extends BaseController
{
    private ObjectManager $objectManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * 嵌入式 SEO 主体列表
     * 
     * @return string
     */
    #[Acl('Weline_Seo::seo_embed_index', '查看嵌入式SEO管理', 'mdi-view-compact-outline', '查看嵌入式SEO管理界面')]
    public function index(): string
    {
        // 获取过滤参数
        $scope = $this->request->getParam('scope', '');
        $search = $this->request->getParam('search', '');
        $status = $this->request->getParam('status', '');

        /** @var SeoSubject $subjectModel */
        $subjectModel = $this->objectManager->getInstance(SeoSubject::class);
        
        // 构建查询
        $query = $subjectModel->reset();
        
        // 按 scope 过滤
        if (!empty($scope)) {
            $query->where(SeoSubject::schema_fields_SCOPE, $scope);
        }
        
        // 按关键词搜索
        if (!empty($search)) {
            $query->where('title', '%' . $search . '%', 'LIKE');
        }
        
        // 按状态过滤
        if ($status !== '') {
            $query->where(SeoSubject::schema_fields_STATUS, (int)$status);
        }
        
        // 获取所有主体
        $subjects = $query->order(SeoSubject::schema_fields_UPDATED_AT, 'DESC')
            ->select()
            ->fetchArray();

        // 为每个主体获取关键词数量
        /** @var SeoKeyword $keywordModel */
        $keywordModel = $this->objectManager->getInstance(SeoKeyword::class);
        
        foreach ($subjects as &$subject) {
            $keywordCount = $keywordModel->reset()
                ->where(SeoKeyword::schema_fields_SUBJECT_ID, $subject['subject_id'])
                ->count();
            $subject['keyword_count'] = $keywordCount;
        }
        
        // 获取 scope 显示名称
        $scopeDisplayName = $scope ? ucfirst(str_replace('_', ' ', $scope)) : '';
        
        $this->assign('title', $scopeDisplayName ? __('%{1} SEO管理', $scopeDisplayName) : __('SEO主体管理'));
        $this->assign('subjects', $subjects);
        $this->assign('scope', $scope);
        $this->assign('search', $search);
        $this->assign('status', $status);
        $this->assign('is_embed', true);
        
        return $this->fetch('Weline_Seo::templates/Backend/Embed/index.phtml');
    }

    /**
     * 嵌入式主体详情页
     * 
     * @return string
     */
    #[Acl('Weline_Seo::seo_embed_detail', '查看嵌入式SEO详情', 'mdi-file-chart-outline', '查看嵌入式SEO主体详情')]
    public function detail(): string
    {
        $subjectId = (int)$this->request->getParam('id', 0);
        $scope = $this->request->getParam('scope', '');
        
        if ($subjectId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('无效的主体ID'),
            ]);
        }

        /** @var SeoSubject $subjectModel */
        $subjectModel = $this->objectManager->getInstance(SeoSubject::class);
        $subjectModel->load($subjectId);
        
        if (!$subjectModel->getId()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('主体不存在'),
            ]);
        }

        // 获取关键词列表
        /** @var SeoKeyword $keywordModel */
        $keywordModel = $this->objectManager->getInstance(SeoKeyword::class);
        $keywords = $keywordModel->reset()
            ->where(SeoKeyword::schema_fields_SUBJECT_ID, $subjectId)
            ->where(SeoKeyword::schema_fields_STATUS, SeoKeyword::STATUS_ENABLED)
            ->order(SeoKeyword::schema_fields_PRIORITY, 'DESC')
            ->select()
            ->fetchArray();

        // 获取AI建议
        /** @var SeoSuggestion $suggestionModel */
        $suggestionModel = $this->objectManager->getInstance(SeoSuggestion::class);
        $suggestion = $suggestionModel->reset()
            ->where(SeoSuggestion::schema_fields_SUBJECT_ID, $subjectId)
            ->where(SeoSuggestion::schema_fields_STATUS, SeoSuggestion::STATUS_ACTIVE)
            ->order(SeoSuggestion::schema_fields_CREATED_AT, 'DESC')
            ->find()
            ->fetch();
        
        $this->assign('title', __('主体SEO详情'));
        $this->assign('subject', $subjectModel->getData());
        $this->assign('keywords', $keywords);
        $this->assign('suggestion', $suggestion->getId() ? $suggestion->getData() : null);
        $this->assign('scope', $scope);
        $this->assign('is_embed', true);
        
        return $this->fetch('Weline_Seo::templates/Backend/Embed/detail.phtml');
    }

    /**
     * 刷新AI建议（AJAX接口）
     * 
     * @return string
     */
    #[Acl('Weline_Seo::seo_embed_refresh', '刷新嵌入式AI建议', 'mdi-refresh', '刷新嵌入式AI建议')]
    public function refreshSuggestion(): string
    {
        $subjectId = (int)$this->request->getPost('subject_id', 0);
        
        if ($subjectId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('无效的主体ID'),
            ]);
        }

        try {
            /** @var SuggestionService $suggestionService */
            $suggestionService = $this->objectManager->getInstance(SuggestionService::class);
            $suggestion = $suggestionService->generateSuggestion($subjectId, true);
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('AI建议生成成功'),
                'data' => [
                    'suggestion' => $suggestion->getData(),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('生成建议失败：%{1}', $e->getMessage()),
            ]);
        }
    }

    /**
     * 添加或更新 SEO 主体（AJAX接口）
     * 
     * @return string
     */
    #[Acl('Weline_Seo::seo_embed_save', '保存嵌入式SEO主体', 'mdi-content-save', '保存嵌入式SEO主体')]
    public function save(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('无效的请求方法'),
            ]);
        }

        $subjectId = (int)$this->request->getPost('subject_id', 0);
        $title = trim($this->request->getPost('title', ''));
        $url = trim($this->request->getPost('url', ''));
        $description = trim($this->request->getPost('description', ''));
        $scope = $this->request->getPost('scope', '');
        $subjectType = $this->request->getPost('subject_type', SeoSubject::SUBJECT_TYPE_PAGE);
        $subjectEntityId = (int)$this->request->getPost('subject_entity_id', 0);
        $status = (int)$this->request->getPost('status', SeoSubject::STATUS_ENABLED);
        $locale = $this->request->getPost('locale', 'zh-CN');

        if (empty($title)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('标题不能为空'),
            ]);
        }

        try {
            /** @var SeoSubject $subjectModel */
            $subjectModel = $this->objectManager->getInstance(SeoSubject::class);
            
            if ($subjectId > 0) {
                $subjectModel->load($subjectId);
                if (!$subjectModel->getId()) {
                    return $this->fetchJson([
                        'success' => false,
                        'message' => __('主体不存在'),
                    ]);
                }
            }

            $subjectModel->setData(SeoSubject::schema_fields_TITLE, $title)
                ->setData(SeoSubject::schema_fields_URL, $url)
                ->setData(SeoSubject::schema_fields_DESCRIPTION, $description)
                ->setData(SeoSubject::schema_fields_SCOPE, $scope)
                ->setData(SeoSubject::schema_fields_SUBJECT_TYPE, $subjectType)
                ->setData(SeoSubject::schema_fields_SUBJECT_ID, $subjectEntityId)
                ->setData(SeoSubject::schema_fields_STATUS, $status)
                ->setData(SeoSubject::schema_fields_LOCALE, $locale)
                ->save();

            return $this->fetchJson([
                'success' => true,
                'message' => __('保存成功'),
                'data' => [
                    'subject_id' => $subjectModel->getId(),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('保存失败：%{1}', $e->getMessage()),
            ]);
        }
    }

    /**
     * 删除 SEO 主体（AJAX接口）
     * 
     * @return string
     */
    #[Acl('Weline_Seo::seo_embed_delete', '删除嵌入式SEO主体', 'mdi-delete', '删除嵌入式SEO主体')]
    public function delete(): string
    {
        $subjectId = (int)$this->request->getPost('subject_id', 0);
        
        if ($subjectId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('无效的主体ID'),
            ]);
        }

        try {
            /** @var SeoSubject $subjectModel */
            $subjectModel = $this->objectManager->getInstance(SeoSubject::class);
            $subjectModel->load($subjectId);
            
            if (!$subjectModel->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('主体不存在'),
                ]);
            }

            $subjectModel->delete();

            return $this->fetchJson([
                'success' => true,
                'message' => __('删除成功'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('删除失败：%{1}', $e->getMessage()),
            ]);
        }
    }

    /**
     * 获取模块显示名称
     * 
     * @param string $module
     * @return string
     */
    private function getModuleDisplayName(string $module): string
    {
        $moduleNames = [
            'GuoLaiRen_PageBuilder' => __('页面构建器'),
            'WeShop_Catalog' => __('商品目录'),
            'WeShop_Product' => __('商品'),
            'Weline_Websites' => __('站点'),
        ];
        
        return $moduleNames[$module] ?? $module;
    }
}
