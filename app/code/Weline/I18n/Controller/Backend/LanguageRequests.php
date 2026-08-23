<?php

declare(strict_types=1);

namespace Weline\I18n\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\I18n\Api\LanguageRequest\LanguageSupportRequestDirectoryInterface;

#[Acl('Weline_I18n::i18n_language_requests', '语言申请', 'language', '国际化')]
final class LanguageRequests extends BackendController
{
    public function __construct(private readonly LanguageSupportRequestDirectoryInterface $directory)
    {
    }

    public function index(): string
    {
        $this->assign('request_data', $this->directory->adminList());
        $this->assignWebsiteSelect('');
        return $this->fetch('Weline_I18n::templates/Backend/LanguageRequests/index.phtml');
    }

    private function assignWebsiteSelect(string $selectedValue): void
    {
        $options = [];
        try {
            $queried = \w_query('websites', 'getWebsiteSelectOptions', [], 'backend');
            if (\is_array($queried)) {
                $options = $queried;
            }
        } catch (\Throwable) {
            $options = [];
        }
        $display = '';
        foreach ($options as $option) {
            if (!\is_array($option)) {
                continue;
            }
            if ((string)($option['value'] ?? '') === $selectedValue) {
                $display = \trim((string)($option['label'] ?? ''));
                break;
            }
        }
        if ($display === '' && $selectedValue !== '') {
            $display = '#' . $selectedValue;
        }
        $this->assign('websiteSelectValue', $selectedValue);
        $this->assign('websiteSelectDisplay', $display);
        $this->assign('websiteSelectOptionsJson', \json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
    }
}
