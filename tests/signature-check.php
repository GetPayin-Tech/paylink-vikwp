<?php
/**
 * Offline signature self-check for the PayLink VikWP gateway.
 *
 * The Joomla/VikWP framework classes only exist inside a live install, so this harness
 * provides the few shims the gateway touches (`JLoader`, `JPayment`), loads the real
 * `paylink.php`, and drives its ACTUAL `signValues()` / `verifyCallbackSignature()` methods
 * through reflection. The expected values are the shared golden vectors used by every other
 * PayLink SDK, so a drift in the signing contract fails this check.
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

echo "\n";

if ($failures === 0) {
    echo "ALL SIGNATURE CHECKS PASSED\n";
    exit(0);
}

echo $failures . " CHECK(S) FAILED\n";
exit(1);
