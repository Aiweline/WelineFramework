<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Release\Api;

/** 发布级 schema 清单：模块表结构、操作引用与整体校验和。 */
interface SchemaManifestInterface
{
    public function releaseId(): string;

    /** @return array<string, array<string, mixed>> 模块名 => 表/操作/校验和 */
    public function modules(): array;

    public function checksum(): string;

    /** @return array<string, mixed> */
    public function toArray(): array;
}
