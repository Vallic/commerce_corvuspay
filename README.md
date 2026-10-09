# Commerce CorvusPay

CorvusPay offsite card gateway for PHP 8.4+, Drupal 11.4+, and Commerce 3.1+. Uses the hosted checkout POST protocol (API version 1.6), with immediate sale and optional fixed installments. No SDK or additional runtime dependencies.

## Setup

Enable `commerce_corvuspay`, then add a CorvusPay gateway at Commerce → Configuration → Payment gateways. Start in **Test** mode. Supply the CorvusPay store ID, API secret and the settlement currency agreed with your acquirer. Billing information is required. Checkout's payment process pane must use **capture**, not authorization.

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
```

The password field never renders the existing secret. Leaving it blank retains the stored key. A key entered into the configuration form is stored in Drupal configuration and will be exported; use the override for live keys. Use distinct merchant credentials for test and live environments.

## Payment lifecycle

Before redirect, the gateway saves a `new` Commerce payment with a cryptographically random 32-character remote ID. Each retry gets a fresh ID. The order's `commerce_corvuspay` data records store ID and installment count per attempt. No card numbers or security codes reach Drupal.

A success return must have a valid HMAC-SHA256 signature, card approval code, and matching persisted attempt, order, gateway, environment and settlement currency. The saved payment amount must still equal the current unpaid order balance. Commerce holds the order lock while the callback completes the existing payment and updates paid totals. Repeated successful callbacks do not create additional payments. The approval code is stored in the attempt metadata.

Cancellation keeps checkout resumable and leaves the attempt unpaid. Because cancellation is unsigned, it does not delete or invalidate an attempt: a delayed authenticated success may still arrive. `new` attempts are evidence of a redirect, not evidence that no charge occurred.

## Limitations and merchant verification

This initial version does not provide capture, refunds, saved cards, subscriptions, flexible/per-brand installment selection, or server notifications. Refunds must be handled in the provider portal and reconciled with Commerce separately.

If the customer pays and closes the provider page without returning, the payment remains `new` in Commerce. Check the provider portal before retrying or fulfilling that order. Automated reconciliation requires CorvusPay's separate certificate-authenticated status API and is outside this redirect integration. There is no webhook URL to register; the notification endpoint returns HTTP 501.

Before live use, test successful sale, cancellation, decline, repeated return, fixed installments with eligible and ineligible cards, and a paid transaction where the customer never returns. Confirm settlement currency, installment agreements and card-only merchant configuration with CorvusPay.

## Development checks

From a Drupal project with core-dev and PHPUnit installed:

```bash
vendor/bin/phpunit web/modules/contrib/commerce_corvuspay/tests/src/Unit
SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit web/modules/contrib/commerce_corvuspay/tests/src/Kernel
vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/contrib/commerce_corvuspay
```

Kernel tests create isolated prefixed tables and exercise real Commerce entities; use a local test database. They do not contact CorvusPay or require merchant credentials.
