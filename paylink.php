<?php
/**
 * @package     VikPaylink
 * @subpackage  core
 * @author      GetPayIn
 * @copyright   Copyright (C) GetPayIn. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://pay.getpayin.com
 */

// No direct access
defined('ABSPATH') or die('No script kiddies please!');

JLoader::import('adapter.payment.payment');

/**
 * PayLink / GetPayIn payment gateway for the Vik plugins.
 *
 * Signs the checkout request with HMAC-SHA256, creates a hosted PayLink checkout via the
 * v2 integration API, redirects the payer to it, and verifies the signed webhook that
 * confirms the payment. The signing contract matches the PayLink server exactly
 * (the same one used by the official PayLink SDKs and the WooCommerce plugin).
 */
abstract class AbstractPaylinkPayment extends JPayment
{
    /**
     * The default PayLink API base host.
     *
     * @var string
     */
    const DEFAULT_BASE_URL = 'https://pay.getpayin.com';

    /**
     * Class constructor. Normalises the order identifiers and customer email from the
     * top-level order info or the nested `details` array, so the component subclasses can
     * stay thin.
     *
     * @param   string  $alias   The name of the plugin that requested the payment.
     * @param   mixed   $order   The order details to start the transaction.
     * @param   mixed   $params  The configuration of the payment.
     */
    public function __construct($alias, $order, $params = array())
    {
        parent::__construct($alias, $order, $params);

        $details = $this->get('details', array());
        $details = is_array($details) ? $details : array();

        if (!$this->get('oid')) {
            $fallbackId = isset($details['id']) ? $details['id'] : 0;
            $this->set('oid', $this->get('id', $fallbackId));
        }

        if (!$this->get('sid') && isset($details['sid'])) {
            $this->set('sid', $details['sid']);
        }

        if (!$this->get('ts') && isset($details['ts'])) {
            $this->set('ts', $details['ts']);
        }

        if (!$this->get('custmail') && isset($details['custmail'])) {
            $this->set('custmail', $details['custmail']);
        }
    }

    /**
     * Builds the administrator configuration form.
     *
     * @return  array   The form parameters.
     */
    protected function buildAdminParameters()
    {
        return array(
            'logo' => array(
                'label' => __('', 'vikpaylink'),
                'type'  => 'custom',
                'html'  => '<img src="' . VIKPAYLINK_URI . 'paylink/paylink-logo.png" alt="PayLink" style="max-width:200px;height:auto;"/>',
            ),
            'authtoken' => array(
                'label' => __('Authentication Token//Copy it from Settings &rarr; Payment Integrations in your PayLink dashboard.', 'vikpaylink'),
                'type'  => 'text',
            ),
            'hashtoken' => array(
                'label' => __('Hash Token//The signing secret from the same screen. It is used to sign requests and verify webhooks, and never leaves your server.', 'vikpaylink'),
                'type'  => 'text',
            ),
            'baseurl' => array(
                'label' => __('API Base URL//Leave as https://pay.getpayin.com unless you were given a dedicated PayLink domain.', 'vikpaylink'),
                'type'  => 'text',
            ),
            'paymentaction' => array(
                'label' => __('Payment Action//Capture charges immediately. Authorize places a hold to capture later, and requires authorize mode enabled on your account.', 'vikpaylink'),
                'type'  => 'select',
                'options' => array('Capture', 'Authorize'),
            ),
            'testmode' => array(
                'label' => __('Test Mode//Enable while you are using PayLink test credentials in the fields above.', 'vikpaylink'),
                'type'  => 'select',
                'options' => array('Yes', 'No'),
            ),
        );
    }

    /**
     * Creates a PayLink checkout for the current order and sends the payer to it.
     *
     * @return  void
     */
    protected function beginTransaction()
    {
        $this->rememberOrderTotal();

        $checkoutUrl = $this->createCheckoutUrl();

        if (!$checkoutUrl) {
            echo '<p class="vikpaylink-error">'
                . __('We could not start the PayLink checkout. Please try again or contact the store.', 'vikpaylink')
                . '</p>';

            return;
        }

        $safeUrl = htmlspecialchars($checkoutUrl, ENT_QUOTES, 'UTF-8');

        echo '<div class="vikpaylink-redirect" style="text-align:center;">'
            . '<p>' . __('Redirecting you to the secure PayLink checkout&hellip;', 'vikpaylink') . '</p>'
            . '<p><a class="btn btn-primary vikpaylink-paynow" href="' . $safeUrl . '">' . __('Pay Now', 'vikpaylink') . '</a></p>'
            . '<script>window.location.href=' . json_encode($checkoutUrl) . ';</script>'
            . '</div>';
    }

