<?php

declare(strict_types=1);

/*
 * Weline Cms Module
 * CMS内容管理系统后端表单提交管理控制器
 */

namespace Weline\Cms\Controller\Backend;

use Weline\Cms\Model\FormSubmission;
use Weline\Cms\Model\Page;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

#[\Weline\Framework\Acl\Acl('Weline_Cms::form_submissions', '表单提交管理', 'mdi mdi-email-open', '管理表单提交记录')]
class Submission extends BackendController
{
    private FormSubmission $formSubmissionModel;
    private Page $pageModel;

    public function __construct(
        FormSubmission $formSubmissionModel,
        Page $pageModel
    ) {
        $this->formSubmissionModel = $formSubmissionModel;
        $this->pageModel = $pageModel;
    }

    #[\Weline\Framework\Acl\Acl('Weline_Cms::form_submissions_index', '查看提交列表', 'mdi mdi-view-list', '查看表单提交列表')]
    public function index()
    {
        // 设置页面标题
        $this->assign('page_title', __('表单提交管理'));
        $this->assign('breadcrumb_parent', __('CMS内容管理系统'));
        $this->assign('breadcrumb_current', __('表单提交'));
        
        // 获取筛选参数
        $search = $this->request->getGet('search', '');
        $pageId = $this->request->getGet('page_id', '');
        $startDate = $this->request->getGet('start_date', '');
        $endDate = $this->request->getGet('end_date', '');
        $extraFieldKey = $this->request->getGet('extra_field_key', '');
        $extraFieldValue = $this->request->getGet('extra_field_value', '');
        
        // 构建查询
        $query = clone $this->formSubmissionModel;
        $query->clear();
        
        // 搜索条件
        if ($search) {
            $query->where(FormSubmission::fields_EMAIL, "%$search%", 'like')
                ->where(FormSubmission::fields_PHONE, "%$search%", 'like', 'or');
        }
        
        // 页面筛选
        if ($pageId) {
            $query->where(FormSubmission::fields_PAGE_ID, $pageId);
        }
        
        // 日期范围筛选
        if ($startDate) {
            $query->where(FormSubmission::fields_SUBMITTED_AT, $startDate, '>=');
        }
        if ($endDate) {
            $query->where(FormSubmission::fields_SUBMITTED_AT, $endDate . ' 23:59:59', '<=');
        }
        
        // 额外字段筛选（需要遍历）
        $submissions = $query->order(FormSubmission::fields_SUBMITTED_AT, 'DESC')
            ->select()
            ->fetch()
            ->getItems();
        
        // 如果有额外字段筛选，进行二次过滤
        if ($extraFieldKey && $extraFieldValue) {
            $submissions = array_filter($submissions, function($submission) use ($extraFieldKey, $extraFieldValue) {
                $extraFields = $submission->getExtraFields();
                return isset($extraFields[$extraFieldKey]) && 
                       stripos($extraFields[$extraFieldKey], $extraFieldValue) !== false;
            });
        }
        
        $this->assign('submissions', $submissions);
        
        // 获取所有页面
        $pages = clone $this->pageModel;
        $pages = $pages->clear()->select()->fetch()->getItems();
        $this->assign('pages', $pages);
        
        // 获取所有额外字段键
        $extraFieldKeys = FormSubmission::getUniqueExtraFieldKeys();
        $this->assign('extra_field_keys', $extraFieldKeys);
        
        // 传递筛选参数给视图
        $this->assign('search', $search);
        $this->assign('page_id', $pageId);
        $this->assign('start_date', $startDate);
        $this->assign('end_date', $endDate);
        $this->assign('extra_field_key', $extraFieldKey);
        $this->assign('extra_field_value', $extraFieldValue);
        
        return $this->fetch();
    }

