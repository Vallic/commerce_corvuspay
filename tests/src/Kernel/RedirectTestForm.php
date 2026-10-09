<?php

namespace Drupal\Tests\commerce_corvuspay\Kernel;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormFactoryInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Exercises the plugin inside a real Drupal form build and render.
 */
class RedirectTestForm extends FormBase {

  /**
   * Constructs a redirect test form.
   */
  public function __construct(protected PluginFormFactoryInterface $pluginFormFactory) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('plugin_form.factory'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'corvuspay_redirect_test';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?PaymentInterface $payment = NULL) {
    $plugin = $payment->getPaymentGateway()->getPlugin();
    $plugin_form = $this->pluginFormFactory->createInstance($plugin, 'offsite-payment');
    $plugin_form->setEntity($payment);
    $form['unsigned'] = ['#type' => 'hidden', '#value' => 'must-not-be-sent'];
    $form['payment'] = $plugin_form->buildConfigurationForm([
      '#parents' => ['payment'],
      '#return_url' => 'https://shop.example/return',
      '#cancel_url' => 'https://shop.example/cancel',
    ], $form_state);
    $form['actions']['next'] = ['#type' => 'submit', '#value' => 'Continue'];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {}

}
