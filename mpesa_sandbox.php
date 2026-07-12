<?php
/**
 * Maseno Retail ERP - M-Pesa Daraja API Integration (Sandbox)
 *
 * Implements:
 *   1. STK Push (Lipa na M-Pesa Online) - initiates payment request
 *   2. C2B Validation - validates incoming payments
 *   3. C2B Confirmation - confirms processed payments
 *   4. Query transaction status
 *
 * Requires: mpesa_consumer_key, mpesa_consumer_secret, mpesa_passkey
 * stored in store_config table. All credentials for sandbox testing.
 *
 * Daraja API Docs: https://developer.safaricom.co.ke/APIs
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/auth.php';

// ──────────────────────────────────────────────────────────────
// 1. CONFIGURATION & TOKEN MANAGEMENT
// ──────────────────────────────────────────────────────────────

/**
 * Get M-Pesa API configuration from store_config.
 */
function mpesa_config(): array
{
    $config = all_store_config();
    return [
        'consumer_key'    => $config['mpesa_consumer_key'] ?? '',
        'consumer_secret' => $config['mpesa_consumer_secret'] ?? '',
        'passkey'         => $config['mpesa_passkey'] ?? '',
        'shortcode'       => $config['mpesa_shortcode'] ?? '174379',
        'env'             => $config['mpesa_env'] ?? 'sandbox',
    ];
}

/**
 * Base URL for Daraja API based on environment.
 */
function mpesa_base_url(): string
{
    $cfg = mpesa_config();
    return $cfg['env'] === 'production'
        ? 'https://api.safaricom.co.ke'
        : 'https://sandbox.safaricom.co.ke';
}

/**
 * Generate OAuth token for Daraja API.
 *
 * @return array ['access_token' => string, 'expires_in' => int]
 */
function mpesa_generate_token(): array
{
    $cfg = mpesa_config();

    if (empty($cfg['consumer_key']) || empty($cfg['consumer_secret'])) {
        return ['error' => 'M-Pesa consumer key/secret not configured.'];
    }

    $credentials = base64_encode($cfg['consumer_key'] . ':' . $cfg['consumer_secret']);
    $url = mpesa_base_url() . '/oauth/v1/generate?grant_type=client_credentials';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . $credentials],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,  // Sandbox only; production should verify
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return ['error' => 'Failed to generate token. HTTP ' . $httpCode . ': ' . $response];
    }

    $data = json_decode($response, true);
    return $data ?: ['error' => 'Invalid token response.'];
}

// ──────────────────────────────────────────────────────────────
// 2. STK PUSH (Lipa na M-Pesa Online)
// ──────────────────────────────────────────────────────────────

/**
 * Initiate STK Push payment request to customer's phone.
 *
 * @param float  $amount       Amount to charge (KES)
 * @param string $phone        Customer phone in format 2547XXXXXXXX
 * @param string $receiptNo    Store receipt number for reference
 * @param string $description  Short description for the payment
 * @return array ['success' => bool, 'CheckoutRequestID' => string|null, 'message' => string]
 */
