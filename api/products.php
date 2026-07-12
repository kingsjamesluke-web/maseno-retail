<?php
/**
 * Maseno Retail ERP - API: Product Lookup
 *
 * GET /api/products.php          - List all products (paginated)
 * GET /api/products.php?search=  - Search products
 * GET /api/products.php?barcode= - Find by barcode/SKU
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { json_response(['error' => 'Method not allowed'], 405); }

try {
    require_auth();

    // Barcode/SKU exact lookup
    if (!empty($_GET['barcode'])) {
        $product = find_product($_GET['barcode']);
        if (is_array($product) && isset($product['id'])) {
            json_response($product);
        } elseif (is_array($product) && !isset($product['id'])) {
            // Multiple matches from name search
            json_response(['error' => 'Not found', 'matches' => $product], 404);
        } else {
            json_response(['error' => 'Product not found'], 404);
        }
        exit;
    }

    // Search with term
    $term     = $_GET['search'] ?? '';
    $category = $_GET['category'] ?? '';
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $perPage  = min(100, max(10, (int)($_GET['per_page'] ?? 50)));

    $result = search_products($term, $category, $page, $perPage);
    json_response($result);

} catch (Exception $e) {
    json_response(['error' => $e->getMessage()], 500);
}