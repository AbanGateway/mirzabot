<?php
ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../Marzban.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../keyboard.php';
require_once __DIR__ . '/../jdf.php';
require __DIR__ . '/../vendor/autoload.php';

$ManagePanel = new ManagePanel();
$textbotlang = languagechange();

// CubePay posts a JSON body to this callback and also appends the same
// data as a querystring (?authority=...&order_id=...&status=paid) for
// backends that only read GET. We accept either.
$rawInput = file_get_contents('php://input');
$jsonInput = $rawInput ? json_decode($rawInput, true) : null;
$jsonInput = is_array($jsonInput) ? $jsonInput : [];

$authority = $jsonInput['authority'] ?? ($_REQUEST['authority'] ?? '');
$invoice_id = $jsonInput['order_id'] ?? ($_REQUEST['order_id'] ?? '');
$authority = htmlspecialchars($authority, ENT_QUOTES, 'UTF-8');
$invoice_id = htmlspecialchars($invoice_id, ENT_QUOTES, 'UTF-8');

$setting = select("setting", "*");
$Payment_report = select("Payment_report", "*", "id_order", $invoice_id, "select");
$price = $Payment_report['price'] ?? 0;

$payment_status = '';
$dec_payment_status = '';

if (!$authority || !$Payment_report) {
    $payment_status = $textbotlang['users']['Balance']['errorLinkPayment'];
} else {
    // Verify the transaction with CubePay. Only the first successful call
    // returns success:true — repeated calls (e.g. user refreshing this
    // page) intentionally get a 409 "already verified" response.
    $token_cubepay = select("PaySetting", "ValuePay", "NamePay", "merchant_token_cubepay", "select")['ValuePay'];
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
    $result = json_decode($result, true);

    $freshlyVerified = ($httpCode == 200 && !empty($result['success']));
    $alreadyVerified = ($httpCode == 409);
    if ($freshlyVerified || $alreadyVerified) {
        // Either freshly verified (200) or already verified before (409):
        // in both cases the transaction is legitimately paid, so we only
        // need to make sure our own DB record isn't paid twice.
        $payment_status = $textbotlang['paymentGateway']['statusSuccess'];
        $dec_payment_status = $textbotlang['paymentGateway']['descThanks'];

        if ($Payment_report['payment_Status'] != "paid") {
            DirectPayment($invoice_id, "../images.jpg");
            update("Payment_report", "payment_Status", "paid", "id_order", $invoice_id);

            $pricecashback = select("PaySetting", "ValuePay", "NamePay", "chashbackcubepay", "select")['ValuePay'];
            $Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
            if ($pricecashback != "0" && $Balance_id) {
                $cashback_amount = ($Payment_report['price'] * $pricecashback) / 100;
                $Balance_confrim = intval($Balance_id['Balance']) + $cashback_amount;
                update("user", "Balance", $Balance_confrim, "id", $Balance_id['id']);
                $text_report = sprintf($textbotlang['paymentGateway']['giftReport'], $cashback_amount);
                sendmessage($Balance_id['id'], $text_report, null, 'HTML');
            }

            $paymentreports = select("topicid", "idreport", "report", "paymentreport", "select")['idreport'];
            $text_report = sprintf(
                $textbotlang['paymentGateway']['reportCubepay'],
                $Payment_report['id_user'],
                $Balance_id['username'] ?? '',
                $price
            );
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $paymentreports,
                    'text' => $text_report,
                    'parse_mode' => "HTML"
                ]);
            }
        }
    } elseif ($httpCode == 402) {
        $payment_status = 'در انتظار پرداخت';
    } else {
        // expired/failed/not-found
        $payment_status = $textbotlang['users']['Balance']['errorLinkPayment'];
        $dec_payment_status = $result['message'] ?? '';
    }
}
?>
<html>
<head>
    <title><?php echo $textbotlang['paymentGateway']['invoiceTitle'] ?></title>
    <style>
    @font-face {
    font-family: 'vazir';
    src: url('/Vazir.eot');
    src: local('☺'), url('../fonts/Vazir.woff') format('woff'), url('../fonts/Vazir.ttf') format('truetype');
}

        body {
            font-family:vazir;
            background-color: #f2f2f2;
            margin: 0;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .confirmation-box {
            background-color: #ffffff;
            border-radius: 8px;
            width:25%;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 40px;
            text-align: center;
        }

        h1 {
            color: #333333;
            margin-bottom: 20px;
        }

        p {
            color: #666666;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="confirmation-box">
        <h1><?php echo $payment_status ?></h1>
        <p><?php echo $textbotlang['paymentGateway']['invoiceTransactionNo'] ?><span><?php echo $invoice_id ?></span></p>
        <p><?php echo $textbotlang['paymentGateway']['invoiceAmount'] ?>  <span><?php echo number_format($price); ?></span><?php echo $textbotlang['paymentGateway']['invoiceAmountUnit'] ?></p>
        <p><?php echo $textbotlang['paymentGateway']['invoiceDate'] ?> <span>  <?php echo jdate('Y/m/d')  ?>  </span></p>
        <p><?php echo $dec_payment_status ?></p>
    </div>
</body>
</html>
