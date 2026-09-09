<?php

declare(strict_types=1);

namespace Weline\Product\Api\Rest\V1;

use Weline\Api\Data\ApiAppActor;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Http\Response;
use Weline\Product\Api\Data\ProductAdminCommand;
use Weline\Product\Api\Data\ProductAdminResult;
use Weline\Product\Api\ProductAdminCommandInterface;
use Weline\Product\Api\ProductAdminReadInterface;
use Weline\Product\Service\ProductRestInput;

/** 供已授权的外部应用管理产品，鉴权由 Weline_Api 统一执行。 */
#[Acl('Weline_Product::api_products', '应用产品管理', 'box', parent_source: 'Weline_Product::commerce:catalog:products')]
final class Products extends FrontendRestController
{
    /**
     * 按请求语言创建产品草稿；一次请求可提交多语言译文或自动翻译后保存。
     * 支持 API 用户或 global 应用安装令牌，需路由权限 Weline_Product::api_products_create；写入 Content-Type 为 application/json。
     * @param string $Authorization （必填；请求头）Bearer API 用户或应用 access_token。
     * @param int $website_id （必填；Body参数）目标 Website 的非负整数 ID，0 为当前主站示例。
     * @param object $payload （必填；Body参数）商品对象；name、sku 必填，product_type 缺省 simple；store_ids 缺省选择活动 Store，[] 表示不选择。
     * @param string $locale （可选；Body参数）已登记语言，如 zh_Hans_CN/en_US；优先于 payload.locale、query locale、Accept-Language 和框架请求语言。
     * @param object $translations （可选；Body参数）以语言代码为键的增量文案对象；支持 name、short_description、description、meta_name、meta_description、meta_keywords、attributes 和 store_id。
     * @param array $translate_to （可选；Body参数）自动翻译目标语言列表；使用请求语言已提交文案，仅补目标组未手写字段，需现有可用翻译渠道。
     * 可选请求头 Accept-Language: en-US；没有明确 locale 时按权重选择已登记语言。
     * @param string $request_hash （可选；Body参数）64 位十六进制幂等键；同一创建重试复用，新产品换键；省略自动生成。
     * 成功 HTTP 201，data.identity.global_product_uuid 用于后续编辑；错误 400/401/403/415/500/502；自动翻译失败返回 502 且不提交产品写入。
     * @example
     * Method: POST
     * Path: /api/weline_product/rest/v1/products/create
     * Body:
     * {"website_id":0,"locale":"zh_Hans_CN","payload":{"name":"中文商品","sku":"API-DOC-EXAMPLE-001","product_type":"simple","store_ids":[]},"translations":{"en_US":{"name":"English product"}}}
     * Response:
     * {"success":true,"error_code":null,"message":"","data":{"identity":{"global_product_uuid":"12345678-1234-4234-8234-123456789abc"},"product_id":322},"code":201,"error":false,"msg":""}
     * @example-end
     * @Document(summary='应用创建产品', description='按请求语言创建草稿，支持批量 translations 和 translate_to 自动翻译，保留现有产品命令规则。', tags=['产品', '应用集成'], category='产品管理')
     */
    #[Acl('Weline_Product::api_products_create', '应用创建产品', 'plus', '允许应用创建产品草稿', accessMode: 'edit', scopeGroup: 'products', apiExposable: true)]
    public function postCreate(): string
    {
        return $this->writeProduct(ProductAdminCommand::ACTION_CREATE);
    }

