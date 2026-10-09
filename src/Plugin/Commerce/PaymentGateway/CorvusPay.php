<?php

namespace Drupal\commerce_corvuspay\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_corvuspay\CorvusPayApiClient;
use Drupal\address\AddressInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_corvuspay\Installments;
use Drupal\commerce_corvuspay\Signature;
use Drupal\commerce_corvuspay\PluginForm\PaymentOffsiteForm;
use Drupal\commerce_corvuspay\PluginForm\PaymentMethodAddForm;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsStoredPaymentMethodsInterface;
use Drupal\commerce_payment\PaymentOrderUpdaterInterface;
use Drupal\commerce_payment\PaymentMethodStorageInterface;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Entity\CurrencyInterface;
use Drupal\commerce_price\Price;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides CorvusPay offsite card payments.
 */
#[CommercePaymentGateway(
  id: 'corvuspay',
  label: new TranslatableMarkup('CorvusPay'),
  display_label: new TranslatableMarkup('Credit card'),
  forms: [
    'offsite-payment' => PaymentOffsiteForm::class,
    'add-payment-method' => PaymentMethodAddForm::class,
  ],
  payment_method_types: ['credit_card'],
  requires_billing_information: TRUE,
)]
class CorvusPay extends OffsitePaymentGatewayBase implements SupportsAuthorizationsInterface, SupportsRefundsInterface, SupportsStoredPaymentMethodsInterface {

  /**
   * The payment total updater.
   */
  protected PaymentOrderUpdaterInterface $orderUpdater;

  /**
   * The HTTP client used for CorvusPay's certificate-authenticated API.
   */
  protected ClientInterface $httpClient;

