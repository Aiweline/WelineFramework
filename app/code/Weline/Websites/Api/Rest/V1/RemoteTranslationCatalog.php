<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Rest\V1;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendRestController;
use Weline\Websites\Model\Website;

/**
 * 远程协助翻译：选站 / 语种只读 REST（薄壳 → websites Query）。
 */
#[Acl(
    'Weline_Websites::rest_v1_remote_translation_catalog',
    '远程翻译选站目录',
    'globe',
    '远程协助翻译可选网站与语种',
    'Weline_Websites::website'
)]
class RemoteTranslationCatalog extends BackendRestController
{
    /**
     * 可翻译目标站列表
     *
     * @return string JSON
     * @Document(summary='远程翻译可选网站列表', description='返回 website_id/code/name/default_language/status，供远程协助翻译选站。', tags=['Websites','远程翻译'], category='远程协助翻译')
     * @example
     * Method: GET
     * Path: /{api_admin}/weline_websites/rest/v1/RemoteTranslationCatalog/getWebsites
     * Response:
     * {"success":true,"code":200,"msg":"ok","data":{"items":[{"website_id":0,"code":"default","name":"Default","default_language":"zh_Hans_CN","status":1}]}}
     * @example-end
     */
    #[Acl('Weline_Websites::rest_v1_remote_translation_catalog_websites', '远程翻译网站列表', 'list')]
    public function getWebsites(): string
    {
        try {
            $rows = $this->executeQuery('getWebsiteList', []);
            if (!\is_array($rows)) {
                $rows = [];
            }
            $items = [];
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $items[] = [
                    'website_id' => (int)($row['website_id'] ?? Website::ID_DEFAULT),
                    'code' => (string)($row['code'] ?? ''),
                    'name' => (string)($row['name'] ?? ''),
                    'default_language' => (string)($row['default_language'] ?? ''),
                    'status' => 1,
                ];
            }

            return $this->success((string)__('获取网站列表成功'), ['items' => $items]);
        } catch (\Throwable $e) {
            return $this->exception($e, (string)__('获取网站列表失败'));
        }
    }

    /**
     * 指定网站语种（空 codes 不回退全球目录）
     *
     * @return string JSON
     * @Document(summary='远程翻译网站语种', description='返回该站 WebsiteLanguage 语种列表；空则 locales=[]。', tags=['Websites','远程翻译'], category='远程协助翻译')
     * @param int $website_id 网站 ID（必填，query）
     * @example
     * Method: GET
     * Path: /{api_admin}/weline_websites/rest/v1/RemoteTranslationCatalog/getLanguages?website_id=0
     * Response:
     * {"success":true,"code":200,"msg":"ok","data":{"website_id":0,"locales":[{"code":"zh_Hans_CN","name":"zh_Hans_CN","is_default":true}]}}
     * @example-end
     */
    #[Acl('Weline_Websites::rest_v1_remote_translation_catalog_languages', '远程翻译网站语种', 'language')]
    public function getLanguages(): string
    {
        try {
            $websiteId = (int)$this->request->getParam('website_id', -1);
            if ($websiteId < Website::ID_DEFAULT) {
                return $this->error((string)__('website_id 无效'), '', 422);
            }

            $site = $this->executeQuery('getWebsiteById', ['website_id' => $websiteId]);
            if (!\is_array($site) || !isset($site['website_id'])) {
                return $this->error((string)__('网站不存在'), ['website_id' => $websiteId], 404);
            }

            $codes = $this->executeQuery('getWebsiteLanguageCodes', ['website_id' => $websiteId]);
            if (!\is_array($codes)) {
                $codes = [];
            }
            $defaultLanguage = (string)($site['default_language'] ?? '');
            $locales = [];
            foreach ($codes as $code) {
                $code = \trim((string)$code);
                if ($code === '') {
                    continue;
                }
                $name = $code;
                try {
                    $resolved = w_query('i18n', 'getLocaleName', [
                        'code' => $code,
                        'display_locale_code' => 'zh_Hans_CN',
                    ]);
                    if (\is_string($resolved) && $resolved !== '') {
                        $name = $resolved;
                    }
                } catch (\Throwable) {
                    // 名称 enrich 失败时回退 code
                }
                $locales[] = [
                    'code' => $code,
                    'name' => $name,
                    'is_default' => $defaultLanguage !== '' && $code === $defaultLanguage,
                ];
            }

            return $this->success((string)__('获取网站语种成功'), [
                'website_id' => $websiteId,
                'locales' => $locales,
            ]);
        } catch (\Throwable $e) {
            return $this->exception($e, (string)__('获取网站语种失败'));
        }
    }

    protected function executeQuery(string $operation, array $params): mixed
    {
        return w_query('websites', $operation, $params);
    }
}
