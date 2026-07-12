<?php
/**
 * Maseno Retail ERP - API: Customer Lookup
 *
 * GET /api/customers.php?search=  - Find customer by phone/name
 * GET /api/customers.php?id=      - Get customer by ID
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../crm.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { json_response(['error' => 'Method not allowed'], 405); }

try {
    require_auth();

    // By ID
    if (!empty($_GET['id'])) {
        $customer = get_customer((int)$_GET['id']);
        if ($customer) {
            json_response($customer);
        } else {
            json_response(['error' => 'Customer not found'], 404);
        }
        exit;
    }

    // By search query
    if (!empty($_GET['search'])) {
        $result = find_customer($_GET['search']);
        if ($result) {
            json_response($result);
        } else {
            json_response(['error' => 'Customer not found'], 404);
        }
        exit;
    }

    // List all (paginated)
    $search  = $_GET['q'] ?? '';
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 50)));

    json_response(list_customers($search, $page, $perPage));

} catch (Exception $e) {
    json_response(['error' => $e->getMessage()], 500);
}