    /**
     * 按语言增量编辑产品；未提交的语言和字段保持原值，同一请求沿用单次版本更新。
     * 支持 API 用户或 global 应用安装令牌，需路由权限 Weline_Product::api_products_update；写入 Content-Type 为 application/json。
     * @param string $Authorization （必填；请求头）Bearer API 用户或应用 access_token。
     * @param int $website_id （必填；Body参数）目标 Website 的非负整数 ID。
     * @param string $global_product_uuid （必填；Body参数）创建响应或详情中的全局产品 UUID。
     * @param object $payload （必填；Body参数）增量字段对象；local_version 必填，取详情 data.product.publish_version；未传字段保留。
     * @param string $locale （可选；Body参数）本次 payload 文案语言；优先于 payload.locale、query locale、Accept-Language 和框架请求语言。
     * @param object $translations （可选；Body参数）按语言代码提交各语言的部分字段；不修改其他语言或基础回退值。
     * @param array $translate_to （可选；Body参数）把本次源语言文案自动翻译到目标语言；目标组已手写字段优先，翻译失败不保存。
     * 可选请求头 Accept-Language: en-US；没有明确 locale 时按权重选择已登记语言。
     * @param string $request_hash （可选；Body参数）64 位十六进制键，省略自动生成。
     * payload 可含 name、short_description、attributes、prices、media_assignments、category_assignments、store_ids、inventory；不执行 SKU 重命名、类型转换或发布。
     * @return string JSON；成功 HTTP 200，data.local_version 为新版本；错误 400/401/403/404/409/415/500/502；409 后回读最新版本再提交。
     * @example
     * Method: PUT
     * Path: /api/weline_product/rest/v1/products/edit
     * Body:
     * {"website_id":0,"global_product_uuid":"12345678-1234-4234-8234-123456789abc","locale":"en_US","payload":{"local_version":0,"name":"Updated English name"},"translations":{"zh_Hans_CN":{"short_description":"保留中文名称，只更新这段说明"}}}
     * Response:
     * {"success":true,"error_code":null,"message":"","data":{"local_version":1},"code":200,"error":false,"msg":""}
     * @example-end
     * @Document(summary='应用编辑产品', description='只修改当前语言及 translations 中提交的字段，保留其他语言，沿用产品版本冲突保护。', tags=['产品', '应用集成'], category='产品管理')
     */
    #[Acl('Weline_Product::api_products_update', '应用编辑产品', 'edit', '允许应用编辑产品', accessMode: 'edit', scopeGroup: 'products', apiExposable: true)]
    public function putEdit(): string
    {
        return $this->writeProduct(ProductAdminCommand::ACTION_SAVE);
    }

    /**
     * 明确发布已保存的产品，复用现有发布校验、Offer 发布与双版本冲突保护。
     * 授予 Weline_Product::api_products_publish；写入 Content-Type 为 application/json。
     * @param string $Authorization （必填；请求头）Bearer access_token。
     * @param int $website_id （必填；Body参数）目标 Website 的非负整数 ID。
     * @param string $global_product_uuid （必填；Body参数）详情 data.identity.global_product_uuid。
     * @param int $expected_version （必填；Body参数）详情 data.identity.version，保护全局产品身份。
     * @param object $payload （必填；Body参数）local_version 为详情 data.product.publish_version；可选 locale 和 currency 用于发布校验。
     * @param string $request_hash （可选；Body参数）64 位十六进制幂等键，同一次发布重试保留。
     * 发布前须已选择活动 Store，并满足现有产品 Provider 校验；失败 data.diagnostics 保留具体错误。
     * 发布仅改变生命周期，需先通过 edit 保存文案、价格和库存；不会自动翻译或写入这些字段。
     * @return string JSON；成功 HTTP 200，data.product.status 为 published，data.product.publish_version 和 data.identity.version 为新版本；错误 400/401/403/404/409/415/500。
     * @example
     * Method: POST
     * Path: /api/weline_product/rest/v1/products/publish
     * Body:
     * {"website_id":0,"global_product_uuid":"12345678-1234-4234-8234-123456789abc","expected_version":0,"payload":{"local_version":0,"locale":"zh_Hans_CN","currency":"CNY"}}
     * Response:
     * {"success":true,"error_code":null,"message":"","data":{"identity":{"global_product_uuid":"12345678-1234-4234-8234-123456789abc","version":1,"lifecycle_status":"published"},"product":{"product_id":322,"status":"published","publish_version":1},"offers":[]},"code":200,"error":false,"msg":""}
     * @example-end
     * @Document(summary='发布产品', description='显式发布已保存的产品，复用活动 Store、Provider、价格等现有校验与双版本保护。', tags=['产品', '发布'], category='产品管理')
     */
    #[Acl('Weline_Product::api_products_publish', '发布产品', 'upload', '允许发布校验通过的产品', accessMode: 'edit', scopeGroup: 'products', apiExposable: true)]
    public function postPublish(): string
    {
        return $this->writeProduct(ProductAdminCommand::ACTION_PUBLISH);
    }

