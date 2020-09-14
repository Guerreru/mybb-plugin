<?php
/***************************************************************************
 *
 *   BBSCoin Plugin
 *	 Author: BBSCoin Foundation
 *   
 *   Website: https://bbscoin.xyz
 *
 *   Dependency: NewPoints Plugin
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

// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

$plugins->add_hook("global_intermediate", "newpoints_bbscoin_nav", 10);
$plugins->add_hook("newpoints_start", "newpoints_bbscoin", 10);

function newpoints_bbscoin_info()
{
	/**
	 * Array of information about the plugin.
	 * name: The name of the plugin
	 * description: Description of what the plugin does
	 * website: The website the plugin is maintained at (Optional)
	 * author: The name of the author of the plugin
	 * authorsite: The URL to the website of the author (Optional)
	 * version: The version number of the plugin
	 * guid: Unique ID issued by the MyBB Mods site for version checking
	 * compatibility: A CSV list of MyBB versions supported. Ex, "121,123", "12*". Wildcards supported.
	 */
	return array(
		"name"			=> "BBSCoin Exchange",
		"description"	=> "You can use this plugin exchange between BBSCoin and Points.",
		"website"		=> "https://bbscoin.xyz",
		"author"		=> "BBSCoin Foundation",
		"authorsite"	=> "https://bbscoin.xyz",
		"version"		=> "2.0.0",
		"guid" 			=> "",
		"compatibility" => "*"
	);
}


function newpoints_bbscoin_uninstall()
{
	global $db;
	$collation = $db->build_create_table_collation();

	// drop tables
	if($db->table_exists("newpoints_bbscoin_locks"))
    {
        $db->drop_table('newpoints_bbscoin_locks');
	}
	newpoints_remove_templates("'newpoints_bbscoin_links','newpoints_bbscoin_main'");

	require_once MYBB_ROOT . 'inc/adminfunctions_templates.php';
	find_replace_templatesets("header_welcomeblock_member", '#'.preg_quote('{$newpoints_bbscoin_links}').'#', '', 0);
}

