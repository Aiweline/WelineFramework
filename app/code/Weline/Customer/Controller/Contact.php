<?php

declare(strict_types=1);

namespace Weline\Customer\Controller;

use Weline\Customer\Model\ContactInquiry;
use Weline\Customer\Model\Customer;
use Weline\Customer\Service\ContactInquiryService;
use Weline\Framework\Http\RedirectException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\FiberOutputBuffer;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Server\Log\WlsLogger;

class Contact extends \Weline\Framework\App\Controller\FrontendController
{
    protected ?string $layoutType = 'contact.default';

    public function getIndex(): string
    {
        return $this->renderPage($this->buildDefaultFormData());
    }

    public function postIndex(): string
    {
        $contactInquiryService = ObjectManager::getInstance(ContactInquiryService::class);
        $formData = $contactInquiryService->normalizeFormData([
            'name' => $this->request->getPost('name'),
            'email' => $this->request->getPost('email'),
            'phone' => $this->request->getPost('phone'),
            'order_number' => $this->request->getPost('order_number'),
            'category' => $this->request->getPost('category'),
            'subject' => $this->request->getPost('subject'),
            'message' => $this->request->getPost('message'),
        ]);

        try {
            /** @var Customer|null $customer */
            $customer = $this->getLoginUser();
            $contactInquiryService->submit($formData, $customer);
            return (string)$this->redirect('/customer/contact', ['submitted' => 1]);
        } catch (RedirectException $exception) {
            throw $exception;
        } catch (\InvalidArgumentException $exception) {
            return $this->renderPage($formData, $exception->getMessage());
        } catch (\Throwable $exception) {
            return $this->renderPage($formData, (string)__('提交失败，请稍后重试。'));
        }
    }

    /**
     * @param array{name:string,email:string,phone:string,order_number:string,category:string,subject:string,message:string} $formData
     */
    private function renderPage(array $formData, ?string $formError = null): string
    {
        $submitted = (int)($this->request->getParam('submitted') ?? 0) === 1;
        $this->assign('title', __('联系我们'));
        $this->assign('form_data', $formData);
        $this->assign('form_error', $formError ?? '');
        $this->assign('contact_submitted', $submitted);
        $this->assign('contact_categories', ContactInquiry::getCategoryOptions());
        $contentTemplate = 'Weline_Customer::templates/frontend/contact.phtml';
        $contentHtml = $this->template($contentTemplate);
        $meta = [
            'showHeader' => true,
            'showFooter' => true,
            'showBreadcrumb' => true,
            'class' => '',
            'content' => $contentHtml,
            'contentTemplate' => $contentTemplate,
        ];
        $this->assign('meta', $meta);
        $this->assign('content', $contentHtml);

        $layoutTemplate = 'Weline_Theme::theme/frontend/layouts/contact/default.phtml';
        $layoutHtml = $this->template($layoutTemplate);
        if ($layoutHtml !== '' || $contentHtml === '') {
            return $layoutHtml;
        }

        $this->logEmptyContactLayout($layoutTemplate, $contentTemplate, $contentHtml);
        try {
            $this->request->getResponse()->setHeader('Content-Type', 'text/html; charset=utf-8');
        } catch (\Throwable) {
        }

        return $contentHtml;
    }

    private function logEmptyContactLayout(string $layoutTemplate, string $contentTemplate, string $contentHtml): void
    {
        if (!class_exists(WlsLogger::class, false)) {
            return;
        }

        try {
            WlsLogger::warning_(
                '[ContactEmptyLayout] '
                . (string)json_encode([
                    'uri' => function_exists('w_env_request_uri') ? (string)w_env_request_uri() : (string)($_SERVER['REQUEST_URI'] ?? ''),
                    'request_id' => RequestLifecycleTrace::ensureRequestId(),
                    'layout_template' => $layoutTemplate,
                    'content_template' => $contentTemplate,
                    'content_bytes' => strlen($contentHtml),
                    'memory_mb' => round(memory_get_usage(true) / 1048576, 2),
                    'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                    'fiber_output' => FiberOutputBuffer::debugState(),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
            );
        } catch (\Throwable) {
            // Diagnostics must not break the contact page fallback.
        }
    }

    /**
     * @return array{name:string,email:string,phone:string,order_number:string,category:string,subject:string,message:string}
     */
    private function buildDefaultFormData(): array
    {
        /** @var Customer|null $customer */
        $customer = $this->getLoginUser();

        return [
            'name' => '',
            'email' => $customer?->getEmail() ?? '',
            'phone' => '',
            'order_number' => '',
            'category' => ContactInquiry::CATEGORY_ORDER,
            'subject' => '',
            'message' => '',
        ];
    }
}
