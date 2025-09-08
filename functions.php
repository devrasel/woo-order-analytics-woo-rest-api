<?php
// functions.php
require_once __DIR__ . '/config.php';

/**
 * Call WooCommerce REST API GET /orders with basic auth
 * Returns decoded JSON array or null on error
 */
function wc_api_get($endpoint, $params = []) {
    $url = rtrim(WC_SITE_URL, '/') . WC_API_NAMESPACE . '/' . ltrim($endpoint, '/');
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, WC_CONSUMER_KEY . ':' . WC_CONSUMER_SECRET);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120); // Increased timeout to 120 seconds

    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($res === false) {
        error_log("WooCommerce API cURL Error: " . $err);
        return null;
    }
    if ($httpCode >= 400) {
        error_log("WooCommerce API HTTP Error: " . $httpCode . " - " . $res);
        return null;
    }

    $json = json_decode($res, true);
    return $json;
}

/**
 * Save compact order entries to DB. Uses INSERT IGNORE so duplicates by order_id are skipped.
 * Expects orders array from Woo API (each order has id, total, status, currency, date_created)
 */
function saveOrdersToDB(array $orders, PDO $pdo) {
    if (empty($orders)) return 0;

    $sql = "INSERT INTO woo_orders (order_id, total, status, currency, created_at, customer_email) VALUES (:order_id, :total, :status, :currency, :created_at, :customer_email) ON DUPLICATE KEY UPDATE total = VALUES(total), status = VALUES(status), currency = VALUES(currency), created_at = VALUES(created_at), customer_email = VALUES(customer_email)";
    $stmt = $pdo->prepare($sql);

    $count = 0;
    foreach ($orders as $order) {
        $orderId = $order['id'] ?? null;
        $total = isset($order['total']) ? (float)$order['total'] : 0.0;
        $status = $order['status'] ?? '';
        $currency = $order['currency'] ?? (isset($order['currency']) ? $order['currency'] : 'USD');
        $dateCreated = isset($order['date_created']) ? date('Y-m-d H:i:s', strtotime($order['date_created'])) : date('Y-m-d H:i:s');
        $customerEmail = $order['billing']['email'] ?? 'N/A';

        if (!$orderId) continue;

        $stmt->execute([
            ':order_id' => $orderId,
            ':total' => $total,
            ':status' => $status,
            ':currency' => $currency,
            ':created_at' => $dateCreated,
            ':customer_email' => $customerEmail,
        ]);

        $count += $stmt->rowCount(); // rowCount returns 1 if inserted, 0 if ignored

        if (!empty($order['line_items'])) {
            saveOrderProductsToDB($order['id'], $order['line_items'], $pdo);
        }
    }

    return $count;
}

/**
 * Save order products to DB.
 */
function saveOrderProductsToDB($orderId, array $products, PDO $pdo) {
    if (empty($products)) return;

    // Delete existing products for this order to prevent duplicates
    $deleteSql = "DELETE FROM woo_order_products WHERE order_id = :order_id";
    $deleteStmt = $pdo->prepare($deleteSql);
    $deleteStmt->execute([':order_id' => $orderId]);

    $sql = "INSERT INTO woo_order_products (order_id, product_id, name, quantity, total, created_at) VALUES (:order_id, :product_id, :name, :quantity, :total, :created_at)";
    $stmt = $pdo->prepare($sql);

    foreach ($products as $product) {
        $stmt->execute([
            ':order_id' => $orderId,
            ':product_id' => $product['product_id'],
            ':name' => $product['name'],
            ':quantity' => $product['quantity'],
            ':total' => $product['total'],
            ':created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

/**
 * Fetch all orders page by page and save. Returns total inserted count.
 */
function syncAllOrders(PDO $pdo, $perPage = WC_PER_PAGE, $startDate = null, $endDate = null) {
    $page = 1;
    $insertedTotal = 0;

    do {
        $params = [
            'per_page' => $perPage,
            'page' => $page,
            'orderby' => 'date',
            'order' => 'asc'
        ];

        if ($startDate && $endDate) {
            $params['modified_after'] = $startDate . 'T00:00:00';
            $params['modified_before'] = $endDate . 'T23:59:59';
        }
        
        $orders = wc_api_get('orders', $params);

        if (!is_array($orders)) {
            error_log("Failed to fetch orders from API or invalid response.");
            break;
        }
        if (empty($orders)) break;

        $inserted = saveOrdersToDB($orders, $pdo);
        $insertedTotal += $inserted;

        // if returned less than perPage, we reached last page
        if (count($orders) < $perPage) break;

        $page++;
    } while (true);

    return $insertedTotal;
}

/**
 * Get total orders count from WooCommerce
 */
function getTotalOrdersCount($startDate = null, $endDate = null) {
    $params = ['per_page' => 1];
    if ($startDate && $endDate) {
        $params['modified_after'] = $startDate . 'T00:00:00';
        $params['modified_before'] = $endDate . 'T23:59:59';
    }

    $url = rtrim(WC_SITE_URL, '/') . WC_API_NAMESPACE . '/orders?' . http_build_query($params);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, WC_CONSUMER_KEY . ':' . WC_CONSUMER_SECRET);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_HEADER, true); // Include headers in the output
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    
    if ($response === false) {
        error_log("WooCommerce API cURL Error for orders count: " . $err);
        return 0;
    }
    
    if ($httpCode >= 400) {
        error_log("WooCommerce API HTTP Error for orders count: " . $httpCode . " - " . $response);
        return 0;
    }
    
    $header = substr($response, 0, $headerSize);
    $headers = [];
    foreach (explode("\r\n", $header) as $line) {
        if (strpos($line, ':') !== false) {
            list($key, $value) = explode(':', $line, 2);
            $key = strtolower(trim($key));
            $headers[$key] = trim($value);
        }
    }
    
    return isset($headers['x-wp-total']) ? (int)$headers['x-wp-total'] : 0;
}
// /**
//  * Get total published products count from WooCommerce
//  */
// function getTotalPublishedProducts() {
//     $url = rtrim(WC_SITE_URL, '/') . WC_API_NAMESPACE . '/products?status=publish&per_page=1';
    
//     $ch = curl_init($url);
//     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//     curl_setopt($ch, CURLOPT_USERPWD, WC_CONSUMER_KEY . ':' . WC_CONSUMER_SECRET);
//     curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
//     curl_setopt($ch, CURLOPT_HEADER, true); // Include headers in the output
    
//     $response = curl_exec($ch);
//     $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
//     $err = curl_error($ch);
//     $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
//     curl_close($ch);
    
//     if ($response === false) {
//         error_log("WooCommerce API cURL Error for products: " . $err);
//         return 0;
//     }
    
//     if ($httpCode >= 400) {
//         error_log("WooCommerce API HTTP Error for products: " . $httpCode . " - " . $response);
//         return 0;
//     }
    
//     $header = substr($response, 0, $headerSize);
//     $headers = [];
//     foreach (explode("\r\n", $header) as $line) {
//         if (strpos($line, ':') !== false) {
//             list($key, $value) = explode(':', $line, 2);
//             $key = strtolower(trim($key));
//             $headers[$key] = trim($value);
//         }
//     }
    
//     return isset($headers['x-wp-total']) ? (int)$headers['x-wp-total'] : 0;
// }