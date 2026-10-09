/**
 * B2B 测试店（非默认店渠）购物通路录制固化：
 * - 店铺 e2e_b2b_commerce / 渠道 web（display_type=b2b）
 * - 匿名 / 登录 / 批发结账（券+留言）
 * - 快捷支付 express
 * - 快捷购买 + 找朋友代付（HelpPay）
 *
 * @weline-e2e-spec { module: Weline_Checkout, type: pathway, layer: frontend, feature: commerce-b2b-store }
 * @weline-e2e-runtime wls
 */

const path = require('path');
const {
  test,
  expect,
  gotoFrontend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const {
  COUPON,
  NOTE_TEXT,
  prepareCustomerOnB2bStore,
  prepareWholesaleOnB2bStore,
  addToCartMode,
  openCheckout,
  ensureCheckoutAddress,
  applyCouponViaUi,
  fillOrderNoteViaUi,
  probeWholesaleSurfaces,
  applyCouponExpectFailForTob,
  submitCheckoutCaptureEvidence,
  loginAsCustomerApi,
  runExpressCheckoutCaptureEvidence,
  assertStep,
} = require('./commerce-b2b-store-checkout-pathways-helpers');

const {
  gotoPdp,
  runQuickPayCaptureEvidence,
  runHelpPayFriendCaptureEvidence,
} = require(path.resolve(
  __dirname,
  '../../../../HelpPay/Test/e2e/frontend/commerce-helppay-pathways-helpers.js'
));

const MODULE = 'Weline_Checkout';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

function couponOk(res) {
  if (!res || res.__error) return false;
  if (res.success === true) return true;
  const code = String(res.coupon_code || (res.data && res.data.coupon_code) || '').toUpperCase();
  return code === COUPON;
}

function couponRejected(res) {
  if (!res) return true;
  if (res.success === false) return true;
  if (res.__error) return true;
  const msg = JSON.stringify(res).toLowerCase();
  return /unavailable|disabled|wholesale|tob|不可用|批发|forbid|denied|invalid/.test(msg);
}

function defaultPdp(prepared) {
  return String((prepared && prepared.pdp_path) || process.env.CHECKOUT_PDP_PATH || '').trim()
    || '/product/qi-ta-xin-kuan-hei-shan-cha-han-fu-nu-chun-qiu-ji-he-zi-qun-guo-11ae8001/';
}

moduleDescribe(test, MODULE, 'B2B测试店购物结账通路', () => {
  test.setTimeout(420000);

  moduleCase(
    test,
    { module: MODULE, id: 'CR-B2B-STORE-GUEST-CHECKOUT' },
    'B2B测试店·匿名结账：加购→券→留言→order_uuid',
    async ({ page }) => {
      const prepared = (prepareCustomerOnB2bStore().fixture) || {};
      const pdp = defaultPdp(prepared);
      const guestEmail = String(prepared.guest_email || `e2e.b2b.guest.${Date.now()}@example.test`);

      await addToCartMode(page, gotoFrontend, pdp, 'toc');
      await openCheckout(page, gotoFrontend);
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.locator('[data-testid="checkout-form-page"]')).toBeVisible({ timeout: 30000 });

      await ensureCheckoutAddress(page, guestEmail);
      const couponRes = await applyCouponViaUi(page, COUPON);
      assertStep(couponOk(couponRes), 'b2b.guest.coupon', couponRes);

      const note = await fillOrderNoteViaUi(page, `${NOTE_TEXT}-b2b-guest`);
      assertStep(note.saved.includes(NOTE_TEXT) || note.saved.includes('b2b'), 'b2b.guest.note', note);

      const evidence = await submitCheckoutCaptureEvidence(page, {
        scope: 'b2b_store_guest',
        mode: 'toc',
        email: guestEmail,
        coupon: COUPON,
        note: `${NOTE_TEXT}-b2b-guest`,
      });
      expect(evidence.order_uuid).toMatch(/[0-9a-f-]{16,}/i);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CR-B2B-STORE-LOGIN-CHECKOUT' },
    'B2B测试店·登录结账：登录→券→留言→order_uuid',
    async ({ page }) => {
      const prepared = (prepareCustomerOnB2bStore().fixture) || {};
      const email = String(prepared.email || '');
      const password = String(prepared.password || '');
      const pdp = defaultPdp(prepared);
      assertStep(!!email && !!password, 'b2b.login.prepare', prepared);

      await loginAsCustomerApi(page, gotoFrontend, email, password);
      await addToCartMode(page, gotoFrontend, pdp, 'toc');
      await openCheckout(page, gotoFrontend);
      await expect(page.locator('[data-testid="checkout-form-page"]')).toBeVisible({ timeout: 30000 });

      await ensureCheckoutAddress(page, email);
      const couponRes = await applyCouponViaUi(page, COUPON);
      assertStep(couponOk(couponRes), 'b2b.login.coupon', couponRes);

      const noteText = `${NOTE_TEXT}-b2b-login`;
      const note = await fillOrderNoteViaUi(page, noteText);
      assertStep(note.saved.includes(NOTE_TEXT) || note.saved.includes('b2b'), 'b2b.login.note', note);

      const evidence = await submitCheckoutCaptureEvidence(page, {
        scope: 'b2b_store_login',
        mode: 'toc',
        email,
        coupon: COUPON,
        note: noteText,
      });
      expect(evidence.order_uuid).toMatch(/[0-9a-f-]{16,}/i);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CR-B2B-STORE-WHOLESALE-CHECKOUT' },
    'B2B测试店·批发结账：tob→定金→券拒→留言→order_uuid',
    async ({ page }) => {
      const prepared = (prepareWholesaleOnB2bStore().fixture) || {};
      const email = String(prepared.email || '');
      const password = String(prepared.password || '');
      const pdp = defaultPdp(prepared);
      assertStep(!!(prepared.wholesale && prepared.wholesale.assigned), 'b2b.wholesale.membership', prepared.wholesale);

      await loginAsCustomerApi(page, gotoFrontend, email, password);
      await addToCartMode(page, gotoFrontend, pdp, 'tob');
      await openCheckout(page, gotoFrontend);
      await expect(page.locator('[data-testid="checkout-form-page"]')).toBeVisible({ timeout: 30000 });

      await page.waitForTimeout(2000);
      const surfaces = await probeWholesaleSurfaces(page);
      assertStep(
        surfaces.deposit_present || surfaces.deposit_visible,
        'b2b.wholesale.deposit_note',
        surfaces
      );

      const couponRes = await applyCouponExpectFailForTob(page, COUPON);
      assertStep(couponRejected(couponRes), 'b2b.wholesale.coupon_rejected', couponRes);

      const noteText = `${NOTE_TEXT}-b2b-wholesale`;
      const note = await fillOrderNoteViaUi(page, noteText);
      assertStep(note.saved.includes(NOTE_TEXT) || note.saved.includes('wholesale'), 'b2b.wholesale.note', note);

      const evidence = await submitCheckoutCaptureEvidence(page, {
        scope: 'b2b_store_wholesale',
        mode: 'tob',
        email,
        coupon: '',
        note: noteText,
        pdp,
        gotoFrontend,
      });
      expect(evidence.order_uuid).toMatch(/[0-9a-f-]{16,}/i);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CR-B2B-STORE-EXPRESS-CHECKOUT' },
    'B2B测试店·快捷支付：加购→startExpressCheckout→order_uuid',
    async ({ page }) => {
      const prepared = (prepareCustomerOnB2bStore().fixture) || {};
      const pdp = defaultPdp(prepared);
      const guestEmail = String(prepared.guest_email || `e2e.b2b.express.${Date.now()}@example.test`);

      await addToCartMode(page, gotoFrontend, pdp, 'toc');
      await expect(page.locator('body')).not.toContainText(FATAL);

      const evidence = await runExpressCheckoutCaptureEvidence(page, {
        scope: 'b2b_store_express',
        email: guestEmail,
      });
      expect(evidence.order_uuid).toMatch(/[0-9a-f-]{16,}/i);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CR-B2B-STORE-HELPPAY-QUICK' },
    'B2B测试店·快捷购买：createQuickPay→startQuickPayment→transaction_no',
    async ({ page }) => {
      const prepared = (prepareCustomerOnB2bStore().fixture) || {};
      const pdp = defaultPdp(prepared);

      await gotoPdp(page, gotoFrontend, pdp);
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.locator('[data-testid="product-quick-pay"], [data-helppay-quick-pay]').first())
        .toBeVisible({ timeout: 30000 });

      const evidence = await runQuickPayCaptureEvidence(page, { scope: 'b2b_store_helppay_quick' });
      assertStep(!!evidence.transaction_no, 'b2b.helppay.quick.txn', evidence);
      expect(evidence.transaction_no).toMatch(/PAY|TXN|[A-Z0-9]{8,}/i);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CR-B2B-STORE-HELPPAY-FRIEND' },
    'B2B测试店·找朋友代付：createHelpPay→startPayerPayment→transaction_no',
    async ({ page }) => {
      const prepared = (prepareCustomerOnB2bStore().fixture) || {};
      const pdp = defaultPdp(prepared);

      await gotoPdp(page, gotoFrontend, pdp);
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.locator('[data-testid="product-help-pay"], [data-helppay-open], [data-testid="help-pay-open"]').first())
        .toBeVisible({ timeout: 30000 });

      const evidence = await runHelpPayFriendCaptureEvidence(page, { scope: 'b2b_store_helppay_friend' });
      assertStep(!!evidence.transaction_no, 'b2b.helppay.friend.txn', evidence);
      expect(evidence.transaction_no).toMatch(/PAY|TXN|[A-Z0-9]{8,}/i);
    }
  );
});
