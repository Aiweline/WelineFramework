<?php
declare(strict_types=1);

namespace Weline\Cms\Extends\Module\Weline_Framework\Query;

use Weline\Cms\Service\PageService;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class CmsQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly PageService $pageService
    ) {
    }

    public function getProviderName(): string
    {
        return 'cms';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getPage' => $this->pageService->getPage($params),
            'listPages' => $this->pageService->listPages($params),
            'listPathGroups' => $this->pageService->listPathGroups($params),
            'resolveThemeTarget' => $this->pageService->resolveThemeTarget((int)($params['target_id'] ?? $params['page_id'] ?? 0)),
            'renderPagePayload' => $this->pageService->renderPagePayload($params),
            default => throw new \InvalidArgumentException(
                (string)__('CMS 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => $this->getProviderName(),
            'name' => 'CMS 页面查询',
            'description' => '提供 CMS 页面、Theme target、前台渲染 payload 查询能力',
            'module' => 'Weline_Cms',
            'operations' => [
                [
                    'name' => 'getPage',
                    'description' => '读取单个 CMS 页面',
                    'params' => [
                        ['name' => 'page_id', 'type' => 'int', 'required' => false, 'description' => '页面ID'],
                        ['name' => 'identifier', 'type' => 'string', 'required' => false, 'description' => '页面路径'],
                        ['name' => 'website_id', 'type' => 'int', 'required' => false, 'description' => '站点ID'],
                        ['name' => 'website_code', 'type' => 'string', 'required' => false, 'description' => '站点代码'],
                        ['name' => 'path_group', 'type' => 'string', 'required' => false, 'description' => '一级 path'],
                        ['name' => 'slug', 'type' => 'string', 'required' => false, 'description' => '组内 slug'],
                        ['name' => 'scope', 'type' => 'string', 'required' => false, 'description' => '页面范围'],
                    ],
                ],
                [
                    'name' => 'listPages',
                    'description' => '分页读取 CMS 页面列表',
                    'params' => [
                        ['name' => 'status', 'type' => 'string', 'required' => false, 'description' => '页面状态'],
                        ['name' => 'website_id', 'type' => 'int', 'required' => false, 'description' => '站点ID'],
                        ['name' => 'path_group', 'type' => 'string', 'required' => false, 'description' => '一级 path'],
                        ['name' => 'scope', 'type' => 'string', 'required' => false, 'description' => '页面范围'],
                        ['name' => 'page', 'type' => 'int', 'required' => false, 'description' => '页码'],
                        ['name' => 'page_size', 'type' => 'int', 'required' => false, 'description' => '每页数量'],
                    ],
                ],
                [
                    'name' => 'listPathGroups',
                    'description' => '读取 CMS 站点一级 path 分组',
                    'params' => [
                        ['name' => 'website_id', 'type' => 'int', 'required' => false, 'description' => '站点ID'],
                        ['name' => 'path_group', 'type' => 'string', 'required' => false, 'description' => '一级 path'],
                        ['name' => 'search', 'type' => 'string', 'required' => false, 'description' => '搜索 path 或别名'],
                    ],
                ],
                [
                    'name' => 'resolveThemeTarget',
                    'description' => '解析 CMS 页面对应的 Theme target',
                    'params' => [
                        ['name' => 'target_id', 'type' => 'int', 'required' => true, 'description' => 'CMS 页面ID'],
                    ],
                ],
                [
                    'name' => 'renderPagePayload',
                    'description' => '解析前台渲染 CMS 页面所需 payload',
                    'params' => [
                        ['name' => 'identifier', 'type' => 'string', 'required' => false, 'description' => '页面路径'],
                        ['name' => 'page_id', 'type' => 'int', 'required' => false, 'description' => '页面ID'],
                        ['name' => 'website_id', 'type' => 'int', 'required' => false, 'description' => '站点ID'],
                        ['name' => 'path_group', 'type' => 'string', 'required' => false, 'description' => '一级 path'],
                        ['name' => 'slug', 'type' => 'string', 'required' => false, 'description' => '组内 slug'],
                        ['name' => 'scope', 'type' => 'string', 'required' => false, 'description' => '页面范围'],
                        ['name' => 'preview', 'type' => 'bool', 'required' => false, 'description' => '是否后台预览'],
                    ],
                ],
            ],
        ];
    }
}
