<?php
/**
 * Maseno Retail ERP - API: Checkout / Sale Completion
 *
 * POST /api/checkout.php
 * Body: { payment_method, amount_tendered, customer_id }
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../pos_backend.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_response(['error' => 'Method not allowed'], 405); }

try {
    $user = require_auth();
    $shiftId = require_shift();
    init_cart();

    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        json_response(['error' => 'Invalid JSON payload'], 400);
    }

    $paymentMethod  = $data['payment_method'] ?? 'cash';
    $amountTendered = (float) ($data['amount_tendered'] ?? 0);
    $customerId     = (int) ($data['customer_id'] ?? 0);

    // Validate payment method
    $validMethods = ['cash', 'mpesa', 'card', 'credit'];
    if (!in_array($paymentMethod, $validMethods)) {
        json_response(['error' => 'Invalid payment method'], 400);
    }

    // For M-Pesa, we need phone number
    if ($paymentMethod === 'mpesa') {
        $phone = $data['phone'] ?? '';
        if (empty($phone)) {
            json_response(['error' => 'Phone number required for M-Pesa payment'], 400);
        }
    }

    $result = complete_sale($shiftId, $user['id'], $paymentMethod, $amountTendered, $customerId);

    if ($result['success']) {
        // If M-Pesa, initiate STK Push
        if ($paymentMethod === 'mpesa' && !empty($phone)) {
            require_once __DIR__ . '/../mpesa_sandbox.php';
            $mpesaResult = mpesa_stk_push($result['total'] ?? $result['grand_total'] ?? 0, $phone, $result['receipt_no']);
            $result['mpesa'] = $mpesaResult;
        }

        json_response([
            'success'         => true,
            'sale_id'         => $result['sale_id'],
            'receipt_no'      => $result['receipt_no'],
            'message'         => $result['message'],
            'change_due'      => $result['change_due'],
            'subtotal'        => get_cart_summary_subtotal(),
            'tax_amount'      => get_cart_summary_tax(),
            'discount_amount' => get_cart_summary_discount(),
            'total'           => get_cart_summary_total(),
            'payment_method'  => $paymentMethod,
            'amount_tendered' => $amountTendered,
        ]);
    } else {
        json_response($result, 400);
    }

} catch (Exception $e) {
    json_response(['error' => $e->getMessage()], 500);
}

// Helper functions to get the last cart totals before it was cleared
function get_cart_summary_subtotal(): float {
    return (float) ($_SESSION['last_cart_subtotal'] ?? 0);
}
function get_cart_summary_tax(): float {
    return (float) ($_SESSION['last_cart_tax'] ?? 0);
}
function get_cart_summary_discount(): float {
    return (float) ($_SESSION['last_cart_discount'] ?? 0);
}
function get_cart_summary_total(): float {
    return (float) ($_SESSION['last_cart_total'] ?? 0);
}