<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc;

/**
 * 模块声明「何种请求身份/参数应 bypass 公共 FPC」（编辑器、预览等）。
 * 升级收集进侧车；热路径只读侧车，不经 ObjectManager/Event。
 */
interface FpcBypassRuleProviderInterface
{
    public const EXTENDS_RELATIVE_PREFIX = 'extends/module/weline_framework/fpc/bypass/';

    /**
     * @return list<array{
     *   id: string,
     *   match: array{
     *     query_keys?: list<string>,
     *     cookie_name_regex?: string,
     *     request_headers?: list<string>,
     *     env_flags?: list<string>
     *   },
     *   effect?: string
     * }>
     */
    public function rules(): array;
}
