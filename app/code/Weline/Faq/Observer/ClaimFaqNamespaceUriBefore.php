<?php

declare(strict_types=1);

namespace Weline\Faq\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Faq\Api\Uri\FaqNamespace;

/** Claims /faq before CMS ProcessCmsPageUriBefore. */
final class ClaimFaqNamespaceUriBefore implements ObserverInterface
{
    public function __construct(
        private readonly Request $request,
    ) {
    }

    public function execute(Event &$event): void
    {
        if ($this->request->isBackend() || $this->request->isApiBackend() || $this->request->isApiFrontend()) {
            return;
        }

        /** @var DataObject|null $data */
        $data = $event->getData('data');
        if (!$data instanceof DataObject) {
            return;
        }

        $path = $data->getData('path');
        if (!is_string($path)) {
            return;
        }

        $identifier = $this->normalizeIdentifier($path);
        if (!FaqNamespace::isFaqIdentifier($identifier)) {
            return;
        }

        $this->request->setData('cms_uri_skip', FaqNamespace::skipPayload());
        $this->request->setData('faq_namespace_owner', 'Weline_Faq');
    }

    private function normalizeIdentifier(string $path): string
    {
        $candidates = [
            (string)(\w_env('request.uri', '') ?? ''),
            (string)(\w_env('full_request_uri', '') ?? ''),
            (string)$this->request->getServer('WELINE_ORIGIN_REQUEST_URI'),
            $path,
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string)(parse_url($candidate, PHP_URL_PATH) ?: $candidate), '/ ');
            if ($candidate === '') {
                continue;
            }
            if (FaqNamespace::isFaqIdentifier($candidate)) {
                return $candidate;
            }
        }

        return trim(strtolower((string)(parse_url($path, PHP_URL_PATH) ?: $path)), '/ ');
    }
}
