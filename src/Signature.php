<?php

namespace Drupal\commerce_corvuspay;

/**
 * Implements CorvusPay's UTF-8 HMAC-SHA256 message protocol.
 */
final class Signature {

  /**
   * Signs all parameters except the signature itself, preserving exact values.
   */
  public static function sign(array $parameters, string $secret): string {
    unset($parameters['signature']);
    ksort($parameters, SORT_STRING);
    $message = '';
    foreach ($parameters as $name => $value) {
      if (!is_string($value)) {
        throw new \InvalidArgumentException('CorvusPay parameters must be strings.');
      }
      $message .= $name . $value;
    }
    return hash_hmac('sha256', $message, $secret);
  }

  /**
   * Verifies a hexadecimal signature without timing-dependent comparison.
   */
  public static function verify(array $parameters, string $secret): bool {
    $signature = $parameters['signature'] ?? NULL;
    if (!is_string($signature) || !preg_match('/^[a-fA-F0-9]{64}$/D', $signature)) {
      return FALSE;
    }
    try {
      return hash_equals(self::sign($parameters, $secret), strtolower($signature));
    }
    catch (\InvalidArgumentException) {
      return FALSE;
    }
  }

}
