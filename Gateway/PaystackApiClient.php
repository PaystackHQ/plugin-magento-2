<?php

namespace Pstk\Paystack\Gateway;

use Magento\Payment\Helper\Data as PaymentHelper;
use Pstk\Paystack\Gateway\Exception\ApiException;
use Pstk\Paystack\Model\Payment\Paystack as PaystackModel;

class PaystackApiClient
{
    private const BASE_URL = 'https://api.paystack.co';

    /** @var PaymentHelper */
    private $paymentHelper;

    /** @var string|null */
    private $secretKey;

    public function __construct(PaymentHelper $paymentHelper)
    {
        $this->paymentHelper = $paymentHelper;
    }

    /**
     * @return string
     */
    private function getSecretKey(): string
    {
        if ($this->secretKey === null) {
            $method = $this->paymentHelper->getMethodInstance(PaystackModel::CODE);
            $this->secretKey = $method->getConfigData('live_secret_key');
            if ($method->getConfigData('test_mode')) {
                $this->secretKey = $method->getConfigData('test_secret_key');
            }
            $this->secretKey = (string) $this->secretKey;
        }
        return $this->secretKey;
    }

    /**
     * Initialize a transaction (Standard/Redirect flow).
     *
     * @param array $params
     * @return object Decoded JSON response with ->data->authorization_url
     * @throws ApiException
     */
    public function initializeTransaction(array $params): object
    {
        return $this->request('POST', '/transaction/initialize', $params);
    }

    /**
     * Verify a transaction by reference.
     *
     * @param string $reference
     * @return object Decoded JSON response with ->data (reference, status, metadata, etc.)
     * @throws ApiException
     */
    public function verifyTransaction(string $reference): object
    {
        return $this->request('GET', '/transaction/verify/' . rawurlencode($reference));
    }

    /**
     * Validate a Paystack webhook signature (HMAC-SHA512).
     *
     * @param string $rawBody   Raw request body from php://input
     * @param string $signature Value of the X-Paystack-Signature header
     * @return bool
     */
    public function validateWebhookSignature(string $rawBody, string $signature): bool
    {
        $computed = hash_hmac('sha512', $rawBody, $this->getSecretKey());
        return hash_equals($computed, $signature);
    }

    /**
     * Log a successful transaction to the Paystack plugin tracker.
     *
     * @param string $transactionReference
     * @param string $publicKey
     * @return void
     */
    public function logTransactionSuccess(string $transactionReference, string $publicKey): void
    {
        $url = 'https://plugin-tracker.paystackintegrations.com/log/charge_success';

        $fields = http_build_query([
            'plugin_name' => 'magento-2',
            'transaction_reference' => $transactionReference,
            'public_key' => $publicKey,
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($ch);
    }

    /**
     * @param string     $method  HTTP method (GET or POST)
     * @param string     $endpoint API path (e.g. /transaction/verify/ref)
     * @param array|null $data     POST body data (will be JSON-encoded)
     * @return object Decoded JSON response body
     * @throws ApiException
     */
    private function request(string $method, string $endpoint, ?array $data = null): object
    {
        $url = self::BASE_URL . $endpoint;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->getSecretKey(),
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        if ($method === 'POST' && $data !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            throw new ApiException('Paystack API request failed: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // Note: curl_close() is intentionally omitted. Since PHP 8.0 the curl handle is a
        // \CurlHandle object that is freed automatically, and calling curl_close() emits a
        // deprecation notice on PHP 8.5 which Magento escalates to an exception — that was
        // aborting verifyPayment() after a successful charge ("payment verification failed"
        // while the payment went through).

        $body = json_decode($response);

        if (!$body) {
            throw new ApiException('Invalid JSON response from Paystack API', $statusCode);
        }

        if ($statusCode >= 400 || (isset($body->status) && !$body->status)) {
            $message = $body->message ?? 'Paystack API request failed';
            throw new ApiException($message, $statusCode);
        }

        return $body;
    }
}