function newpoints_bbscoin_activate()
{
	global $db, $mybb;
	// add settings
	// take a look at inc/plugins/newpoints.php to know exactly what each parameter means
	newpoints_add_setting('newpoints_bbscoin_pay_ratio', 'newpoints_bbscoin', 'BBSCoin -> Point', 'BBSCoin to Point Exchange Rate', 'text', "0.1", 1);
	newpoints_add_setting('newpoints_bbscoin_pay_to_coin_ratio', 'newpoints_bbscoin', 'BBSCoin <- Point', 'Point To BBSCoin Exchange Rate', 'text', "10", 2);
	newpoints_add_setting('newpoints_bbscoin_pay_to_bbscoin', 'newpoints_bbscoin', 'Withdraw BBSCoin', 'Allow get BBSCoin by points', 'yesno', 1, 3);
	newpoints_add_setting('newpoints_bbscoin_wallet_address', 'newpoints_bbscoin', 'Site BBSCoin Wallet', 'Your wallet address to receive BBSCoin', 'text', '', 4);
	newpoints_add_setting('newpoints_bbscoin_walletd', 'newpoints_bbscoin', 'Walletd Service URL', 'Your walletd or web wallet url (Web wallet is https://api.bbs.money)', 'text', 'http://127.0.0.1:8070/json_rpc', 5);
	newpoints_add_setting('newpoints_bbscoin_confirmed_blocks', 'newpoints_bbscoin', 'Transfer Required Confirmed Blocks', 'The confirmation number of transaction', 'text', '1', 6);
	newpoints_add_setting('newpoints_bbscoin_siteid', 'newpoints_bbscoin', 'BBSCoin Web Wallet Site Id', 'Your Web Wallet Site Id (Walletd do not need to be filled in)', 'text', '', 7);
	newpoints_add_setting('newpoints_bbscoin_sitekey', 'newpoints_bbscoin', 'BBSCoin Web Wallet Site Key', 'Your Web Wallet Site Key (Walletd do not need to be filled in)', 'text', '', 8);
	newpoints_add_setting('newpoints_bbscoin_withdraw_fee', 'newpoints_bbscoin', 'Withdraw Fee', 'The withdraw fee', 'text', '1', 9);
	newpoints_add_setting('newpoints_bbscoin_apimode', 'newpoints_bbscoin', 'Api Mode', 'Select the API mode', "select\n0=Walletd\n1=Web Wallet API\n2=Web Wallet Webhook", '0', 10);
	newpoints_add_setting('newpoints_bbscoin_nosecure', 'newpoints_bbscoin', 'Disable HTTPS', 'For server cannot request https', 'yesno', 0, 11);
	rebuild_settings();
	global $db;
	$collation = $db->build_create_table_collation();
	
	// create tables
	if(!$db->table_exists("newpoints_bbscoin_orders"))
    {
		$db->write_query("CREATE TABLE `".TABLE_PREFIX."newpoints_bbscoin_orders` (
              `orderid` char(50) NOT NULL,
              `transaction_hash` char(64) NOT NULL,
              `address` char(100) NOT NULL,
              `dateline` int(10) unsigned NOT NULL DEFAULT '0',
              PRIMARY KEY (`orderid`),
              UNIQUE `transaction_hash` (`transaction_hash`),
              KEY `address` (`address`, `dateline`)
            ) ENGINE=MyISAM{$collation}");
	}

	if(!$db->table_exists("newpoints_bbscoin_locks"))
    {
		$db->write_query("CREATE TABLE `".TABLE_PREFIX."newpoints_bbscoin_locks` (
              `uid` int(10) unsigned NOT NULL DEFAULT '0',
              `dateline` int(10) unsigned NOT NULL DEFAULT '0',
              PRIMARY KEY (`uid`)
            ) ENGINE=MyISAM{$collation}");
	}

	newpoints_add_template('newpoints_bbscoin_links', '<li><a href="{$mybb->settings[\'bburl\']}/newpoints.php?action=bbscoin">{$lang->newpoints_bbscoin_usercp_nav_name}</a></li>');
	newpoints_add_template('newpoints_bbscoin_main', '<html>
<head>
<title>{$mybb->settings[\'bbname\']} - {$lang->newpoints_bank}</title>
{$headerinclude}
{$javascript}
</head>
<body>
{$header}
<table width="100%" border="0" align="center">
<tr>
<td valign="top">

<form action="newpoints.php" method="POST" target="_blank">
<input type="hidden" name="postcode" value="{$mybb->post_code}" />
<input type="hidden" name="action" value="bbscoin_to_points" />
<table border="0" cellspacing="{$theme[\'borderwidth\']}" cellpadding="{$theme[\'tablespace\']}" class="tborder">
<tr>
<td class="thead" colspan="2"><strong>{$lang->newpoints_bbscoin_topoint}</strong></td>
</tr>
<tr>
<td class="trow1" width="100%" colspan="2"><strong>{$lang->newpoints_bbscoin_topoint_desc}{$mybb->settings[\'newpoints_bbscoin_pay_ratio\']}</td>
</tr>
<tr>
<td class="trow2" width="50%"><strong>{$lang->newpoints_bbscoin_topoint_deposit}:</strong></td>
<td class="trow2" width="50%"><input type="text" name="amount" id="addfundamount" onkeyup="addcalcredit()" value="" class="textbox" size="20" /> {$lang->newpoints_bbscoin_points} {$lang->newpoints_bbscoin_topoint_cacl} <span id="desamount">0</span> BBS</td>
</tr>
<tr>
<td class="trow1" width="50%"><strong>{$lang->newpoints_bbscoin_paymentid}:</strong><br /><span class="smalltext">{$lang->newpoints_bbscoin_paymentid_desc}</span></td>
<td class="trow1" width="50%"><input type="text" name="paymentId" readonly="readonly" value="{$_SERVER[\'bbscoin_paymentid\']}" class="textbox" size="85" id="paymentId"/></td>
</tr>
<tr>
<td class="trow1" width="50%"><strong>{$lang->newpoints_bbscoin_topoint_transactionhash}:</strong><br /><span class="smalltext">{$lang->newpoints_bbscoin_topoint_transactiontips}<br />{$mybb->settings[\'newpoints_bbscoin_wallet_address\']}</span></td>
<td class="trow1" width="50%"><input type="text" name="transaction_hash" value="" class="textbox" size="85" /></td>
</tr>
<tr>
<td class="tfoot" width="100%" colspan="2" align="center"><input type="submit" name="submit" value="{$lang->newpoints_submit}" class="button" /></td>
</tr>
</table>
</form>
<br />
<form action="newpoints.php" method="POST" target="_blank">
<input type="hidden" name="postcode" value="{$mybb->post_code}" />
<input type="hidden" name="action" value="points_to_bbscoin" />
<table border="0" cellspacing="{$theme[\'borderwidth\']}" cellpadding="{$theme[\'tablespace\']}" class="tborder" style="display: {$bbscoin_withdraw};">
<tr>
<td class="thead" colspan="2"><strong>{$lang->newpoints_bbscoin_tobbs}</strong></td>
</tr>
<tr>
<td class="trow1" width="100%" colspan="2"><strong>{$lang->newpoints_bbscoin_tobbs_desc}{$mybb->settings[\'newpoints_bbscoin_pay_to_coin_ratio\']}</td>
</tr>
<tr>
<td class="trow2" width="50%"><strong>{$lang->newpoints_bbscoin_tobbs_withdraw}:</strong><br /><span class="smalltext">{$lang->newpoints_bbscoin_points_balance} {$mybb->user[\'newpoints\']}</span> {$lang->newpoints_bbscoin_points}</td>
<td class="trow2" width="50%"><input type="text" name="amount" id="addcoinamount" onkeyup="addcalcoin()" value="" class="textbox" size="20" /> BBS {$lang->newpoints_bbscoin_tobbs_cacl}  <span id="coin_desamount">0</span> {$lang->newpoints_bbscoin_points}</td>
</tr>
<tr>
<td class="trow1" width="50%"><strong>{$lang->newpoints_bbscoin_tobbs_address}:</strong><br /><span class="smalltext">{$lang->newpoints_bbscoin_tobbs_address_desc}</span></td>
<td class="trow1" width="50%"><input type="text" name="walletaddress" value="" class="textbox" size="85" /></td>
</tr>
<tr>
<td class="tfoot" width="100%" colspan="2" align="center"><input type="submit" name="submit" value="{$lang->newpoints_submit}" class="button" /></td>
</tr>
</table>
</form>
<script type="text/javascript">
function random_paymentid(len) {
    len = len || 64;
    var xchars = \'ABCDEF0123456789\'; 
    var maxPos = xchars.length;
    var pwd = \'\';
    for (i = 0; i < len; i++) {
        pwd += xchars.charAt(Math.floor(Math.random() * maxPos));
    }
    return pwd;
}
function addcalcredit() {
var addfundamount = $(\'#addfundamount\').val().replace(/^0/, \'\');
var addfundamount = parseInt(addfundamount);
$(\'#desamount\').text(!isNaN(addfundamount) ? Math.ceil(((addfundamount / {$mybb->settings[\'newpoints_bbscoin_pay_ratio\']}) * 100)) / 100 : 0);
}

function addcalcoin() {
var addcoinamount = $(\'#addcoinamount\').val().replace(/^0/, \'\');
var addcoinamount = parseInt(addcoinamount);
$(\'#coin_desamount\').text(!isNaN(addcoinamount) ? Math.ceil(((addcoinamount / {$mybb->settings[\'newpoints_bbscoin_pay_to_coin_ratio\']}) * 100)) / 100 : 0);
}
</script>


</td>
</tr>
</table>
{$footer}
</body>
</html>');
	require_once MYBB_ROOT . 'inc/adminfunctions_templates.php';
	find_replace_templatesets("header_welcomeblock_member", '#'.preg_quote('{$searchlink}').'#', '{$newpoints_bbscoin_links}'.'{$searchlink}');

}

function newpoints_bbscoin_deactivate()
{
	global $db, $mybb;
	// delete settings
	newpoints_remove_settings("'newpoints_bbscoin_pay_ratio','newpoints_bbscoin_pay_to_coin_ratio','newpoints_bbscoin_pay_to_bbscoin','newpoints_bbscoin_wallet_address','newpoints_bbscoin_walletd','newpoints_bbscoin_confirmed_blocks','newpoints_bbscoin_siteid','newpoints_bbscoin_sitekey','newpoints_bbscoin_withdraw_fee'");
	rebuild_settings();
}

function newpoints_bbscoin_nav()
{
    global $templates, $mybb, $newpoints_bbscoin_links, $lang;
	newpoints_lang_load('newpoints_bbscoin');
    eval("\$newpoints_bbscoin_links = \"".$templates->get('newpoints_bbscoin_links')."\";"); 
}


function newpoints_bbscoin($page)
{
	global $mybb, $db, $lang, $cache, $bbscoin_withdraw, $theme, $header, $templates, $plugins, $headerinclude, $footer, $options;

    require_once MYBB_ROOT."inc/plugins/newpoints/bbscoin/bbscoinapi.php";
    require_once MYBB_ROOT."inc/plugins/newpoints/bbscoin/bbscoinapi_partner.php";

    $_SERVER['bbscoin_paymentid'] = hash('sha256', $_SERVER['HTTP_HOST'].$mybb->user['uid']);

    if ($mybb->settings['newpoints_bbscoin_apimode'] == 1) {
        BBSCoinApiWebWallet::setSiteInfo($mybb->settings['newpoints_bbscoin_siteid'], $mybb->settings['newpoints_bbscoin_sitekey'], $mybb->settings['newpoints_bbscoin_nosecure']);
        require_once MYBB_ROOT."inc/plugins/newpoints/bbscoin/webapi.inc.php";
    } elseif ($mybb->settings['newpoints_bbscoin_apimode'] == 2) {
        BBSCoinApiWebWallet::setSiteInfo($mybb->settings['newpoints_bbscoin_siteid'], $mybb->settings['newpoints_bbscoin_sitekey'], $mybb->settings['newpoints_bbscoin_nosecure']);
        BBSCoinApiWebWallet::recvCallback();
        require_once MYBB_ROOT."inc/plugins/newpoints/bbscoin/webwallet.inc.php";
    } else {
        require_once MYBB_ROOT."inc/plugins/newpoints/bbscoin/walletd.inc.php";
    }
}

function bbscoin_send_pm($subject, $message, $to, $from) {
    global $mybb;

    require_once(MYBB_ROOT . "inc/datahandlers/pm.php");
    $pmhandler = new PMDataHandler();
    $pmhandler->admin_override = true;

    $pm = array(
        "subject" => $subject,
        "message" => $message,
        "icon" => "-1",
        "toid" => array(),
        "fromid" => $from,
        "do" => "",
        "pmid" => ""
    );
    $pm["options"] = array(
        "signature" => "0",
        "disablesmilies" => "0",
        "savecopy" => "0",
        "readreceipt" => "0"
    );

    if (!is_array($to)) {
        array_push($pm["toid"], $to);
    } else {
        foreach($to as $uid) {
            array_push($pm["toid"], $uid);
        }
    }

    $pmhandler->set_data($pm);

    if ($pmhandler->validate_pm()) {
        $pmhandler->insert_pm();
        return true;
    } else {
        return false;
    }
} 
