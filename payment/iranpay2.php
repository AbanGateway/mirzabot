<?php
ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../jdf.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../Marzban.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../keyboard.php';
require __DIR__ . '/../vendor/autoload.php';
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\Font\OpenSans;
use Endroid\QrCode\Label\LabelAlignment;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

$ManagePanel = new ManagePanel();

$rawInput = file_get_contents('php://input');
$jsonInput = $rawInput ? json_decode($rawInput, true) : null;
$jsonInput = is_array($jsonInput) ? $jsonInput : [];

$authority = $jsonInput['authority'] ?? ($_REQUEST['authority'] ?? '');
$data_order_id = $jsonInput['order_id'] ?? ($_REQUEST['order_id'] ?? '');
$authority = htmlspecialchars($authority, ENT_QUOTES, 'UTF-8');
$data_order_id = htmlspecialchars($data_order_id, ENT_QUOTES, 'UTF-8');

// A crypto or VIP payment reports back without an authority: it sends
// order_id + status + amount plus an HMAC signature instead. These values are
// kept raw on purpose - the signature was computed over the exact strings that
// were sent, so escaping them first would never match.
$callback_sig = (string) ($jsonInput['sig'] ?? ($_REQUEST['sig'] ?? ''));
$callback_status = (string) ($jsonInput['status'] ?? ($_REQUEST['status'] ?? ''));
$callback_amount = (string) ($jsonInput['amount'] ?? $jsonInput['amount_toman'] ?? ($_REQUEST['amount'] ?? ($_REQUEST['amount_toman'] ?? '')));
$callback_order_id = (string) ($jsonInput['order_id'] ?? ($_REQUEST['order_id'] ?? ''));
$isSignedCallback = ($callback_sig !== '' && $callback_order_id !== '');

$Payment_report = select("Payment_report", "*", "id_order", $data_order_id, "select");
if (!$Payment_report)
    return;
$token_cubepay = select("PaySetting", "*", "NamePay", "apiternado", "select")['ValuePay'];
if ($Payment_report['payment_Status'] == "expire")
    return;
$setting = select("setting", "*", null, null, "select");
$price = $Payment_report['price'];
if ($Payment_report['payment_Status'] != "paid" && ($authority || $isSignedCallback)) {
    if ($isSignedCallback) {
        // Crypto / VIP: the signature is the proof, so there is nothing to call
        // back to. It is built over "order_id|status|amount" with the same
        // gateway token this bot already stores, and the amount is in toman.
        $expected_sig = hash_hmac(
            'sha256',
            $callback_order_id . '|' . $callback_status . '|' . $callback_amount,
            (string) $token_cubepay
        );
        $signatureValid = hash_equals($expected_sig, $callback_sig);
        $isVerifiedForThisOrder = $signatureValid
            && $callback_status === 'paid'
            && (string) $callback_order_id === (string) $data_order_id
            && (float) $callback_amount >= (float) $price;
        $paymentAccepted = $isVerifiedForThisOrder;
        $response = [
            'order_id' => $callback_order_id,
            'status' => $callback_status,
            'amount_toman' => $callback_amount,
            'verified_by' => 'signature',
        ];
        if (!$signatureValid) {
            error_log("CubePay: invalid callback signature for order {$data_order_id}");
        }
    } else {
        $ch = curl_init('https://cubevps.ir/smspay/api/verify-payment.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['authority' => $authority]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token_cubepay
        ));
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $response = json_decode($result, true);

        // When the admin passes the gateway fee on to the customer, the invoice is
        // larger than the order price, so an exact match would reject a valid
        // payment. Underpayment is still refused; paying at least the order price
        // is what matters here.
        $amount_rial = intval($price) * 10;
        $isVerifiedForThisOrder = is_array($response)
            && isset($response['order_id'], $response['amount'])
            && (string) $response['order_id'] === (string) $data_order_id
            && intval($response['amount']) >= $amount_rial;

        $paymentAccepted = (($httpCode == 200 && !empty($response['success'])) || $httpCode == 409)
            && $isVerifiedForThisOrder;
    }

    if ($paymentAccepted) {
        echo json_encode(array("status" => true));
        if (!claimPaymentPaid($Payment_report['id_order']))
            return;
        $textbotlang = languagechange();
        try {
            DirectPayment($data_order_id, "../images.jpg");
        } catch (Throwable $directPaymentError) {
            error_log("DirectPayment failed for order {$data_order_id}: " . $directPaymentError->getMessage());
            return;
        }
        $pricecashback = select("PaySetting", "ValuePay", "NamePay", "chashbackiranpay2", "select")['ValuePay'];
        $Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
        if ($pricecashback != "0") {
            $result_cashback = ($Payment_report['price'] * $pricecashback) / 100;
            $Balance_confrim = intval($Balance_id['Balance']) + $result_cashback;
            update("user", "Balance", $Balance_confrim, "id", $Balance_id['id']);
            $pricecashback = number_format($pricecashback);
            $text_report = sprintf($textbotlang['paymentGateway']['giftReport'], $result_cashback);
            sendmessage($Balance_id['id'], $text_report, null, 'HTML');
        }
        $paymentreports = select("topicid", "idreport", "report", "paymentreport", "select")['idreport'];
        $text_reportpayment = sprintf($textbotlang['paymentGateway']['reportTronado'], $Balance_id['username'], $Balance_id['id'], $price);
        $database = json_encode($response);
        $statement = $pdo->prepare("UPDATE Payment_report SET dec_not_confirmed = :dec_not_confirmed WHERE id_order = :id_order");
        $statement->bindValue(':dec_not_confirmed', $database);
        $statement->bindValue(':id_order', $Payment_report['id_order']);
        $statement->execute();
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $paymentreports,
                'text' => $text_reportpayment,
                'parse_mode' => "HTML"
            ]);
        }
    } else {
        echo json_encode(array("status" => false));
    }
}