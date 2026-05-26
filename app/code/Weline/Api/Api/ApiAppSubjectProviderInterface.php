<?php
declare(strict_types=1);

namespace Weline\Api\Api;

interface ApiAppSubjectProviderInterface
{
    public function getSubjectType(): string;

    public function validateSubject(string $subjectId): bool;

    public function getSubjectLabel(string $subjectId): string;
}
