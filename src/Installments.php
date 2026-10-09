<?php

namespace Drupal\commerce_corvuspay;

/**
 * Validates and formats the fixed installment parameter.
 */
final class Installments {

  /**
   * Returns no parameter for zero, otherwise a validated two-digit count.
   */
  public static function parameters(mixed $count, int $maximum): array {
    if ((!is_int($count) && !is_string($count)) || !preg_match('/^\d{1,2}$/D', (string) $count)) {
      throw new \InvalidArgumentException('Invalid installment count.');
    }
    $count = (int) $count;
    if ($maximum < 2 || $maximum > 99 || $count === 1 || $count > $maximum) {
      throw new \InvalidArgumentException('Installment count exceeds the configured limits.');
    }
    return $count === 0 ? [] : ['number_of_installments' => sprintf('%02d', $count)];
  }

}
