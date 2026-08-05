<?php

declare(strict_types=1);

namespace Weline\I18n\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\I18n\Api\LanguageRequest\LanguageSupportRequestDirectoryInterface;

#[Acl('Weline_I18n::i18n_language_requests', '语言申请', 'mdi mdi-translate-variant', '国际化')]
final class LanguageRequests extends BackendController
{
    public function __construct(private readonly LanguageSupportRequestDirectoryInterface $directory)
    {
    }

    public function index(): string
    {
        $this->assign('request_data', $this->directory->adminList());
        return $this->fetch('Weline_I18n::templates/Backend/LanguageRequests/index.phtml');
    }
}
