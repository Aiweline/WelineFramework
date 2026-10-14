<?php

declare(strict_types=1);

namespace Weline\I18n\Controller\Frontend\InlineEdit;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\InlineEdit\InlineEditLocaleCatalog;
use Weline\I18n\Service\InlineEdit\InlineEditPhraseService;
use Weline\I18n\Service\InlineEdit\InlineEditSession;
use Weline\I18n\Service\InlineEdit\InlineEditUpdatePageService;
use Weline\I18n\Service\InlineEdit\InlinePhraseMarker;

/**
 * Storefront inline-edit JSON API (pierce + session cookie required).
 *
 * @Extra type=fpc enabled=false
 */
class Api extends FrontendController
{
    private function requireSession(): ?array
    {
        /** @var InlineEditSession $session */
        $session = ObjectManager::getInstance(InlineEditSession::class);
        if (!$session->isActive($this->request)) {
            return [
                'success' => false,
                'message' => InlinePhraseMarker::plain('点译会话无效或已过期'),
            ];
        }

        return null;
    }

    public function postPhrase()
    {
        if ($err = $this->requireSession()) {
            return $this->fetchJson($err);
        }
        $word = (string)$this->request->getPost('word', '');
        $translate = (string)$this->request->getPost('translate', '');
        $locale = (string)$this->request->getPost('locale_code', '');
        /** @var InlineEditPhraseService $svc */
        $svc = ObjectManager::getInstance(InlineEditPhraseService::class);
        $result = $svc->save($word, $translate, $locale);

        return $this->fetchJson([
            'success' => (bool)$result['ok'],
            'message' => $result['message'],
            'translate' => $result['translate'] ?? null,
            'locale_code' => $result['locale'] ?? null,
        ]);
    }

    public function getLocales()
    {
        if ($err = $this->requireSession()) {
            return $this->fetchJson($err);
        }
        /** @var InlineEditLocaleCatalog $catalog */
        $catalog = ObjectManager::getInstance(InlineEditLocaleCatalog::class);

        return $this->fetchJson([
            'success' => true,
            'locales' => $catalog->listForPopover(),
        ]);
    }

    public function postPurgePage()
    {
        $wantsSse = $this->wantsUpdatePageSse();
        if ($err = $this->requireSession()) {
            if ($wantsSse) {
                $sse = new SseWriter();
                $sse->start();
                $sse->sendEvent('failed', $err);
                $sse->complete(['success' => false]);

                return;
            }

            return $this->fetchJson($err);
        }
        $path = \trim((string)$this->request->getPost('path', ''));
        if ($path === '') {
            $uri = (string)$this->request->getServer('REQUEST_URI');
            $path = (string)(\parse_url($uri, \PHP_URL_PATH) ?: '/');
        }
        $locale = \trim((string)$this->request->getPost('locale_code', ''));
        /** @var InlineEditUpdatePageService $svc */
        $svc = ObjectManager::getInstance(InlineEditUpdatePageService::class);

        if ($wantsSse) {
            $svc->stream(new SseWriter(), $path, $locale);

            return;
        }

        $result = $svc->run($path, $locale);

        return $this->fetchJson([
            'success' => (bool)$result['ok'],
            'slug' => $result['slug'] ?? '',
            'locale' => $result['locale'] ?? '',
            'published' => $result['published'] ?? false,
            'skipped' => $result['skipped'] ?? null,
            'message' => $result['message'],
        ]);
    }

    private function wantsUpdatePageSse(): bool
    {
        $flag = \trim((string)$this->request->getGet('sse', (string)$this->request->getPost('sse', '')));
        if ($flag === '1' || \strtolower($flag) === 'true') {
            return true;
        }
        $accept = \strtolower((string)$this->request->getServer('HTTP_ACCEPT'));

        return \str_contains($accept, 'text/event-stream');
    }

    public function postStop()
    {
        if ($err = $this->requireSession()) {
            // Still attempt cookie clear for operator convenience.
        }
        /** @var InlineEditSession $session */
        $session = ObjectManager::getInstance(InlineEditSession::class);
        $session->stop();

        return $this->fetchJson([
            'success' => true,
            'message' => InlinePhraseMarker::plain('已退出店面点译'),
        ]);
    }
}
