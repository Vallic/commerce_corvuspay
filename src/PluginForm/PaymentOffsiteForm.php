<?php

namespace Drupal\commerce_corvuspay\PluginForm;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;

/**
 * Uses Commerce's standard automatic POST redirect and fallback button.
 */
class PaymentOffsiteForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_corvuspay\Plugin\Commerce\PaymentGateway\CorvusPay $gateway */
    $gateway = $payment->getPaymentGateway()->getPlugin();
    $data = $gateway->prepareRedirect($payment, $form['#return_url'], $form['#cancel_url'], $form['#capture']);
    $form = $this->buildRedirectForm($form, $form_state, $gateway->getCheckoutUrl(), $data, self::REDIRECT_POST);
    $form['#corvuspay_parameter_names'] = array_keys($data);
    return $form;
  }

  /**
   * Prevents Drupal form metadata from leaking into the signed provider POST.
   */
  public static function processRedirectForm(array $form, FormStateInterface $form_state, array &$complete_form) {
    $form = parent::processRedirectForm($form, $form_state, $complete_form);
    $complete_form['#corvuspay_parameter_names'] = $form['#corvuspay_parameter_names'];
    $complete_form['#after_build'][] = [static::class, 'filterRedirectInputs'];
    return $form;
  }

  /**
   * Sends only signed fields, with an unnamed manual submit button.
   */
  public static function filterRedirectInputs(array $form, FormStateInterface $form_state): array {
    $names = $form['#corvuspay_parameter_names'];
    static::filterInputs($form, $names);
    $next = $form['actions']['next'] ?? [];
    $form['actions']['next'] = [
      '#type' => 'inline_template',
      '#template' => '<button type="submit" class="button button--primary">{{ label }}</button>',
      '#context' => ['label' => $next['#value'] ?? t('Proceed to CorvusPay')],
      '#suffix' => $next['#suffix'] ?? '',
    ];
    return $form;
  }

  /**
   * Removes unsigned inputs, including form tokens and submit button names.
   */
  protected static function filterInputs(array &$element, array $names): void {
    if (!empty($element['#input']) && !in_array($element['#name'] ?? '', $names, TRUE)) {
      $element['#access'] = FALSE;
    }
    foreach (Element::children($element) as $key) {
      static::filterInputs($element[$key], $names);
    }
  }

}
