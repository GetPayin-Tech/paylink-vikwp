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
 * GetPayIn payment gateway for the Vik plugins.
 *
 * Signs the checkout request with HMAC-SHA256, creates a hosted GetPayIn checkout via the
 * v2 integration API, redirects the payer to it, and verifies the signed webhook that
 * confirms the payment. The signing contract matches the GetPayIn server exactly
 * (the same one used by the official GetPayIn SDKs and the WooCommerce plugin).
 */
abstract class AbstractPaylinkPayment extends JPayment
{
    /**
     * The default GetPayIn API base host.
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
                'label' => '',
                'type'  => 'custom',
                'html'  => '<img src="' . VIKPAYLINK_URI . 'paylink/paylink-logo.png" alt="GetPayIn" style="max-width:200px;height:auto;"/>',
            ),
            'authtoken' => array(
                'label' => __('Authentication Token//Copy it from Settings &rarr; Payment Integrations in your GetPayIn dashboard.', 'vikpaylink'),
                'type'  => 'text',
            ),
            'hashtoken' => array(
                'label' => __('Hash Token//The signing secret from the same screen. It is used to sign requests and verify webhooks, and never leaves your server.', 'vikpaylink'),
                'type'  => 'text',
            ),
            'baseurl' => array(
                'label' => __('API Base URL//Leave as https://pay.getpayin.com unless you were given a dedicated GetPayIn domain.', 'vikpaylink'),
                'type'  => 'text',
            ),
            'paymentaction' => array(
                'label' => __('Payment Action//Capture charges immediately. Authorize places a hold to capture later, and requires authorize mode enabled on your account.', 'vikpaylink'),
                'type'  => 'select',
                'options' => array('Capture', 'Authorize'),
            ),
            'installments_enabled' => array(
                'label' => __('Installments//Offer fixed installments on the GetPayIn checkout. Requires installments enabled on your account.', 'vikpaylink'),
                'type'  => 'select',
                'options' => array('No', 'Yes'),
            ),
            'installments' => array(
                'label' => __('Number of Installments//Between 2 and 24. Used only when installments are enabled above.', 'vikpaylink'),
                'type'  => 'text',
            ),
            'paymenttype' => array(
                'label' => __('Payment Type//One-off takes a single payment. Recurring creates a subscription mandate and charges the order total every cycle.', 'vikpaylink'),
                'type'  => 'select',
                'options' => array('One-off', 'Recurring subscription'),
            ),
            'cadence_interval' => array(
                'label' => __('Recurring Interval//The billing period for recurring payments.', 'vikpaylink'),
                'type'  => 'select',
                'options' => array('month', 'week', 'day', 'year'),
            ),
            'cadence_count' => array(
                'label' => __('Recurring Interval Count//How many intervals between charges. 1 with a monthly interval means once a month.', 'vikpaylink'),
                'type'  => 'text',
            ),
            'total_cycles' => array(
                'label' => __('Total Cycles//Optional. Stop after this many charges. Leave empty for an open-ended subscription.', 'vikpaylink'),
                'type'  => 'text',
            ),
            'consent_text' => array(
                'label' => __('Consent Text//Shown to the payer when they authorise recurring charges. Required for recurring payments.', 'vikpaylink'),
                'type'  => 'textarea',
            ),
        );
    }

    /**
     * Creates a GetPayIn checkout for the current order and sends the payer to it.
     *
     * @return  void
     */
    protected function beginTransaction()
    {
        $this->rememberOrderTotal();

        $checkoutUrl = $this->isRecurring() ? $this->createRecurringUrl() : $this->createCheckoutUrl();

        if (!$checkoutUrl) {
            echo '<p class="vikpaylink-error">'
                . __('We could not start the GetPayIn checkout. Please try again or contact the store.', 'vikpaylink')
                . '</p>';

            return;
        }

        $safeUrl = htmlspecialchars($checkoutUrl, ENT_QUOTES, 'UTF-8');

        echo '<div class="vikpaylink-redirect" style="text-align:center;">'
            . '<p>' . __('Redirecting you to the secure GetPayIn checkout&hellip;', 'vikpaylink') . '</p>'
            . '<p><a class="btn btn-primary vikpaylink-paynow" href="' . $safeUrl . '">' . __('Pay Now', 'vikpaylink') . '</a></p>'
            . '<script>window.location.href=' . json_encode($checkoutUrl) . ';</script>'
            . '</div>';
    }

