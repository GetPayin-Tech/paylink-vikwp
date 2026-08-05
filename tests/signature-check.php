<?php
/**
 * Offline signature self-check for the GetPayIn VikWP gateway.
 *
 * The Joomla/VikWP framework classes only exist inside a live install, so this harness
 * provides the few shims the gateway touches (`JLoader`, `JPayment`), loads the real
 * `paylink.php`, and drives its ACTUAL `signValues()` / `verifyCallbackSignature()` methods
 * through reflection. The expected values are the shared golden vectors used by every other
 * GetPayIn SDK, so a drift in the signing contract fails this check.
 *
 * @package     VikPaylink
 * @subpackage  tests
 * @author      GetPayIn
 * @copyright   Copyright (C) GetPayIn. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://pay.getpayin.com
 */

define('ABSPATH', __DIR__);
define('VIKPAYLINK_URI', 'https://example.test/');

/**
 * Minimal stand-in for Joomla's class autoloader.
 */
class JLoader
{
    /**
     * No-op import; the real loader pulls in framework adapters we do not need here.
     *
     * @param   string  $key   The dotted resource key.
     * @param   mixed   $base  Optional base path.
     *
     * @return  bool
     */
    public static function import($key, $base = null)
    {
        return true;
    }
}

/**
 * Minimal stand-in for VikWP's JPayment base, exposing only the order-info accessors and the
 * admin-parameter reader the gateway relies on.
 */
abstract class JPayment
{
    /**
     * The order info array.
     *
     * @var array
     */
    protected $order = array();

    /**
     * The admin configuration values.
     *
     * @var array
     */
    protected $params = array();

    /**
     * @param   string  $alias   The requesting plugin alias.
     * @param   mixed   $order   The order info.
     * @param   array   $params  The admin configuration.
     */
    public function __construct($alias, $order, $params = array())
    {
        $this->order = is_array($order) ? $order : array();
        $this->params = is_array($params) ? $params : array();
    }

    /**
     * @param   string  $key      The order-info key.
     * @param   mixed   $default  The fallback value.
     *
     * @return  mixed
     */
    public function get($key, $default = null)
    {
        return array_key_exists($key, $this->order) ? $this->order[$key] : $default;
    }

    /**
     * @param   string  $key    The order-info key.
     * @param   mixed   $value  The value to store.
     *
     * @return  void
     */
    public function set($key, $value)
    {
        $this->order[$key] = $value;
    }

    /**
     * @param   string  $key  The admin parameter key.
     *
     * @return  mixed
     */
    public function getParam($key)
    {
        return array_key_exists($key, $this->params) ? $this->params[$key] : null;
    }
}

require __DIR__ . '/../paylink.php';

/**
 * Concrete gateway so the abstract class can be instantiated for testing.
 */
class TestPaylinkPayment extends AbstractPaylinkPayment
{
}

$hashToken = 'test_hash_token_abc123';

$gateway = new TestPaylinkPayment('vikbooking', array(), array('hashtoken' => $hashToken));

$reflection = new ReflectionObject($gateway);

$signValues = $reflection->getMethod('signValues');
$signValues->setAccessible(true);

$verify = $reflection->getMethod('verifyCallbackSignature');
$verify->setAccessible(true);

$failures = 0;

/**
 * Asserts a boolean expectation and records failures.
 *
 * @param   string  $name  The case label.
 * @param   bool    $ok    Whether it passed.
 * @param   string  $extra Optional diagnostic line.
 *
 * @return  void
 */
$assert = function ($name, $ok, $extra = '') use (&$failures) {
    printf("%-6s %s\n", $ok ? 'OK' : 'FAIL', $name);

    if (!$ok) {
        $failures++;

        if ($extra !== '') {
            echo '       ' . $extra . "\n";
        }
    }
};

$initSignature = $signValues->invoke($gateway, array('Jane', 'Roe', 'jane@example.com', 'Basic', '100.00', 'EGP'));
$assert(
    'init request signature',
    $initSignature === 'TSN92zc8DASlsgxV8jRgAIkDjeePVScATZ9y5ylfWUk=',
    'got=' . $initSignature
);

$webhookGolden = '+obQT6T6ZLgmu3sXBEcvXHebHHHaAbuLwIi5yUZFANE=';
$webhookPayload = array(
    'success'        => 1,
    'invoice_id'     => 123,
    'invoice_status' => 'PAID',
    'message'        => '',
    'signature'      => $webhookGolden,
);
$assert(
    'webhook signature accepted',
    $verify->invoke($gateway, $webhookPayload, $webhookGolden) === true
);
$assert(
    'webhook signature rejected when tampered',
    $verify->invoke($gateway, $webhookPayload, 'not-the-signature') === false
);

$subGolden = 'rk5BFKdp6JRLCX70/iPThwaybLuHV9t/AXaBRPA/J2E=';
$subPayload = array(
    'success'             => 1,
    'invoice_id'          => 555,
    'invoice_status'      => 'PAID',
    'message'             => 'ok',
    'mandate_id'          => 'M-1',
    'external_reference'  => 'sub_1',
    'subscription_status' => 'active',
    'signature'           => $subGolden,
);
$assert(
    'subscription webhook signature accepted',
    $verify->invoke($gateway, $subPayload, $subGolden) === true
);

