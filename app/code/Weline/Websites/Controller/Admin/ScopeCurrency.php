<?php

declare(strict_types=1);

namespace Weline\Websites\Controller\Admin;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\WebsiteCurrency;

/**
 * Website currency display-order admin (soft reorder).
 */
class ScopeCurrency extends BackendController
{
    public function postReorderWebsite()
    {
        $websiteId = \max(0, (int)$this->request->getPost('website_id', 0));
        $codes = $this->normalizeCodes($this->request->getPost('currency_codes', []));
        $isJson = $this->isJsonRequest();
        try {
            /** @var WebsiteCurrency $wc */
            $wc = ObjectManager::getInstance(WebsiteCurrency::class);
            $wc->reorderWebsiteCurrencies($websiteId, $codes);
            if ($isJson) {
                return $this->fetchJson(['success' => true, 'message' => (string)__('网站货币排序已保存')]);
            }
            $this->getMessageManager()->addSuccess(__('网站货币排序已保存'));
        } catch (ResponseTerminateException $e) {
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
            $raw = $raw === null || $raw === '' ? [] : [$raw];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $code) {
            $code = \strtoupper(\trim((string)$code));
            if ($code === '') {
                continue;
            }
            if (isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
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
