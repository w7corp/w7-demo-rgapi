<?php
/**
 * [WeEngine System] Copyright (c) 2014 W7.CC
 * $sn: pro/payment/wechat/notify.php : v a4b6a17a6d8a : 2015/09/14 08:41:00 : yanghf $
 */
define('IN_MOBILE', true);
require '../../framework/bootstrap.inc.php';
$input = file_get_contents('php://input');
$isxml = true;
if (!empty($input) && empty($_GET['out_trade_no'])) {
    $data_v3 = json_decode($input, true);
    if (!empty($data_v3) && 'TRANSACTION.SUCCESS' != $data_v3['event_type']) {
        echo json_encode(['code' => $data_v3['code'], 'message' => $data_v3['message']]);
        exit;
    }
    load()->library('wechatpay-v3');
    $_W['uniacid'] = substr($_SERVER['REQUEST_URI'], strrpos($_SERVER['REQUEST_URI'], '/') + 1);
    $setting = uni_setting_load('payment');
    $key = $setting['payment']['wechat']['apikey'];
    $decrypter = new \WechatPay\GuzzleMiddleware\Util\AesUtil($key);
    $plain = $decrypter->decryptToString($data_v3['resource']['associated_data'], $data_v3['resource']['nonce'], $data_v3['resource']['ciphertext']);
    $data_v3 = json_decode($plain, true);
    if (empty($data_v3)) {
        echo json_encode(['code' => 'FAIL', 'message' => '解密失败']);
        exit;
    }
    $isxml = false;
    $data_v3['total_fee'] = $data_v3['amount']['total'];
    $data_v3['openid'] = $data_v3['payer']['openid'];
    $data_v3['time_end'] = $data_v3['success_time'];
    $get = $data_v3;
} else {
    $isxml = false;
    $get = $_GET;
}
load()->web('common');
WeUtility::logging('pay', var_export($get, true));
$log = table('core_paylog')
    ->where(array('uniontid' => $get['out_trade_no']))
    ->get();
$_W['uniacid'] = $_W['weid'] = intval($log['uniacid']);
$_W['uniaccount'] = $_W['account'] = uni_fetch($_W['uniacid']);
if (!empty($log) && $log['status'] == '0' && (($get['amount']['payer_total'] / 100) == $log['card_fee'])) {
    table('core_paylog')->where(array('plid' => $log['plid']))->fill(array('status' => 1))->save();
    if ($log['type'] == 'wxapp') {
        $site = WeUtility::createModuleWxapp($log['module']);
    } else {
        $site = WeUtility::createModuleSite($log['module']);
    }
    if (!is_error($site)) {
        $method = 'payResult';
        if (method_exists($site, $method)) {
            $ret = array();
            $ret['weid'] = $log['weid'];
            $ret['uniacid'] = $log['uniacid'];
            $ret['acid'] = $log['acid'];
            $ret['result'] = 'success';
            $ret['type'] = $log['type'];
            $ret['from'] = 'notify';
            $ret['tid'] = $log['tid'];
            $ret['uniontid'] = $log['uniontid'];
            $ret['transaction_id'] = $log['transaction_id'];
            $ret['trade_type'] = $get['trade_type'];
            $ret['follow'] = $get['is_subscribe'] == 'Y' ? 1 : 0;
            $ret['user'] = empty($log['openid']) ? $get['openid'] : $log['openid'];
            $ret['fee'] = $log['fee'];
            $ret['tag'] = $log['tag'];
            $ret['is_usecard'] = $log['is_usecard'];
            $ret['card_type'] = $log['card_type'];
            $ret['card_fee'] = $log['card_fee'];
            $ret['card_id'] = $log['card_id'];
            if (!empty($get['time_end'])) {
                $ret['paytime'] = strtotime($get['time_end']);
            }
            $site->$method($ret);
            if ($isxml) {
                $result = array(
                    'return_code' => 'SUCCESS',
                    'return_msg' => 'OK'
                );
                echo array2xml($result);
                exit;
            } else {
                exit('success');
            }
        }
    }
}
if ($isxml) {
    $result = array(
        'return_code' => 'SUCCESS',
        'return_msg' => 'OK'
    );
    echo array2xml($result);
    exit;
} else {
    exit('fail');
}
