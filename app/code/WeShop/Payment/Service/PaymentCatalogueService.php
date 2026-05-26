<?php

declare(strict_types=1);

namespace WeShop\Payment\Service;

use Symfony\Component\Intl\Countries;
use WeShop\Payment\Provider\AdyenCheckout;
use WeShop\Payment\Provider\Alipay;
use WeShop\Payment\Provider\CashOnDelivery;
use WeShop\Payment\Provider\CreditLineEvent;
use WeShop\Payment\Provider\GenericHostedRedirect;
use WeShop\Payment\Provider\ManualTransfer;
use WeShop\Payment\Provider\PayPal;
use WeShop\Payment\Provider\StripeCheckout;
use WeShop\Payment\Provider\WeChatPay;

class PaymentCatalogueService
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function getProviders(): array
    {
        return [
            'manual' => $this->provider('manual', '人工/线下', 'bank', 'global'),
            'stripe' => $this->provider('stripe', 'Stripe', 'global_psp', 'global'),
            'adyen' => $this->provider('adyen', 'Adyen', 'global_psp', 'global'),
            'paypal' => $this->provider('paypal', 'PayPal', 'wallet', 'global'),
            'braintree' => $this->provider('braintree', 'Braintree', 'global_psp', 'global'),
            'checkout_com' => $this->provider('checkout_com', 'Checkout.com', 'global_psp', 'global'),
            'worldpay' => $this->provider('worldpay', 'Worldpay', 'global_psp', 'global'),
            'nuvei' => $this->provider('nuvei', 'Nuvei', 'global_psp', 'global'),
            'airwallex' => $this->provider('airwallex', 'Airwallex', 'global_psp', 'global'),
            'rapyd' => $this->provider('rapyd', 'Rapyd', 'global_psp', 'global'),
            'dlocal' => $this->provider('dlocal', 'dLocal', 'local_psp', 'latin_america'),
            'ebanx' => $this->provider('ebanx', 'EBANX', 'local_psp', 'latin_america'),
            'alipay' => $this->provider('alipay', '支付宝', 'wallet', 'china'),
            'wechatpay' => $this->provider('wechatpay', '微信支付', 'wallet', 'china'),
            'unionpay' => $this->provider('unionpay', '银联', 'card', 'china'),
            'lianlian' => $this->provider('lianlian', '连连支付', 'cross_border', 'china'),
            'pingpong' => $this->provider('pingpong', 'PingPong', 'cross_border', 'china'),
            'payoneer' => $this->provider('payoneer', 'Payoneer', 'cross_border', 'global'),
            'wise' => $this->provider('wise', 'Wise Business', 'bank', 'global'),
            'klarna' => $this->provider('klarna', 'Klarna', 'bnpl', 'europe'),
            'mercado_pago' => $this->provider('mercado_pago', 'Mercado Pago', 'local_psp', 'latin_america'),
            'razorpay' => $this->provider('razorpay', 'Razorpay', 'local_psp', 'asia_pacific'),
            'xendit' => $this->provider('xendit', 'Xendit', 'local_psp', 'asia_pacific'),
            'midtrans' => $this->provider('midtrans', 'Midtrans', 'local_psp', 'asia_pacific'),
            'flutterwave' => $this->provider('flutterwave', 'Flutterwave', 'local_psp', 'africa'),
            'paystack' => $this->provider('paystack', 'Paystack', 'local_psp', 'africa'),
            'hyperpay' => $this->provider('hyperpay', 'HyperPay', 'local_psp', 'middle_east'),
            'paytabs' => $this->provider('paytabs', 'PayTabs', 'local_psp', 'middle_east'),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getMethodRegistry(): array
    {
        $allCountries = $this->getCountryCodes();
        $globalCurrencies = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'HKD', 'SGD', 'CNY'];

        return [
            'stripe_checkout' => $this->method('stripe_checkout', 'stripe', 'Stripe Checkout', '全球卡组织、钱包和本地支付方式聚合入口。', StripeCheckout::class, [
                'enabled' => false,
                'method_type' => 'card',
                'flow' => 'redirect',
                'sort_order' => 10,
                'popularity_score' => 96,
                'countries' => $allCountries,
                'country_popularity' => $this->scores([
                    'US' => 100, 'CA' => 96, 'GB' => 96, 'AU' => 94, 'SG' => 92, 'HK' => 90,
                    'FR' => 88, 'DE' => 88, 'NL' => 86, 'JP' => 84, 'BR' => 76, 'MX' => 74, 'CN' => 60,
                ]),
                'currencies' => $globalCurrencies,
                'required_config' => ['secret_key', 'success_url', 'cancel_url'],
                'config' => [
                    'environment' => 'sandbox',
                    'sandbox_secret_key' => '',
                    'live_secret_key' => '',
                    'sandbox_publishable_key' => '',
                    'live_publishable_key' => '',
                    'webhook_secret' => '',
                    'success_url' => '',
                    'cancel_url' => '',
                ],
                'config_fields' => array_merge($this->environmentFields(), [
                    $this->field('sandbox_secret_key', '沙盒 Secret Key', 'password'),
                    $this->field('live_secret_key', '正式 Secret Key', 'password'),
                    $this->field('sandbox_publishable_key', '沙盒 Publishable Key'),
                    $this->field('live_publishable_key', '正式 Publishable Key'),
                    $this->field('webhook_secret', 'Webhook 签名密钥', 'password'),
                    $this->field('success_url', '支付成功返回 URL'),
                    $this->field('cancel_url', '支付取消返回 URL'),
                ]),
            ]),
            'adyen_checkout' => $this->method('adyen_checkout', 'adyen', 'Adyen Checkout', '面向全球企业的卡、本地银行、钱包和线下统一收单。', AdyenCheckout::class, [
                'enabled' => false,
                'method_type' => 'card',
                'flow' => 'redirect',
                'sort_order' => 20,
                'popularity_score' => 94,
                'countries' => $allCountries,
                'country_popularity' => $this->scores([
                    'NL' => 95, 'DE' => 96, 'GB' => 95, 'FR' => 94, 'US' => 90, 'AU' => 88,
                    'SG' => 86, 'HK' => 84, 'BR' => 76, 'MX' => 74, 'CN' => 62,
                ]),
                'currencies' => $globalCurrencies,
                'required_config' => ['api_key', 'merchant_account', 'api_url', 'return_url'],
                'config' => [
                    'environment' => 'sandbox',
                    'sandbox_api_url' => 'https://checkout-test.adyen.com/v71/payments',
                    'live_api_url' => '',
                    'sandbox_api_key' => '',
                    'live_api_key' => '',
                    'merchant_account' => '',
                    'return_url' => '',
                    'webhook_hmac_key' => '',
                ],
                'config_fields' => array_merge($this->environmentFields(), [
                    $this->field('sandbox_api_url', '沙盒 API URL'),
                    $this->field('live_api_url', '正式 API URL'),
                    $this->field('sandbox_api_key', '沙盒 API Key', 'password'),
                    $this->field('live_api_key', '正式 API Key', 'password'),
                    $this->field('merchant_account', 'Merchant Account'),
                    $this->field('return_url', 'Return URL'),
                    $this->field('webhook_hmac_key', 'Webhook HMAC Key', 'password'),
                ]),
            ]),
            'paypal' => $this->method('paypal', 'paypal', 'PayPal', 'PayPal 钱包与卡收单，适合跨境零售和小额 B2B。', PayPal::class, [
                'enabled' => false,
                'method_type' => 'wallet',
                'flow' => 'redirect',
                'sort_order' => 30,
                'popularity_score' => 93,
                'countries' => $allCountries,
                'country_popularity' => $this->scores([
                    'US' => 98, 'GB' => 94, 'DE' => 92, 'CA' => 92, 'AU' => 90, 'FR' => 88,
                    'IT' => 86, 'ES' => 84, 'JP' => 82, 'HK' => 78, 'SG' => 78,
                ]),
                'currencies' => ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'HKD', 'SGD'],
                'required_config' => ['client_id', 'client_secret', 'return_url', 'cancel_url'],
                'config' => [
                    'environment' => 'sandbox',
                    'sandbox_client_id' => '',
                    'sandbox_client_secret' => '',
                    'live_client_id' => '',
                    'live_client_secret' => '',
                    'return_url' => '',
                    'cancel_url' => '',
                    'webhook_id' => '',
                ],
                'config_fields' => array_merge($this->environmentFields(), [
                    $this->field('sandbox_client_id', '沙盒 Client ID'),
                    $this->field('sandbox_client_secret', '沙盒 Client Secret', 'password'),
                    $this->field('live_client_id', '正式 Client ID'),
                    $this->field('live_client_secret', '正式 Client Secret', 'password'),
                    $this->field('return_url', 'Return URL'),
                    $this->field('cancel_url', 'Cancel URL'),
                    $this->field('webhook_id', 'Webhook ID'),
                ]),
            ]),
            'braintree_dropin' => $this->genericMethod('braintree_dropin', 'braintree', 'Braintree Drop-in', 'PayPal 体系下的卡和钱包托管收单。', ['US' => 86, 'GB' => 80, 'AU' => 78, 'CA' => 78], $allCountries, $globalCurrencies, 78, 40),
            'checkout_com_hosted' => $this->genericMethod('checkout_com_hosted', 'checkout_com', 'Checkout.com Hosted Payments', '全球卡、本地支付和风控托管收银台。', ['GB' => 92, 'AE' => 86, 'US' => 84, 'FR' => 82, 'HK' => 78], $allCountries, $globalCurrencies, 85, 50),
            'worldpay_hosted' => $this->genericMethod('worldpay_hosted', 'worldpay', 'Worldpay Hosted Payment Page', '企业级全球银行卡和本地支付托管页。', ['GB' => 90, 'US' => 82, 'CA' => 76, 'AU' => 74], $allCountries, $globalCurrencies, 80, 60),
            'nuvei_checkout' => $this->genericMethod('nuvei_checkout', 'nuvei', 'Nuvei Checkout', '全球卡、钱包、本地支付和加密支付聚合。', ['CA' => 86, 'US' => 82, 'GB' => 78, 'IL' => 76], $allCountries, $globalCurrencies, 78, 70),
            'airwallex_payments' => $this->genericMethod('airwallex_payments', 'airwallex', 'Airwallex Payments', '空中云汇全球账户、收单和本地支付。', ['HK' => 96, 'SG' => 94, 'AU' => 88, 'GB' => 80, 'US' => 78, 'CN' => 72], $allCountries, $globalCurrencies, 82, 80),
            'rapyd_collect' => $this->genericMethod('rapyd_collect', 'rapyd', 'Rapyd Collect', '覆盖多国本地钱包、银行转账和现金网络的聚合收款。', ['SG' => 82, 'BR' => 80, 'MX' => 78, 'ID' => 78, 'ZA' => 72], $allCountries, $globalCurrencies, 76, 90),
            'dlocal_payins' => $this->genericMethod('dlocal_payins', 'dlocal', 'dLocal Payins', '拉美、非洲和新兴市场本地支付直连。', ['BR' => 96, 'MX' => 92, 'AR' => 90, 'CO' => 90, 'CL' => 84, 'PE' => 82, 'UY' => 80], ['BR', 'MX', 'AR', 'CO', 'CL', 'PE', 'UY', 'PY', 'EC', 'BO', 'NG', 'ZA', 'EG', 'MA', 'IN', 'ID', 'TH', 'VN'], ['USD', 'BRL', 'MXN', 'ARS', 'COP', 'CLP', 'PEN', 'UYU'], 84, 100),
            'ebanx_payins' => $this->genericMethod('ebanx_payins', 'ebanx', 'EBANX Payins', '拉美本地卡、Pix、现金券和银行转账。', ['BR' => 95, 'MX' => 88, 'CO' => 86, 'CL' => 82, 'PE' => 82, 'AR' => 80], ['BR', 'MX', 'CO', 'CL', 'PE', 'AR', 'EC', 'BO', 'UY'], ['USD', 'BRL', 'MXN', 'COP', 'CLP', 'PEN', 'ARS'], 82, 110),
            'alipay' => $this->method('alipay', 'alipay', '支付宝', '中国主流钱包和跨境支付方式。', Alipay::class, [
                'enabled' => false,
                'method_type' => 'wallet',
                'flow' => 'redirect',
                'sort_order' => 120,
                'popularity_score' => 88,
                'countries' => ['CN', 'HK', 'MO', 'SG', 'MY'],
                'country_popularity' => $this->scores(['CN' => 100, 'HK' => 82, 'MO' => 80, 'SG' => 70, 'MY' => 68]),
                'currencies' => ['CNY', 'HKD', 'USD'],
                'required_config' => ['app_id', 'merchant_id', 'public_key', 'private_key'],
                'config' => [
                    'environment' => 'sandbox',
                    'app_id' => '',
                    'merchant_id' => '',
                    'public_key' => '',
                    'private_key' => '',
                    'notify_url' => '',
                    'return_url' => '',
                    'product_code' => 'FAST_INSTANT_TRADE_PAY',
                    'timeout_express' => '30m',
                    'sign_type' => 'RSA2',
                ],
                'config_fields' => array_merge($this->environmentFields(), [
                    $this->field('app_id', 'App ID'),
                    $this->field('merchant_id', '商户 ID'),
                    $this->field('public_key', '支付宝公钥', 'textarea'),
                    $this->field('private_key', '应用私钥', 'textarea'),
                    $this->field('notify_url', '异步通知 URL'),
                    $this->field('return_url', '同步返回 URL'),
                    $this->field('product_code', '产品码'),
                    $this->field('timeout_express', '支付超时时间'),
                    $this->field('sign_type', '签名类型'),
                ]),
            ]),
            'wechatpay' => $this->method('wechatpay', 'wechatpay', '微信支付', '微信生态内 H5、JSAPI、Native 和小程序支付。', WeChatPay::class, [
                'enabled' => false,
                'method_type' => 'wallet',
                'flow' => 'redirect',
                'sort_order' => 130,
                'popularity_score' => 87,
                'countries' => ['CN', 'HK', 'MO', 'SG', 'MY'],
                'country_popularity' => $this->scores(['CN' => 98, 'HK' => 84, 'MO' => 82, 'SG' => 72, 'MY' => 70]),
                'currencies' => ['CNY', 'HKD'],
                'required_config' => ['app_id', 'mch_id', 'api_v3_key'],
                'config' => [
                    'environment' => 'sandbox',
                    'app_id' => '',
                    'mch_id' => '',
                    'api_v3_key' => '',
                    'merchant_cert_path' => '',
                    'notify_url' => '',
                    'trade_type' => 'MWEB',
                    'sign_type' => 'MD5',
                    'scene_info' => '{"h5_info":{"type":"Wap"}}',
                    'spbill_create_ip' => '',
                ],
                'config_fields' => array_merge($this->environmentFields(), [
                    $this->field('app_id', 'App ID'),
                    $this->field('mch_id', '商户号'),
                    $this->field('api_v3_key', 'API v3 密钥', 'password'),
                    $this->field('merchant_cert_path', '商户证书路径'),
                    $this->field('notify_url', '通知 URL'),
                    $this->field('trade_type', '交易类型'),
                    $this->field('sign_type', '签名类型'),
                    $this->field('scene_info', '场景信息', 'textarea'),
                    $this->field('spbill_create_ip', '客户端 IP 覆盖'),
                ]),
            ]),
            'unionpay' => $this->genericMethod('unionpay', 'unionpay', '银联', '中国和亚太银行卡组织支付。', ['CN' => 92, 'HK' => 82, 'MO' => 78, 'SG' => 70], ['CN', 'HK', 'MO', 'SG', 'MY', 'TH', 'KR', 'JP'], ['CNY', 'HKD', 'USD'], 80, 140),
            'lianlian_payin' => $this->genericMethod('lianlian_payin', 'lianlian', '连连支付', '中国跨境收款、钱包和本地付款能力。', ['CN' => 84, 'HK' => 76, 'US' => 68, 'GB' => 66], ['CN', 'HK', 'US', 'GB', 'SG', 'AU', 'JP', 'KR', 'VN', 'TH', 'MY', 'ID', 'PH'], ['CNY', 'USD', 'HKD', 'EUR', 'GBP'], 75, 150),
            'pingpong_payin' => $this->genericMethod('pingpong_payin', 'pingpong', 'PingPong', '中国跨境电商和 B2B 收付款。', ['CN' => 80, 'US' => 70, 'GB' => 68, 'DE' => 66, 'JP' => 64], ['CN', 'US', 'GB', 'DE', 'FR', 'IT', 'ES', 'JP', 'CA', 'AU', 'SG', 'HK'], ['CNY', 'USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD'], 72, 160),
            'payoneer_checkout' => $this->genericMethod('payoneer_checkout', 'payoneer', 'Payoneer Checkout', '跨境企业收单和全球账户收款。', ['US' => 72, 'GB' => 70, 'CN' => 68, 'DE' => 66, 'HK' => 64], $allCountries, $globalCurrencies, 70, 170),
            'wise_business_transfer' => $this->genericMethod('wise_business_transfer', 'wise', 'Wise Business 转账', '多币种银行账户和本地转账收款。', ['GB' => 86, 'EU' => 84, 'US' => 78, 'AU' => 76, 'SG' => 74], $allCountries, $globalCurrencies, 74, 180, 'bank_transfer'),
            'manual_transfer' => $this->method('manual_transfer', 'manual', '银行转账', '下单后通过银行账户、SWIFT、IBAN 或本地账户付款。', ManualTransfer::class, [
                'enabled' => true,
                'is_default' => true,
                'method_type' => 'bank_transfer',
                'flow' => 'offline',
                'sort_order' => 200,
                'popularity_score' => 68,
                'countries' => $allCountries,
                'country_popularity' => $this->scores(['US' => 76, 'GB' => 76, 'DE' => 78, 'SG' => 74, 'AE' => 78]),
                'currencies' => [],
                'config' => [
                    'instructions' => '请将订单金额转入配置的银行账户，并使用订单号作为付款备注。',
                    'account_name' => '',
                    'bank_name' => '',
                    'account_number' => '',
                    'swift_code' => '',
                    'iban' => '',
                    'reference_note' => '请使用订单号作为付款备注。',
                ],
                'config_fields' => [
                    $this->field('instructions', '说明', 'textarea'),
                    $this->field('account_name', '账户名称'),
                    $this->field('bank_name', '开户银行'),
                    $this->field('account_number', '银行账号'),
                    $this->field('swift_code', 'SWIFT/BIC'),
                    $this->field('iban', 'IBAN'),
                    $this->field('reference_note', '付款备注'),
                ],
            ]),
            'b2b_credit_account' => $this->method('b2b_credit_account', 'manual', '信用额度支付', '仅派发 WeShop_Payment::credit_payment_requested 事件，授信处理由监听器完成。', CreditLineEvent::class, [
                'enabled' => false,
                'method_type' => 'credit',
                'flow' => 'event',
                'sort_order' => 210,
                'popularity_score' => 66,
                'countries' => $allCountries,
                'country_popularity' => $this->scores(['US' => 84, 'GB' => 82, 'DE' => 82, 'CN' => 78, 'SG' => 80, 'AE' => 80]),
                'currencies' => [],
                'config' => [
                    'instructions' => '仅限已通过授信审核的 B2B 客户使用。',
                ],
                'config_fields' => [
                    $this->field('instructions', '说明', 'textarea'),
                ],
            ]),
            'cash_on_delivery' => $this->method('cash_on_delivery', 'manual', '货到付款', '配送送达时现场收款。', CashOnDelivery::class, [
                'enabled' => true,
                'method_type' => 'cash',
                'flow' => 'offline',
                'sort_order' => 220,
                'popularity_score' => 42,
                'countries' => ['AE', 'SA', 'KW', 'QA', 'OM', 'BH', 'IN', 'ID', 'TH', 'VN', 'PH'],
                'country_popularity' => $this->scores(['AE' => 76, 'SA' => 74, 'IN' => 72, 'ID' => 68, 'PH' => 66]),
                'currencies' => [],
                'config' => ['instructions' => '配送送达时向客户收款。', 'fee' => '0'],
                'config_fields' => [
                    $this->field('instructions', '说明', 'textarea'),
                    ['key' => 'fee', 'label' => '货到付款手续费', 'type' => 'number', 'step' => '0.01'],
                ],
            ]),
            'ach_debit' => $this->genericMethod('ach_debit', 'stripe', 'ACH Debit', '美国银行账户扣款。', ['US' => 90], ['US'], ['USD'], 70, 230, 'bank_debit'),
            'acss_debit' => $this->genericMethod('acss_debit', 'stripe', 'ACSS Debit', '加拿大银行账户扣款。', ['CA' => 86], ['CA'], ['CAD'], 66, 240, 'bank_debit'),
            'bacs_debit' => $this->genericMethod('bacs_debit', 'stripe', 'Bacs Direct Debit', '英国银行账户扣款。', ['GB' => 88], ['GB'], ['GBP'], 68, 250, 'bank_debit'),
            'becs_debit' => $this->genericMethod('becs_debit', 'stripe', 'BECS Direct Debit', '澳大利亚银行账户扣款。', ['AU' => 84], ['AU'], ['AUD'], 64, 260, 'bank_debit'),
            'sepa_bank_transfer' => $this->genericMethod('sepa_bank_transfer', 'adyen', 'SEPA 银行转账', '欧元区 SEPA 转账和扣款。', ['DE' => 86, 'FR' => 84, 'NL' => 84, 'ES' => 80, 'IT' => 80], ['AT', 'BE', 'CY', 'DE', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PT', 'SI', 'SK'], ['EUR'], 72, 270, 'bank_transfer'),
            'ideal' => $this->genericMethod('ideal', 'adyen', 'iDEAL', '荷兰实时银行支付。', ['NL' => 100], ['NL'], ['EUR'], 78, 280, 'local_bank'),
            'open_banking_pay_by_bank' => $this->genericMethod('open_banking_pay_by_bank', 'adyen', 'Open Banking / Pay by Bank', '英国和欧洲开放银行账户支付。', ['GB' => 95, 'IE' => 86, 'DE' => 84, 'NL' => 82, 'FR' => 80, 'ES' => 78, 'SE' => 76], ['GB', 'IE', 'DE', 'NL', 'FR', 'ES', 'SE', 'FI', 'NO', 'DK'], ['GBP', 'EUR', 'SEK', 'NOK', 'DKK'], 73, 285, 'local_bank'),
            'klarna' => $this->genericMethod('klarna', 'klarna', 'Klarna', '欧洲和北美先买后付。', ['SE' => 94, 'DE' => 86, 'NO' => 84, 'FI' => 82, 'US' => 74, 'GB' => 72], ['SE', 'NO', 'FI', 'DK', 'DE', 'AT', 'NL', 'GB', 'US'], ['SEK', 'NOK', 'EUR', 'DKK', 'GBP', 'USD'], 72, 290, 'bnpl'),
            'pix' => $this->genericMethod('pix', 'ebanx', 'Pix', '巴西实时二维码和银行支付。', ['BR' => 100], ['BR'], ['BRL'], 82, 300, 'real_time_bank'),
            'spei' => $this->genericMethod('spei', 'dlocal', 'SPEI', '墨西哥实时银行转账。', ['MX' => 94], ['MX'], ['MXN'], 74, 310, 'real_time_bank'),
            'pse' => $this->genericMethod('pse', 'dlocal', 'PSE', '哥伦比亚银行跳转支付。', ['CO' => 94], ['CO'], ['COP'], 72, 320, 'local_bank'),
            'upi' => $this->genericMethod('upi', 'razorpay', 'UPI', '印度实时支付网络。', ['IN' => 100], ['IN'], ['INR'], 82, 330, 'real_time_bank'),
            'paynow' => $this->genericMethod('paynow', 'stripe', 'PayNow', '新加坡实时二维码支付。', ['SG' => 90], ['SG'], ['SGD'], 70, 340, 'real_time_bank'),
            'payto' => $this->genericMethod('payto', 'stripe', 'PayTo', '澳大利亚实时账户支付。', ['AU' => 82], ['AU'], ['AUD'], 64, 350, 'real_time_bank'),
            'mobile_money_mpesa' => $this->genericMethod('mobile_money_mpesa', 'flutterwave', 'M-Pesa', '肯尼亚、坦桑尼亚等东非移动钱包。', ['KE' => 100, 'TZ' => 94], ['KE', 'TZ', 'UG', 'RW'], ['KES', 'TZS', 'UGX', 'RWF', 'USD'], 74, 360, 'mobile_money'),
            'local_virtual_account' => $this->genericMethod('local_virtual_account', 'airwallex', '本地虚拟账户', '为客户分配本地收款账号，适合 B2B 银行转账和跨境收款。', ['SG' => 88, 'HK' => 86, 'US' => 80, 'GB' => 78, 'AU' => 78, 'DE' => 76], $allCountries, $globalCurrencies, 69, 365, 'bank_transfer'),
            'flutterwave' => $this->genericMethod('flutterwave', 'flutterwave', 'Flutterwave', '非洲多国卡、银行和移动钱包聚合。', ['NG' => 92, 'KE' => 84, 'GH' => 82, 'ZA' => 78], ['NG', 'KE', 'GH', 'ZA', 'UG', 'TZ', 'RW', 'ZM', 'CI', 'SN', 'CM'], ['NGN', 'KES', 'GHS', 'ZAR', 'USD'], 76, 370),
            'paystack' => $this->genericMethod('paystack', 'paystack', 'Paystack', '尼日利亚、加纳、南非本地卡和银行支付。', ['NG' => 94, 'GH' => 86, 'ZA' => 78], ['NG', 'GH', 'ZA', 'KE'], ['NGN', 'GHS', 'ZAR', 'KES', 'USD'], 74, 380),
            'mercado_pago' => $this->genericMethod('mercado_pago', 'mercado_pago', 'Mercado Pago', '拉美钱包、卡和现金券。', ['AR' => 94, 'BR' => 90, 'MX' => 86, 'CL' => 80, 'CO' => 78], ['AR', 'BR', 'MX', 'CL', 'CO', 'PE', 'UY'], ['ARS', 'BRL', 'MXN', 'CLP', 'COP', 'PEN', 'UYU'], 78, 390),
            'xendit' => $this->genericMethod('xendit', 'xendit', 'Xendit', '东南亚卡、虚拟账户、电子钱包和 QR。', ['ID' => 94, 'PH' => 90, 'MY' => 78, 'TH' => 76, 'VN' => 74], ['ID', 'PH', 'MY', 'TH', 'VN'], ['IDR', 'PHP', 'MYR', 'THB', 'VND', 'USD'], 76, 400),
            'midtrans' => $this->genericMethod('midtrans', 'midtrans', 'Midtrans', '印尼卡、银行转账、便利店和电子钱包。', ['ID' => 92], ['ID'], ['IDR'], 70, 410),
            'hyperpay' => $this->genericMethod('hyperpay', 'hyperpay', 'HyperPay', '中东地区卡、本地银行和钱包支付。', ['SA' => 90, 'AE' => 86, 'BH' => 80, 'KW' => 78, 'QA' => 76, 'OM' => 74], ['SA', 'AE', 'BH', 'KW', 'QA', 'OM', 'JO', 'EG'], ['SAR', 'AED', 'BHD', 'KWD', 'QAR', 'OMR', 'JOD', 'EGP', 'USD'], 74, 420),
            'paytabs' => $this->genericMethod('paytabs', 'paytabs', 'PayTabs', '海湾和中东商户常用托管收银台。', ['AE' => 84, 'SA' => 84, 'BH' => 80, 'KW' => 76, 'QA' => 74], ['AE', 'SA', 'BH', 'KW', 'QA', 'OM', 'EG', 'JO'], ['AED', 'SAR', 'BHD', 'KWD', 'QAR', 'OMR', 'EGP', 'JOD', 'USD'], 70, 430),
            'razorpay' => $this->genericMethod('razorpay', 'razorpay', 'Razorpay', '印度卡、UPI、钱包和网银聚合。', ['IN' => 96], ['IN'], ['INR'], 78, 440),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function getCountryCodes(): array
    {
        return array_values(Countries::getCountryCodes());
    }

    /**
     * @return array<int, array{code: string, name: string}>
     */
    public function getCountryOptions(string $locale = 'zh_Hans_CN'): array
    {
        $options = [];
        foreach ($this->getCountryCodes() as $code) {
            $name = Countries::getName($code, $locale);
            $options[] = [
                'code' => $code,
                'name' => $name !== '' ? $name : $code,
            ];
        }

        return $options;
    }

    /**
     * @param array<string, int> $scores
     * @return array<string, int>
     */
    private function scores(array $scores): array
    {
        $normalized = [];
        foreach ($scores as $country => $score) {
            $country = strtoupper((string) $country);
            if (strlen($country) !== 2) {
                continue;
            }
            $normalized[$country] = (int) $score;
        }

        return $normalized;
    }

    /**
     * @param array<string, int> $countryScores
     * @param array<int, string> $countries
     * @param array<int, string> $currencies
     */
    private function genericMethod(
        string $code,
        string $providerCode,
        string $title,
        string $description,
        array $countryScores,
        array $countries,
        array $currencies,
        int $popularityScore,
        int $sortOrder,
        string $methodType = 'aggregator'
    ): array {
        return $this->method($code, $providerCode, $title, $description, GenericHostedRedirect::class, [
            'enabled' => false,
            'method_type' => $methodType,
            'flow' => 'redirect',
            'sort_order' => $sortOrder,
            'popularity_score' => $popularityScore,
            'countries' => $countries,
            'country_popularity' => $this->scores($countryScores),
            'currencies' => $currencies,
            'required_config' => ['api_url', 'api_key', 'merchant_id', 'return_url', 'notify_url'],
            'config' => [
                'environment' => 'sandbox',
                'sandbox_api_url' => '',
                'live_api_url' => '',
                'sandbox_api_key' => '',
                'live_api_key' => '',
                'merchant_id' => '',
                'return_url' => '',
                'notify_url' => '',
                'webhook_secret' => '',
            ],
            'config_fields' => array_merge($this->environmentFields(), [
                $this->field('sandbox_api_url', '沙盒 API URL'),
                $this->field('live_api_url', '正式 API URL'),
                $this->field('sandbox_api_key', '沙盒 API Key', 'password'),
                $this->field('live_api_key', '正式 API Key', 'password'),
                $this->field('merchant_id', '商户 ID'),
                $this->field('return_url', 'Return URL'),
                $this->field('notify_url', 'Webhook/Notify URL'),
                $this->field('webhook_secret', 'Webhook 签名密钥', 'password'),
            ]),
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function method(string $code, string $providerCode, string $title, string $description, string $providerClass, array $extra = []): array
    {
        $countries = array_values(array_unique(array_map(
            static fn(mixed $value): string => strtoupper((string) $value),
            (array) ($extra['countries'] ?? [])
        )));
        $countryPopularity = \is_array($extra['country_popularity'] ?? null) ? $extra['country_popularity'] : [];
        $countryTags = array_values(array_unique(array_merge($countries, array_keys($countryPopularity))));
        sort($countryTags);

        return array_replace([
            'code' => $code,
            'provider_code' => $providerCode,
            'title' => $title,
            'description' => $description,
            'provider' => $providerClass,
            'enabled' => false,
            'is_default' => false,
            'sort_order' => 0,
            'popularity_score' => 0,
            'icon' => '',
            'areas' => ['frontend', 'backend', 'api'],
            'currencies' => [],
            'countries' => $countries,
            'country_tags' => $countryTags,
            'country_popularity' => $countryPopularity,
            'method_type' => 'aggregator',
            'flow' => 'redirect',
            'config' => [],
            'config_fields' => [],
            'required_config' => [],
            'documentation_path' => $providerCode . '/' . $code . '.md',
        ], $extra, [
            'countries' => $countries,
            'country_tags' => $countryTags,
            'country_popularity' => $countryPopularity,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function provider(string $code, string $title, string $type, string $region): array
    {
        return [
            'code' => $code,
            'title' => $title,
            'provider_type' => $type,
            'region' => $region,
            'status' => true,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function environmentFields(): array
    {
        return [
            [
                'key' => 'environment',
                'label' => '运行环境',
                'type' => 'select',
                'options' => [
                    'sandbox' => '沙盒',
                    'live' => '正式',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function field(string $key, string $label, string $type = 'text'): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'type' => $type,
        ];
    }
}