    /**
     * Verifies the signed PayLink webhook and reports the payment status to Vik.
     *
     * @param   JPaymentStatus  &$status  The transaction status object.
     *
     * @return  bool   Always true to stop the iteration.
     */
    protected function validateTransaction(JPaymentStatus &$status)
    {
        $payload = $this->readCallbackPayload();

        if (!$payload) {
            $status->appendLog('PayLink: empty or unreadable webhook payload.');

            return true;
        }

        $provided = isset($payload['signature']) ? (string) $payload['signature'] : '';

        if ($provided === '' || !$this->verifyCallbackSignature($payload, $provided)) {
            $status->appendLog('PayLink: webhook signature verification failed.');

            return true;
        }

        $invoiceStatus = strtoupper((string) (isset($payload['invoice_status']) ? $payload['invoice_status'] : ''));
        $success = (int) (isset($payload['success']) ? $payload['success'] : 0) === 1;

        if ($success && ($invoiceStatus === 'PAID' || $invoiceStatus === 'AUTHORIZED' || $invoiceStatus === 'CAPTURED' || $invoiceStatus === '')) {
            $status->verified();
            $status->paid($this->resolveOrderTotal());
        } else {
            $message = isset($payload['message']) ? (string) $payload['message'] : '';
            $status->appendLog('PayLink: payment not completed (status: ' . $invoiceStatus . '). ' . $message);
        }

        return true;
    }

    /**
     * Displays the outcome message and redirects the payer back to the shop.
     *
     * @param   int  $res  1 on success, 0 on failure.
     *
     * @return  void
     */
    protected function complete($res = 0)
    {
        $app = JFactory::getApplication();

        if ($res) {
            $url = $this->get('return_url');
            $app->enqueueMessage(__('Thank you! Your payment was received.', 'vikpaylink'));
        } else {
            $url = $this->get('error_url');
            $app->enqueueMessage(__('We could not verify your payment. Please try again.', 'vikpaylink'));
        }

        $app->redirect($url);
    }

    /**
     * Calls the PayLink v2 init endpoint and returns the hosted checkout URL.
     *
     * @return  mixed   The checkout URL on success, otherwise false.
     */
    protected function createCheckoutUrl()
    {
        $signed = $this->buildSignedFields();
        $signature = $this->signValues(array_values($signed));

        $body = $signed;
        $body['token'] = (string) $this->getParam('authtoken');
        $body['signature'] = $signature;

        if ($this->getParam('paymentaction') === 'Authorize') {
            $body['payment_mode'] = 'authorize';
        }

        $response = $this->httpPost($this->apiBaseUrl() . '/api/v2/integration/init', $body);

        if (!is_array($response) || empty($response['checkout_url'])) {
            return false;
        }

        return (string) $response['checkout_url'];
    }

    /**
     * Builds the SIGNED init fields in the exact order the PayLink v2 endpoint concatenates
     * them: first_name, last_name, email, order_title, order_amount, currency,
     * redirection_url, webhook_url. Optional billing address fields and order_details are
     * omitted here.
     *
     * @return  array   The ordered field map.
     */
    protected function buildSignedFields()
    {
        list($firstName, $lastName) = $this->resolveCustomerName();

        return array(
            'first_name'      => $firstName,
            'last_name'       => $lastName,
            'email'           => (string) $this->get('custmail', ''),
            'order_title'     => (string) $this->get('transaction_name', 'Order ' . $this->get('sid', '')),
            'order_amount'    => $this->formatAmount($this->resolveOrderTotal()),
            'currency'        => (string) $this->get('transaction_currency', 'EUR'),
            'redirection_url' => (string) $this->get('return_url', ''),
            'webhook_url'     => (string) $this->get('notify_url', ''),
        );
    }

    /**
     * Signs an ordered list of values: base64(hmac_sha256(implode('', values), hash_token)).
     *
     * @param   array  $orderedValues  The already-ordered values.
     *
     * @return  string  The base64 signature.
     */
    protected function signValues(array $orderedValues)
    {
        $concatenated = implode('', array_map('strval', $orderedValues));

        return base64_encode(hash_hmac('sha256', $concatenated, (string) $this->getParam('hashtoken'), true));
    }

    /**
     * Verifies a PayLink callback signature against the ordered subset the server signs:
     * success, invoice_id, invoice_status, message, plus mandate_id, external_reference,
     * subscription_status when present.
     *
     * @param   array   $payload   The decoded callback payload.
     * @param   string  $provided  The signature carried in the payload.
     *
     * @return  bool   True when the recomputed signature matches.
     */
    protected function verifyCallbackSignature(array $payload, $provided)
    {
        $ordered = array(
            $this->payloadValue($payload, 'success'),
            $this->payloadValue($payload, 'invoice_id'),
            $this->payloadValue($payload, 'invoice_status'),
            $this->payloadValue($payload, 'message'),
        );

        foreach (array('mandate_id', 'external_reference', 'subscription_status') as $key) {
            if (array_key_exists($key, $payload)) {
                $ordered[] = $this->payloadValue($payload, $key);
            }
        }

        $expected = base64_encode(hash_hmac('sha256', implode('', $ordered), (string) $this->getParam('hashtoken'), true));

        return hash_equals($expected, (string) $provided);
    }