    #[\Weline\Framework\Acl\Acl('Weline_Cms::form_submissions_view', '查看提交详情', 'mdi mdi-eye', '查看提交详情')]
    public function view()
    {
        $submissionId = $this->request->getGet('id');
        if (!$submissionId) {
            $this->getMessageManager()->addError(__('提交记录ID不能为空！'));
            $this->redirect('*/backend/submission/index');
            return;
        }

        $submission = clone $this->formSubmissionModel;
        $submission->clear()->load($submissionId);
        
        if (!$submission->getId()) {
            $this->getMessageManager()->addError(__('提交记录不存在！'));
            $this->redirect('*/backend/submission/index');
            return;
        }

        $this->assign('page_title', __('查看提交详情'));
        $this->assign('breadcrumb_parent', __('表单提交管理'));
        $this->assign('breadcrumb_current', __('提交详情'));
        $this->assign('submission', $submission);
        
        // 获取关联页面信息
        if ($submission->getData(FormSubmission::fields_PAGE_ID)) {
            $page = clone $this->pageModel;
            $page->clear()->load($submission->getData(FormSubmission::fields_PAGE_ID));
            if ($page->getId()) {
                $this->assign('page', $page);
            }
        }

        return $this->fetch();
    }

    #[\Weline\Framework\Acl\Acl('Weline_Cms::form_submissions_delete', '删除提交记录', 'mdi mdi-delete', '删除提交记录')]
    public function postDelete()
    {
        try {
            $submissionId = $this->request->getPost('id');
            if (!$submissionId) {
                $this->getMessageManager()->addError(__('提交记录ID不能为空！'));
                $this->redirect('*/backend/submission/index');
                return;
            }

            $submission = clone $this->formSubmissionModel;
            $submission->clear()->load($submissionId);
            
            if (!$submission->getId()) {
                $this->getMessageManager()->addError(__('提交记录不存在！'));
                $this->redirect('*/backend/submission/index');
                return;
            }

            $submission->delete();
            
            $this->getMessageManager()->addSuccess(__('提交记录删除成功！'));
            $this->redirect('*/backend/submission/index');
        } catch (\Exception $exception) {
            $this->getMessageManager()->addWarning(__('提交记录删除失败！'));
            if (DEV) {
                $this->getMessageManager()->addException($exception);
            }
            $this->redirect('*/backend/submission/index');
        }
    }
    
    #[\Weline\Framework\Acl\Acl('Weline_Cms::form_submissions_export', '导出提交记录', 'mdi mdi-download', '导出提交记录为CSV')]
    public function export()
    {
        try {
            // 获取所有提交记录
            $submissions = clone $this->formSubmissionModel;
            $submissions = $submissions->clear()
                ->order(FormSubmission::fields_SUBMITTED_AT, 'DESC')
                ->select()
                ->fetch()
                ->getItems();
            
            // 获取所有额外字段键
            $extraFieldKeys = FormSubmission::getUniqueExtraFieldKeys();
            
            // 设置CSV头部
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=form_submissions_' . date('Y-m-d_H-i-s') . '.csv');
            
            // 输出BOM以支持Excel打开UTF-8
            echo "\xEF\xBB\xBF";
            
            $output = fopen('php://output', 'w');
            
            // CSV标题行
            $headers = ['ID', '页面ID', '邮箱', '电话', 'IP地址', '提交时间'];
            foreach ($extraFieldKeys as $key) {
                $headers[] = $key;
            }
            fputcsv($output, $headers);
            
            // 数据行
            foreach ($submissions as $submission) {
                $row = [
                    $submission->getId(),
                    $submission->getData(FormSubmission::fields_PAGE_ID),
                    $submission->getData(FormSubmission::fields_EMAIL),
                    $submission->getData(FormSubmission::fields_PHONE),
                    $submission->getData(FormSubmission::fields_IP_ADDRESS),
                    $submission->getData(FormSubmission::fields_SUBMITTED_AT)
                ];
                
                $extraFields = $submission->getExtraFields();
                foreach ($extraFieldKeys as $key) {
                    $row[] = $extraFields[$key] ?? '';
                }
                
                fputcsv($output, $row);
            }
            
            fclose($output);
            exit;
            
        } catch (\Exception $exception) {
            $this->getMessageManager()->addError(__('导出失败！'));
            if (DEV) {
                $this->getMessageManager()->addException($exception);
            }
            $this->redirect('*/backend/submission/index');
        }
    }
}

