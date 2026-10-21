<?php

declare(strict_types=1);

namespace Weline\Inquiry\Service;

use Weline\Admin\Service\BackendMenuSignalInbox;
use Weline\Framework\Manager\ObjectManager;
use Weline\Inquiry\Extends\Module\Weline_Admin\BackendMenuSignalProvider\InquirySubmissionMenuSignalProvider;

final class SubmissionMenuSignalPublisher
{
    public function publish(int $submissionId, array $form, array $payload): void
    {
        if ($submissionId <= 0 || !class_exists(BackendMenuSignalInbox::class)) {
            return;
        }

        try {
            $href = $this->href($submissionId);
            if ($href === '') {
                return;
            }

            $title = (string)__('新询盘') . ' #' . $submissionId;
            $formName = trim((string)($form['name'] ?? ''));
            if ($formName !== '') {
                $title .= ' · ' . $formName;
            }

            $name = trim((string)($payload['name'] ?? $payload['contact_name'] ?? $payload['company'] ?? ''));
            if ($name === '') {
                $email = trim((string)($payload['email'] ?? ''));
                $name = $email;
            }
            if ($name !== '') {
                $title .= ' · ' . $name;
            }
            $title = mb_substr($title, 0, 255);

            $this->inbox()->publish(
                InquirySubmissionMenuSignalProvider::MENU_SOURCE_ID,
                (string)$submissionId,
                $title,
                $href,
                true
            );
        } catch (\Throwable $e) {
            w_log_error('Inquiry menu signal publish failed: ' . $e->getMessage(), [], 'inquiry_menu_signal');
        }
    }

    public function markRead(int $submissionId, int $backendUserId): void
    {
        if ($submissionId <= 0 || $backendUserId <= 0 || !class_exists(BackendMenuSignalInbox::class)) {
            return;
        }

        try {
            $this->inbox()->markRead(
                InquirySubmissionMenuSignalProvider::MENU_SOURCE_ID,
                (string)$submissionId,
                $backendUserId
            );
        } catch (\Throwable $e) {
            w_log_error('Inquiry menu signal markRead failed: ' . $e->getMessage(), [], 'inquiry_menu_signal');
        }
    }

    public function href(int $submissionId): string
    {
        if ($submissionId <= 0) {
            return '';
        }

        try {
            $url = ObjectManager::getInstance(\Weline\Framework\Http\UrlInterface::class);
            $href = trim((string)$url->getBackendUrl('inquiry/backend/submission', ['focus' => $submissionId]));
            if ($href === '') {
                return '';
            }

            return str_contains($href, '#')
                ? $href
                : $href . '#inquiry-submission-' . $submissionId;
        } catch (\Throwable) {
            return '';
        }
    }

    private function inbox(): BackendMenuSignalInbox
    {
        return ObjectManager::getInstance(BackendMenuSignalInbox::class);
    }
}
