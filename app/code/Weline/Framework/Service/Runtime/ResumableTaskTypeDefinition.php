<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Runtime;

use Weline\Framework\Runtime\Resumable\ResumableTaskHandlerInterface;

/**
 * Server-side task type registration. Browser input always selects the stable
 * type code; it never selects a PHP class.
 */
final readonly class ResumableTaskTypeDefinition
{
    /** @param class-string<ResumableTaskHandlerInterface> $handlerClass */
    public function __construct(
        public string $typeCode,
        public string $module,
        public string $handlerClass,
    ) {
        if (trim($this->typeCode) === '' || trim($this->module) === '' || trim($this->handlerClass) === '') {
            throw new \InvalidArgumentException('Resumable task type definition is incomplete.');
        }
    }
}
