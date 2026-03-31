<?php

declare(strict_types=1);

namespace WeShop\QA\Controller\Backend;

use WeShop\QA\Service\QAService;
use Weline\Admin\Controller\BaseController;

/**
 * 后台Q&A管理控制器
 */
class QA extends BaseController
{
    public function __construct(
        private readonly QAService $qaService
    ) {
    }

    #[Acl('WeShop_QA::qa_index', 'View Q&A List', 'mdi mdi-help-circle', 'View questions list')]
    public function index(): string
    {
        $this->assign('page_title', (string) __('Q&A Management'));
        $this->assign('list_url', $this->_url->getBackendUrl('*/backend/qa/index'));
        $this->assign('view_url', $this->_url->getBackendUrl('*/backend/qa/view'));
        $this->assign('approve_url', $this->_url->getBackendUrl('*/backend/qa/approve'));
        $this->assign('reject_url', $this->_url->getBackendUrl('*/backend/qa/reject'));

        return $this->fetch('WeShop_QA', 'backend/qa/index/index');
    }

    #[Acl('WeShop_QA::qa_view', 'View Q&A Detail', 'mdi mdi-eye', 'View question detail')]
    public function view(): string
    {
        $questionId = (int) $this->request->getParam('id', 0);

        if ($questionId <= 0) {
            $this->getMessageManager()->addError(__('Invalid question ID.'));
            $this->redirect('*/backend/qa');
            return '';
        }

        $question = $this->qaService->getQuestion($questionId);

        if (!$question) {
            $this->getMessageManager()->addError(__('Question not found.'));
            $this->redirect('*/backend/qa');
            return '';
        }

        $this->assign('page_title', (string) __('Q&A Detail'));
        $this->assign('question', $question->getData());
        $this->assign('question_id', $questionId);
        $this->assign('approve_url', $this->_url->getBackendUrl('*/backend/qa/approve'));
        $this->assign('reject_url', $this->_url->getBackendUrl('*/backend/qa/reject'));
        $this->assign('back_url', $this->_url->getBackendUrl('*/backend/qa'));

        return $this->fetch('WeShop_QA', 'backend/qa/view/index');
    }

    public function approve(): string
    {
        if (!$this->request->isPost()) {
            $this->getMessageManager()->addError(__('Invalid request method.'));
            $this->redirect('*/backend/qa');
            return '';
        }

        try {
            $questionId = (int) $this->request->getParam('id', 0);
            $answer = $this->request->getParam('answer', null);

            if ($questionId <= 0) {
                throw new \InvalidArgumentException((string) __('Invalid question ID.'));
            }

            $result = $this->qaService->approveQuestion($questionId, $answer);

            if ($result) {
                $this->getMessageManager()->addSuccess(__('Question approved successfully.'));
            } else {
                $this->getMessageManager()->addError(__('Failed to approve question.'));
            }
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError(
                __('Approve failed: %{1}', [$throwable->getMessage()])
            );
        }

        $this->redirect('*/backend/qa');
        return '';
    }

    public function reject(): string
    {
        if (!$this->request->isPost()) {
            $this->getMessageManager()->addError(__('Invalid request method.'));
            $this->redirect('*/backend/qa');
            return '';
        }

        try {
            $questionId = (int) $this->request->getParam('id', 0);

            if ($questionId <= 0) {
                throw new \InvalidArgumentException((string) __('Invalid question ID.'));
            }

            $result = $this->qaService->rejectQuestion($questionId);

            if ($result) {
                $this->getMessageManager()->addSuccess(__('Question rejected successfully.'));
            } else {
                $this->getMessageManager()->addError(__('Failed to reject question.'));
            }
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError(
                __('Reject failed: %{1}', [$throwable->getMessage()])
            );
        }

        $this->redirect('*/backend/qa');
        return '';
    }
}
