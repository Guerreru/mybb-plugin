<?php
if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

if (!$mybb->user['uid']) {
	return;	
}

if ($mybb->input['action'] == "bbscoin")
{
	if (!$mybb->settings['newpoints_bbscoin_wallet_address']) {
        error($lang->newpoints_bbscoin_no_address);
    }

    if (!$mybb->settings['newpoints_bbscoin_pay_to_bbscoin']) {
        $bbscoin_withdraw = 'none';
    }

    $plugins->run_hooks("newpoints_bbscoin_page_start");


    eval("\$page = \"".$templates->get('newpoints_bbscoin_main')."\";");

    $plugins->run_hooks("newpoints_bbscoin_page_end");

	output_page($page);
} elseif ($mybb->input['action'] == "bbscoin_to_points") 
{
    verify_post_check($mybb->input['postcode']);

    $plugins->run_hooks("newpoints_bbscoin_bbscoin_to_points_start");

	// load language files
	newpoints_lang_load('newpoints_bbscoin');

	$query = $db->simple_select('newpoints_bbscoin_locks', '*', " uid = '" . $mybb->user['uid'] . "'", array('limit' => 1));
	if ($db->num_rows($query) > 0) {
        $lockinfo = $db->fetch_array($query);
        if (time() - $lockinfo['dateline'] > 10) {
    	    $db->delete_query('newpoints_bbscoin_locks', "uid = '" . $mybb->user['uid'] . "'");
        }
        error($lang->newpoints_bbscoin_cc);
	} else {
        $lockdata = array(
            'uid' => $mybb->user['uid'],
            'dateline' => time()
        );
        $db->insert_query("newpoints_bbscoin_locks", $lockdata, "uid");
    }

    $orderid = date('YmdHis').base_convert($mybb->user['uid'], 10, 36);
    $transaction_hash = trim($mybb->input['transaction_hash']);
    $paymentId = trim($mybb->input['paymentId']);

	$query = $db->simple_select('newpoints_bbscoin_orders', '*', " transaction_hash = '" . $db->escape_string($transaction_hash) . "'", array('limit' => 1));
	if ($db->num_rows($query) > 0) {
        $db->delete_query('newpoints_bbscoin_locks', "uid = '" . $mybb->user['uid'] . "'");
    	error($lang->newpoints_bbscoin_used);
	}

    $rsp_data = BBSCoinApi::getTransaction($mybb->settings['newpoints_bbscoin_walletd'], $transaction_hash); 
    $status_rsp_data = BBSCoinApi::getStatus($mybb->settings['newpoints_bbscoin_walletd']); 

    $blockCount = $status_rsp_data['result']['blockCount'];
    $transactionBlockIndex = $rsp_data['result']['transaction']['blockIndex'];
    $confirmed = $blockCount - $transactionBlockIndex + 1;
    if ($blockCount <= 0 || $transactionBlockIndex <= 0 || $confirmed <= $mybb->settings['newpoints_bbscoin_confirmed_blocks']) {
        $db->delete_query('newpoints_bbscoin_locks', "uid = '" . $mybb->user['uid'] . "'");
    	error($lang->sprintf($lang->newpoints_bbscoin_notconfirmed, $mybb->settings['newpoints_bbscoin_confirmed_blocks']));
    }

    $trans_amount = 0;

    if ($rsp_data['result']['transaction']['transfers']) {
        foreach ($rsp_data['result']['transaction']['transfers'] as $transfer_item) {
            if ($transfer_item['address'] == $mybb->settings['newpoints_bbscoin_wallet_address']) {
                $trans_amount += $transfer_item['amount'];
            }
        }
    }

    $trans_amount = $trans_amount / 100000000;
    $amount = $trans_amount * $mybb->settings['newpoints_bbscoin_pay_ratio'];

    $orderinfo = array(
    	'uid' => $mybb->user['uid'],
    	'amount' => $amount,
    	'price' => $trans_amount,
    );

    if ($paymentId == $_SERVER['bbscoin_paymentid'] && strtolower($rsp_data['result']['transaction']['paymentId']) == strtolower($paymentId)) {
        $db->insert_query("newpoints_bbscoin_orders", array(
                'orderid' => $orderid,
                'transaction_hash' => $transaction_hash,
                'address' => '',
                'dateline' => time(),
        ), "transaction_hash");
    	newpoints_addpoints($mybb->user['uid'], $orderinfo['amount']);

        newpoints_log('Deposit From BBSCoin', 'Points:'.$orderinfo['amount'].', BBSCoin: '.$trans_amount.', transaction_hash:'.$transaction_hash);
        bbscoin_send_pm($lang->newpoints_bbscoin_succ, $lang->sprintf($lang->newpoints_bbscoin_succ_desc, $orderinfo['amount'], $transaction_hash), $mybb->user['uid'], 1);

        $plugins->run_hooks("newpoints_bbscoin_bbscoin_to_points_succ");

        $db->delete_query('newpoints_bbscoin_locks', "uid = '" . $mybb->user['uid'] . "'");
        redirect($mybb->settings['bburl'] . "/member.php?action=profile&uid=".$mybb->user['uid'], $lang->newpoints_bbscoin_succ);
    } else {
        $db->delete_query('newpoints_bbscoin_locks', "uid = '" . $mybb->user['uid'] . "'");
        error($lang->newpoints_bbscoin_paymentid_error);
    }
} elseif ($mybb->input['action'] == "points_to_bbscoin") 
{
    verify_post_check($mybb->input['postcode']);

    $plugins->run_hooks("newpoints_bbscoin_points_to_bbscoins_start");

	// load language files
	newpoints_lang_load('newpoints_bbscoin');

    if(!$mybb->settings['newpoints_bbscoin_pay_to_bbscoin']) {
    	error($lang->newpoints_bbscoin_close_withdraw);
    }

    $amount = $mybb->input['amount'];
    $need_point = ceil((($amount / $mybb->settings['newpoints_bbscoin_pay_to_coin_ratio']) * 100)) / 100;

    if ($need_point < 1) {
    	error($lang->newpoints_bbscoin_least);
    }

    $walletaddress = trim($mybb->input['walletaddress']);

    if ($mybb->settings['newpoints_bbscoin_wallet_address'] == $walletaddress) {
        error($lang->newpoints_bbscoin_withdraw_error);
    }

    $real_price = $amount * 100000000 - ($mybb->settings['newpoints_bbscoin_withdraw_fee'] * 100000000);

    if ($real_price <= 0) {
        error($lang->newpoints_bbscoin_withdraw_too_low);
    }

	$query = $db->simple_select('newpoints_bbscoin_locks', '*', " uid = '" . $mybb->user['uid'] . "'", array('limit' => 1));
	if ($db->num_rows($query) > 0) {
        $lockinfo = $db->fetch_array($query);
        if (time() - $lockinfo['dateline'] > 10) {
    	    $db->delete_query('newpoints_bbscoin_locks', "uid = '" . $mybb->user['uid'] . "'");
        }
        error($lang->newpoints_bbscoin_cc);
	} else {
        $lockdata = array(
            'uid' => $mybb->user['uid'],
            'dateline' => time()
        );
        $db->insert_query("newpoints_bbscoin_locks", $lockdata, "uid");
    }

    if ($need_point > $mybb->user['newpoints']) {
        $db->delete_query('newpoints_bbscoin_locks', "uid = '" . $mybb->user['uid'] . "'");
    	error($lang->newpoints_bbscoin_no_enough);
    }

    $orderid = date('YmdHis').base_convert($mybb->user['uid'], 10, 36);

    newpoints_addpoints($mybb->user['uid'], -$need_point);
    newpoints_log('Withdraw To BBSCoin Start', 'Points:'.$need_point.', BBSCoin:'.$amount.', address:'.$walletaddress.', Order ID:'.$orderid);

    $rsp_data = BBSCoinApi::sendTransaction($mybb->settings['newpoints_bbscoin_walletd'], $mybb->settings['newpoints_bbscoin_wallet_address'], $real_price, $walletaddress, $mybb->settings['newpoints_bbscoin_withdraw_fee'] * 100000000);

    if ($rsp_data['result']['transactionHash']) {
        $db->insert_query("newpoints_bbscoin_orders", array(
                'orderid' => $orderid,
                'transaction_hash' => $rsp_data['result']['transactionHash'],
                'address' => $walletaddress,
                'dateline' => time(),
        ), "transaction_hash");

        newpoints_log('Withdraw To BBSCoin Success', 'Points:'.$need_point.', BBSCoin:'.$amount.', address:'.$walletaddress.', Order ID:'.$orderid);
        bbscoin_send_pm($lang->newpoints_bbscoin_withdraw_succ_title, $lang->sprintf($lang->newpoints_bbscoin_withdraw_succ, $rsp_data['result']['transactionHash'], $need_point), $mybb->user['uid'], 1);

        $plugins->run_hooks("newpoints_bbscoin_points_to_bbscoins_succ");

        $db->delete_query('newpoints_bbscoin_locks', "uid = '" . $mybb->user['uid'] . "'");
        redirect($mybb->settings['bburl'] . "/member.php?action=profile&uid=".$mybb->user['uid'], $lang->sprintf($lang->newpoints_bbscoin_withdraw_succ, $rsp_data['result']['transactionHash'], $need_point));
    } else {
        newpoints_addpoints($mybb->user['uid'], $need_point);
        newpoints_log('Withdraw To BBSCoin Refund', 'Points:'.$need_point.', BBSCoin:'.$amount.', address:'.$walletaddress.', Order ID:'.$orderid);
        $db->delete_query('newpoints_bbscoin_locks', "uid = '" . $mybb->user['uid'] . "'");
        error($lang->newpoints_bbscoin_fail);
    }

}
