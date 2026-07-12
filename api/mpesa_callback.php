<?php
/**
 * Maseno Retail ERP - M-Pesa Callback Handler
 *
 * This script receives callbacks from Safaricom Daraja API:
 *   - STK Push callback (POST from Safaricom after STK push)
 *   - C2B Validation callback (registered via Daraja portal)
 *   - C2B Confirmation callback (registered via Daraja portal)
 *
 * All endpoints return the JSON response Safaricom expects.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../mpesa_sandbox.php';

// Determine which callback type based on URL parameter or request body
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

// STK Push callback has 'Body' wrapper
if (isset($data['Body']['stkCallback'])) {
    handle_stk_callback($data['Body']['stkCallback']);
    exit;
}

// C2B callbacks: check for TransactionType
if (isset($data['TransactionType'])) {
    // Validation calls come first, then confirmation
    // We use a simple approach: always log and accept
    if (isset($data['BillRefNumber'])) {
        mpesa_c2b_confirmation();
    } else {
        mpesa_c2b_validation();
    }
    exit;
}

// No recognizable structure - log and respond
$db = getDB();
$db->prepare("
    INSERT INTO mpesa_transactions (transaction_type, trans_id, result_code, result_desc, raw_callback)
    VALUES ('unknown_callback', 'N/A', 1, 'Unrecognized callback format', ?::jsonb)
")->execute([json_encode($data)]);

header('Content-Type: application/json');
echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Unrecognized callback']);
exit;

// ──────────────────────────────────────────────────────────────
// STK PUSH CALLBACK HANDLER
// ──────────────────────────────────────────────────────────────

/**
 * Handle the STK Push callback from Safaricom.
 *
 * Body.stkCallback = {
 *   MerchantRequestID, CheckoutRequestID, ResultCode, ResultDesc,
 *   CallbackMetadata: { Item: [{ Name, Value }] }
 * }
 *
 * ResultCode 0 = success
 */
function handle_stk_callback(array $callback): void
{
    $db = getDB();
    $checkoutId   = $callback['CheckoutRequestID'] ?? '';
    $resultCode   = $callback['ResultCode'] ?? 1;
    $resultDesc   = $callback['ResultDesc'] ?? '';

    // Extract metadata
    $metadata = [];
    if (isset($callback['CallbackMetadata']['Item'])) {
        foreach ($callback['CallbackMetadata']['Item'] as $item) {
            $metadata[$item['Name']] = $item['Value'] ?? null;
        }
    }

    $transId      = $metadata['MpesaReceiptNumber'] ?? $checkoutId;
    $transAmount  = (float) ($metadata['Amount'] ?? 0);
    $phoneNumber  = $metadata['PhoneNumber'] ?? '';
    $transTime    = $metadata['TransactionDate'] ?? null;

    // Format transaction time if provided (YmdHis → Y-m-d H:i:s)
    if ($transTime && strlen($transTime) === 14) {
        $transTime = substr($transTime, 0, 4) . '-' . substr($transTime, 4, 2) . '-' .
                     substr($transTime, 6, 2) . ' ' . substr($transTime, 8, 2) . ':' .
                     substr($transTime, 10, 2) . ':' . substr($transTime, 12, 2);
    }

    // Update the transaction record
    $db->prepare("
        UPDATE mpesa_transactions
        SET trans_id       = ?,
            trans_time     = COALESCE(?, trans_time),
            trans_amount   = ?,
            msisdn         = COALESCE(?, msisdn),
            result_code    = ?,
            result_desc    = ?,
            raw_callback   = ?::jsonb
        WHERE trans_id = ? OR trans_id = 'N/A'
    ")->execute([
        $transId,
        $transTime,
        $transAmount,
        $phoneNumber,
        $resultCode,
        $resultDesc,
        json_encode($callback),
        $checkoutId,
    ]);

    // If successful, find the sale by bill_ref_number and link it
    if ($resultCode === 0 && !empty($metadata['MpesaReceiptNumber'])) {
        // Find the original transaction to get bill_ref_number
        $origStmt = $db->prepare("
            SELECT bill_ref_number, sale_id FROM mpesa_transactions
            WHERE trans_id = ? OR trans_id = ?
            ORDER BY id DESC LIMIT 1
        ");
        $origStmt->execute([$checkoutId, $transId]);
        $orig = $origStmt->fetch();

        if ($orig && !empty($orig['bill_ref_number'])) {
            // Link receipt to sale
            $saleStmt = $db->prepare("
                UPDATE sales
                SET payment_method = 'mpesa',
                    mpesa_receipt  = ?,
                    sale_status    = 'complete'
                WHERE receipt_no = ?
                  AND sale_status = 'complete'
                RETURNING id
            ");
            $saleStmt->execute([$transId, $orig['bill_ref_number']]);
            $saleRow = $saleStmt->fetch();

            if ($saleRow) {
                $db->prepare("UPDATE mpesa_transactions SET sale_id = ? WHERE trans_id = ?")
                   ->execute([$saleRow['id'], $transId]);
            }
        }
    }

    // Respond to Safaricom
    header('Content-Type: application/json');
    echo json_encode([
        'ResultCode' => 0,
        'ResultDesc' => 'Success',
    ]);
    exit;
}