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

    if ($paymentId != $_SERVER['bbscoin_paymentid']) {
        $db->delete_query('newpoints_bbscoin_locks', "uid = '" . $mybb->user['uid'] . "'");
        error($lang->newpoints_bbscoin_deposit_failed.' '.$rsp_data['message']);
    }

    // online wallet
    try {
        $rsp_data = BBSCoinApiWebWallet::checkTransaction($mybb->settings['newpoints_bbscoin_walletd'], $transaction_hash, $paymentId, $mybb->user['uid']);
    } catch (Exception $e) {
        $db->delete_query('newpoints_bbscoin_locks', "uid = '" . $mybb->user['uid'] . "'");
        error('Error '.$e->getCode().','.$e->getMessage());
    }

    $db->delete_query('newpoints_bbscoin_locks', "uid = '" . $mybb->user['uid'] . "'");
    if ($rsp_data['success']) {
        redirect($mybb->settings['bburl'] . "/member.php?action=profile&uid=".$mybb->user['uid'], $lang->newpoints_bbscoin_deposit_wait);
    } else {
        error($lang->newpoints_bbscoin_deposit_failed.' '.$rsp_data['message']);
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

    $real_price = $amount - $mybb->settings['newpoints_bbscoin_withdraw_fee'];

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

    try {
        $rsp_data = BBSCoinApiWebWallet::send($mybb->settings['newpoints_bbscoin_walletd'], $mybb->settings['newpoints_bbscoin_wallet_address'], $real_price, $walletaddress, $orderid, $mybb->user['uid'], $need_point, $mybb->settings['newpoints_bbscoin_withdraw_fee']);
    } catch (Exception $e) {
        $db->delete_query('newpoints_bbscoin_locks', "uid = '" . $mybb->user['uid'] . "'");
        error('Error '.$e->getCode().','.$e->getMessage());
    }

    if ($rsp_data['success'] == true) {
        $db->insert_query("newpoints_bbscoin_orders", array(
                'orderid' => $orderid,
                'transaction_hash' => $rsp_data['result']['transactionHash'],
                'address' => $walletaddress,
                'dateline' => time(),
        ), "transaction_hash");
    	newpoints_addpoints($mybb->user['uid'], -$need_point);

        newpoints_log('Withdraw To BBSCoin', 'Points:'.$need_point.', BBSCoin:'.$amount.', address:'.$walletaddress);
        bbscoin_send_pm($lang->newpoints_bbscoin_withdraw_succ_title, $lang->sprintf($lang->newpoints_bbscoin_withdraw_succ, $rsp_data['result']['transactionHash'], $need_point), $mybb->user['uid'], 1);

        $plugins->run_hooks("newpoints_bbscoin_points_to_bbscoins_succ");

        $db->delete_query('newpoints_bbscoin_locks', "uid = '" . $mybb->user['uid'] . "'");
        redirect($mybb->settings['bburl'] . "/member.php?action=profile&uid=".$mybb->user['uid'], $lang->sprintf($lang->newpoints_bbscoin_withdraw_succ, $rsp_data['result']['transactionHash']));
    } else {
        $db->delete_query('newpoints_bbscoin_locks', "uid = '" . $mybb->user['uid'] . "'");
        error($lang->newpoints_bbscoin_fail.' '.$rsp_data['message']);
    }

}