function mpesa_stk_push(float $amount, string $phone, string $receiptNo, string $description = 'Maseno Retail Purchase'): array
{
    $cfg    = mpesa_config();
    $token  = mpesa_generate_token();

    if (isset($token['error'])) {
        return ['success' => false, 'CheckoutRequestID' => null, 'message' => $token['error']];
    }

    // Format phone: ensure 254 prefix, strip + and leading 0
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) === 9) {
        $phone = '254' . $phone;
    } elseif (strlen($phone) === 10 && $phone[0] === '0') {
        $phone = '254' . substr($phone, 1);
    }
    // Now should be 2547XXXXXXXX (12 digits)

    $timestamp   = date('YmdHis');
    $shortcode   = $cfg['shortcode'];
    $passkey     = $cfg['passkey'];
    $password    = base64_encode($shortcode . $passkey . $timestamp);

    $postData = [
        'BusinessShortCode' => $shortcode,
        'Password'          => $password,
        'Timestamp'         => $timestamp,
        'TransactionType'   => 'CustomerPayBillOnline',
        'Amount'            => round($amount),
        'PartyA'            => $phone,
        'PartyB'            => $shortcode,
        'PhoneNumber'       => $phone,
        'CallBackURL'       => rtrim(STORE_URL ?? 'https://example.com', '/') . '/api/mpesa_callback.php',
        'AccountReference'  => $receiptNo,
        'TransactionDesc'   => substr($description, 0, 13),
    ];

    $url = mpesa_base_url() . '/mpesa/stkpush/v1/processrequest';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token['access_token'],
            'Content-Type: application/json',
        ],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return ['success' => false, 'CheckoutRequestID' => null, 'message' => "HTTP {$httpCode}: {$response}"];
    }

    $result = json_decode($response, true);

    // Log the STK push attempt
    $db = getDB();
    $db->prepare("
        INSERT INTO mpesa_transactions
            (transaction_type, trans_id, trans_amount, business_shortcode,
             bill_ref_number, msisdn, result_code, result_desc, raw_callback)
        VALUES ('stk_push', ?, ?, ?, ?, ?, ?, ?, ?::jsonb)
    ")->execute([
        $result['CheckoutRequestID'] ?? 'N/A',
        $amount,
        $shortcode,
        $receiptNo,
        $phone,
        $result['ResponseCode'] ?? '1',
        $result['ResponseDescription'] ?? 'Unknown',
        json_encode($result),
    ]);

    if (($result['ResponseCode'] ?? '1') === '0') {
        return [
            'success'            => true,
            'CheckoutRequestID'  => $result['CheckoutRequestID'],
            'message'            => 'STK Push sent. Customer will be prompted to enter PIN.',
            'raw'                => $result,
        ];
    }

    return [
        'success'           => false,
        'CheckoutRequestID' => $result['CheckoutRequestID'] ?? null,
        'message'           => $result['ResponseDescription'] ?? 'STK Push failed.',
        'raw'               => $result,
    ];
}

/**
 * Query the status of an STK Push transaction.
 *
 * @param string $checkoutRequestID The CheckoutRequestID from stk_push response
 * @return array
 */
function mpesa_query_status(string $checkoutRequestID): array
{
    $cfg    = mpesa_config();
    $token  = mpesa_generate_token();

    if (isset($token['error'])) {
        return ['success' => false, 'message' => $token['error']];
    }

    $timestamp = date('YmdHis');
    $password  = base64_encode($cfg['shortcode'] . $cfg['passkey'] . $timestamp);

    $postData = [
        'BusinessShortCode' => $cfg['shortcode'],
        'Password'          => $password,
        'Timestamp'         => $timestamp,
        'CheckoutRequestID' => $checkoutRequestID,
    ];

    $url = mpesa_base_url() . '/mpesa/stkpushquery/v1/query';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token['access_token'],
            'Content-Type: application/json',
        ],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'response'  => json_decode($response, true) ?? $response,
    ];
}

// ──────────────────────────────────────────────────────────────
// 3. C2B CALLBACK HANDLERS
// ──────────────────────────────────────────────────────────────

/**
 * Process C2B Validation callback.
 * Called by Safaricom before processing a payment.
 * Return JSON to accept or reject the transaction.
 *
 * Expected POST body from Safaricom:
 * {
 *   "TransactionType": "Pay Bill",
 *   "TransID": "RCR1234567",
 *   "TransTime": "20260711120000",
 *   "TransAmount": "1500.00",
 *   "BusinessShortCode": "174379",
 *   "BillRefNumber": "RCP-20260711-000001",
 *   "InvoiceNumber": "",
 *   "MSISDN": "254708374149",
 *   "FirstName": "John",
 *   "MiddleName": "",
 *   "LastName": "Doe"
 * }
 */
