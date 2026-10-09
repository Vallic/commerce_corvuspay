# Commerce CorvusPay

CorvusPay offsite card gateway for PHP 8.4+, Drupal 11.4+, and Commerce 3.1+. Uses the hosted checkout POST protocol (API version 1.6), with sale, preauthorization, installments, refunds, and consent-based saved cards. It uses Drupal's HTTP client and PHP OpenSSL; no provider SDK is required.

## Setup

Enable `commerce_corvuspay`, then add a CorvusPay gateway at Commerce → Configuration → Payment gateways. Start in **Test** mode. Supply the CorvusPay store ID, API secret and the settlement currency agreed with your acquirer. Billing information is required. Checkout's payment process pane can use **capture** for immediate sale or **authorize** to preauthorize and capture/void later.

The hosted checkout URLs are fixed:

- Test: `https://wallet.test.corvuspay.com/checkout/`
- Live: `https://wallet.corvuspay.com/checkout/`

Commerce generates the success and cancellation URLs per order. They must use HTTPS, contain no query parameters, and fit CorvusPay's 200-byte limit. Return signatures cover all returned query parameters except `signature`; do not add tracking parameters to callback URLs.

Only card payments are supported. The request hides other hosted payment tabs. Configure the merchant account for card payments as well. Non-card success payloads without a card approval code are rejected.

### Installments

Choose one of:

- **One-time payment**: no installment parameters.
- **Fixed count**: all customers using this gateway request the configured count.
- **Order field**: read a single-value integer field from the order. Empty or zero means one-time payment; 2–99 means installments, subject to the configured maximum. A missing or incorrectly typed field fails checkout.

Set the maximum to your acquirer agreement. Fixed-count requests restrict the available cards to those supporting the requested count; customers cannot change it on CorvusPay. The module does not calculate discounts or installment surcharges.

### Secrets

Use a per-environment override for production secrets. For a gateway with machine name `corvuspay`:

```php
$config['commerce_payment.commerce_payment_gateway.corvuspay']['configuration']['secret_key'] = getenv('CORVUSPAY_SECRET_KEY') ?: '';
$config['commerce_payment.commerce_payment_gateway.corvuspay']['configuration']['store_id'] = getenv('CORVUSPAY_STORE_ID') ?: '';
$config['commerce_payment.commerce_payment_gateway.corvuspay']['configuration']['api_certificate_path'] = getenv('CORVUSPAY_CERTIFICATE_PATH') ?: '';
$config['commerce_payment.commerce_payment_gateway.corvuspay']['configuration']['api_certificate_password'] = getenv('CORVUSPAY_CERTIFICATE_PASSWORD') ?: '';
```

The password field never renders the existing secret. Leaving it blank retains the stored key. A key entered into the configuration form is stored in Drupal configuration and will be exported; use the override for live keys. Use distinct merchant credentials for test and live environments.

The management API requires a CorvusPay PKCS#12 client certificate and its password. For each operation, the module converts it to short-lived permission-restricted PEM files for HTTPS, then removes them. Keep the certificate path and password outside exported configuration. Capture, void, refund, and saved-card charges fail closed if the certificate is unavailable.

## Payment lifecycle

Before redirect, the gateway saves a `new` Commerce payment with a cryptographically random 32-character remote ID. Each retry gets a fresh ID. The order's `commerce_corvuspay` data records store ID, installment count, capture preference, and save-card preference per attempt. No card numbers or security codes reach Drupal.

A success return must have a valid HMAC-SHA256 signature, card approval code, and matching persisted attempt, order, gateway, environment and settlement currency. The saved payment amount must still equal the current unpaid order balance. Commerce holds the order lock while the callback completes the existing payment and updates paid totals. Repeated successful callbacks do not create additional payments. The approval code is stored in the attempt metadata.

Cancellation keeps checkout resumable and leaves the attempt unpaid. Because cancellation is unsigned, it does not delete or invalidate an attempt: a delayed authenticated success may still arrive. `new` attempts are evidence of a redirect, not evidence that no charge occurred. Authorize mode stores an `authorization` payment on signed success; capture and void then call CorvusPay's certificate-authenticated API and update Commerce only after a successful XML response.

### Saved cards

Add a single-value **boolean** field to the order type for customer consent and expose it as an unchecked checkbox in checkout with clear terms. Set its machine name in **Saved-card consent order field**. The module sends `subscription=true` only when this field is checked. After a valid signed return, it verifies the transaction through CorvusPay's certificate-backed status API, then stores the returned `account_id` as a Commerce credit-card payment method and only the card brand, last four digits, and expiry for display. If this optional status lookup fails, the payment is still recorded and the card is not saved. Subsequent payments use CorvusPay's `next_sub_payment` API. Confirm with CorvusPay/acquirer that subscription account IDs may be reused for the intended customer-initiated purchases before enabling this field. CorvusPay does not document a remote token-revocation API: deleting the Commerce method removes the local reference, and revocation must be handled through the provider portal.

There is no standalone save-card redirect in the supplied manual; a card is saved only during a successful consented order payment. No reusable token is created on an ordinary one-time payment.

## Notifications and merchant verification

CorvusPay's management API supports full and partial capture, voids, full and partial refunds, and subsequent subscription charges. For partial refunds, CorvusPay's `new_amount` is the amount the merchant keeps after the refund, so successive refunds subtract both prior and current refunded amounts from the captured amount. The module computes that retained amount from Commerce's `refunded_amount`.

Commerce also exposes a notification route and invokes the gateway's `onNotify()` method. This CorvusPay hosted-form integration uses the shopper's signed success return and cancellation return; the manual and SDK do not document a server-to-server webhook for these redirect payments. `onNotify()` currently returns HTTP 501 because there is no CorvusPay event format to authenticate and map. The separate certificate-backed transaction status API can reconcile a payment when the shopper does not return. That is a merchant-initiated status check, not a CorvusPay notification.

If the customer pays and closes the provider page without returning, the payment remains `new` in Commerce. Check the provider portal before retrying or fulfilling that order. Automated reconciliation requires CorvusPay's separate certificate-authenticated status API. There is no webhook URL to register for this hosted-form integration; the Commerce notification endpoint returns HTTP 501.

Before live use, test sale, preauthorization, full/partial capture, void, full/partial and successive refunds, saved-card consent and subsequent charge, cancellation, decline, repeated return, fixed installments with eligible/ineligible cards, and a paid transaction where the customer never returns. Confirm settlement currency, installment agreements, subscription-token reuse and card-only merchant configuration with CorvusPay.

## Development checks

From a Drupal project with core-dev and PHPUnit installed:

```bash
vendor/bin/phpunit web/modules/contrib/commerce_corvuspay/tests/src/Unit
SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit web/modules/contrib/commerce_corvuspay/tests/src/Kernel
vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/contrib/commerce_corvuspay
```

Kernel tests create isolated prefixed tables and exercise real Commerce entities; use a local test database. They do not contact CorvusPay or require merchant credentials.
