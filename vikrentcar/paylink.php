<?php
/**
 * @package     VikPaylink
 * @subpackage  vikrentcar
 * @author      GetPayIn
 * @copyright   Copyright (C) GetPayIn. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://pay.getpayin.com
 */

// No direct access
defined('ABSPATH') or die('No script kiddies please!');

JLoader::import('paylink', VIKPAYLINK_DIR);

/**
 * Collects payments in VikRentCar through the GetPayIn gateway.
 *
 * @since 1.0
 */
class VikRentCarPaylinkPayment extends AbstractPaylinkPayment
{
}
