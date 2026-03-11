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
 * SEO 管理后台控制器
 * 
 * @package Weline_Seo
 */
#[Acl('Weline_Seo::seo_dashboard', 'SEO总览', 'mdi-view-dashboard-outline', 'SEO总览', 'Weline_Backend::seo_group')]
class Dashboard extends BaseController
{
    private ObjectManager $objectManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * SEO 报表首页
     * 
     * @return string
     */
    #[Acl('Weline_Seo::seo_dashboard_index', '查看SEO总览', 'mdi-view-dashboard-outline', '查看SEO总览')]
    public function index(): string
    {
        /** @var SeoSubject $subjectModel */
        $subjectModel = $this->objectManager->getInstance(SeoSubject::class);
        
        // 获取所有启用的SEO主体
        $subjects = $subjectModel->reset()
            ->where(SeoSubject::schema_fields_STATUS, SeoSubject::STATUS_ENABLED)
            ->order(SeoSubject::schema_fields_UPDATED_AT, 'DESC')
            ->select()
            ->fetchArray();

        // 为每个主体获取关键词统计
        /** @var SeoKeyword $keywordModel */
        $keywordModel = $this->objectManager->getInstance(SeoKeyword::class);
        
        foreach ($subjects as &$subject) {
            $keywords = $keywordModel->reset()
                ->where(SeoKeyword::schema_fields_SUBJECT_ID, $subject['subject_id'])
                ->where(SeoKeyword::schema_fields_STATUS, SeoKeyword::STATUS_ENABLED)
                ->order(SeoKeyword::schema_fields_PRIORITY, 'DESC')
                ->limit(5)
                ->select()
                ->fetchArray();
            
            $subject['keywords'] = $keywords;
            $subject['keyword_count'] = count($keywords);
        }
        
        $this->assign('title', __('SEO总览'));
        $this->assign('subjects', $subjects);
        
        return $this->fetch();
    }

    /**
     * 主体SEO详情页（无id时显示列表，有id时显示详情）
     * 
     * @return string
     */
    #[Acl('Weline_Seo::seo_subject_detail', '查看主体详情', 'mdi-file-chart-outline', '查看主体SEO详情')]
    public function subject(): string
    {
        $subjectId = (int)$this->request->getParam('id', 0);
        
        // 如果没有 id，显示主体列表
        if ($subjectId <= 0) {
            return $this->subjectList();
        }

        /** @var SeoSubject $subjectModel */
        $subjectModel = $this->objectManager->getInstance(SeoSubject::class);
        $subjectModel->load($subjectId);
        
        if (!$subjectModel->getId()) {
            $this->getMessageManager()->addError(__('主体不存在'));
            return $this->redirect('*/backend/dashboard/subject');
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
        
        return $this->fetch('Weline_Seo::templates/Backend/Dashboard/subject_detail.phtml');
    }

    /**
     * 主体列表页
     * 
     * @return string
     */
    private function subjectList(): string
    {
        /** @var SeoSubject $subjectModel */
        $subjectModel = $this->objectManager->getInstance(SeoSubject::class);
        
        // 获取搜索参数
        $search = $this->request->getParam('search', '');
        $status = $this->request->getParam('status', '');
        
        // 构建查询
        $query = $subjectModel->reset();
        
        if (!empty($search)) {
            $query->where('title', '%' . $search . '%', 'LIKE');
        }
        
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
        
        $this->assign('title', __('SEO主体列表'));
        $this->assign('subjects', $subjects);
        $this->assign('search', $search);
        $this->assign('status', $status);
        
        return $this->fetch('Weline_Seo::templates/Backend/Dashboard/subject.phtml');
    }

    /**
     * 刷新AI建议（AJAX接口）
     * 
     * @return string
     */
    #[Acl('Weline_Seo::seo_refresh_suggestion', '刷新AI建议', 'mdi-refresh', '刷新AI建议')]
    public function refreshSuggestion(): string
    {
        $subjectId = (int)$this->request->getPost('subject_id', 0);
        
        if ($subjectId <= 0) {
            return $this->jsonResponse(false, __('无效的主体ID'));
        }

        try {
            /** @var SuggestionService $suggestionService */
            $suggestionService = $this->objectManager->getInstance(SuggestionService::class);
            $suggestion = $suggestionService->generateSuggestion($subjectId, true);
            
            return $this->jsonResponse(true, __('AI建议生成成功'), [
                'suggestion' => $suggestion->getData(),
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('生成建议失败：%{1}', $e->getMessage()));
        }
    }
}