    /**
     * Stringifies a payload value for signature reconstruction, matching PHP's implode
     * coercion on the server (missing/null becomes '', bool becomes '1'/'0').
     *
     * @param   array   $payload  The payload.
     * @param   string  $key      The field name.
     *
     * @return  string  The wire string.
     */
    protected function payloadValue(array $payload, $key)
    {
        if (!array_key_exists($key, $payload) || $payload[$key] === null) {
            return '';
        }

        if (is_bool($payload[$key])) {
            return $payload[$key] ? '1' : '0';
        }

        return (string) $payload[$key];
    }

    /**
     * Reads the callback body, supporting the JSON webhook payload and form-encoded returns.
     *
     * @return  array   The decoded payload.
     */
    protected function readCallbackPayload()
    {
        $raw = file_get_contents('php://input');

        if ($raw) {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return is_array($_POST) && $_POST ? $_POST : array();
    }

    /**
     * The configured API base URL, without a trailing slash.
     *
     * @return  string
     */
    protected function apiBaseUrl()
    {
        $url = trim((string) $this->getParam('baseurl'));

        if ($url === '') {
            $url = self::DEFAULT_BASE_URL;
        }

        return rtrim($url, '/');
    }

    /**
     * Formats a monetary amount to a fixed 2-decimal wire form.
     *
     * @param   mixed  $amount  The amount.
     *
     * @return  string
     */
    protected function formatAmount($amount)
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * Resolves the order total, falling back to the value remembered at checkout time when
     * the callback context no longer carries it.
     *
     * @return  float
     */
    protected function resolveOrderTotal()
    {
        $total = (float) $this->get('total_to_pay', 0);

        if ($total > 0) {
            return $total;
        }

        $remembered = $this->recallOrderTotal();

        if ($remembered !== null) {
            $this->set('total_to_pay', $remembered);

            return (float) $remembered;
        }

        return $total;
    }

    /**
     * Best-effort first/last name for the payer, derived from the order details and falling
     * back to the email local part or a generic name. PayLink requires both to be non-empty.
     *
     * @return  array  A [firstName, lastName] pair.
     */
    protected function resolveCustomerName()
    {
        $details = $this->get('details', array());
        $details = is_array($details) ? $details : array();

        $full = '';

        foreach (array('first_name', 'firstname', 'nominative', 'name', 'fullname', 'custname') as $key) {
            if (!empty($details[$key])) {
                $full = trim($full . ' ' . (string) $details[$key]);
            }
        }

        if ($full === '') {
            $full = trim(str_replace(array('@', '.', '_', '-'), ' ', (string) $this->get('custmail', '')));
        }

        if ($full === '') {
            $full = 'Guest Customer';
        }

        $parts = preg_split('/\s+/', trim($full));
        $first = isset($parts[0]) && $parts[0] !== '' ? $parts[0] : 'Guest';
        $last  = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : 'Customer';

        return array($first, $last);
    }

    /**
     * Persists the order total in a transient keyed by the order, so the webhook can report
     * the paid amount even when its context lacks it.
     *
     * @return  void
     */
    protected function rememberOrderTotal()
    {
        if (function_exists('set_transient')) {
            set_transient($this->totalTransientKey(), (string) $this->get('total_to_pay', 0), 60 * MINUTE_IN_SECONDS);
        }
    }

    /**
     * Reads back the remembered order total.
     *
     * @return  mixed  The stored value, or null when absent.
     */
    protected function recallOrderTotal()
    {
        if (function_exists('get_transient')) {
            $value = get_transient($this->totalTransientKey());

            if ($value !== false) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The transient key that stores this order's total.
     *
     * @return  string
     */
    protected function totalTransientKey()
    {
        return 'vikpaylink_' . $this->get('sid', '0') . '_' . $this->get('oid', '0');
    }

    /**
     * POSTs form-encoded fields to a PayLink endpoint and returns the `data` payload.
     *
     * @param   string  $url     The endpoint URL.
     * @param   array   $fields  The request fields.
     *
     * @return  mixed   The decoded `data` array on success, otherwise false.
     */
    protected function httpPost($url, array $fields)
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => array(
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
                'Idempotency-Key: ' . substr('vik_' . $this->get('sid', '0') . '_' . $this->get('oid', '0'), 0, 64),
            ),
        ));

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $code < 200 || $code >= 300) {
            return false;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return false;
        }

        return isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;
    }
}
