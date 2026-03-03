<?php

declare(strict_types=1);

/*
 * Weline Cms Module
 * CMS内容管理系统前端表单提交控制器
 */

namespace Weline\Cms\Controller\Frontend;

use Weline\Cms\Model\FormSubmission;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

class Form extends FrontendController
{
    private FormSubmission $formSubmissionModel;

    public function __construct(
        FormSubmission $formSubmissionModel
    ) {
        $this->formSubmissionModel = $formSubmissionModel;
    }

    /**
     * 处理表单提交
     */
    public function submit()
    {
        try {
            // 只接受 POST 请求
            if (!$this->request->isPost()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('非法请求')
                ], 405);
            }

            // 获取表单数据
            $pageId = (int)($this->request->getPost('page_id') ?: $this->request->getGet('page_id', 0));
            $email = trim($this->request->getPost('email', ''));
            $phone = trim($this->request->getPost('phone', ''));

            // 验证必填字段
            if (empty($email)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('请输入邮箱地址')
                ]);
            }

            if (empty($phone)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('请输入电话号码')
                ]);
            }

            // 验证邮箱格式
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('邮箱格式不正确')
                ]);
            }

            // 验证电话格式（简单验证）
            if (!preg_match('/^[\d\s\-\+\(\)]+$/', $phone)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('电话号码格式不正确')
                ]);
            }

            // 获取其他信息
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
            $referer = $_SERVER['HTTP_REFERER'] ?? '';

            // 保存表单提交记录
            $submission = clone $this->formSubmissionModel;
            $submission->clearData()
                ->setData(FormSubmission::fields_PAGE_ID, $pageId)
                ->setData(FormSubmission::fields_EMAIL, $email)
                ->setData(FormSubmission::fields_PHONE, $phone)
                ->setData(FormSubmission::fields_USER_AGENT, $userAgent)
                ->setData(FormSubmission::fields_IP_ADDRESS, $ipAddress)
                ->setData(FormSubmission::fields_REFERER, $referer)
                ->setData(FormSubmission::fields_STATUS, FormSubmission::STATUS_NEW)
                ->save();

            // 返回成功响应
            return $this->fetchJson([
                'success' => true,
                'message' => __('提交成功！感谢您的关注。'),
                'data' => [
                    'submission_id' => $submission->getId()
                ]
            ]);

        } catch (\Exception $exception) {
            // 记录错误日志
            w_log_error('Form submission error: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString());

            // 开发环境返回详细错误信息
            if (DEV) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('提交失败：') . $exception->getMessage(),
                    'error_detail' => [
                        'message' => $exception->getMessage(),
                        'file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                        'trace' => $exception->getTraceAsString()
                    ]
                ], 500);
            }

            return $this->fetchJson([
                'success' => false,
                'message' => __('提交失败，请稍后再试')
            ], 500);
        }
    }
}
