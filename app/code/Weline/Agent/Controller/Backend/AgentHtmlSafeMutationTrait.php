<?php

declare(strict_types=1);

namespace Weline\Agent\Controller\Backend;

/**
 * HTML 浏览器提交时重定向列表，避免整页吐 JSON；XHR/JSON Accept 仍返回 JSON。
 */
trait AgentHtmlSafeMutationTrait
{
    /**
     * @param array{success?: bool, msg?: string, data?: mixed} $result
     */
    private function respondMutation(array $result, string $listingPath): mixed
    {
        if ($this->wantsJsonMutationResponse()) {
            return $this->fetchJson($result);
        }
        $msg = (string) ($result['msg'] ?? '');
        if (!empty($result['success'])) {
            $this->getMessageManager()->addSuccess($msg !== '' ? $msg : __('操作成功'));
        } else {
            $this->getMessageManager()->addError($msg !== '' ? $msg : __('操作失败'));
        }
        return $this->redirect($listingPath);
    }

    private function wantsJsonMutationResponse(): bool
    {
        $xhr = strtolower((string) ($this->request->getServer('HTTP_X_REQUESTED_WITH') ?? ''));
        if ($xhr === 'xmlhttprequest') {
            return true;
        }
        $accept = strtolower((string) ($this->request->getServer('HTTP_ACCEPT') ?? ''));
        if (str_contains($accept, 'text/html') && !str_contains($accept, 'application/json')) {
            return false;
        }
        if (str_contains($accept, 'application/json')) {
            return true;
        }
        $contentType = strtolower((string) (
            $this->request->getServer('CONTENT_TYPE')
            ?? $this->request->getServer('HTTP_CONTENT_TYPE')
            ?? ''
        ));
        return str_contains($contentType, 'application/json');
    }
}
