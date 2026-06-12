<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query\Provider;

/**
 * 统一查询器接口
 *
 * 各模块通过 extends 注册查询器，实现本接口即可对外提供查询能力。
 * Framework 通过 QueryProviderRegistry 自动扫描并注册所有实现类。
 *
 * 使用说明查询（帮助）：
 *   w_query()                                    => 列出所有 provider 摘要
 *   w_query('widget')                            => widget 完整 descriptor（含 operations/params）
 *   w_query('WeShop_Product')                    => 按模块名解析 provider 帮助
 *   w_query('framework', 'introspect', { what: 'providers' })           => 列出所有 provider
 *   w_query('framework', 'introspect', { what: 'operations', provider: 'xxx' }) => 某 provider 的 operations
 *   w_query('framework', 'introspect', { what: 'operation', provider: 'xxx', operation: 'yyy' }) => 详细参数
 *   w_query('framework', 'introspect', { what: 'provider', provider: 'xxx' }) => 单 provider 完整 descriptor
 *   php bin/w query:help [provider|WeShop_Product] [operation]          => CLI 人类可读帮助
 *
 * getDescriptor() 返回格式（必须遵守）：
 *   [
 *       'provider'    => 'widget',                         // 与 getProviderName() 一致
 *       'name'        => 'Widget 部件查询',                  // 可读名称
 *       'description' => '提供部件列表、配置、预览等查询能力',   // 简短说明
 *       'module'      => 'Weline_Widget',                  // 所属模块名
 *       'operations'  => [
 *           [
 *               'name'        => 'getAvailableList',
 *               'description' => '获取可用部件列表',
 *               'params'      => [
 *                   ['name' => 'page_type',      'type' => 'string|null', 'required' => false, 'description' => '页面类型'],
 *                   ['name' => 'filter_options', 'type' => 'array|null',  'required' => false, 'description' => '过滤选项'],
 *               ],
 *           ],
 *           // ...更多 operation
 *       ],
 *   ]
 */
interface QueryProviderInterface
{
    /**
     * 提供者标识（如 'widget'、'crud'），用于路由与 introspect 列表
     */
    public function getProviderName(): string;

    /**
     * 执行查询
     *
     * @param string $operation 操作名
     * @param array  $params    操作参数
     * @return mixed 查询结果
     */
    public function execute(string $operation, array $params = []): mixed;

    /**
     * 返回使用说明描述（格式见接口 docblock）
     *
     * @return array{provider: string, name: string, description: string, module: string, operations: array}
     */
    public function getDescriptor(): array;
}

