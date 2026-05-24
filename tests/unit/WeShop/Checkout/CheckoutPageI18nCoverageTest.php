<?php

declare(strict_types=1);

namespace Tests\Unit\WeShop\Checkout;

use PHPUnit\Framework\TestCase;

final class CheckoutPageI18nCoverageTest extends TestCase
{
    public function testCheckoutVisiblePhrasesHaveChineseAndEnglishTranslations(): void
    {
        $violations = [];

        foreach ($this->checkoutI18nTargets() as $target) {
            $keys = [];
            foreach ($target['files'] as $file) {
                foreach ($this->extractPhraseKeys($this->rootPath($file)) as $key) {
                    $keys[$key] = true;
                }
            }

            $zh = $this->readCsvMap($this->rootPath($target['zh']));
            $en = $this->readCsvMap($this->rootPath($target['en']));

            foreach (array_keys($keys) as $key) {
                if (!array_key_exists($key, $zh)) {
                    $violations[] = $target['name'] . ' missing zh_Hans_CN: ' . $key;
                }

                if (!array_key_exists($key, $en)) {
                    $violations[] = $target['name'] . ' missing en_US: ' . $key;
                    continue;
                }

                if ($this->containsChinese($key) && $this->containsChinese($en[$key])) {
                    $violations[] = $target['name'] . ' en_US still contains Chinese: ' . $key . ' => ' . $en[$key];
                }
            }
        }

        self::assertSame([], $violations, "Checkout i18n coverage violations:\n" . implode("\n", $violations));
    }

    public function testCheckoutNotificationHookDoesNotHideVisibleTextInNumericEntities(): void
    {
        $path = $this->rootPath('app/code/WeShop/Notification/view/hooks/Weline_Checkout/frontend/layouts/checkout/notification-preferences.phtml');
        $contents = (string) file_get_contents($path);

        self::assertDoesNotMatchRegularExpression(
            '/&#(?:x[0-9a-f]+|\d+);/i',
            $contents,
            'Visible checkout hook text must use __() keys, not numeric HTML entities.'
        );
    }

    public function testCheckoutDictionaryCoversRuntimeMethodPhrases(): void
    {
        $violations = [];
        $zh = $this->readCsvMap($this->rootPath('app/code/WeShop/Checkout/i18n/zh_Hans_CN.csv'));
        $en = $this->readCsvMap($this->rootPath('app/code/WeShop/Checkout/i18n/en_US.csv'));

        foreach ($this->checkoutRuntimeMethodPhraseKeys() as $key) {
            if (!array_key_exists($key, $zh)) {
                $violations[] = 'missing zh_Hans_CN: ' . $key;
            }

            if (!array_key_exists($key, $en)) {
                $violations[] = 'missing en_US: ' . $key;
                continue;
            }

            if ($this->containsChinese($en[$key])) {
                $violations[] = 'en_US still contains Chinese: ' . $key . ' => ' . $en[$key];
            }
        }

        self::assertSame([], $violations, "Checkout runtime method i18n violations:\n" . implode("\n", $violations));
    }

    /**
     * @return array<int, array{name: string, files: array<int, string>, zh: string, en: string}>
     */
    private function checkoutI18nTargets(): array
    {
        return [
            [
                'name' => 'WeShop_Checkout',
                'files' => [
                    'app/code/WeShop/Checkout/Api/Rest/V1/Checkout.php',
                    'app/code/WeShop/Checkout/Controller/Frontend/Checkout/Index.php',
                    'app/code/WeShop/Checkout/Controller/Frontend/Checkout/Methods.php',
                    'app/code/WeShop/Checkout/Controller/Frontend/Checkout/PlaceOrder.php',
                    'app/code/WeShop/Checkout/Controller/Frontend/Checkout/Success.php',
                    'app/code/WeShop/Checkout/Service/CheckoutPageDataService.php',
                    'app/code/WeShop/Checkout/Service/CheckoutService.php',
                    'app/code/WeShop/Checkout/Service/OrderSuccessPageDataService.php',
                    'app/code/WeShop/Checkout/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php',
                    'app/code/WeShop/Checkout/view/templates/frontend/checkout/index.phtml',
                    'app/code/WeShop/Checkout/view/templates/frontend/checkout/success.phtml',
                ],
                'zh' => 'app/code/WeShop/Checkout/i18n/zh_Hans_CN.csv',
                'en' => 'app/code/WeShop/Checkout/i18n/en_US.csv',
            ],
            [
                'name' => 'WeShop_Notification checkout hook',
                'files' => [
                    'app/code/WeShop/Notification/view/hooks/Weline_Checkout/frontend/layouts/checkout/notification-preferences.phtml',
                ],
                'zh' => 'app/code/WeShop/Notification/i18n/zh_Hans_CN.csv',
                'en' => 'app/code/WeShop/Notification/i18n/en_US.csv',
            ],
            [
                'name' => 'Weline_Checkout hooks',
                'files' => [
                    'app/code/Weline/Checkout/hook.php',
                ],
                'zh' => 'app/code/Weline/Checkout/i18n/zh_Hans_CN.csv',
                'en' => 'app/code/Weline/Checkout/i18n/en_US.csv',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function checkoutRuntimeMethodPhraseKeys(): array
    {
        return [
            '固定运费',
            '按固定运费配送。',
            '免运费',
            '符合条件的订单免费配送。',
            '到店自提',
            '到选择的本地门店自提订单。',
            '预计送达：%{1}。',
            '该服务支持免运费。',
            '配送服务',
            '由 %{1} 提供配送服务。',
            '%{1}-%{2} 天',
            '%{1} 天',
            '企业信用账户',
            '订单计入企业信用额度，并在到期日前按发票付款。',
            '仅适用于已开通有效信用额度的企业客户。',
            '说明',
            '银行转账',
            '下单后通过银行转账付款。',
            '请将订单金额转入配置的银行账户，并使用订单号作为付款备注。',
            '请使用订单号作为付款备注。',
            '订单创建后展示给客户。',
            '账户名称',
            '开户银行',
            '银行账号',
            '付款备注',
            '货到付款',
            '配送送达时现金付款。',
            '配送送达时向客户收款。',
            '货到付款手续费',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function extractPhraseKeys(string $path): array
    {
        $contents = (string) file_get_contents($path);
        $keys = [];

        foreach ([
            "/\\\\?__\\(\\s*'((?:\\\\'|[^'])*)'/su",
            '/\\\\?__\\(\\s*"((?:\\\\"|[^"])*)"/su',
        ] as $pattern) {
            preg_match_all($pattern, $contents, $matches);
            foreach ($matches[1] ?? [] as $rawKey) {
                $keys[] = stripcslashes($rawKey);
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<string, string>
     */
    private function readCsvMap(string $path): array
    {
        $handle = fopen($path, 'rb');
        self::assertIsResource($handle, 'Unable to read i18n CSV: ' . $path);

        $map = [];
        $line = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (!is_array($row) || count($row) < 2) {
                continue;
            }

            $key = (string) $row[0];
            if ($line === 0) {
                $key = preg_replace('/^\xEF\xBB\xBF/', '', $key) ?? $key;
            }

            $map[$key] = (string) $row[1];
            $line++;
        }

        fclose($handle);

        return $map;
    }

    private function containsChinese(string $value): bool
    {
        return preg_match('/\p{Han}/u', $value) === 1;
    }

    private function rootPath(string $path): string
    {
        return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
