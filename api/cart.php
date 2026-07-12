<?php
/**
 * Maseno Retail ERP - API: Cart Management
 *
 * POST /api/cart.php  { action: 'add'|'update'|'remove'|'clear', product_id, quantity }
 * GET  /api/cart.php  - Returns current cart state
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../pos_backend.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

try {
    require_auth();
    init_cart();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response(get_cart());
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['error' => 'Method not allowed'], 405);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || empty($data['action'])) {
        json_response(['error' => 'Invalid request'], 400);
    }

    $action     = $data['action'];
    $productId  = (int) ($data['product_id'] ?? 0);
    $quantity   = (float) ($data['quantity'] ?? 1);
    $discountPct = (float) ($data['discount_pct'] ?? 0);

    $result = match ($action) {
        'add'    => cart_add($productId, $quantity, $discountPct),
        'update' => cart_update_qty($productId, $quantity),
        'remove' => cart_remove($productId),
        'clear'  => ['success' => true, 'message' => 'Cart cleared.'],
        default  => ['success' => false, 'message' => 'Unknown action.'],
    };

    if ($action === 'clear') cart_clear();

    json_response($result);

} catch (Exception $e) {
    json_response(['error' => $e->getMessage()], 500);
}