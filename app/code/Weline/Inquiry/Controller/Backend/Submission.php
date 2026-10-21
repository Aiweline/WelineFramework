<?php

declare(strict_types=1);

namespace Weline\Inquiry\Controller\Backend;

use Weline\Backend\Api\Auth\BackendUserContextProviderInterface;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Inquiry\Model\Form;
use Weline\Inquiry\Model\Submission as SubmissionModel;
use Weline\Inquiry\Service\InquiryMailReplySlotService;
use Weline\Inquiry\Service\InquiryWebsiteScopeService;
use Weline\Inquiry\Service\SubmissionMenuSignalPublisher;
use Weline\Mail\Service\MailComposerService;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

/**
 * 询盘提交记录（站点范围列表；本页只读询盘数据，回复走企业邮箱线程）。
 */
#[Acl('Weline_Inquiry::submissions', '询盘提交记录', 'list', '查看询盘提交记录', 'Weline_Inquiry::root')]
final class Submission extends BackendController
{
    public function __construct(
        private readonly SubmissionModel $submission,
        private readonly Form $form,
        private readonly SubmissionMenuSignalPublisher $menuSignalPublisher,
        private readonly InquiryMailReplySlotService $mailReplySlot,
        private readonly InquiryWebsiteScopeService $websiteScope,
    ) {
    }

    public function index(): string
    {
        $this->assign('page_title', (string)__('询盘提交记录'));

        $userId = $this->menuSignalActorId();
        $currentWebsiteId = $this->currentWebsiteId($userId);
        $canSwitchScope = $this->isSuperAdmin($userId);
        $scope = $this->resolveScope($canSwitchScope, $currentWebsiteId);
        $websites = $this->websiteOptions();
        $websiteNames = [];
        foreach ($websites as $site) {
            $websiteNames[(int)$site['website_id']] = (string)$site['name'];
        }

        $focusId = max(0, (int)$this->request->getParam('focus', 0));
        $this->assign('focus_submission_id', $focusId);
        $this->assign('scope_website_id', $scope);
        $this->assign('can_switch_scope', $canSwitchScope);
        $this->assign('current_website_id', $currentWebsiteId);
        $this->assign('websites', $websites);
        $this->assign('website_names', $websiteNames);

        $this->submission->reset();
        $this->applyScopeFilter($scope);
        $this->submission
            ->order(SubmissionModel::schema_fields_CREATED_AT, 'DESC')
            ->order(SubmissionModel::schema_fields_ID, 'DESC')
            ->pagination()
            ->select()
            ->fetch();

        $formNames = [];
        foreach ($this->form->reset()->select()->fetchArray() as $form) {
            $formNames[(int)($form[Form::schema_fields_ID] ?? 0)] = (string)($form[Form::schema_fields_NAME] ?? '');
        }

        $items = [];
        foreach ($this->submission->getItems() as $row) {
            if ($row instanceof SubmissionModel) {
                $item = $row->getData();
            } elseif (is_array($row)) {
                $item = $row;
            } else {
                continue;
            }
            $item['mail_reply'] = $this->mailReplySlot->buildForSubmission($item, $formNames, $userId);
            $items[] = $item;
        }

        $this->assign('submissions', $items);
        $this->markMenuSignalRead($focusId);
        $this->assign('form_names', $formNames);
        $this->assign('pagination', $this->submission->getPagination());

        return $this->fetch();
    }

    #[Acl('Weline_Inquiry::reply', '回复询盘', 'reply', '发送站内回复', 'Weline_Inquiry::submissions')]
    public function postReply(): string
    {
        $payload = $this->request->getBodyParams();
        if (!is_array($payload) || $payload === []) {
            $payload = $this->request->getParams();
        }
        if (!is_array($payload)) {
            $payload = [];
        }

        $userId = $this->menuSignalActorId();
        $submissionId = (int)($payload['source_id'] ?? $payload['submission_id'] ?? 0);
        $submission = $this->loadScopedSubmission($submissionId, $userId);
        if ($submission === null) {
            return $this->jsonResponse(['success' => false, 'message' => (string)__('询盘不存在或不属于当前站点')], 404);
        }
        if (!class_exists(MailComposerService::class)) {
            return $this->jsonResponse(['success' => false, 'message' => (string)__('企业邮箱模块未启用')], 422);
        }

        $toEmail = $this->submissionEmail($submission);
        if ($toEmail === '') {
            return $this->jsonResponse(['success' => false, 'message' => (string)__('该询盘无有效邮箱，无法站内回复')], 422);
        }
        $subject = trim((string)($payload['subject'] ?? ''));
        $body = trim((string)($payload['body'] ?? ''));
        if ($subject === '' || $body === '') {
            return $this->jsonResponse(['success' => false, 'message' => (string)__('主题和正文不能为空')], 422);
        }

        $accountId = $this->mailReplySlot->resolveAccountId($userId, (int)($payload['account_id'] ?? 0));
        if ($accountId <= 0) {
            return $this->jsonResponse(['success' => false, 'message' => (string)__('请选择可用的站内发件邮箱')], 422);
        }

        $result = ObjectManager::getInstance(MailComposerService::class)->send(
            $accountId,
            $toEmail,
            $subject,
            $body,
            InquiryMailReplySlotService::SOURCE,
            $submissionId
        );
        if (!empty($result['success'])) {
            $this->menuSignalPublisher->markRead($submissionId, $userId);
        }

        return $this->jsonResponse($result, !empty($result['success']) ? 200 : (int)($result['code'] ?? 422));
    }

