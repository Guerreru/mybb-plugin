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

class BBSCoinApi {

    // Send Request
    public static function getUrlContent($url, $data_string) {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'BBSCoin');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $data = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $data;
    }

    // Send Transaction
    public static function sendTransaction($walletd, $address, $real_price, $sendto, $fee = 50000000) {
        $req_data = array(
          'params' => array(
              'anonymity' => 0,
              'fee' => $fee,
              'unlockTime' => 0,
              'changeAddress' => $address,
              "transfers" => array(
               0 => array(
                    'amount' => $real_price,
                    'address' => $sendto,
                )
              )
          ),
          "jsonrpc" => "2.0",
          "method" => "sendTransaction"
        );

        $result = self::getUrlContent($walletd, json_encode($req_data)); 
        $rsp_data = json_decode($result, true);
        
        return $rsp_data;
    }

    // Get Status
    public static function getStatus($walletd) {
        $status_req_data = array(
          "jsonrpc" => "2.0",
          "method" => "getStatus"
        );

        $result = self::getUrlContent($walletd, json_encode($status_req_data)); 
        $status_rsp_data = json_decode($result, true);
        return $status_rsp_data;
    }

    // Get Transaction
    public static function getTransaction($walletd, $transaction_hash) {
        $req_data = array(
          "params" => array(
          	"transactionHash" => $transaction_hash
          ),
          "jsonrpc" => "2.0",
          "method" => "getTransaction"
        );

        $result = self::getUrlContent($walletd, json_encode($req_data)); 
        $rsp_data = json_decode($result, true);

        return $rsp_data;
    }

}

class BBSCoinApiWebWallet {

    private static $online_api_site_id  = '';
    private static $online_api_site_key = '';
    private static $timeout  = 30;
    private static $connectTimeout = 3;
    private static $noSecure = false;

    // Set Site Info
    public static function setSiteInfo($site_id, $site_key, $noSecure = false) {
        self::$online_api_site_id = $site_id;
        self::$online_api_site_key = $site_key;
        self::$noSecure = $noSecure;
    }

    // Send Request
    public static function getUrlContent($url, $data) {
        $ch = curl_init();

        if ($data) {
            $data_string = json_encode($data);
        } else {
            $data_string = '';
        }

        if (self::$online_api_site_id && self::$online_api_site_key) {
            $sign = self::sign($data_string);
            $url_suff = 'appid='.self::$online_api_site_id.'&sign='.$sign['sign'].'&ts='.$sign['ts'];
            if (strpos($url, '?') === false) {
                $url .= '?'.$url_suff;
            } else {
                $url .= '&'.$url_suff;
            }
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'BBSCoin PHP Client/1.0');
        curl_setopt($ch, CURLOPT_POST, true);
        if (self::$noSecure) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1);
            curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'TLSv1');
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
        ));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::$connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::$timeout);
        $data = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new Exception('curl error, code='.curl_errno($ch).', msg='.curl_error($ch));
        }
        
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $resp_data = json_decode($data, true);
        if (!$resp_data) {
            throw new Exception('empty response data, code='.$http_code);
        }

        return $resp_data;
    }

    // Generate Sign
    public static function sign($data_string, $ts = 0) {
        if (!$ts) {
            $ts = time().'000';
        }
        $sign = hash_hmac('sha256', $data_string.$ts, self::$online_api_site_key);
        return array(
            'sign' => $sign,
            'ts' => $ts
        );
    }

    // Online Wallet callback
    public static function recvCallback() {
        if ($_GET['sign'] && $_GET['ts']) {
            header('Content-type: application/json');
            if (time() - $_GET['ts'] > 300 || $_GET['ts'] - time() > 300) {
                echo json_encode(array(
                    'success' => false,
                    'code' => 1
                ));
                exit;
            }

            $data_string = file_get_contents("php://input");
            if (self::sign($data_string, $_GET['ts']) != $_GET['sign']) {
                echo json_encode(array(
                    'success' => false,
                    'code' => 2
                ));
                exit;
            }

            $json_data = json_decode($data_string, true);
            if (!$json_data) {
                echo json_encode(array(
                    'success' => false,
                    'code' => 3
                ));
                exit;
            }

            echo json_encode(BBSCoinApiPartner::callback($json_data));
            exit;
        }
    }

    public static function getTransactionDetails($walletd, $hash) {
        return self::getUrlContent($walletd.'/wallet/transaction-details', array(
                'hash' => (string)$hash,
            )
        );
    }

    // send
    public static function send($walletd, $address, $real_price, $sendto, $orderid, $uin, $points, $fee = 1, $webhook = true) {
        $req_data = array(
          'mixin' => 0,
          'fee' => (string)$fee,
          'paymentId' => '',
          "transfers" => array(
           0 => array(
                'amount' => (string)$real_price,
                'address' => (string)$sendto,
            )
          )
       );
       if ($webhook) {
            $req_data['webhook'] = array(
                'data' => array(
                    'action' => 'withdraw',
                    'orderid' => $orderid,
                    'uin' => $uin,
                    'points' => $points,
                )
            );
        }

        $rsp_data = self::getUrlContent($walletd.'/wallet/send', $req_data); 
        
        return $rsp_data;
    }

    // check_transaction
    public static function checkTransaction($walletd, $transaction_hash, $paymentId, $uin) {
        $req_data = array(
            'hash' => $transaction_hash,
            'paymentId' => $paymentId,
            'data' => array(
                'action' => 'deposit',
                'uin' => $uin,
            )
        );

        $rsp_data = self::getUrlContent($walletd.'/webhook/create', $req_data); 

        return $rsp_data;
    }

}

