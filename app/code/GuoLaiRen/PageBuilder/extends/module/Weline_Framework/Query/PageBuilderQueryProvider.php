<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Extends\Module\Weline_Framework\Query;

use GuoLaiRen\PageBuilder\Helper\PageBuilderUrlCacheInvalidator;
use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

/**
 * PageBuilder 查询器
 *
 * 提供 getPageById、clearUrlCaches 等能力，供其他模块通过 w_query('page_builder', ...) 调用。
 * 亦可通过 Framework REST：POST body { "provider":"page_builder","operation":"clearUrlCaches","params":{} }。
 */
class PageBuilderQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly Page $pageModel
    ) {
    }

    public function getProviderName(): string
    {
        return 'page_builder';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getPageById' => $this->getPageById($params),
            'clearUrlCaches' => $this->clearUrlCaches($params),
            default => throw new \InvalidArgumentException(
                (string)__('PageBuilder 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    /**
     * @param array<string, mixed> $params page_id 可选；不传则全量清理（兼容旧调用）
     */
    private function clearUrlCaches(array $params): array
    {
        $pageId = (int)($params['page_id'] ?? 0);
        if ($pageId > 0) {
            return PageBuilderUrlCacheInvalidator::invalidateForPageId(
                $pageId,
                (string)($params['wls_instance'] ?? 'default')
            );
        }
        PageBuilderUrlCacheInvalidator::invalidateRouterAndRewrite();

        return ['cleared' => true, 'scope' => 'all'];
    }

    private function getPageById(array $params): ?array
    {
        $pageId = (int)($params['page_id'] ?? 0);
        if ($pageId <= 0) {
            return null;
        }
        $page = clone $this->pageModel;
        $page->load($pageId);
        if (!$page->getId()) {
            return null;
        }
        return [
            'page_id' => (int)$page->getId(),
            'handle' => $page->getData(Page::schema_fields_HANDLE),
            'type' => $page->getData(Page::schema_fields_TYPE),
            'name' => $page->getData(Page::schema_fields_NAME),
            'title' => $page->getData(Page::schema_fields_TITLE),
            'default_locale' => $page->getData(Page::schema_fields_DEFAULT_LOCALE),
            'website_id' => (int)($page->getData(Page::schema_fields_WEBSITE_ID) ?? 0),
            'status' => (int)($page->getData(Page::schema_fields_STATUS) ?? 0),
        ];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'page_builder',
            'name' => __('PageBuilder 页面查询'),
            'description' => __('提供页面信息查询能力'),
            'module' => 'GuoLaiRen_PageBuilder',
            'operations' => [
                [
                    'name' => 'getPageById',
                    'description' => __('根据 ID 获取页面信息'),
                    'params' => [
                        ['name' => 'page_id', 'type' => 'int', 'required' => true],
                    ],
                ],
                [
                    'name' => 'clearUrlCaches',
                    'description' => __('按 page_id 清除该页 URL 重写、router 统一缓存并通知 WLS；不传 page_id 时全量清理'),
                    'params' => [
                        ['name' => 'page_id', 'type' => 'int', 'required' => false],
                        ['name' => 'wls_instance', 'type' => 'string', 'required' => false],
                    ],
                ],
            ],
        ];
    }
}
