<?php

namespace Drupal\commerce_corvuspay\Plugin\Commerce\PaymentGateway;

use Drupal\address\AddressInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_corvuspay\Installments;
use Drupal\commerce_corvuspay\Signature;
use Drupal\commerce_corvuspay\PluginForm\PaymentOffsiteForm;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\PaymentOrderUpdaterInterface;
use Drupal\commerce_price\Calculator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides immediate-sale CorvusPay card payments.
 */
#[CommercePaymentGateway(
  id: 'corvuspay',
  label: new TranslatableMarkup('CorvusPay'),
  display_label: new TranslatableMarkup('Credit card'),
  forms: ['offsite-payment' => PaymentOffsiteForm::class],
  payment_method_types: ['credit_card'],
  requires_billing_information: TRUE,
)]
class CorvusPay extends OffsitePaymentGatewayBase {

  /**
   * The payment total updater.
   */
  protected PaymentOrderUpdaterInterface $orderUpdater;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->orderUpdater = $container->get('commerce_payment.order_updater');
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
      'currency' => 'EUR',
      'installments_mode' => 'none',
      'installments' => 0,
      'installments_field' => '',
      'max_installments' => 12,
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
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      foreach (['store_id', 'currency', 'installments_mode', 'installments_field'] as $key) {
        $this->configuration[$key] = $values[$key];
      }
      foreach (['installments', 'max_installments'] as $key) {
        $this->configuration[$key] = (int) $values[$key];
      }
      if ($values['secret_key'] !== '') {
        $this->configuration['secret_key'] = $values['secret_key'];
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
    if (!$capture || !$payment->isNew()) {
      throw new PaymentGatewayException('CorvusPay supports new immediate-sale payments only.');
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
      'require_complete' => 'false',
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
    $data['signature'] = Signature::sign($data, $this->configuration['secret_key']);
    $payment->setRemoteId($data['order_number']);
    $payment->setRemoteState('redirected');
    $payment->save();
    $attempts = $order->getData('commerce_corvuspay', []);
    $attempts[$data['order_number']] = [
      'store_id' => $data['store_id'],
      'installments' => $data['number_of_installments'] ?? '0',
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
    if ($payment->getState()->getId() === 'completed') {
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
    $attempts = $order->getData('commerce_corvuspay', []);
    $attempts[$parameters['order_number']] = $attempt;
    $order->setData('commerce_corvuspay', $attempts);
    $order->save();
    $payment->setRemoteState('approved');
    $payment->setState('completed');
    $payment->save();
    // Persist paid totals while Commerce still holds the order lock.
    $this->orderUpdater->updateOrder($order, TRUE);
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
