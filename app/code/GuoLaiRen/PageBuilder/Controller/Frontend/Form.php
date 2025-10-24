<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 前端表单提交控制器
 */

namespace GuoLaiRen\PageBuilder\Controller\Frontend;

use GuoLaiRen\PageBuilder\Model\FormSubmission;
use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Framework\App\Controller\FrontendController;

class Form extends FrontendController
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

    /**
     * 处理表单提交
     */
    public function submit()
    {
        if (!$this->request->isPost()) {
            return $this->returnJson(['success' => false, 'message' => __('请求方法错误')], 405);
        }

        try {
            // 获取所有POST数据
            $postData = $this->request->getPost();
            
            // 提取基本字段
            $email = $postData['email'] ?? '';
            $phone = $postData['phone'] ?? '';
            $pageId = $postData['page_id'] ?? 0;
            
            // 验证基本字段
            if (empty($email) && empty($phone)) {
                return $this->returnJson([
                    'success' => false, 
                    'message' => __('邮箱和电话至少需要填写一项')
                ], 400);
            }
            
            // 提取额外字段
            $excludeKeys = ['email', 'phone', 'page_id', 'form_key'];
            $extraFields = [];
            foreach ($postData as $key => $value) {
                if (!in_array($key, $excludeKeys)) {
                    $extraFields[$key] = $value;
                }
            }
            
            // 获取请求信息
            $ipAddress = $this->getClientIp();
            $userAgent = $this->request->getServer('HTTP_USER_AGENT', '');
            $referer = $this->request->getServer('HTTP_REFERER', '');
            
            // 创建提交记录
            $submission = clone $this->formSubmissionModel;
            $submission->clearData()
                ->setData(FormSubmission::fields_PAGE_ID, $pageId)
                ->setData(FormSubmission::fields_EMAIL, $email)
                ->setData(FormSubmission::fields_PHONE, $phone)
                ->setExtraFields($extraFields)
                ->setData(FormSubmission::fields_IP_ADDRESS, $ipAddress)
                ->setData(FormSubmission::fields_USER_AGENT, $userAgent)
                ->setData(FormSubmission::fields_REFERER, $referer)
                ->save(true);
            
            // 获取页面的跳转URL
            $redirectUrl = '';
            if ($pageId) {
                $page = clone $this->pageModel;
                $page->clear()->load($pageId);
                if ($page->getId()) {
                    $redirectUrl = $page->getData(Page::fields_REDIRECT_URL) ?: '';
                }
            }
            
            $response = [
                'success' => true,
                'message' => __('提交成功'),
                'submission_id' => $submission->getId()
            ];
            
            // 如果有跳转URL，则添加到响应中
            if (!empty($redirectUrl)) {
                $response['redirect_url'] = $redirectUrl;
            }
            
            return $this->returnJson($response);
            
        } catch (\Exception $exception) {
            if (DEV) {
                return $this->returnJson([
                    'success' => false,
                    'message' => $exception->getMessage()
                ], 500);
            }
            
            return $this->returnJson([
                'success' => false,
                'message' => __('提交失败，请稍后重试')
            ], 500);
        }
    }
    
    /**
     * 获取客户端IP地址
     */
    private function getClientIp(): string
    {
        $ip = '';
        
        if (!empty($this->request->getServer('HTTP_CLIENT_IP'))) {
            $ip = $this->request->getServer('HTTP_CLIENT_IP');
        } elseif (!empty($this->request->getServer('HTTP_X_FORWARDED_FOR'))) {
            $ip = $this->request->getServer('HTTP_X_FORWARDED_FOR');
        } else {
            $ip = $this->request->getServer('REMOTE_ADDR', '');
        }
        
        return $ip;
    }
    
    /**
     * 返回JSON响应
     */
    private function returnJson(array $data, int $statusCode = 200)
    {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }
}

