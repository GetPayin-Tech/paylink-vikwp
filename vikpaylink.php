<?php
/*
Plugin Name:  GetPayIn for VikWP
Plugin URI:   https://github.com/GetPayin-Tech/paylink-vikwp
Description:  GetPayIn integration to collect payments through the Vik plugins (VikBooking, VikRentCar, VikRentItems, VikAppointments, VikRestaurants).
Version:      1.2.0
Requires at least: 5.6
Requires PHP: 7.2
Author:       GetPayIn
Author URI:   https://pay.getpayin.com
License:      GPL2
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  vikpaylink
Domain Path:  /languages
Update URI:   https://github.com/GetPayin-Tech/paylink-vikwp
*/

// No direct access
defined('ABSPATH') or die('No script kiddies please!');

// require utils functions
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'utils.php';

define('VIKPAYLINK_LANG', basename(dirname(__FILE__)) . DIRECTORY_SEPARATOR . 'languages');

define('VIKPAYLINKVERSION', '1.2.0');

add_action('init', function () {
    JFactory::getLanguage()->load('vikpaylink', VIKPAYLINK_LANG);
});

/**
 * The Vik components this gateway plugs into. For each one the plugin registers the
 * discovery filter (so the payment appears in the list) and the loader action (so the
 * gateway class is instantiated when `paylink` is dispatched).
 */
$vikpaylink_components = array('vikrestaurants', 'vikrentitems', 'vikrentcar', 'vikappointments', 'vikbooking');

foreach ($vikpaylink_components as $vikpaylink_component) {
    /**
     * Pushes the GetPayIn gateway into the supported payments of the component.
     *
     * @param   array   $drivers  The current list of supported drivers.
     *
     * @return  array   The updated drivers list.
     */
    add_filter("get_supported_payments_{$vikpaylink_component}", function ($drivers) use ($vikpaylink_component) {
        $driver = vikpaylink_get_payment_path($vikpaylink_component);

        if ($driver) {
            $drivers[] = $driver;
        }

        return $drivers;
    });

    /**
     * Loads the GetPayIn payment handler when dispatched by the component.
     *
     * @param   array   &$drivers  A list of payment classnames.
     * @param   string  $payment   The name of the invoked payment.
     *
     * @return  void
     */
    add_action("load_payment_gateway_{$vikpaylink_component}", function (&$drivers, $payment) use ($vikpaylink_component) {
        if ($payment == 'paylink') {
            $classname = vikpaylink_load_payment($vikpaylink_component);

            if ($classname) {
                $drivers[] = $classname;
            }
        }
    }, 10, 2);
}

/**
 * Provides the GetPayIn logo to VikBooking's order confirmation screen.
 *
 * @param   array   $logo   An array with the payment name, logo base path and base URI.
 *
 * @return  array   The updated logo information.
 */
add_filter('vikbooking_oconfirm_payment_logo', function ($logo) {
    if ($logo['name'] == 'paylink') {
        $logo['path'] = VIKPAYLINK_DIR . DIRECTORY_SEPARATOR . 'vikbooking' . DIRECTORY_SEPARATOR . 'paylink.png';
        $logo['uri']  = VIKPAYLINK_URI . 'vikbooking/paylink.png';
    }

    return $logo;
});
