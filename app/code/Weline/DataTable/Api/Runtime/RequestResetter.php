<?php

declare(strict_types=1);

namespace Weline\DataTable\Api\Runtime;

use Weline\DataTable\Helper\TransactionManager;
use Weline\DataTable\Helper\UiAssets;
use Weline\DataTable\Taglib\Field;
use Weline\Framework\Runtime\RequestResetException;
use Weline\Framework\Runtime\RequestResetterInterface;

final class RequestResetter implements RequestResetterInterface
{
    public function resetRequest(): void
    {
        $failures = [];
        try {
            Field::resetRequestState();
        } catch (\Throwable $throwable) {
            RequestResetException::append($failures, 'field_request_state', $throwable);
        }

        try {
            UiAssets::resetRequestState();
        } catch (\Throwable $throwable) {
            RequestResetException::append($failures, 'ui_assets_request_state', $throwable);
        }

        try {
            TransactionManager::cleanup();
        } catch (\Throwable $throwable) {
            RequestResetException::append($failures, 'transaction_manager', $throwable);
        }

        if ($failures !== []) {
            throw new RequestResetException('datatable_request_resetter', $failures);
        }
    }
}
