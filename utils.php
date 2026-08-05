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

// Define plugin base path
define('VIKPAYLINK_DIR', dirname(__FILE__));
// Define plugin base URI
define('VIKPAYLINK_URI', plugin_dir_url(__FILE__));

/**
 * Imports the gateway file for the given component and returns the classname
 * that the caller will instantiate.
 *
 * @param   string  $plugin  The name of the caller component.
 *
 * @return  mixed   The payment classname when available, otherwise false.
 */
function vikpaylink_load_payment($plugin)
{
    if (!JLoader::import("{$plugin}.paylink", VIKPAYLINK_DIR)) {
        return false;
    }

    return ucwords($plugin) . 'PaylinkPayment';
}

/**
 * Returns the path of the gateway file for the given component.
 *
 * @param   string  $plugin  The name of the caller component.
 *
 * @return  mixed   The path when the file exists, otherwise false.
 */
function vikpaylink_get_payment_path($plugin)
{
    $path = VIKPAYLINK_DIR . DIRECTORY_SEPARATOR . $plugin . DIRECTORY_SEPARATOR . 'paylink.php';

    if (!is_file($path)) {
        return false;
    }

    return $path;
}
