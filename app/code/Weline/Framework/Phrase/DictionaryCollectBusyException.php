<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

/**
 * Thrown when another process already holds the dictionary-collect single-flight lock.
 * Extends RuntimeException so CLI/cron can fail soft without writing exception.log on construct.
 */
final class DictionaryCollectBusyException extends \RuntimeException
{
    public const BUSY_MARKER = 'I18N_DICTIONARY_COLLECT_BUSY';
}
