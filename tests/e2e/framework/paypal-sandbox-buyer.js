/**
 * Committed PayPal sandbox buyer. Env PAYPAL_SANDBOX_BUYER_EMAIL / PASSWORD still wins.
 */
const fs = require('fs');
const path = require('path');

const buyerFile = path.resolve(
  __dirname,
  '../../../app/code/Weline/Payment/etc/platform/paypal.sandbox.buyer.json'
);

function loadBundledBuyer() {
  const raw = fs.readFileSync(buyerFile, 'utf8');
  const data = JSON.parse(raw);
  const email = String(data.email || '').trim();
  const password = String(data.password || '').trim();
  if (email === '' || password === '') {
    throw new Error('PayPal sandbox buyer file is missing email or password: ' + buyerFile);
  }
  return { email, password };
}

const bundled = loadBundledBuyer();

module.exports = {
  BUYER_EMAIL: process.env.PAYPAL_SANDBOX_BUYER_EMAIL || bundled.email,
  BUYER_PASS: process.env.PAYPAL_SANDBOX_BUYER_PASSWORD || bundled.password,
  buyerFile,
};
