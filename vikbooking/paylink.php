<?php
/**
 * @package     VikPaylink
 * @subpackage  vikbooking
 * @author      GetPayIn
 * @copyright   Copyright (C) GetPayIn. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @link        https://pay.getpayin.com
 */

// No direct access
defined('ABSPATH') or die('No script kiddies please!');

JLoader::import('paylink', VIKPAYLINK_DIR);

/**
 * VikBooking does not provide a return_url to the after-validation stage; build one and
 * route it following the shortcodes standards so the payer lands back on the order.
 *
 * @param   AbstractPaylinkPayment  &$payment  The payment instance.
 * @param   int                     $res       The validation outcome.
 *
 * @return  void
 */
add_action('payment_on_after_validation_vikbooking', function (&$payment, $res) {
    if (!$payment->isDriver('paylink')) {
        return;
    }

    $url = 'index.php?option=com_vikbooking&task=vieworder&sid=' . $payment->get('sid') . '&ts=' . $payment->get('ts');

    $model  = JModel::getInstance('vikbooking', 'shortcodes', 'admin');
    $itemid = $model->all('post_id');

    if (count($itemid)) {
        $url = JRoute::_($url . '&Itemid=' . $itemid[0]->post_id, false);
    }

    JFactory::getApplication()->redirect($url);
    exit;
}, 10, 2);

/**
 * Collects payments in VikBooking through the PayLink gateway.
 *
 * @since 1.0
 */
class VikBookingPaylinkPayment extends AbstractPaylinkPayment
{
}