function mpesa_c2b_validation(): never
{
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data) {
        header('Content-Type: application/json');
        echo json_encode([
            'ResultCode' => 1,
            'ResultDesc' => 'Invalid JSON payload',
        ]);
        exit;
    }

    // Log the validation request
    $db = getDB();
    $db->prepare("
        INSERT INTO mpesa_transactions
            (transaction_type, trans_id, trans_amount, business_shortcode,
             bill_ref_number, msisdn, first_name, middle_name, last_name,
             result_code, result_desc, raw_callback)
        VALUES ('c2b_validation', ?, ?, ?, ?, ?, ?, ?, ?, 0, 'Accepted', ?::jsonb)
    ")->execute([
        $data['TransID'] ?? 'N/A',
        (float) ($data['TransAmount'] ?? 0),
        $data['BusinessShortCode'] ?? '',
        $data['BillRefNumber'] ?? '',
        $data['MSISDN'] ?? '',
        $data['FirstName'] ?? '',
        $data['MiddleName'] ?? '',
        $data['LastName'] ?? '',
        json_encode($data),
    ]);

    // Validate the bill reference (receipt number) exists
    if (!empty($data['BillRefNumber'])) {
        $check = $db->prepare("SELECT id FROM sales WHERE receipt_no = ? AND sale_status = 'complete'");
        $check->execute([$data['BillRefNumber']]);
        if (!$check->fetch()) {
            header('Content-Type: application/json');
            echo json_encode([
                'ResultCode' => 1,
                'ResultDesc' => 'Invalid receipt reference',
            ]);
            exit;
        }
    }

    // Accept the transaction
    header('Content-Type: application/json');
    echo json_encode([
        'ResultCode' => 0,
        'ResultDesc' => 'Accepted',
    ]);
    exit;
}

/**
 * Process C2B Confirmation callback.
 * Called by Safaricom after a successful payment.
 * Updates the sale record with M-Pesa receipt and links the transaction.
 */
function mpesa_c2b_confirmation(): never
{
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data) {
        header('Content-Type: application/json');
        echo json_encode([
            'ResultCode' => 1,
            'ResultDesc' => 'Invalid JSON payload',
        ]);
        exit;
    }

    $db = getDB();

    // Update the mpesa_transactions log
    $updateStmt = $db->prepare("
        UPDATE mpesa_transactions
        SET result_code    = 0,
            result_desc    = 'Confirmed',
            trans_time     = ?,
            org_account_balance = ?,
            raw_callback   = ?::jsonb
        WHERE trans_id = ?
    ");
    $updateStmt->execute([
        $data['TransTime'] ?? date('Y-m-d H:i:s'),
        (float) ($data['OrgAccountBalance'] ?? 0),
        json_encode($data),
        $data['TransID'] ?? '',
    ]);

    // If we have a BillRefNumber (receipt), link it
    if (!empty($data['BillRefNumber'])) {
        $saleStmt = $db->prepare("
            UPDATE sales
            SET payment_method = 'mpesa',
                mpesa_receipt  = ?,
                sale_status    = 'complete'
            WHERE receipt_no = ?
              AND sale_status = 'complete'
            RETURNING id
        ");
        $saleStmt->execute([$data['TransID'], $data['BillRefNumber']]);
        $saleRow = $saleStmt->fetch();

        if ($saleRow) {
            // Link mpesa transaction to sale
            $db->prepare("UPDATE mpesa_transactions SET sale_id = ? WHERE trans_id = ?")
               ->execute([$saleRow['id'], $data['TransID']]);
        }
    }

    // Confirm receipt
    header('Content-Type: application/json');
    echo json_encode([
        'ResultCode' => 0,
        'ResultDesc' => 'Success',
    ]);
    exit;
}

// ──────────────────────────────────────────────────────────────
// 4. UTILITY FUNCTIONS
// ──────────────────────────────────────────────────────────────

/**
 * Check if M-Pesa is configured (has consumer key/secret).
 */
function mpesa_is_configured(): bool
{
    $cfg = mpesa_config();
    return !empty($cfg['consumer_key']) && !empty($cfg['consumer_secret']);
}

/**
 * List recent M-Pesa transactions from the log.
 */
function list_mpesa_transactions(int $limit = 50): array
{
    $db = getDB();
    return $db->query("
        SELECT mt.*, s.receipt_no
        FROM mpesa_transactions mt
        LEFT JOIN sales s ON s.id = mt.sale_id
        ORDER BY mt.created_at DESC
        LIMIT {$limit}
    ")->fetchAll();
}