    /**
     * Verifies the signed GetPayIn webhook and reports the payment status to Vik.
     *
     * @param   JPaymentStatus  &$status  The transaction status object.
     *
     * @return  bool   Always true to stop the iteration.
     */
    protected function validateTransaction(JPaymentStatus &$status)
    {
        $payload = $this->readCallbackPayload();

        if (!$payload) {
            $status->appendLog('GetPayIn: empty or unreadable webhook payload.');

            return true;
        }

        $provided = isset($payload['signature']) ? (string) $payload['signature'] : '';

        if ($provided === '' || !$this->verifyCallbackSignature($payload, $provided)) {
            $status->appendLog('GetPayIn: webhook signature verification failed.');

            return true;
        }

        if (!empty($payload['mandate_id'])) {
            $this->rememberMandateId((string) $payload['mandate_id']);
        }

        $invoiceStatus = strtoupper((string) (isset($payload['invoice_status']) ? $payload['invoice_status'] : ''));
        $success = (int) (isset($payload['success']) ? $payload['success'] : 0) === 1;

        if ($success && ($invoiceStatus === 'PAID' || $invoiceStatus === 'AUTHORIZED' || $invoiceStatus === 'CAPTURED' || $invoiceStatus === '')) {
            $status->verified();
            $status->paid($this->resolveOrderTotal());
        } else {
            $message = isset($payload['message']) ? (string) $payload['message'] : '';
            $status->appendLog('GetPayIn: payment not completed (status: ' . $invoiceStatus . '). ' . $message);
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
     * Calls the GetPayIn v2 init endpoint and returns the hosted checkout URL.
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

        if ($this->getParam('installments_enabled') === 'Yes') {
            $body['installments_enabled'] = '1';
            $body['installments'] = (string) $this->installmentCount();
        }

        $response = $this->httpPost($this->apiBaseUrl() . '/api/v2/integration/init', $body);
        $checkoutUrl = $this->checkoutUrlFrom($response);

        return $checkoutUrl !== '' ? $checkoutUrl : false;
    }

    /**
     * Creates a GetPayIn recurring mandate and returns the hosted setup-charge URL, remembering
     * the returned mandate id (`POST /api/v2/integration/recurring/init`).
     *
     * @return  mixed   The checkout URL on success, otherwise false.
     */
    protected function createRecurringUrl()
    {
        $signed = $this->buildRecurringFields();
        $signature = $this->signValues(array_values($signed));

        $body = $signed;
        $body['token'] = (string) $this->getParam('authtoken');
        $body['signature'] = $signature;

        $response = $this->httpPost($this->apiBaseUrl() . '/api/v2/integration/recurring/init', $body);
        $checkoutUrl = $this->checkoutUrlFrom($response);

        if ($checkoutUrl === '') {
            return false;
        }

        if (is_array($response) && !empty($response['mandate_id'])) {
            $this->rememberMandateId((string) $response['mandate_id']);
        }

        return $checkoutUrl;
    }

    /**
     * Builds the SIGNED init fields in the exact order the GetPayIn v2 endpoint concatenates
     * them (the FormRequest `rules()` order, mirrored from the official SDKs):
     * first_name, last_name, email, order_title, order_amount, [address, city, country, state,]
     * currency, [redirection_url, webhook_url, order_details]. Optional fields are omitted
     * entirely when empty, exactly as the server skips absent values.
     *
     * @return  array   The ordered field map.
     */
    protected function buildSignedFields()
    {
        list($firstName, $lastName) = $this->resolveCustomerName();
        $billing = $this->resolveBilling();

        $fields = array(
            'first_name'   => $firstName,
            'last_name'    => $lastName,
            'email'        => (string) $this->get('custmail', ''),
            'order_title'  => (string) $this->get('transaction_name', 'Order ' . $this->get('sid', '')),
            'order_amount' => $this->formatAmount($this->resolveOrderTotal()),
        );

        $this->appendIfFilled($fields, 'address', $billing['address']);
        $this->appendIfFilled($fields, 'city', $billing['city']);
        $this->appendIfFilled($fields, 'country', $billing['country']);
        $this->appendIfFilled($fields, 'state', $billing['state']);

        $fields['currency'] = (string) $this->get('transaction_currency', 'EUR');

        $this->appendIfFilled($fields, 'redirection_url', (string) $this->get('return_url', ''));
        $this->appendIfFilled($fields, 'webhook_url', (string) $this->get('notify_url', ''));
        $this->appendIfFilled($fields, 'order_details', $this->resolveOrderDetails());

        return $fields;
    }

    /**
     * Builds the SIGNED recurring fields in the exact order the GetPayIn recurring endpoint
     * concatenates them: first_name, last_name, email, order_title, order_amount, currency,
     * cadence_interval, cadence_count, [total_cycles,] consent_text, external_reference,
     * redirection_url, webhook_url. Optional fields are omitted when empty.
     *
     * @return  array   The ordered field map.
     */
    protected function buildRecurringFields()
    {
        list($firstName, $lastName) = $this->resolveCustomerName();

        $fields = array(
            'first_name'       => $firstName,
            'last_name'        => $lastName,
            'email'            => (string) $this->get('custmail', ''),
            'order_title'      => (string) $this->get('transaction_name', 'Order ' . $this->get('sid', '')),
            'order_amount'     => $this->formatAmount($this->resolveOrderTotal()),
            'currency'         => (string) $this->get('transaction_currency', 'EUR'),
            'cadence_interval' => $this->cadenceInterval(),
            'cadence_count'    => (string) $this->cadenceCount(),
        );

        $this->appendIfFilled($fields, 'total_cycles', $this->totalCycles());

        $fields['consent_text'] = $this->consentText();
        $fields['external_reference'] = $this->externalReference();

        $this->appendIfFilled($fields, 'redirection_url', (string) $this->get('return_url', ''));
        $this->appendIfFilled($fields, 'webhook_url', (string) $this->get('notify_url', ''));

        return $fields;
    }

    /**
     * Appends a key to the ordered field map only when the value is non-empty, preserving the
     * server's skip-absent-optional signing semantics.
     *
     * @param   array   &$fields  The field map being built.
     * @param   string  $key      The wire key.
     * @param   string  $value    The candidate value.
     *
     * @return  void
     */
    protected function appendIfFilled(array &$fields, $key, $value)
    {
        if ((string) $value !== '') {
            $fields[$key] = (string) $value;
        }
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
     * Verifies a GetPayIn callback signature against the ordered subset the server signs:
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

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
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
     * back to the email local part or a generic name. GetPayIn requires both to be non-empty.
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
     * Best-effort billing address for the payer, gathered from the common Vik order-detail
     * keys. Every value is optional; empty ones are skipped from the signed request.
     *
     * @return  array  A map with address, city, country and state keys.
     */
    protected function resolveBilling()
    {
        $details = $this->get('details', array());
        $details = is_array($details) ? $details : array();

        return array(
            'address' => $this->firstDetail($details, array('address', 'custaddress', 'billing_address', 'field_address', 'street')),
            'city'    => $this->firstDetail($details, array('city', 'custcity', 'billing_city', 'field_city')),
            'country' => $this->firstDetail($details, array('country', 'custcountry', 'country_code', 'billing_country', 'field_country')),
            'state'   => $this->firstDetail($details, array('state', 'custstate', 'province', 'region', 'billing_state', 'field_state')),
        );
    }

    /**
     * Returns the first non-empty value among the given detail keys.
     *
     * @param   array  $details  The order-detail map.
     * @param   array  $keys     The candidate keys, in priority order.
     *
     * @return  string
     */
    protected function firstDetail(array $details, array $keys)
    {
        foreach ($keys as $key) {
            if (!empty($details[$key])) {
                return (string) $details[$key];
            }
        }

        return '';
    }

    /**
     * Optional free-text order description sent to GetPayIn, taken from the order info when the
     * component provides one. Empty by default so it is skipped from the signature.
     *
     * @return  string
     */
    protected function resolveOrderDetails()
    {
        $details = $this->get('order_details', $this->get('description', ''));

        return is_string($details) ? trim($details) : '';
    }

    /**
     * Whether this gateway is configured to create a recurring subscription mandate.
     *
     * @return  bool
     */
    protected function isRecurring()
    {
        return $this->getParam('paymenttype') === 'Recurring subscription';
    }

    /**
     * The fixed installment count, clamped to the GetPayIn-supported 2–24 range.
     *
     * @return  int
     */
    protected function installmentCount()
    {
        return max(2, min(24, (int) $this->getParam('installments')));
    }

    /**
     * The recurring billing interval, defaulting to monthly.
     *
     * @return  string
     */
    protected function cadenceInterval()
    {
        $interval = (string) $this->getParam('cadence_interval');

        return in_array($interval, array('day', 'week', 'month', 'year'), true) ? $interval : 'month';
    }

    /**
     * The number of intervals between recurring charges, at least 1.
     *
     * @return  int
     */
    protected function cadenceCount()
    {
        return max(1, (int) $this->getParam('cadence_count'));
    }

    /**
     * The optional cap on the number of recurring charges. Empty means open-ended.
     *
     * @return  string
     */
    protected function totalCycles()
    {
        $cycles = (int) $this->getParam('total_cycles');

        return $cycles > 0 ? (string) $cycles : '';
    }

    /**
     * The consent statement shown when authorising recurring charges, with a sensible default.
     *
     * @return  string
     */
    protected function consentText()
    {
        $consent = trim((string) $this->getParam('consent_text'));

        return $consent !== '' ? $consent : __('I authorise recurring charges for this subscription.', 'vikpaylink');
    }

    /**
     * A stable per-order reference so recurring webhooks can be correlated back to the order.
     *
     * @return  string
     */
    protected function externalReference()
    {
        return 'vik_' . $this->get('sid', '0') . '_' . $this->get('oid', '0');
    }

    /**
     * Persists the mandate id returned by a recurring setup, keyed by the order.
     *
     * @param   string  $mandateId  The GetPayIn mandate id.
     *
     * @return  void
     */
    protected function rememberMandateId($mandateId)
    {
        if (function_exists('set_transient')) {
            set_transient('vikpaylink_mandate_' . $this->externalReference(), $mandateId, 60 * MINUTE_IN_SECONDS);
        }
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
     * POSTs form-encoded fields to a GetPayIn endpoint and returns the `data` payload.
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
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $this->logError('request to ' . $url . ' failed: ' . $error);

            return false;
        }

        if ($code < 200 || $code >= 300) {
            $this->logError('HTTP ' . $code . ' from ' . $url . ': ' . substr((string) $raw, 0, 1000));

            return false;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            $this->logError('non-JSON response from ' . $url . ': ' . substr((string) $raw, 0, 500));

            return false;
        }

        return isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;
    }

    /**
     * Reads the hosted checkout URL from an init/recurring response, accepting both the
     * `checkout_url` and `url` keys, and logs the payload when neither is present.
     *
     * @param   mixed  $response  The decoded response payload.
     *
     * @return  string  The checkout URL, or an empty string when absent.
     */
    protected function checkoutUrlFrom($response)
    {
        if (!is_array($response)) {
            return '';
        }

        $url = !empty($response['checkout_url'])
            ? $response['checkout_url']
            : (!empty($response['url']) ? $response['url'] : '');

        if ($url === '') {
            $this->logError('no checkout_url in response: ' . substr((string) json_encode($response), 0, 500));
        }

        return (string) $url;
    }

    /**
     * Writes a diagnostic line to the PHP error log. It never includes the hash token or the
     * request body, so the signing secret cannot leak into logs.
     *
     * @param   string  $message  The message to record.
     *
     * @return  void
     */
    protected function logError($message)
    {
        error_log('GetPayIn VikWP: ' . $message);
    }
}
