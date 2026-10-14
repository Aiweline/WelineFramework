<?php

declare(strict_types=1);

namespace Weline\I18n\Controller\Backend;

use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\InlineEdit\InlineEditSession;
use Weline\I18n\Service\InlineEdit\InlinePhraseMarker;
use Weline\Theme\Service\InstallLocalStorefrontBaseResolver;
use Weline\Websites\Model\Website;

/**
 * Dictionary 「店面点译」：种 pierce+会话 Cookie，返回店面 jump_url。
 */
class InlineEdit extends BaseController
{
    /**
     * @Extra type=acl message="开启店面点译会话" source="I18n::backend_inline_edit_start"
     */
    public function postStart()
    {
        try {
            $target = \trim((string)$this->request->getPost('target_url', ''));
            if ($target === '') {
                $target = $this->resolveDefaultStorefrontUrl();
            }
            /** @var InlineEditSession $session */
            $session = ObjectManager::getInstance(InlineEditSession::class);
            $built = $session->startAndBuildJumpUrl($target);

            return $this->fetchJson([
                'success' => true,
                'jump_url' => $built['jump_url'],
                'cookie_name' => $built['cookie_name'],
                'message' => InlinePhraseMarker::plain('已生成店面点译跳转地址'),
            ]);
        } catch (ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @Extra type=acl message="结束店面点译会话" source="I18n::backend_inline_edit_stop"
     */
    public function postStop()
    {
        try {
            /** @var InlineEditSession $session */
            $session = ObjectManager::getInstance(InlineEditSession::class);
            $session->stop();

            return $this->fetchJson([
                'success' => true,
                'message' => InlinePhraseMarker::plain('已退出店面点译'),
            ]);
        } catch (ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function resolveDefaultStorefrontUrl(): string
    {
        try {
            /** @var InstallLocalStorefrontBaseResolver $resolver */
            $resolver = ObjectManager::getInstance(InstallLocalStorefrontBaseResolver::class);
            $origin = \trim((string)($resolver->resolveForWebsite(Website::ID_DEFAULT, '') ?? ''));
            if ($origin !== '' && \preg_match('#^https?://#i', $origin) === 1) {
                return \rtrim($origin, '/') . '/';
            }
        } catch (\Throwable) {
        }

        return '/';
    }
}
