<?php

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/razorpay-sdk/Razorpay.php';

use Razorpay\Api\Api;
use Razorpay\Api\Errors;

/**
 * Event constants
 */
const ORDER_PAID  = 'order.paid';

// Detect module name from filename.
$gatewayModuleName = 'razorpay';

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

$api = new Api($gatewayParams['keyId'], $gatewayParams['keySecret']);

/**
 * Process a Razorpay Webhook.
 */

$post = file_get_contents('php://input');

$data = json_decode($post, true);

if (json_last_error() !== 0)
{
    return;
}

$enabled = $gatewayParams['enableWebhook'];

if ($enabled === 'on' and
    (empty($data['event']) === false))
{
    if (isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE']) === true)
    {
        $razorpayWebhookSecret = $gatewayParams['webhookSecret'];

        if (empty($razorpayWebhookSecret) === true)
        {
            return;
        }

        try
        {
            $api->utility->verifyWebhookSignature($post,
                                                        $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'],
                                                        $razorpayWebhookSecret);
        }
        catch (Errors\SignatureVerificationError $e)
        {
            $log = array(
                'message'   => $e->getMessage(),
                'data'      => $data,
                'event'     => 'razorpay.whmcs.signature.verify_failed'
            );

            logTransaction($gatewayParams["name"], $log, "Unsuccessful-".$e->getMessage());

            header('HTTP/1.1 401 Unauthorized', true, 401);
            return;
        }

        switch ($data['event'])
        {
            case ORDER_PAID:
                return orderPaid($data, $gatewayParams, $gatewayModuleName);

            default:
                return;
        }
    }
}


/**
 * Order Paid webhook
 *
 * @param array $data
 * @param array $gatewayParams
 * @param string $gatewayModuleName
 */
function orderPaid(array $data, $gatewayParams, $gatewayModuleName)
{
    // We don't process subscription/invoice payments here if invoice_id is set in payment entity
    // (This usually indicates a subscription payment, handled differently)
    if (isset($data['payload']['payment']['entity']['invoice_id']) === true && !empty($data['payload']['payment']['entity']['invoice_id']))
    {
        logTransaction($gatewayParams['name'], "returning order.paid webhook", "Invoice ID (Subscription) exists");
        return;
    }

    //
    // Order entity should be sent as part of the webhook payload
    // 'whmcs_order_id' here actually refers to the WHMCS Invoice ID (as set in rzpordermapping/creation)
    //
    $invoiceId = $data['payload']['order']['entity']['notes']['whmcs_order_id'];
    $razorpayPaymentId = $data['payload']['payment']['entity']['id'];

    // Validate Callback Invoice ID.
    $invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);
    
    // Check Callback Transaction ID.
    // If the Callback script ran first, this will stop here.
    checkCbTransID($razorpayPaymentId);

    // Fetch Invoice to verify amount
    $invoice = mysql_fetch_assoc(select_query('tblinvoices', '*', array("id"=>$invoiceId)));

    if (!$invoice) {
        logTransaction($gatewayParams['name'], "Invoice not found for ID: $invoiceId", "Failure");
        return;
    }

    // Check if already paid
    if($invoice['status'] === 'Paid')
    {
        logTransaction($gatewayParams['name'], "Invoice $invoiceId already paid", "INFO");
        return;
    }

    $success = false;
    $error = "";

    // Amount verification
    // Razorpay sends amount in paise (integer). WHMCS is in currency units (float).
    $razorpayAmount = $data['payload']['payment']['entity']['amount']; // Integer (e.g., 10000 for 100.00)
    $whmcsAmount = (int) round($invoice['total'] * 100); // Convert WHMCS total to paise

    // Allow a small buffer for float precision issues (optional, but strict equality is usually fine here)
    if($razorpayAmount === $whmcsAmount)
    {
        $success = true;
    }
    else
    {
        $error = "WHMCS_ERROR: Amount mismatch. Invoice: $whmcsAmount, Razorpay: $razorpayAmount";
    }

    $log = [
        'merchant_order_id'   => $invoiceId,
        'razorpay_payment_id' => $razorpayPaymentId,
        'webhook' => true
    ];

    if ($success === true)
    {
        # Successful
        
        // 1. Calculate Fees from Webhook Payload
        // Razorpay sends fee in the webhook payload, so we don't need an API call.
        $feeAmount = 0;
        if (isset($data['payload']['payment']['entity']['fee'])) {
            // Fee is in paise, convert to standard unit
            $feeAmount = $data['payload']['payment']['entity']['fee'] / 100;
        }

        # Apply Payment to Invoice: invoiceid, transactionid, amount paid, fees, modulename
        // Note: We use the Invoice Total, not the Razorpay amount, to avoid penny rounding issues in WHMCS
        addInvoicePayment($invoiceId, $razorpayPaymentId, $invoice['total'], $feeAmount, $gatewayModuleName);
        
        logTransaction($gatewayParams["name"], $log, "Successful"); 
    }
    else
    {
        # Unsuccessful
        logTransaction($gatewayParams["name"], $log, "Unsuccessful-".$error);
    }

    // Graceful exit
    exit;
}
?>