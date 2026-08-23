<?php

declare(strict_types=1);

namespace Weline\FileManager\Api\Exception;

/** Signals that a FileAsset exists but must not be disclosed to this access context. */
final class FileAccessDeniedException extends \RuntimeException
{
}
