<?php

declare(strict_types=1);

namespace WeShop\Compliance\Controller\Frontend\Compliance;

use WeShop\Compliance\Service\CompliancePageDataService;
use WeShop\Frontend\Controller\BaseController;

class Privacy extends BaseController
{
    protected ?string $layoutType = 'compliance';

    public function __construct(
        private readonly CompliancePageDataService $compliancePageDataService
    ) {
    }

    public function index(): string
    {
        foreach ($this->compliancePageDataService->buildPrivacyPage() as $key => $value) {
            $this->assign($key, $value);
        }

        return $this->fetch();
    }
}

