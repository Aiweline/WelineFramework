<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

/**
 * deploy.flat_static.* 扁平静态白名单 Provider。
 *
 * 角色（降级）：可选精简/排除、非 statics 额外源、过渡幂等兼容桥。
 * 防 404 主路径已改为 Deploy\Upgrade 对每个活跃模块 view/statics 整树铺到
 * pub/static/{Vendor}/{Module}/；新模块无需登记本接口即可在 PROD 下使用 Module::。
 */
interface FlatStaticRuntimeFilesProviderInterface
{
    public function moduleName(): string;

    /**
     * @return list<string>
     */
    public function relativeFiles(): array;
}
