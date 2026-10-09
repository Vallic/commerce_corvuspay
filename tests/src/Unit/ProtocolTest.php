<?php

namespace Drupal\Tests\commerce_corvuspay\Unit;

use Drupal\commerce_corvuspay\Installments;
use Drupal\commerce_corvuspay\Signature;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the provider's signing and installment wire protocol.
 */
#[Group('commerce_corvuspay')]
class ProtocolTest extends UnitTestCase {

  /**
   * Tests an independently computed canonical message and HMAC vector.
   */
  public function testSigning(): void {
    $parameters = ['store_id' => '123', 'cart' => 'Prsten Ž 256', 'amount' => '10.00'];
    $expected = hash_hmac('sha256', 'amount10.00cartPrsten Ž 256store_id123', 'secret');
    $this->assertSame($expected, Signature::sign($parameters, 'secret'));
    $parameters['signature'] = strtoupper($expected);
    $this->assertTrue(Signature::verify($parameters, 'secret'));
    $this->assertFalse(Signature::verify($parameters, 'wrong secret'));
    $parameters['amount'] = '100.00';
    $this->assertFalse(Signature::verify($parameters, 'secret'));
  }

  /**
   * Tests malformed values and missing signatures fail closed.
   */
  public function testMalformedSignature(): void {
    $invalid = [
      [],
      ['signature' => []],
      ['signature' => 'bad'],
      ['signature' => str_repeat('a', 64), 'cart' => ['injected']],
    ];
    foreach ($invalid as $parameters) {
      $this->assertFalse(Signature::verify($parameters, 'secret'));
    }
  }

  /**
   * Tests the manual's fixed-count formatting.
   */
  public function testInstallments(): void {
    $this->assertSame([], Installments::parameters(0, 12));
    $this->assertSame(['number_of_installments' => '06'], Installments::parameters('6', 12));
    $this->assertSame(['number_of_installments' => '12'], Installments::parameters(12, 12));
  }

  /**
   * Tests invalid and out-of-contract installment inputs.
   */
  #[DataProvider('invalidCounts')]
  public function testInvalidInstallments(mixed $count): void {
    $this->expectException(\InvalidArgumentException::class);
    Installments::parameters($count, 12);
  }

  /**
   * Provides hostile inputs and values outside merchant limits.
   */
  public static function invalidCounts(): array {
    return [[1], [-1], [13], ['100'], ['6.0'], [6.0], [TRUE], [[]], [''], [NULL]];
  }

}