    /**
     * 读取产品编辑快照；product.publish_version 是下一次编辑的 local_version。
     * 支持 API 用户或 global 应用安装令牌，需路由权限 Weline_Product::api_products_detail；返回当前编辑快照。
     * @param string $Authorization （必填；请求头）Bearer API 用户或应用 access_token。
     * @param int $website_id （必填；GET参数）目标 Website 的非负整数 ID。
     * @param string $global_product_uuid （必填；GET参数）待查询的全局产品 UUID。
     * @param string $locale （可选；GET参数）目标语言，优先于 Accept-Language；省略时使用框架请求语言。
     * 可选请求头 Accept-Language: en-US；没有明确 locale 时按权重选择已登记语言。
     * @param string $currency （可选；GET参数）价格货币，省略为 CNY，最大 8 字节。
     * @return string JSON；成功 HTTP 200，data.locale 为目标语言，data.content 为含基础回退的该语言视图，data.translations 为显式语言内容；storefront_urls 返回实际可见店面的 loc/product_id/store_id（未发布或未选择 Store 时为空）；保留原 product、attributes、Offer 与 Store 配置；data.product.publish_version 是编辑版本；错误 400/401/403/404/500。
     * @example
     * Method: GET
     * Path: /api/weline_product/rest/v1/products/detail?website_id=0&global_product_uuid=12345678-1234-4234-8234-123456789abc&locale=en_US
     * Response:
     * {"success":true,"error_code":null,"message":"","data":{"product":{"sku":"API-DOC-EXAMPLE-001","publish_version":0,"product_id":322},"attributes":[],"locale":"en_US","content":{"name":"English product"},"translations":{"zh_Hans_CN":{"name":"中文商品"},"en_US":{"name":"English product"}}},"code":200,"error":false,"msg":""}
     * @example-end
     * @Document(summary='应用读取产品', description='读取产品、Offer、属性和当前编辑版本。', tags=['产品', '应用集成'], category='产品管理')
     */
    #[Acl('Weline_Product::api_products_detail', '应用读取产品', 'eye', '允许应用读取产品编辑快照', accessMode: 'read', scopeGroup: 'products', apiExposable: true)]
    public function getDetail(): string
    {
        if (($failure = $this->applicationFailure()) !== null) {
            return $failure;
        }
        try {
            $websiteId = ProductRestInput::nonNegativeInt($this->request->getGet('website_id'), 'website_id');
            $uuid = $this->request->getGet('global_product_uuid');
            if (!is_string($uuid) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $uuid)) {
                throw new \InvalidArgumentException('product_admin_product_uuid_invalid');
            }
            $locale = $this->requestLocale();
            $currency = $this->request->getGet('currency', 'CNY');
            if (!is_string($currency) || strlen($currency) > 8) {
                throw new \InvalidArgumentException('product_api_locale_or_currency_invalid');
            }
            $snapshot = $this->getObject(ProductAdminReadInterface::class)->snapshot(
                $websiteId, $uuid, null, $locale, $currency,
            );
            $data = \Weline\Product\Service\ProductRestTranslations::localizeSnapshot($snapshot->toArray(), $locale);
            $data['storefront_urls'] = $this->getObject(\Weline\Product\Service\ProductSitemapUrlService::class)->getUrlsForProduct(
                $websiteId, (int)($data['product']['product_id'] ?? 0),
            );
            return $this->respond(ProductAdminResult::ok($data));
        } catch (\InvalidArgumentException $exception) {
            return $this->inputFailure($exception);
        } catch (\Throwable) {
            return $this->respond(ProductAdminResult::fail('product_admin_internal_error', 'product_api_internal_error'));
        }
    }

    /** 请求体明确语言优先，其次查询参数、Accept-Language 和框架请求语言。 */
    private function requestLocale(array $body = []): string
    {
        $locale = $body['locale'] ?? $body['payload']['locale'] ?? $this->request->getParameterBag()->getQuery('locale');
        if ($locale !== null) {
            return \Weline\Product\Service\ProductRestTranslations::normalizeLocale($locale);
        }
        return \Weline\Product\Service\ProductRestTranslations::negotiateLocale(
            (string)($this->request->getServerBag()->getHeader('Accept-Language') ?? ''),
            (string)\Weline\Framework\App\State::getLangLocal(),
        );
    }

    private function writeProduct(string $action): string
    {
        if (($failure = $this->applicationFailure()) !== null) {
            return $failure;
        }
        try {
            $contentType = strtolower($this->request->getContentType());
            if (!preg_match('#^application/json(?:\s*;|$)#', $contentType)) {
                return $this->respond(ProductAdminResult::fail('product_api_json_content_type_required', 'product_api_json_content_type_required'), 415);
            }
            $body = ProductRestInput::decode($this->request->getParameterBag()->getRawBody());
            $actor = $this->request->getData('api_app_actor');
            $apiUser = $this->request->getData('api_authenticated_user');
            $command = ProductRestInput::command(
                $action,
                $body,
                $actor instanceof ApiAppActor ? $actor->getInstallationId() : 0,
                $actor instanceof ApiAppActor ? null : $apiUser->getIdempotencyScope(),
            );
            if (in_array($action, [ProductAdminCommand::ACTION_CREATE, ProductAdminCommand::ACTION_SAVE], true)) {
                $command = (new \Weline\Product\Service\ProductRestTranslations())->prepareCommand(
                    $command, $body, $this->requestLocale($body),
                );
            }
            $result = $this->getObject(ProductAdminCommandInterface::class)->execute($command);
            return $this->respond($result, $result->success && $action === ProductAdminCommand::ACTION_CREATE ? 201 : null);
        } catch (\InvalidArgumentException $exception) {
            return $this->inputFailure($exception);
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'product_api_translation_failed') {
                return $this->respond(ProductAdminResult::fail('product_api_translation_failed', 'product_api_translation_failed'), 502);
            }
            return $this->respond(ProductAdminResult::fail('product_admin_internal_error', 'product_api_internal_error'));
        } catch (\Throwable) {
            return $this->respond(ProductAdminResult::fail('product_admin_internal_error', 'product_api_internal_error'));
        }
    }

    private function applicationFailure(): ?string
    {
        $actor = $this->request->getData('api_app_actor');
        if ($actor instanceof ApiAppActor) {
            if ($actor->getInstallation()->getSubjectType() !== 'global') {
                return $this->respond(ProductAdminResult::fail('product_api_global_installation_required', 'product_api_global_installation_required'), 403);
            }
            return null;
        }
        if ($this->request->getData('api_authenticated_user') instanceof \Weline\Api\Api\AuthenticatedApiUser) {
            return null;
        }
        return $this->respond(ProductAdminResult::fail('product_api_application_required', 'product_api_application_required'), 401);
    }

    private function inputFailure(\InvalidArgumentException $exception): string
    {
        $code = $exception->getMessage();
        if (!preg_match('/^[a-z][a-z0-9_.-]{2,127}$/D', $code)) {
            $code = 'product_admin_invalid_argument';
        }
        return $this->respond(ProductAdminResult::fail($code, $code));
    }

    private function respond(ProductAdminResult $result, ?int $status = null): string
    {
        $code = $result->errorCode ?? '';
        $status ??= match (true) {
            $result->success => 200,
            $code === 'product_admin_internal_error' => 500,
            str_contains($code, 'not_found') => 404,
            str_contains($code, 'conflict'), $code === 'product_archived_readonly' => 409,
            default => 400,
        };
        $body = $result->toArray();
        if ($code === 'product_admin_internal_error') {
            // 业务层的异常诊断仅用于内部，不向外部应用返回 SQL 或堆栈。
            $body['message'] = 'product_api_internal_error';
            $body['data'] = [];
        }
        $body['code'] = $status;
        $body['error'] = !$result->success;
        $body['msg'] = $body['message'];
        $this->request->getResponse()->setCode($status);
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');
        return Response::json($body, $status)->getBody();
    }
}
