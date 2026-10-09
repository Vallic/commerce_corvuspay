<?php

namespace Drupal\Tests\commerce_corvuspay\Kernel;

use Drupal\commerce_corvuspay\Plugin\Commerce\PaymentGateway\CorvusPay;
use Drupal\commerce_corvuspay\Signature;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_price\Price;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\profile\Entity\Profile;
use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests discovery, persisted attempts and authenticated payment completion.
 */
#[Group('commerce_corvuspay')]
class CorvusPayTest extends OrderKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['commerce_payment', 'commerce_corvuspay'];

  /**
   * The gateway configuration entity.
   */
  protected PaymentGateway $gateway;

  /**
   * The discovered gateway plugin.
   */
  protected CorvusPay $plugin;

  /**
   * The sample order.
   */
  protected Order $order;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('commerce_payment');
    $this->installConfig(['commerce_payment']);
    $this->gateway = PaymentGateway::create([
      'id' => 'corvuspay',
      'label' => 'CorvusPay',
      'plugin' => 'corvuspay',
      'configuration' => ['store_id' => '123', 'secret_key' => 'test-secret', 'currency' => 'USD'],
    ]);
    $this->gateway->save();
    $this->plugin = $this->gateway->getPlugin();
    $profile = Profile::create([
      'type' => 'customer',
      'address' => [
        'country_code' => 'HR',
        'given_name' => 'Test',
        'family_name' => 'Buyer',
        'address_line1' => 'Test 1',
        'locality' => 'Zagreb',
        'postal_code' => '10000',
      ],
    ]);
    $profile->save();
    $item = OrderItem::create([
      'type' => 'test',
      'title' => 'Ring',
      'quantity' => 1,
      'unit_price' => new Price('30', 'USD'),
    ]);
    $item->save();
    $this->order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'order_items' => [$item],
      'billing_profile' => $profile,
      'payment_gateway' => $this->gateway,
    ]);
    $this->order->save();
  }

  /**
   * Creates and prepares a persisted payment attempt.
   */
  protected function redirect(): array {
    $payment = Payment::create([
      'state' => 'new',
      'amount' => $this->order->getBalance(),
      'payment_gateway' => $this->gateway,
      'order_id' => $this->order,
    ]);
    return $this->plugin->prepareRedirect($payment, 'https://shop.example/return', 'https://shop.example/cancel', TRUE);
  }

  /**
   * Creates an authenticated card success request.
   */
  protected function success(array $data): Request {
    $parameters = ['order_number' => $data['order_number'], 'language' => 'en', 'approval_code' => '88888'];
    $parameters['signature'] = Signature::sign($parameters, 'test-secret');
    return new Request($parameters);
  }

  /**
   * Tests discovery, redirect signing, completion and repeat-return handling.
   */
  public function testSaleAndReplay(): void {
    $data = $this->redirect();
    $this->assertSame('false', $data['require_complete']);
    $this->assertSame('30.00', $data['amount']);
    $this->assertSame('HR', $data['cardholder_country_code']);
    $this->assertTrue(Signature::verify($data, 'test-secret'));
    $this->assertArrayNotHasKey('number_of_installments', $data);
    $this->assertSame('https://wallet.test.corvuspay.com/checkout/', $this->plugin->getCheckoutUrl());
    $this->plugin->onReturn($this->order, $this->success($data));
    $this->plugin->onReturn($this->order, $this->success($data));
    $payments = $this->container->get('entity_type.manager')->getStorage('commerce_payment')->loadByProperties(['order_id' => $this->order->id()]);
    $this->assertCount(1, $payments);
    $payment = reset($payments);
    $this->assertSame('completed', $payment->getState()->getId());
    $this->assertTrue($this->order->getBalance()->isZero());
    $this->assertSame('88888', $this->order->getData('commerce_corvuspay')[$data['order_number']]['approval_code']);
  }

  /**
   * Tests a signed success for another order cannot complete this order.
   */
  public function testWrongOrder(): void {
    $data = $this->redirect();
    $other = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'order_items' => $this->order->getItems(),
    ]);
    $other->save();
    $this->expectException(PaymentGatewayException::class);
    $this->plugin->onReturn($other, $this->success($data));
  }

  /**
   * Tests tampering is rejected before touching persisted payments.
   */
  public function testTamperedReturn(): void {
    $request = $this->success($this->redirect());
    $request->query->set('approval_code', 'tampered');
    $this->expectException(PaymentGatewayException::class);
    $this->plugin->onReturn($this->order, $request);
  }

  /**
   * Tests another successful attempt cannot pay the same balance again.
   */
  public function testTwoAttempts(): void {
    $first = $this->redirect();
    $second = $this->redirect();
    $this->assertNotSame($first['order_number'], $second['order_number']);
    $this->plugin->onReturn($this->order, $this->success($first));
    $this->expectException(PaymentGatewayException::class);
    $this->plugin->onReturn($this->order, $this->success($second));
  }

  /**
   * Tests a valid card success cannot pay a changed order balance.
   */
  public function testChangedBalance(): void {
    $data = $this->redirect();
    $item = $this->order->getItems()[0];
    $item->setUnitPrice(new Price('40', 'USD'));
    $item->save();
    $this->order->recalculateTotalPrice();
    $this->expectException(PaymentGatewayException::class);
    $this->plugin->onReturn($this->order, $this->success($data));
  }

  /**
   * Tests a generic integer order field drives fixed installments.
   */
  public function testOrderFieldInstallments(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_installments',
      'entity_type' => 'commerce_order',
      'type' => 'integer',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_installments',
      'entity_type' => 'commerce_order',
      'bundle' => 'default',
    ])->save();
    $this->order = $this->reloadEntity($this->order);
    $this->order->set('field_installments', 6);
    $this->order->save();
    $this->plugin->setConfiguration([
      'store_id' => '123',
      'secret_key' => 'test-secret',
      'currency' => 'USD',
      'installments_mode' => 'order_field',
      'installments_field' => 'field_installments',
    ]);
    $data = $this->redirect();
    $this->assertSame('06', $data['number_of_installments']);
    $this->assertTrue(Signature::verify($data, 'test-secret'));
  }

  /**
   * Tests checkout cannot accidentally request unsupported preauthorization.
   */
  public function testCaptureRequired(): void {
    $payment = Payment::create([
      'amount' => new Price('30', 'USD'),
      'payment_gateway' => $this->gateway,
      'order_id' => $this->order,
    ]);
    $this->expectException(PaymentGatewayException::class);
    $this->plugin->prepareRedirect($payment, 'https://shop.example/return', 'https://shop.example/cancel', FALSE);
  }

  /**
   * Tests non-card success payloads cannot complete a card payment.
   */
  public function testNonCardReturn(): void {
    $data = $this->redirect();
    $parameters = ['order_number' => $data['order_number'], 'language' => 'en'];
    $parameters['signature'] = Signature::sign($parameters, 'test-secret');
    $this->expectException(PaymentGatewayException::class);
    $this->plugin->onReturn($this->order, new Request($parameters));
  }

  /**
   * Tests rendered auto/manual POST fields contain exactly the signed payload.
   */
  public function testRenderedRedirect(): void {
    $payment = Payment::create([
      'amount' => $this->order->getBalance(),
      'payment_gateway' => $this->gateway,
      'order_id' => $this->order,
    ]);
    $form = $this->container->get('form_builder')->getForm(RedirectTestForm::class, $payment);
    $html = (string) $this->container->get('renderer')->renderInIsolation($form);
    $document = new \DOMDocument();
    @$document->loadHTML($html);
    $xpath = new \DOMXPath($document);
    $parameters = [];
    foreach ($xpath->query('//input[@name]') as $input) {
      $parameters[$input->getAttribute('name')] = $input->getAttribute('value');
    }
    $this->assertTrue(Signature::verify($parameters, 'test-secret'));
    foreach (['form_id', 'form_build_id', 'form_token', 'unsigned', 'op'] as $name) {
      $this->assertArrayNotHasKey($name, $parameters);
    }
    $this->assertCount(1, $xpath->query('//button[@type="submit" and not(@name)]'));
    $this->assertSame($this->plugin->getCheckoutUrl(), $xpath->query('//form')->item(0)->getAttribute('action'));
  }

  /**
   * Tests a different settlement currency fails before creating an attempt.
   */
  public function testCurrencyMismatch(): void {
    $this->plugin->setConfiguration(['store_id' => '123', 'secret_key' => 'test-secret', 'currency' => 'EUR']);
    $this->expectException(PaymentGatewayException::class);
    $this->redirect();
  }

  /**
   * Tests fixed counts are formatted without enabling flexible installments.
   */
  public function testFixedInstallments(): void {
    $this->plugin->setConfiguration([
      'store_id' => '123',
      'secret_key' => 'test-secret',
      'currency' => 'USD',
      'installments_mode' => 'fixed',
      'installments' => 6,
    ]);
    $data = $this->redirect();
    $this->assertSame('06', $data['number_of_installments']);
    $this->assertArrayNotHasKey('payment_all', $data);
    $this->assertArrayNotHasKey('payment_all_dynamic', $data);
  }

}
