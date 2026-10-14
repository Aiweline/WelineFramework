<?php

declare(strict_types=1);

namespace Weline\Websites\Controller\Admin;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\WebsiteLanguage;

/**
 * Website language capsule reorder endpoint（店面关联语种排序由网站编辑页胶囊 DnD 完成）。
 * 冗余 manage 管理表已移除。
 */
class ScopeLanguage extends BackendController
{
    public function postReorderWebsite()
    {
        $websiteId = \max(0, (int)$this->request->getPost('website_id', 0));
        $codes = $this->normalizeCodes($this->request->getPost('language_codes', []));
        $isJson = $this->isJsonRequest();
        try {
            /** @var WebsiteLanguage $wl */
            $wl = ObjectManager::getInstance(WebsiteLanguage::class);
            // Soft path only — membership writes stay on the heavy invalidate path.
            $wl->reorderWebsiteLanguages($websiteId, $codes);
            if ($isJson) {
                return $this->fetchJson(['success' => true, 'message' => (string)__('网站语种排序已保存')]);
            }
            $this->getMessageManager()->addSuccess(__('网站语种排序已保存'));
        } catch (ResponseTerminateException $e) {
            // fetchJson / redirect 终止不可吞成业务失败 JSON。
            throw $e;
        } catch (\Throwable $e) {
            if ($isJson) {
                return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
            }
            $this->getMessageManager()->addError($e->getMessage());
        }

        return $this->redirect('*/admin/website/edit', ['id' => $websiteId]);
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private function normalizeCodes(mixed $raw): array
    {
        if (!\is_array($raw)) {
            $raw = \is_string($raw) ? \preg_split('/[\s,]+/', $raw) ?: [] : [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $code) {
            $code = \trim(\str_replace('-', '_', (string)$code));
            if ($code === '') {
                continue;
            }
            $key = \strtolower($code);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $code;
        }

        return $out;
    }

    private function isJsonRequest(): bool
    {
        $accept = \strtolower((string)($this->request->getHeader('Accept') ?? ''));
        $xrw = \strtolower((string)($this->request->getHeader('X-Requested-With') ?? ''));

        return \str_contains($accept, 'application/json') || $xrw === 'xmlhttprequest';
    }
}
