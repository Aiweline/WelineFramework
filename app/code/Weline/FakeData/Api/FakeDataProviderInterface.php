<?php

declare(strict_types=1);

namespace Weline\FakeData\Api;

use Weline\FakeData\Data\FakeDataContext;
use Weline\FakeData\Data\FakeDataResult;

interface FakeDataProviderInterface
{
    public function getCode(): string;

    public function getModuleName(): string;

    public function getLabel(): string;

    public function getSortOrder(): int;

    /**
     * @return array<int, string> Provider codes that must run before this provider.
     */
    public function getDependencies(): array;

    public function describe(): array;

    public function seed(FakeDataContext $context): FakeDataResult;

    public function cleanup(FakeDataContext $context): FakeDataResult;
}

