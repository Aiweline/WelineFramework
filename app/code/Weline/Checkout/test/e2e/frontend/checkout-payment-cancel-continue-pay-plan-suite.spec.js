/**
 * 支付取消后继续支付 · 全路径计划套件
 *
 * 含壳层 CTA smoke + **真实业务通路**（建单→续付→回报 order_uuid）。
 *
 * @weline-e2e-spec { module: Weline_Checkout, type: plan-suite, layer: frontend, feature: payment-cancel-continue-pay }
 * @weline-e2e-runtime wls
 */

require('./checkout-success-actions.spec.js');
require('./checkout-continue-pay-real-pathway.spec.js');
