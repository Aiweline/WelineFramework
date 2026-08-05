<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Runtime;

use Weline\Framework\Runtime\Resumable\ResumableTaskHandlerInterface;
use Weline\Framework\Service\Query\Value\FrontendWorkerBackendAcl;

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
        public array $allowedAreas = ['frontend'],
        public ?string $backendAclSourceId = null,
    ) {
        if (trim($this->typeCode) === '' || trim($this->module) === '' || trim($this->handlerClass) === '') {
            throw new \InvalidArgumentException('Resumable task type definition is incomplete.');
        }
        if ($this->allowedAreas === []
            || !\array_is_list($this->allowedAreas)
            || \count($this->allowedAreas) !== \count(\array_unique($this->allowedAreas))
            || \array_diff($this->allowedAreas, ['frontend', 'backend']) !== []) {
            throw new \InvalidArgumentException('Resumable task allowed areas are invalid.');
        }
        if (\in_array('backend', $this->allowedAreas, true) !== ($this->backendAclSourceId !== null)) {
            throw new \InvalidArgumentException('Resumable task backend area and ACL source are inconsistent.');
        }
        if ($this->backendAclSourceId !== null
            && !FrontendWorkerBackendAcl::isValidSourceId($this->backendAclSourceId)) {
            throw new \InvalidArgumentException('Resumable task backend ACL source is invalid.');
        }
    }
}
