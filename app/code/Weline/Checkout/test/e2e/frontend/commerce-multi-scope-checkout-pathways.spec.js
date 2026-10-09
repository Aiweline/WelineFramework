/**
 * 多范围购物结账通路（录制固化）：
 * - 匿名（guest）结账：加购 → 结账 → 优惠券 → 留言
 * - 登录结账：会话登录 → 同上
 * - 批发（tob）结账：批发加购 → 定金提示 → 券不可用 → 留言仍可用
 *
 * @weline-e2e-spec { module: Weline_Checkout, type: pathway, layer: frontend, feature: commerce-multi-scope }
 * @weline-e2e-runtime wls
 */

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
  prepareCustomer,
  prepareWholesaleCustomer,
  addToCartMode,
  openCheckout,
  ensureCheckoutAddress,
  applyCouponViaUi,
  fillOrderNoteViaUi,
  probeWholesaleSurfaces,
  applyCouponExpectFailForTob,
  submitCheckoutCaptureEvidence,
  loginAsCustomerApi,
  assertStep,
} = require('./commerce-multi-scope-checkout-pathways-helpers');

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

moduleDescribe(test, MODULE, '多范围购物结账通路', () => {
  test.setTimeout(360000);

  moduleCase(
    test,
    { module: MODULE, id: 'CR-GUEST-CHECKOUT-COUPON-NOTE' },
    '匿名结账：加购→结账页→优惠券→订单留言',
    async ({ page }) => {
      const prepared = (prepareCustomer().fixture) || {};
      const pdp = String((prepared && prepared.pdp_path) || process.env.CHECKOUT_PDP_PATH || '').trim()
        || '/product/qi-ta-xin-kuan-hei-shan-cha-han-fu-nu-chun-qiu-ji-he-zi-qun-guo-11ae8001/';
      const guestEmail = String((prepared && prepared.guest_email) || `e2e.guest.${Date.now()}@example.test`);

      await addToCartMode(page, gotoFrontend, pdp, 'toc');
      await openCheckout(page, gotoFrontend);
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.locator('[data-testid="checkout-form-page"]')).toBeVisible({ timeout: 30000 });

      await ensureCheckoutAddress(page, guestEmail);
      const couponRes = await applyCouponViaUi(page, COUPON);
      assertStep(couponOk(couponRes), 'guest.coupon', couponRes);
      await expect(page.locator('[data-testid="checkout-coupon"]')).toBeVisible();

      const note = await fillOrderNoteViaUi(page, NOTE_TEXT);
      assertStep(note.saved.includes(NOTE_TEXT) || note.saved === NOTE_TEXT, 'guest.note', note);

      const evidence = await submitCheckoutCaptureEvidence(page, {
        scope: 'guest',
        mode: 'toc',
        email: guestEmail,
        coupon: COUPON,
        note: NOTE_TEXT,
      });
      expect(evidence.order_uuid).toMatch(/[0-9a-f-]{16,}/i);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CR-LOGIN-CHECKOUT-COUPON-NOTE' },
    '登录结账：会话登录→加购→结账→优惠券→留言',
    async ({ page }) => {
      const prepared = (prepareCustomer().fixture) || {};
      const email = String(prepared.email || '');
      const password = String(prepared.password || '');
      const pdp = String(prepared.pdp_path || '').trim()
        || '/product/qi-ta-xin-kuan-hei-shan-cha-han-fu-nu-chun-qiu-ji-he-zi-qun-guo-11ae8001/';
      assertStep(!!email && !!password, 'login.prepare', prepared);

      await loginAsCustomerApi(page, gotoFrontend, email, password);
      await addToCartMode(page, gotoFrontend, pdp, 'toc');
      await openCheckout(page, gotoFrontend);
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.locator('[data-testid="checkout-form-page"]')).toBeVisible({ timeout: 30000 });

      await ensureCheckoutAddress(page, email);
      const couponRes = await applyCouponViaUi(page, COUPON);
      assertStep(couponOk(couponRes), 'login.coupon', couponRes);

      const noteText = `${NOTE_TEXT}-login`;
      const note = await fillOrderNoteViaUi(page, noteText);
      assertStep(
        note.saved.includes(NOTE_TEXT) || note.saved.includes('login'),
        'login.note',
        note
      );
      await expect(page.locator('[data-testid="checkout-coupon"]')).toBeVisible();

      const evidence = await submitCheckoutCaptureEvidence(page, {
        scope: 'login',
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
    { module: MODULE, id: 'CR-WHOLESALE-CHECKOUT-NOTE' },
    '批发结账：tob加购→定金提示→券拒绝→留言可用',
    async ({ page }) => {
      const prepared = (prepareWholesaleCustomer().fixture) || {};
      const email = String(prepared.email || '');
      const password = String(prepared.password || '');
      const pdp = String(prepared.pdp_path || '').trim()
        || '/product/qi-ta-xin-kuan-hei-shan-cha-han-fu-nu-chun-qiu-ji-he-zi-qun-guo-11ae8001/';
      assertStep(!!(prepared.wholesale && prepared.wholesale.assigned), 'wholesale.membership', prepared.wholesale);

      await loginAsCustomerApi(page, gotoFrontend, email, password);
      await addToCartMode(page, gotoFrontend, pdp, 'tob');
      await openCheckout(page, gotoFrontend);
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.locator('[data-testid="checkout-form-page"]')).toBeVisible({ timeout: 30000 });

      // Wholesale surfaces may render after cart-type hydrate.
      await page.waitForTimeout(2000);
      const surfaces = await probeWholesaleSurfaces(page);
      assertStep(
        surfaces.deposit_present || surfaces.deposit_visible,
        'wholesale.deposit_note',
        surfaces
      );

      const couponRes = await applyCouponExpectFailForTob(page, COUPON);
      assertStep(couponRejected(couponRes), 'wholesale.coupon_rejected', couponRes);

      const noteText = `${NOTE_TEXT}-wholesale`;
      const note = await fillOrderNoteViaUi(page, noteText);
      assertStep(
        note.saved.includes(NOTE_TEXT) || note.saved.includes('wholesale'),
        'wholesale.note',
        note
      );

      const evidence = await submitCheckoutCaptureEvidence(page, {
        scope: 'wholesale',
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
});
