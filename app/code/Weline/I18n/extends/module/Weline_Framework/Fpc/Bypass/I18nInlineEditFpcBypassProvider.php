<?php

declare(strict_types=1);

namespace Weline\I18n\Extends\Module\Weline_Framework\Fpc\Bypass;

use Weline\Framework\Http\Fpc\FpcBypassRuleProviderInterface;
use Weline\I18n\Service\InlineEdit\InlineEditSession;

/**
 * Inline dictionary edit: bypass serve+publish only with pierce stamp.
 */
final class I18nInlineEditFpcBypassProvider implements FpcBypassRuleProviderInterface
{
    public function rules(): array
    {
        return [
            [
                'id' => 'i18n.inline_edit_query',
                'match' => [
                    'require_pierce_stamp' => true,
                    'query_keys' => [InlineEditSession::QUERY_FLAG],
                ],
                'effect' => 'bypass_serve_and_publish',
            ],
            [
                'id' => 'i18n.inline_edit_cookie',
                'match' => [
                    'require_pierce_stamp' => true,
                    'cookie_name_regex' => '/(?:^|;\s*)' . \preg_quote(InlineEditSession::COOKIE_NAME, '/') . '(?:_w\\d+)?=/',
                ],
                'effect' => 'bypass_serve_and_publish',
            ],
        ];
    }
}
