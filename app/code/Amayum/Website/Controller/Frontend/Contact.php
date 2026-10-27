<?php

declare(strict_types=1);

namespace Amayum\Website\Controller\Frontend;

use Amayum\Website\Service\CaseStudyCatalog;
use Amayum\Website\Service\GuideCatalog;
use Amayum\Website\Service\InquiryService;
use Amayum\Website\Service\SeoFactsService;
use Amayum\Website\Service\SiteProfileService;

/**
 * 诊断申请：GET 渲染表单，POST 服务端校验/落库/发信。
 *
 * @Extra type=fpc enabled=false
 */
final class Contact extends BasePage
{
    protected ?string $layoutType = 'contact_page';
    protected string $pageType = 'contact';
    protected string $contentTemplate = 'contact/index.phtml';
    protected string $bodyClass = 'page--contact';

    public function __construct(
        SiteProfileService $siteProfile,
        SeoFactsService $seoFacts,
        CaseStudyCatalog $caseStudies,
        GuideCatalog $guides,
        private readonly InquiryService $inquiry,
    ) {
        parent::__construct($siteProfile, $seoFacts, $caseStudies, $guides);
    }

    public function index(): string
    {
        $websiteCode = $this->siteProfile->currentWebsiteCode();
        $profile = $this->siteProfile->profile($websiteCode);
        $flags = $this->siteProfile->flags($websiteCode);
        $smtpAvailable = (bool)($flags['contact_smtp_enabled'] ?? false)
            && $this->inquiry->smtpAvailable($websiteCode);
        $state = [
            'status' => 'idle',
            'message' => '',
            'errors' => [],
            'values' => [],
            'mailto' => $this->inquiry->mailto($profile),
        ];

        if ($this->request->isPost()) {
            $state = $this->handlePost($profile, $flags, $state);
        }

        $this->assign([
            'contact_state' => $state,
            'contact_fields' => $this->fieldDefinitions(),
            'stack_options' => InquiryService::STACKS,
            'market_options' => InquiryService::MARKETS,
            'contact_form_token' => $this->inquiry->issueFormToken(),
            'smtp_available' => $smtpAvailable,
            'response_sla' => (string)($profile['response_sla'] ?? ''),
            'location' => (string)($profile['location'] ?? ''),
            'contact_mailto' => $state['mailto'],
        ]);

        return $this->renderPage();
    }

    protected function buildSeo(string $websiteCode): array
    {
        return $this->seoFacts->contact($websiteCode);
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $flags
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function handlePost(array $profile, array $flags, array $state): array
    {
        $sameOrigin = $this->inquiry->isSameOrigin($this->request);
        $tokenValid = $this->inquiry->verifyRequestToken($this->request);
        if (!$sameOrigin && !$tokenValid) {
            $this->request->getResponse()->setCode(403);

            return \array_replace($state, [
                'status' => 'failed',
                'errors' => ['form' => (string)__('请求来源校验失败，请刷新页面后重试。')],
            ]);
        }

        $input = (array)$this->request->getPost();
        $validation = $this->inquiry->validate($input);
        $state['values'] = $validation['data'];
        if (!$validation['valid']) {
            $this->request->getResponse()->setCode(422);

            return \array_replace($state, [
                'status' => 'invalid',
                'errors' => $validation['errors'],
            ]);
        }

        $ipHash = $this->inquiry->hashIp((string)$this->request->clientIP());
        if ($this->inquiry->rateLimitExceeded($ipHash)) {
            $this->request->getResponse()->setCode(429);

            return \array_replace($state, [
                'status' => 'rate_limited',
                'errors' => ['form' => (string)__('提交过于频繁，请稍后再试，或直接使用下方邮件地址。')],
            ]);
        }
        $this->inquiry->recordAttempt($ipHash);

        $result = $this->inquiry->submit($validation['data'], $this->request, $profile, $flags);
        $state['status'] = (string)$result['status'];
        $state['message'] = (string)$result['message'];
        $state['mailto'] = (string)$result['mailto'];
        if (($result['ok'] ?? false) === true) {
            $state['values'] = [];
        }

        return $state;
    }

    /** @return array<string, array<string, mixed>> */
    private function fieldDefinitions(): array
    {
        return [
            'name' => [
                'label' => (string)__('姓名'),
                'type' => 'text',
                'required' => true,
                'maxlength' => 80,
                'autocomplete' => 'name',
            ],
            'email' => [
                'label' => (string)__('工作邮箱'),
                'type' => 'email',
                'required' => true,
                'maxlength' => 254,
                'autocomplete' => 'email',
            ],
            'company' => [
                'label' => (string)__('公司 / 站点'),
                'type' => 'text',
                'required' => false,
                'maxlength' => 120,
                'autocomplete' => 'organization',
            ],
            'stack' => [
                'label' => (string)__('当前栈'),
                'type' => 'select',
                'required' => false,
                'options' => InquiryService::STACKS,
            ],
            'market' => [
                'label' => (string)__('目标市场'),
                'type' => 'select',
                'required' => false,
                'options' => InquiryService::MARKETS,
            ],
            'volume_band' => [
                'label' => (string)__('月订单量或 GMV 区间'),
                'type' => 'text',
                'required' => false,
                'maxlength' => 80,
            ],
            'timeline' => [
                'label' => (string)__('计划上线时间'),
                'type' => 'text',
                'required' => false,
                'maxlength' => 80,
            ],
            'message' => [
                'label' => (string)__('当前最大卡点'),
                'type' => 'textarea',
                'required' => true,
                'minlength' => 8,
                'maxlength' => 2000,
            ],
            'consent' => [
                'label' => (string)__('我同意 Amayum 使用以上信息回复这次咨询，不做订阅推送。'),
                'type' => 'checkbox',
                'required' => true,
            ],
        ];
    }
}
