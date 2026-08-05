<?php

declare(strict_types=1);

namespace Weline\Search\Service;

/**
 * Search query/degrade conflicts（fail closed；禁止空成功伪装）。
 */
final class SearchQueryException extends \RuntimeException
{
    public const ERROR_EMPTY_SUCCESS_FORBIDDEN = 'search_empty_success_forbidden';
    public const ERROR_DIRECT_READER_DOWN = 'search_direct_reader_down';
    public const ERROR_DIRECT_CONTRACT = 'search_direct_contract_invalid';
    public const ERROR_INDEX_READ = 'search_index_read_failed';
    public const ERROR_RECOVERY_WATERMARK = 'search_recovery_watermark_not_caught_up';
    public const ERROR_RECOVERY_CONFLICT = 'search_recovery_marker_conflict';
    public const ERROR_SCOPE = 'search_scope_invalid';

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
