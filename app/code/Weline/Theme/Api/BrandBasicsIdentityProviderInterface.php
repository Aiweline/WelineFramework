<?php

declare(strict_types=1);

namespace Weline\Theme\Api;

use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Theme Editor「基础信息」身份字段扩展点。
 *
 * Website / Store / Channel 等模块把自己的真实身份（名称、简介等）挂到基础信息面板；
 * 保存必须写回模块权威实体，而不是 Theme appearance.brand。
 */
interface BrandBasicsIdentityProviderInterface
{
    public function getCode(): string;

    public function getModule(): string;

    public function supports(ScopeIdentity $identity): bool;

    /**
     * @return array{
     *   label:string,
     *   scope_kind:string,
     *   fields:list<array{key:string,type:string,label:string,required?:bool,max?:int,rows?:int}>,
     *   values:array<string,string>
     * }
     */
    public function load(ScopeIdentity $identity): array;

    /**
     * @param array<string,string> $values
     * @return array{
     *   label:string,
     *   scope_kind:string,
     *   fields:list<array{key:string,type:string,label:string,required?:bool,max?:int,rows?:int}>,
     *   values:array<string,string>
     * }
     */
    public function save(ScopeIdentity $identity, array $values): array;
}
