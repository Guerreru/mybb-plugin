<?php
/***************************************************************************
 *
 *   BBSCoin Api for PHP
 *   Author: BBSCoin Foundation
 *   
 *   Website: https://bbscoin.xyz
 *
 ***************************************************************************/
 
/****************************************************************************
	This program is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.
	
	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
	
	You should have received a copy of the GNU General Public License
	along with this program.  If not, see <http://www.gnu.org/licenses/>.
****************************************************************************/

// Class for site interface
class BBSCoinApiPartner {

    public static function callback($json_data) {
        global $mybb, $lang, $db, $plugins;

        if ($json_data['data']['action'] == 'deposit') {
            if ($json_data['callbackData']['amount'] > 0) {
                $trans_amount = $json_data['callbackData']['amount'];
                $amount = $trans_amount * $mybb->settings['newpoints_bbscoin_pay_ratio'];

                $orderid = date('YmdHis').base_convert($json_data['data']['uin'], 10, 36);
                
                $orderinfo = array(
                	'uid' => $json_data['data']['uin'],
                	'amount' => $amount,
                	'price' => $trans_amount,
                );

            	$query = $db->simple_select('newpoints_bbscoin_orders', '*', " transaction_hash = '" . $db->escape_string($json_data['callbackData']['hash']) . "'", array('limit' => 1));
            	if ($db->num_rows($query) > 0) {
                    return array('success' => true);
            	}

                $db->insert_query("newpoints_bbscoin_orders", array(
                        'orderid' => $orderid,
                        'transaction_hash' => $json_data['callbackData']['hash'],
                        'address' => '',
                        'dateline' => time(),
                ), "transaction_hash");

                newpoints_addpoints($json_data['data']['uin'], $orderinfo['amount']);
                newpoints_log('Deposit From BBSCoin', 'Points:'.$orderinfo['amount'].', BBSCoin: '.$trans_amount.', transaction_hash:'.$json_data['callbackData']['hash'], '', 'callback user', $json_data['data']['uin']);

                //notify user
                bbscoin_send_pm($lang->newpoints_bbscoin_succ, $lang->sprintf($lang->newpoints_bbscoin_succ_desc, $orderinfo['amount'], $json_data['callbackData']['hash']), $json_data['data']['uin'], 1);

                $plugins->run_hooks("newpoints_bbscoin_bbscoin_to_points_callback_succ");
            }

            return array('success' => true);
        } elseif ($json_data['data']['action'] == 'withdraw') {
            if ($json_data['callbackData']['status'] != 'normal') {
                
            	$query = $db->simple_select('newpoints_bbscoin_orders', '*', " orderid = '" . $db->escape_string($json_data['data']['orderid'].'_R') . "'", array('limit' => 1));
            	if ($db->num_rows($query) > 0) {
                    return array('success' => true);
            	}

                $db->insert_query("newpoints_bbscoin_orders", array(
                        'orderid' => $json_data['data']['orderid'].'_R',
                        'transaction_hash' => $json_data['data']['orderid'].'_R',
                        'address' => '',
                        'dateline' => time(),
                ), "transaction_hash");

            	newpoints_addpoints($json_data['data']['uin'], $json_data['data']['points']);
                newpoints_log('Withdraw To BBSCoin Failed', 'Points:'.$json_data['data']['points'].', Order ID:'.$json_data['data']['orderid'], '', 'callback user', $json_data['data']['uin']);

                //notify user
                bbscoin_send_pm($lang->newpoints_bbscoin_withdraw_failed, $lang->sprintf($lang->newpoints_bbscoin_withdraw_failed_desc, $json_data['data']['orderid']), $json_data['data']['uin'], 1);
            }

            return array('success' => true);
        } else {
            return array('success' => false, 'message' => 'error action');
        }
    }
}