    #[Acl('Weline_Inquiry::submissions', '询盘提交记录', 'list', '查看询盘沟通线程', 'Weline_Inquiry::root')]
    public function getThread(): string
    {
        $userId = $this->menuSignalActorId();
        $submissionId = (int)($this->request->getGet('source_id', $this->request->getParam('source_id', 0)));
        $submission = $this->loadScopedSubmission($submissionId, $userId);
        if ($submission === null) {
            return $this->jsonResponse(['success' => false, 'items' => [], 'message' => (string)__('询盘不存在或不属于当前站点')], 404);
        }
        if (!class_exists(MailComposerService::class)) {
            return $this->jsonResponse(['success' => true, 'items' => []]);
        }

        $items = ObjectManager::getInstance(MailComposerService::class)
            ->listThreadBySource(InquiryMailReplySlotService::SOURCE, $submissionId);

        return $this->jsonResponse(['success' => true, 'items' => $items]);
    }

    private function markMenuSignalRead(int $focusId): void
    {
        $userId = $this->menuSignalActorId();
        if ($userId <= 0) {
            return;
        }

        if ($focusId > 0) {
            $this->menuSignalPublisher->markRead($focusId, $userId);
            return;
        }

        foreach ($this->submission->getItems() as $row) {
            $id = is_array($row) ? (int)($row['submission_id'] ?? 0) : (int)$row->getData('submission_id');
            if ($id > 0) {
                $this->menuSignalPublisher->markRead($id, $userId);
            }
        }
    }

    private function menuSignalActorId(): int
    {
        try {
            $user = ObjectManager::getInstance(BackendUserContextProviderInterface::class)->current();
            if ($user !== null && (int)$user->getId() > 0) {
                return (int)$user->getId();
            }
        } catch (\Throwable $e) {
            w_log_warning('Inquiry menu signal actor resolve failed: ' . $e->getMessage(), [], 'inquiry_menu_signal');
        }

        return max(0, (int)($this->getLoginUserId() ?? 0));
    }

    private function isSuperAdmin(int $userId): bool
    {
        if ($userId === 1) {
            return true;
        }
        if ($userId <= 0) {
            return false;
        }
        try {
            $context = BackendUser::getAclContext($userId);

            return (int)($context['role_id'] ?? 0) === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    private function currentWebsiteId(int $userId = 0): int
    {
        if ($userId > 0 && !$this->isSuperAdmin($userId)) {
            return $this->websiteScope->websiteIdForBackendUser($userId);
        }

        return $this->websiteScope->currentWebsiteId();
    }

    private function resolveScope(bool $canSwitchScope, int $currentWebsiteId): string
    {
        if (!$canSwitchScope) {
            return (string)$currentWebsiteId;
        }
        $requested = trim((string)$this->request->getParam('website_id', ''));
        if ($requested === '' || $requested === 'all') {
            return 'all';
        }
        if ($requested === 'unassigned') {
            return 'unassigned';
        }
        if (preg_match('/^\d+$/', $requested) === 1) {
            return (string)(int)$requested;
        }

        return 'all';
    }

    private function applyScopeFilter(string $scope): void
    {
        if ($scope === 'all') {
            return;
        }
        if ($scope === 'unassigned') {
            $this->submission->where(SubmissionModel::schema_fields_WEBSITE_ID, 0);

            return;
        }
        $this->submission->where(SubmissionModel::schema_fields_WEBSITE_ID, (int)$scope);
    }

    /**
     * @return list<array{website_id:int,name:string,code:string,url:string}>
     */
    private function websiteOptions(): array
    {
        $options = [];
        try {
            $catalog = ObjectManager::getInstance(WebsiteCatalogInterface::class);
            foreach ($catalog->all() as $site) {
                if ($site instanceof \Weline\Websites\Api\Catalog\Data\WebsiteSummary) {
                    $row = $site->toArray();
                } elseif (is_array($site)) {
                    $row = $site;
                } else {
                    continue;
                }
                $options[] = [
                    'website_id' => (int)($row['website_id'] ?? 0),
                    'name' => (string)($row['name'] ?? ''),
                    'code' => (string)($row['code'] ?? ''),
                    'url' => (string)($row['url'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            w_log_warning('Inquiry website catalog failed: ' . $e->getMessage(), [], 'inquiry');
        }

        return $options;
    }

    private function loadScopedSubmission(int $submissionId, int $userId): ?SubmissionModel
    {
        if ($submissionId <= 0) {
            return null;
        }
        $submission = ObjectManager::getInstance(SubmissionModel::class);
        $submission->reset()->clearData()->load($submissionId);
        if (!$submission->getId()) {
            return null;
        }
        if ($this->isSuperAdmin($userId)) {
            return $submission;
        }
        if ((int)$submission->getData(SubmissionModel::schema_fields_WEBSITE_ID) !== $this->currentWebsiteId($userId)) {
            return null;
        }

        return $submission;
    }

    private function submissionEmail(SubmissionModel $submission): string
    {
        $payload = json_decode((string)$submission->getData(SubmissionModel::schema_fields_PAYLOAD_JSON), true);
        $email = is_array($payload) ? strtolower(trim((string)($payload['email'] ?? ''))) : '';

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
    }

    private function jsonResponse(array $data, int $code = 200): string
    {
        $response = $this->request->getResponse();
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store');
        $response->setCode($code);

        return json_encode($data, JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