$initFull = $signValues->invoke($gateway, array('John', 'Doe', 'john@example.com', 'Gold Plan', '250', '1 Main St', 'Cairo', 'EG', 'C', 'USD', 'https://shop.example.com/return', 'https://shop.example.com/webhook', 'note'));
$assert(
    'init full (billing + order_details) signature',
    $initFull === 'HpF+SI9LirGLrMLvVeFVbgxg4P+reftqQoBd3PEGc9M=',
    'got=' . $initFull
);

$recurringFull = $signValues->invoke($gateway, array('Sam', 'Doe', 'sam@example.com', 'Gold', '250', 'USD', 'month', '1', '12', '2027-01-01', 'I authorise monthly charges.', 'sub_1', 'https://shop.example.com/r', 'https://shop.example.com/w'));
$assert(
    'recurring full signature',
    $recurringFull === 'WhKcyD4mgwk+/3N3vpKLrPuADXt0tduRxYpZ7oeucOM=',
    'got=' . $recurringFull
);

$recurringMinimal = $signValues->invoke($gateway, array('Sam', 'Doe', 'sam@example.com', 'Gold', '250', 'USD', 'month', '1', 'I authorise monthly charges.'));
$assert(
    'recurring minimal signature',
    $recurringMinimal === '+iqRMH/msH9xOx7JrzuRVn3VBzhfNQNaG72hbtzCl+c=',
    'got=' . $recurringMinimal
);

$buildSigned = $reflection->getMethod('buildSignedFields');
$buildSigned->setAccessible(true);

$buildRecurring = $reflection->getMethod('buildRecurringFields');
$buildRecurring->setAccessible(true);

/**
 * Builds a gateway seeded with the given order info and admin params.
 *
 * @param   array  $order   The order info.
 * @param   array  $params  The admin params.
 *
 * @return  TestPaylinkPayment
 */
$makeGateway = function (array $order, array $params) use ($hashToken) {
    $params['hashtoken'] = $hashToken;

    return new TestPaylinkPayment('vikbooking', $order, $params);
};

$fullOrder = array(
    'details'              => array('first_name' => 'John Doe', 'address' => '1 Main St', 'city' => 'Cairo', 'country' => 'EG', 'state' => 'C'),
    'custmail'             => 'john@example.com',
    'transaction_name'     => 'Gold Plan',
    'total_to_pay'         => 250,
    'transaction_currency' => 'USD',
    'return_url'           => 'https://shop.example.com/return',
    'notify_url'           => 'https://shop.example.com/webhook',
    'order_details'        => 'note',
);
$fullKeys = array_keys($buildSigned->invoke($makeGateway($fullOrder, array())));
$assert(
    'buildSignedFields full order matches server field order',
    $fullKeys === array('first_name', 'last_name', 'email', 'order_title', 'order_amount', 'address', 'city', 'country', 'state', 'currency', 'redirection_url', 'webhook_url', 'order_details'),
    'got=' . implode(',', $fullKeys)
);

$minimalOrder = array(
    'custmail'             => 'jane@example.com',
    'transaction_name'     => 'Basic',
    'total_to_pay'         => 100,
    'transaction_currency' => 'EGP',
);
$minimalKeys = array_keys($buildSigned->invoke($makeGateway($minimalOrder, array())));
$assert(
    'buildSignedFields skips empty optionals',
    $minimalKeys === array('first_name', 'last_name', 'email', 'order_title', 'order_amount', 'currency'),
    'got=' . implode(',', $minimalKeys)
);

$recurringParams = array(
    'paymenttype'      => 'Recurring subscription',
    'cadence_interval' => 'month',
    'cadence_count'    => '1',
    'total_cycles'     => '12',
    'consent_text'     => 'I authorise monthly charges.',
);
$recurringKeys = array_keys($buildRecurring->invoke($makeGateway($fullOrder, $recurringParams)));
$assert(
    'buildRecurringFields matches server field order',
    $recurringKeys === array('first_name', 'last_name', 'email', 'order_title', 'order_amount', 'currency', 'cadence_interval', 'cadence_count', 'total_cycles', 'consent_text', 'external_reference', 'redirection_url', 'webhook_url'),
    'got=' . implode(',', $recurringKeys)
);

$apiBaseUrl = $reflection->getMethod('apiBaseUrl');
$apiBaseUrl->setAccessible(true);

$assert(
    'apiBaseUrl adds https:// when the scheme is missing',
    $apiBaseUrl->invoke($makeGateway(array(), array('baseurl' => 'pay.getpayin.com'))) === 'https://pay.getpayin.com'
);
$assert(
    'apiBaseUrl defaults to https://pay.getpayin.com when empty',
    $apiBaseUrl->invoke($makeGateway(array(), array('baseurl' => ''))) === 'https://pay.getpayin.com'
);
$assert(
    'apiBaseUrl preserves an explicit scheme and trims the trailing slash',
    $apiBaseUrl->invoke($makeGateway(array(), array('baseurl' => 'https://custom.example.com/'))) === 'https://custom.example.com'
);

echo "\n";

if ($failures === 0) {
    echo "ALL SIGNATURE CHECKS PASSED\n";
    exit(0);
}

echo $failures . " CHECK(S) FAILED\n";
exit(1);