  /**
   * The module logger.
   */
  protected LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->orderUpdater = $container->get('commerce_payment.order_updater');
    $instance->httpClient = $container->get('http_client');
    $instance->logger = $container->get('logger.factory')->get('commerce_corvuspay');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'mode' => 'test',
      'store_id' => '',
      'secret_key' => '',
      'api_certificate_path' => '',
      'api_certificate_password' => '',
      'currency' => 'EUR',
      'installments_mode' => 'none',
      'installments' => 0,
      'installments_field' => '',
      'max_installments' => 12,
      'save_payment_method_field' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['store_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CorvusPay store ID'),
      '#default_value' => $this->configuration['store_id'],
      '#required' => TRUE,
      '#element_validate' => [[static::class, 'validateStoreId']],
    ];
    $form['secret_key'] = [
      '#type' => 'password',
      '#title' => $this->t('API secret key'),
      '#description' => $this->t('Leave blank to retain the existing key. For production, provide the key through a settings.php configuration override.'),
    ];
    $form['api_certificate_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Management API certificate path'),
      '#description' => $this->t('Absolute path to the CorvusPay PKCS#12 certificate. Required for capture, void, refunds, and saved-card charges.'),
      '#default_value' => $this->configuration['api_certificate_path'],
    ];
    $form['api_certificate_password'] = [
      '#type' => 'password',
      '#title' => $this->t('Management API certificate password'),
      '#description' => $this->t('Leave blank to retain the existing password. Use a settings.php configuration override in production.'),
    ];
    $form['currency'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Settlement currency'),
      '#description' => $this->t('The ISO 4217 currency agreed with your acquirer. CorvusPay does not convert currencies.'),
      '#default_value' => $this->configuration['currency'],
      '#required' => TRUE,
      '#maxlength' => 3,
    ];
    $form['installments_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Installments'),
      '#options' => [
        'none' => $this->t('One-time payment'),
        'fixed' => $this->t('Fixed count for this gateway'),
        'order_field' => $this->t('Read count from an order field'),
      ],
      '#default_value' => $this->configuration['installments_mode'],
    ];
    $form['installments'] = [
      '#type' => 'number',
      '#title' => $this->t('Fixed installment count'),
      '#default_value' => $this->configuration['installments'],
      '#min' => 0,
      '#max' => 99,
      '#step' => 1,
    ];
    $form['installments_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Order installment field'),
      '#description' => $this->t('Machine name of a single-value integer field. Zero or an empty field means one-time payment.'),
      '#default_value' => $this->configuration['installments_field'],
    ];
    $form['max_installments'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum installment count'),
      '#default_value' => $this->configuration['max_installments'],
      '#min' => 2,
      '#max' => 99,
      '#step' => 1,
      '#required' => TRUE,
    ];
    $form['save_payment_method_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Saved-card consent order field'),
      '#description' => $this->t('Optional single-value boolean order field containing the customer’s explicit consent to save this card.'),
      '#default_value' => $this->configuration['save_payment_method_field'],
    ];
    return $form;
  }

  /**
   * Validates merchant store identifiers without integer conversion.
   */
  public static function validateStoreId(array &$element, FormStateInterface $form_state, array &$complete_form): void {
    if (!preg_match('/^[1-9][0-9]*$/D', $element['#value'])) {
      $form_state->setError($element, t('Enter a positive numeric CorvusPay store ID.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    if ($values['secret_key'] === '' && $this->configuration['secret_key'] === '') {
      $form_state->setError($form['secret_key'], $this->t('Provide an API secret key.'));
    }
    if (!preg_match('/^[A-Z]{3}$/D', $values['currency'])) {
      $form_state->setError($form['currency'], $this->t('Enter a three-letter uppercase currency code.'));
    }
    if ($values['installments_mode'] === 'fixed') {
      try {
        $parameters = Installments::parameters($values['installments'], (int) $values['max_installments']);
        if (!$parameters) {
          throw new \InvalidArgumentException();
        }
      }
      catch (\InvalidArgumentException) {
        $form_state->setError($form['installments'], $this->t('Enter a count from two to the configured maximum.'));
      }
    }
    if ($values['installments_mode'] === 'order_field' && !preg_match('/^[a-z][a-z0-9_]*$/D', $values['installments_field'])) {
      $form_state->setError($form['installments_field'], $this->t('Enter an order field machine name.'));
    }
    if ($values['save_payment_method_field'] !== '' && !preg_match('/^[a-z][a-z0-9_]*$/D', $values['save_payment_method_field'])) {
      $form_state->setError($form['save_payment_method_field'], $this->t('Enter an order field machine name.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $string_settings = [
        'store_id',
        'currency',
        'installments_mode',
        'installments_field',
        'api_certificate_path',
        'save_payment_method_field',
      ];
      foreach ($string_settings as $key) {
        $this->configuration[$key] = $values[$key];
      }
      foreach (['installments', 'max_installments'] as $key) {
        $this->configuration[$key] = (int) $values[$key];
      }
      if ($values['secret_key'] !== '') {
        $this->configuration['secret_key'] = $values['secret_key'];
      }
      if ($values['api_certificate_password'] !== '') {
        $this->configuration['api_certificate_password'] = $values['api_certificate_password'];
      }
    }
  }

  /**
   * Gets the trusted provider endpoint for the selected environment.
   */
  public function getCheckoutUrl(): string {
    return match ($this->getMode()) {
      'test' => 'https://wallet.test.corvuspay.com/checkout/',
      'live' => 'https://wallet.corvuspay.com/checkout/',
      default => throw new PaymentGatewayException('Unsupported CorvusPay mode.'),
    };
  }

  /**
   * Persists and signs a validated payment attempt before redirect.
   */
  public function prepareRedirect(PaymentInterface $payment, string $return_url, string $cancel_url, bool $capture): array {
    $this->assertCredentials();
    if (!$payment->isNew()) {
      throw new PaymentGatewayException('CorvusPay offsite checkout only supports new payments.');
    }
    foreach ([$return_url, $cancel_url] as $url) {
      if (strlen($url) > 200 || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_QUERY) !== NULL) {
        throw new PaymentGatewayException('CorvusPay requires HTTPS callback URLs without query parameters, at most 200 bytes long.');
      }
    }
    $order = $payment->getOrder();
    $this->orderUpdater->updateOrder($order);
    $amount = $payment->getAmount();
    if (!$amount || !$amount->isPositive() || $amount->getCurrencyCode() !== $this->configuration['currency'] || !$amount->equals($order->getBalance())) {
      throw new PaymentGatewayException('Invalid CorvusPay payment amount or settlement currency.');
    }
    $profile = $order->getBillingProfile();
    if (!$profile || !$profile->hasField('address') || $profile->get('address')->isEmpty()) {
      throw new PaymentGatewayException('CorvusPay requires a billing address.');
    }
    $address = $profile->get('address')->first();
    if (!$address instanceof AddressInterface) {
      throw new PaymentGatewayException('CorvusPay requires an address field.');
    }
    $country = (string) $address->getCountryCode();
    if (!preg_match('/^[A-Z]{2}$/D', $country)) {
      throw new PaymentGatewayException('CorvusPay requires a billing country code.');
    }
    $language = $order->language()->getId();
    $language = $language === 'sr' ? 'rs' : $language;
    if (!in_array($language, ['hr', 'en', 'it', 'de', 'rs', 'sl', 'mk', 'sq'], TRUE)) {
      $language = 'en';
    }
    /** @var \Drupal\commerce_price\Entity\CurrencyInterface|null $currency */
    $currency = $this->entityTypeManager->getStorage('commerce_currency')->load($amount->getCurrencyCode());
    if (!$currency) {
      throw new PaymentGatewayException('Unknown CorvusPay settlement currency.');
    }
    $number = Calculator::round($amount->getNumber(), $currency->getFractionDigits());
    if (Calculator::compare($number, $amount->getNumber()) !== 0) {
      throw new PaymentGatewayException('CorvusPay amount has unsupported currency precision.');
    }
    if (!str_contains($number, '.')) {
      $number .= '.0';
    }
    $data = [
      'version' => '1.6',
      'store_id' => $this->configuration['store_id'],
      'order_number' => bin2hex(random_bytes(16)),
      'language' => $language,
      'currency' => $amount->getCurrencyCode(),
      'amount' => $number,
      'cart' => 'Order ' . $order->id(),
      'require_complete' => $capture ? 'false' : 'true',
      'cardholder_country_code' => $country,
      'success_url' => $return_url,
      'cancel_url' => $cancel_url,
      // Restrict the hosted UI to card payments. Validate card returns as well.
      'hide_tabs' => 'pis,wallet,paysafecard,googlepay,applepay,ips,crypto',
    ];
    $optional = [
      'cardholder_name' => [$address->getGivenName(), 40],
      'cardholder_surname' => [$address->getFamilyName(), 40],
      'cardholder_address' => [$address->getAddressLine1(), 100],
      'cardholder_city' => [$address->getLocality(), 20],
      'cardholder_zip_code' => [$address->getPostalCode(), 9],
      'cardholder_email' => [$order->getEmail(), 100],
    ];
    foreach ($optional as $key => [$value, $length]) {
      if ($value !== NULL && $value !== '') {
        $data[$key] = mb_substr($value, 0, $length);
      }
    }
    $data += $this->getInstallmentParameters($order);
    if ($this->customerConsentedToSaveCard($order)) {
      $data['subscription'] = 'true';
    }
    $data['signature'] = Signature::sign($data, $this->configuration['secret_key']);
    $payment->setRemoteId($data['order_number']);
    $payment->setRemoteState('redirected');
    $payment->save();
    $attempts = $order->getData('commerce_corvuspay', []);
    $attempts[$data['order_number']] = [
      'store_id' => $data['store_id'],
      'installments' => $data['number_of_installments'] ?? '0',
      'capture' => $capture,
      'save_card' => isset($data['subscription']),
    ];
    $order->setData('commerce_corvuspay', $attempts);
    $order->save();
    return $data;
  }

  /**
   * Gets a fixed count from configuration or a generic integer order field.
   */
  protected function getInstallmentParameters(OrderInterface $order): array {
    $count = match ($this->configuration['installments_mode']) {
      'none' => 0,
      'fixed' => $this->configuration['installments'],
      'order_field' => $this->getOrderInstallments($order),
      default => throw new PaymentGatewayException('Unsupported CorvusPay installment mode.'),
    };
    try {
      $parameters = Installments::parameters($count, $this->configuration['max_installments']);
      if ($this->configuration['installments_mode'] === 'fixed' && !$parameters) {
        throw new \InvalidArgumentException();
      }
      return $parameters;
    }
    catch (\InvalidArgumentException $e) {
      throw new PaymentGatewayException('Invalid CorvusPay installment configuration or order count.', 0, $e);
    }
  }

  /**
   * Reads a configured field without assuming a site-specific order bundle.
   */
  protected function getOrderInstallments(OrderInterface $order): mixed {
    $name = $this->configuration['installments_field'];
    if (!$name || !$order->hasField($name) || $order->getFieldDefinition($name)->getType() !== 'integer' || $order->getFieldDefinition($name)->getFieldStorageDefinition()->getCardinality() !== 1) {
      throw new PaymentGatewayException('CorvusPay requires a single-value integer installment field.');
    }
    return $order->get($name)->isEmpty() ? 0 : $order->get($name)->value;
  }

  /**
   * Checks the configured customer-consent field before requesting a token.
   */
  protected function customerConsentedToSaveCard(OrderInterface $order): bool {
    $name = $this->configuration['save_payment_method_field'];
    if ($name === '') {
      return FALSE;
    }
    if (!$order->hasField($name) || $order->getFieldDefinition($name)->getType() !== 'boolean' || $order->getFieldDefinition($name)->getFieldStorageDefinition()->getCardinality() !== 1) {
      throw new PaymentGatewayException('CorvusPay saved-card consent requires a single-value boolean order field.');
    }
    return !$order->get($name)->isEmpty() && (bool) $order->get($name)->value;
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    $this->assertCredentials();
    $parameters = $request->query->all();
    if (!Signature::verify($parameters, $this->configuration['secret_key']) || empty($parameters['approval_code']) || empty($parameters['language']) || !isset($parameters['order_number']) || !is_string($parameters['order_number'])) {
      throw new PaymentGatewayException('Invalid CorvusPay card payment signature or response.');
    }
    if (isset($parameters['store_id']) && $parameters['store_id'] !== $this->configuration['store_id']) {
      throw new PaymentGatewayException('CorvusPay store mismatch.');
    }
    $storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payments = $storage->loadByProperties([
      'payment_gateway' => $this->parentEntity->id(),
      'order_id' => $order->id(),
      'remote_id' => $parameters['order_number'],
    ]);
    if (count($payments) !== 1) {
      throw new PaymentGatewayException('Unknown or ambiguous CorvusPay payment attempt.');
    }
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = reset($payments);
    $attempt = $order->getData('commerce_corvuspay', [])[$parameters['order_number']] ?? [];
    if (($attempt['store_id'] ?? '') !== $this->configuration['store_id'] || $payment->getPaymentGatewayMode() !== $this->getMode()) {
      throw new PaymentGatewayException('CorvusPay payment attempt configuration changed.');
    }
    // Commerce's return controller holds the order lock across this operation.
    if (in_array($payment->getState()->getId(), ['completed', 'authorization'], TRUE)) {
      return;
    }
    $installments = $this->getInstallmentParameters($order)['number_of_installments'] ?? '0';
    if (($attempt['installments'] ?? '') !== $installments) {
      throw new PaymentGatewayException('CorvusPay installment selection changed after redirect.');
    }
    // Recalculate from stored payments, including another successful attempt.
    $this->orderUpdater->updateOrder($order);
    if ($payment->getState()->getId() !== 'new' || $payment->getAmount()->getCurrencyCode() !== $this->configuration['currency'] || !$payment->getAmount()->equals($order->getBalance())) {
      throw new PaymentGatewayException('CorvusPay payment amount no longer matches the order balance.');
    }
    $attempt['approval_code'] = $parameters['approval_code'];
    $capture = !empty($attempt['capture']);
    if (!empty($attempt['save_card'])) {
      try {
        if (!$order->getCustomerId()) {
          throw new PaymentGatewayException('CorvusPay saved payment methods require an authenticated customer account.');
        }
        $account_id = $parameters['account_id'] ?? '';
        $expiry = $parameters['subscription_exp_date'] ?? '';
        if (!is_string($account_id) || !preg_match('/^[\x21-\x7E]{1,22}$/D', $account_id) || !is_string($expiry) || !preg_match('/^\d{8}$/D', $expiry)) {
          throw new PaymentGatewayException('CorvusPay did not return valid saved-card details.');
        }
        $currency = $this->entityTypeManager->getStorage('commerce_currency')->load($payment->getAmount()->getCurrencyCode());
        if (!$currency instanceof CurrencyInterface || $currency->getNumericCode() === '') {
          throw new PaymentGatewayException('CorvusPay requires a numeric ISO currency code to verify saved-card details.');
        }
        $card_details = $this->api()->status($parameters['order_number'], (string) $currency->getNumericCode());
        $payment_method = $this->createSavedPaymentMethod($order, $account_id, $card_details['expiration-date'], $card_details['cc-type'], $card_details['card-details']);
        $payment->set('payment_method', $payment_method);
      }
      catch (PaymentGatewayException $exception) {
        // The authenticated return already confirms the payment. A failed
        // optional card-detail lookup must not leave a paid order unpaid.
        $this->logger->warning('Payment @order succeeded, but its CorvusPay payment method could not be saved: @message', [
          '@order' => $order->id(),
          '@message' => $exception->getMessage(),
        ]);
      }
    }
    $attempts = $order->getData('commerce_corvuspay', []);
    $attempts[$parameters['order_number']] = $attempt;
    $order->setData('commerce_corvuspay', $attempts);
    $order->save();
    $payment->setRemoteState($capture ? 'approved' : 'pre_authorized');
    $payment->setState($capture ? 'completed' : 'authorization');
    $payment->save();
    // Persist paid totals while Commerce still holds the order lock.
    if ($capture) {
      $this->orderUpdater->updateOrder($order, TRUE);
    }
  }

  /**
   * {@inheritdoc} */
  public function canCapturePayment(PaymentInterface $payment): bool {
    return $payment->getState()->getId() === 'authorization';
  }

  /**
   * {@inheritdoc} */
  public function capturePayment(PaymentInterface $payment, ?Price $amount = NULL): void {
    $this->assertPaymentState($payment, ['authorization']);
    $amount ??= $payment->getAmount();
    $this->assertOperationAmount($payment, $amount, FALSE);
    $partial = !$amount->equals($payment->getAmount());
    $this->api()->request($partial ? 'partial_complete' : 'complete', $payment->getRemoteId(), $partial ? $this->formatAmount($amount) : NULL, $partial ? $amount->getCurrencyCode() : NULL, $this->getPaymentAccountId($payment));
    $payment->setAmount($amount);
    $payment->setState('completed');
    $payment->setRemoteState('captured');
    $payment->save();
    $this->orderUpdater->updateOrder($payment->getOrder(), TRUE);
  }

  /**
   * {@inheritdoc} */
  public function canVoidPayment(PaymentInterface $payment): bool {
    return $payment->getState()->getId() === 'authorization';
  }

  /**
   * {@inheritdoc} */
  public function voidPayment(PaymentInterface $payment): void {
    $this->assertPaymentState($payment, ['authorization']);
    $this->api()->request('cancel', $payment->getRemoteId());
    $payment->setState('authorization_voided');
    $payment->setRemoteState('canceled');
    $payment->save();
  }

  /**
   * {@inheritdoc} */
  public function canRefundPayment(PaymentInterface $payment): bool {
    return in_array($payment->getState()->getId(), ['completed', 'partially_refunded'], TRUE);
  }

  /**
   * {@inheritdoc} */
  public function refundPayment(PaymentInterface $payment, ?Price $amount = NULL): void {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    $amount ??= $payment->getBalance();
    $this->assertRefundAmount($payment, $amount);
    $previously_refunded = $payment->getRefundedAmount();
    $refunded = $previously_refunded->add($amount);
    $remaining = $payment->getAmount()->subtract($refunded);
    if ($remaining->isZero()) {
      $this->api()->request('refund', $payment->getRemoteId());
      $payment->setState('refunded');
    }
    else {
      $this->api()->request('partial_refund', $payment->getRemoteId(), $this->formatAmount($remaining), $amount->getCurrencyCode());
      $payment->setState('partially_refunded');
    }
    $payment->setRefundedAmount($refunded);
    $payment->setRemoteState('refunded');
    $payment->save();
  }

  /**
   * {@inheritdoc} */
  public function createPayment(PaymentInterface $payment, $capture = TRUE): void {
    if (!$capture) {
      throw new \InvalidArgumentException('CorvusPay saved-card charges cannot be authorized without capture.');
    }
    $this->assertPaymentState($payment, ['new']);
    $method = $payment->getPaymentMethod();
    $this->assertPaymentMethod($method);
    $amount = $payment->getAmount();
    $this->assertOperationAmount($payment, $amount, FALSE);
    $remote_id = bin2hex(random_bytes(16));
    $this->api()->request('next_sub_payment', $remote_id, $this->formatAmount($amount), $amount->getCurrencyCode(), $method->getRemoteId());
    $payment->setRemoteId($remote_id);
    $payment->setRemoteState('approved');
    $payment->setState('completed');
    $payment->save();
    $this->orderUpdater->updateOrder($payment->getOrder(), TRUE);
  }

  /**
   * {@inheritdoc} */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method): void {
    // CorvusPay does not document a remote account_id revoke operation.
    $payment_method->delete();
  }

  /**
   * Creates a Commerce stored method from signed CorvusPay subscription data.
   */
  protected function createSavedPaymentMethod(OrderInterface $order, string $accountId, string $expiry, string $cardType, string $cardDetails): PaymentMethodInterface {
    $types = [
      'visa' => 'visa',
      'mastercard' => 'mastercard',
      'maestro' => 'maestro',
      'amex' => 'amex',
      'diners' => 'dinersclub',
      'discover' => 'discover',
      'jcb' => 'jcb',
    ];
    $type = $types[strtolower($cardType)] ?? NULL;
    if (!$type || !isset(CreditCard::getTypes()[$type]) || !preg_match('/\d{4}$/D', $cardDetails)) {
      throw new PaymentGatewayException('CorvusPay returned unsupported saved-card details.');
    }
    if (!preg_match('/^\d{4}$/D', $expiry)) {
      throw new PaymentGatewayException('CorvusPay returned an invalid saved-card expiry.');
    }
    // The status API returns card expiry as YYMM (e.g. 1502 for February 2015).
    $year = '20' . substr($expiry, 0, 2);
    $month = substr($expiry, 2, 2);
    if (!checkdate((int) $month, 1, (int) $year)) {
      throw new PaymentGatewayException('CorvusPay returned an invalid saved-card expiry.');
    }
    $storage = $this->entityTypeManager->getStorage('commerce_payment_method');
    if (!$storage instanceof PaymentMethodStorageInterface) {
      throw new PaymentGatewayException('Commerce payment method storage is unavailable.');
    }
    $method = $storage->createForCustomer('credit_card', $this->parentEntity->id(), $order->getCustomerId(), $order->getBillingProfile());
    $method->set('card_type', $type);
    $method->set('card_number', substr($cardDetails, -4));
    $method->set('card_exp_month', $month);
    $method->set('card_exp_year', $year);
    $method->setExpiresTime(CreditCard::calculateExpirationTimestamp($month, $year));
    $method->setRemoteId($accountId);
    $method->save();
    return $method;
  }

  /**
   * Builds the management API client for this gateway configuration. */
  protected function api(): CorvusPayApiClient {
    if ($this->configuration['api_certificate_path'] === '') {
      throw new PaymentGatewayException('Configure the CorvusPay management API client certificate.');
    }
    return new CorvusPayApiClient($this->httpClient, $this->configuration['store_id'], $this->configuration['secret_key'], $this->getMode(), $this->configuration['api_certificate_path'], $this->configuration['api_certificate_password']);
  }

  /**
   * Formats a Commerce price for CorvusPay. */
  protected function formatAmount(Price $amount): string {
    return $amount->getNumber();
  }

  /**
   * Validates a capture or saved-card charge amount. */
  protected function assertOperationAmount(PaymentInterface $payment, Price $amount, bool $refund): void {
    if ($amount->getCurrencyCode() !== $payment->getAmount()->getCurrencyCode() || !$amount->isPositive() || (!$refund && $amount->greaterThan($payment->getAmount()))) {
      throw PaymentGatewayException::createForPayment($payment, 'Invalid CorvusPay operation amount.');
    }
  }

  /**
   * Returns the account ID for an authorized subscription transaction. */
  protected function getPaymentAccountId(PaymentInterface $payment): ?string {
    $method = $payment->getPaymentMethod();
    return $method ? $method->getRemoteId() : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    // The redirect protocol does not define authenticated webhook delivery.
    return new Response('', Response::HTTP_NOT_IMPLEMENTED);
  }

  /**
   * Rejects unusable credentials before sending or accepting payment data.
   */
  protected function assertCredentials(): void {
    if (!preg_match('/^[1-9][0-9]*$/D', $this->configuration['store_id']) || $this->configuration['secret_key'] === '') {
      throw new PaymentGatewayException('CorvusPay credentials are not configured.');
    }
  }

}
