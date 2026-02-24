<?php

declare(strict_types=1);

namespace Weline\I18n\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Http\Cookie;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\I18n\Model\I18n;

/**
 * I18n 模块查询器
 *
 * 提供已安装语言列表等查询能力，供主题编辑器等模块通过 Weline_I18n::query 调用。
 */
class I18nQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly I18n $i18n
    ) {
    }

    public function getProviderName(): string
    {
        return 'i18n';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getInstalledLocales' => $this->getInstalledLocales($params),
            default => throw new \InvalidArgumentException((string)__('I18n 查询器不支持的 operation：%{1}', $operation)),
        };
    }

    /**
     * 获取已安装语言列表（含 SVG 国旗）
     *
     * @param array $params display_locale_code, width, height, installed
     * @return list<array{code: string, name: string, flag: string}>
     */
    private function getInstalledLocales(array $params): array
    {
        $displayLocale = (string)($params['display_locale_code'] ?? Cookie::getLangLocal() ?? 'zh_Hans_CN');
        $width = (int)($params['width'] ?? 20);
        $height = (int)($params['height'] ?? 15);
        $installed = (bool)($params['installed'] ?? true);

        $raw = $this->i18n->getLocalesWithFlagsDisplaySelf($displayLocale, $width, $height, $installed);

        $list = [];
        foreach ($raw as $code => $info) {
            $list[] = [
                'code' => $code,
                'name' => $info['name'] ?? $code,
                'flag' => $info['flag'] ?? '',
            ];
        }
        return $list;
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'i18n',
            'name' => __('I18n 国际化查询'),
            'description' => __('提供已安装语言列表等查询能力'),
            'module' => 'Weline_I18n',
            'operations' => [
                [
                    'name' => 'getInstalledLocales',
                    'description' => __('获取已安装语言列表（含名称与 SVG 国旗）'),
                    'params' => [
                        ['name' => 'display_locale_code', 'type' => 'string', 'required' => false, 'description' => __('显示名称所用语言代码，默认当前语言')],
                        ['name' => 'width', 'type' => 'int', 'required' => false, 'description' => __('国旗宽度，默认 20')],
                        ['name' => 'height', 'type' => 'int', 'required' => false, 'description' => __('国旗高度，默认 15')],
                        ['name' => 'installed', 'type' => 'bool', 'required' => false, 'description' => __('仅已安装语言，默认 true')],
                    ],
                ],
            ],
        ];
    }
}
