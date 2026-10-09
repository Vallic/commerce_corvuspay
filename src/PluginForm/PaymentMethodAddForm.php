<?php

namespace Drupal\commerce_corvuspay\PluginForm;

use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_payment\PluginForm\PaymentGatewayFormBase;

/**
 * Explains that CorvusPay methods can only be saved during checkout.
 *
 * CorvusPay's documented hosted flow does not provide standalone
 * tokenization. This form deliberately never collects card data.
 */
class PaymentMethodAddForm extends PaymentGatewayFormBase {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['message'] = [
      '#type' => 'item',
      '#title' => $this->t('Save a CorvusPay card'),
      '#markup' => $this->t('To save a card, select CorvusPay during checkout and choose the save-card consent option. A card cannot be stored without completing a payment.'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $form_state->setError($form['message'], $this->t('Save a CorvusPay card during checkout with a successful payment.'));
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {}

}
