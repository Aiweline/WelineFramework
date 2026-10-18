<?php

declare(strict_types=1);

namespace Weline\Framework\Extends\Module\Weline_Framework\Fpc\Bypass;

use Weline\Framework\Http\Fpc\FpcBypassRuleProviderInterface;

/**
 * Install pierce stamp alone MUST bypass public FPC (UC-FPC-Pierce / Extra UC-05).
 * FactsBuilder sets env fpc_pierce_stamp=1 when FpcPierceStamp::isValidRequest.
 */
final class PierceStampFpcBypassProvider implements FpcBypassRuleProviderInterface
{
    public function rules(): array
    {
        return [
            [
                'id' => 'framework.fpc_pierce_stamp',
                'match' => [
                    'env_flags' => ['fpc_pierce_stamp'],
                ],
                'effect' => 'bypass_serve_and_publish',
            ],
        ];
    }
}
