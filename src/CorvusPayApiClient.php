<?php

namespace Drupal\commerce_corvuspay;

use GuzzleHttp\ClientInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;

/**
 * Calls the CorvusPay transaction management API.
 *
 * The API requires a merchant PKCS#12 client certificate. The certificate is
 * converted to short-lived PEM files because Drupal's HTTP client accepts PEM.
 */
final class CorvusPayApiClient {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly string $storeId,
    private readonly string $secret,
    private readonly string $mode,
    private readonly string $certificatePath,
    private readonly string $certificatePassword,
  ) {}

  /**
   * Performs one authenticated management operation.
   */
  public function request(string $operation, string $orderNumber, ?string $newAmount = NULL, ?string $currency = NULL, ?string $accountId = NULL): void {
    $paths = [
      'complete' => 'complete',
      'partial_complete' => 'partial_complete',
      'cancel' => 'cancel',
      'refund' => 'refund',
      'partial_refund' => 'partial_refund',
      'next_sub_payment' => 'next_sub_payment',
    ];
    if (!isset($paths[$operation])) {
      throw new PaymentGatewayException('Unsupported CorvusPay management operation.');
    }
    $parameters = ['store_id' => $this->storeId, 'order_number' => $orderNumber];
    if ($accountId !== NULL && in_array($operation, ['complete', 'partial_complete'], TRUE)) {
      $parameters += ['subscription' => 'true', 'account_id' => $accountId];
    }
    if (in_array($operation, ['partial_complete', 'partial_refund'], TRUE)) {
      if ($newAmount === NULL || $currency === NULL) {
        throw new PaymentGatewayException('CorvusPay requires an amount and currency for a partial operation.');
      }
      $parameters += ['version' => '1.6', 'new_amount' => $newAmount, 'currency' => $currency];
      $hash_data = $this->secret . $orderNumber . $this->storeId . '1.6' . $newAmount . $currency;
    }
    elseif ($operation === 'next_sub_payment') {
      if ($accountId === NULL || $accountId === '') {
        throw new PaymentGatewayException('CorvusPay subscription account is missing.');
      }
      $parameters += ['version' => '1.6', 'subscription' => 'true', 'account_id' => $accountId];
      if ($newAmount !== NULL && $currency !== NULL) {
        $parameters += ['new_amount' => $newAmount, 'currency' => $currency];
        $hash_data = $this->secret . $orderNumber . $this->storeId . '1.6' . $newAmount . $currency;
      }
      else {
        $hash_data = $this->secret . $orderNumber . $this->storeId . '1.6';
      }
    }
    else {
      $hash_data = $this->secret . $orderNumber . $this->storeId;
    }
    $parameters['hash'] = sha1($hash_data);
    $this->send($paths[$operation], $parameters);
  }

  /**
   * Queries transaction status to obtain signed subscription card metadata.
   *
   * @return array{cc-type: string, card-details: string, expiration-date: string}
   *   The card brand and masked card number.
   */
  public function status(string $orderNumber, string $numericCurrencyCode): array {
    $timestamp = gmdate('YmdHis');
    $version = '1.6';
    $parameters = [
      'store_id' => $this->storeId,
      'order_number' => $orderNumber,
      'currency_code' => $numericCurrencyCode,
      'timestamp' => $timestamp,
      'version' => $version,
      'hash' => sha1($this->secret . $orderNumber . $this->storeId . $numericCurrencyCode . $timestamp . $version),
    ];
    $body = $this->send('status', $parameters, FALSE);
    $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
    if (!$xml || $xml->getName() !== 'trans-status' || (string) $xml->{'order-number'} !== $orderNumber || (string) $xml->{'response-code'} !== '0') {
      throw new PaymentGatewayException('CorvusPay could not verify the subscription transaction status.');
    }
    return [
      'cc-type' => (string) $xml->{'cc-type'},
      'card-details' => (string) $xml->{'card-details'},
      'expiration-date' => (string) $xml->{'expiration-date'},
    ];
  }

  /**
   * Sends the request with a client certificate and verifies the XML result.
   */
  private function send(string $path, array $parameters, bool $expectOperationSuccess = TRUE): string {
    if (!is_readable($this->certificatePath) || !function_exists('openssl_pkcs12_read')) {
      throw new PaymentGatewayException('A readable CorvusPay PKCS#12 client certificate and OpenSSL are required.');
    }
    $contents = file_get_contents($this->certificatePath);
    $certificate = [];
    if ($contents === FALSE || !openssl_pkcs12_read($contents, $certificate, $this->certificatePassword) || empty($certificate['cert']) || empty($certificate['pkey'])) {
      throw new PaymentGatewayException('Unable to read the CorvusPay client certificate.');
    }
    $cert_file = tempnam(sys_get_temp_dir(), 'cpcert');
    $key_file = tempnam(sys_get_temp_dir(), 'cpkey');
    if ($cert_file === FALSE || $key_file === FALSE) {
      throw new PaymentGatewayException('Unable to prepare CorvusPay client certificate files.');
    }
    try {
      chmod($cert_file, 0600);
      chmod($key_file, 0600);
      if (file_put_contents($cert_file, $certificate['cert']) === FALSE || file_put_contents($key_file, $certificate['pkey']) === FALSE) {
        throw new PaymentGatewayException('Unable to prepare CorvusPay client certificate files.');
      }
      $base_url = $this->mode === 'test' ? 'https://testcps.corvus.hr/' : 'https://cps.corvus.hr/';
      $response = $this->httpClient->request('POST', $base_url . $path, [
        'form_params' => $parameters,
        'cert' => $cert_file,
        'ssl_key' => $key_file,
        'connect_timeout' => 10,
        'timeout' => 30,
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
        'headers' => ['Accept' => 'application/xml, text/xml'],
      ]);
      $body = (string) $response->getBody();
      if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300 || ($expectOperationSuccess && !preg_match('/<response-code(?:\s[^>]*)?>\s*0\s*<\/response-code>/i', $body))) {
        throw new PaymentGatewayException('CorvusPay rejected the transaction management request.');
      }
      return $body;
    }
    catch (PaymentGatewayException $exception) {
      throw $exception;
    }
    catch (\Throwable $exception) {
      throw new PaymentGatewayException('CorvusPay transaction management request failed.', 0, $exception);
    }
    finally {
      @unlink($cert_file);
      @unlink($key_file);
      unset($certificate, $contents);
    }
  }

}
