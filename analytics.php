<?php
// analytics.php
require_once __DIR__ . '/config.php';
$rates = require __DIR__ . '/currency_rates.php';

// Determine filter period
$filterPeriod = isset($_GET['period']) ? $_GET['period'] : 'all'; // Default to all

$dateCondition = '';
switch ($filterPeriod) {
    case '7days':
        $dateCondition = "created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        break;
    case '10days':
        $dateCondition = "created_at >= DATE_SUB(CURDATE(), INTERVAL 10 DAY)";
        break;
    case '15days':
        $dateCondition = "created_at >= DATE_SUB(CURDATE(), INTERVAL 15 DAY)";
        break;
    case '30days':
        $dateCondition = "created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        break;
    case '6months':
        $dateCondition = "created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)";
        break;
    case 'last_year':
        $dateCondition = "created_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
        break;
    case 'all':
    default:
        // If 'all' is selected, check for a specific month filter
        if (isset($_GET['period']) && $_GET['period'] !== 'all') { // This means a month like "September 2025" is selected
            $selectedMonth = $_GET['period'];
            $dateCondition = "DATE_FORMAT(created_at, '%M %Y') = '" . $selectedMonth . "'";
        } else {
            $dateCondition = "";
        }
        break;
}

// Build WHERE clause parts
$whereParts = [];
if (!empty($dateCondition)) {
    $whereParts[] = $dateCondition;
}

// Helper function to build query with dynamic WHERE clause
function buildQuery($baseSql, $whereParts, $statusCondition = null) {
    $currentWhereParts = $whereParts;
    if ($statusCondition) {
        $currentWhereParts[] = $statusCondition;
    }
    if (!empty($currentWhereParts)) {
        return $baseSql . " WHERE " . implode(" AND ", $currentWhereParts);
    }
    return $baseSql;
}

// Total orders
$totalOrders = (int)$pdo->query(buildQuery("SELECT COUNT(*) FROM woo_orders", $whereParts))->fetchColumn();

// Orders by status
$completedOrders = (int)$pdo->query(buildQuery("SELECT COUNT(*) FROM woo_orders", $whereParts, "status = 'completed'"))->fetchColumn();
$processingOrders = (int)$pdo->query(buildQuery("SELECT COUNT(*) FROM woo_orders", $whereParts, "status = 'processing'"))->fetchColumn();
$canceledOrders = (int)$pdo->query(buildQuery("SELECT COUNT(*) FROM woo_orders", $whereParts, "status = 'cancelled'"))->fetchColumn();
$failedOrders = (int)$pdo->query(buildQuery("SELECT COUNT(*) FROM woo_orders", $whereParts, "status = 'failed'"))->fetchColumn();
$refundedOrders = (int)$pdo->query(buildQuery("SELECT COUNT(*) FROM woo_orders", $whereParts, "status = 'refunded'"))->fetchColumn();

// Total published products
//$totalProducts = getTotalPublishedProducts();
$totalProducts = 68659;
// Current month's orders
// Current month's orders (now "Latest 10 Orders" and should be affected by period filter)
$ordersLimit = isset($_GET['orders_limit']) ? (int)$_GET['orders_limit'] : 10;
if ($ordersLimit === 0) {
    $latestOrdersLimit = "";
} else {
    $latestOrdersLimit = " LIMIT " . $ordersLimit;
}
$currentMonthOrders = $pdo->query(buildQuery("SELECT * FROM woo_orders", $whereParts) . " ORDER BY created_at DESC" . $latestOrdersLimit)->fetchAll(PDO::FETCH_ASSOC);

// Calculate current month's gross, refund, and failed totals in BASE_CURRENCY
$currentMonthGross = 0.0;
$currentMonthRefund = 0.0;
$currentMonthFailed = 0.0;

foreach ($currentMonthOrders as $order) {
    $orderTotal = (float)$order['total'];
    $orderCurrency = strtoupper($order['currency']);
    $rate = isset($rates[$orderCurrency]) ? (float)$rates[$orderCurrency] : 1.0;

    $convertedTotal = $orderTotal * $rate;

    $currentMonthGross += $convertedTotal;
    if ($order['status'] === 'refunded') {
        $currentMonthRefund += $convertedTotal;
    }
    if ($order['status'] === 'failed') {
        $currentMonthFailed += $convertedTotal;
    }
}

$currentMonthNet = $currentMonthGross - $currentMonthRefund - $currentMonthFailed;

// The counts for total, refunded, and failed orders are now calculated
// directly with SQL queries, so these manual counts are no longer needed.

// Aggregated monthly per currency
$sql = "
SELECT DATE_FORMAT(created_at, '%M %Y') AS month, currency,
       SUM(total) AS gross,
       COUNT(*) AS gross_count,
       SUM(CASE WHEN status = 'refunded' THEN total ELSE 0 END) AS refund,
       COUNT(CASE WHEN status = 'refunded' THEN 1 ELSE NULL END) AS refund_count,
       SUM(CASE WHEN status = 'failed' THEN total ELSE 0 END) AS failed,
       COUNT(CASE WHEN status = 'failed' THEN 1 ELSE NULL END) AS failed_count,
       COUNT(*) AS total_orders_count
FROM woo_orders";


$sql .= "
GROUP BY month, currency
ORDER BY month DESC
";
$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Convert and combine per month into BASE_CURRENCY
$summary = [];

// The selectedMonthFilter logic is now integrated into the $dateCondition switch statement
// No need for $selectedMonthFilter variable here anymore.
 
foreach ($rows as $r) {
    $month = $r['month'];
    $currency = strtoupper($r['currency']);
 
    // The filtering logic is now handled in the SQL query directly via $whereParts
    // No need for this conditional check here anymore.

    $gross = (float)$r['gross'];
    $grossCount = (int)$r['gross_count'];
    $refund = (float)$r['refund'];
    $refundCount = (int)$r['refund_count'];
    $failed = (float)$r['failed'];
    $failedCount = (int)$r['failed_count'];
    $totalOrdersCount = (int)$r['total_orders_count'];

    // Calculate current month's summary by currency based on $currentMonthOrders
    // This block is now outside the main summary loop
    
// Calculate current month's summary by currency based on $currentMonthOrders
$currentMonthSummaryByCurrency = [];
foreach ($currentMonthOrders as $order) {
    $orderTotal = (float)$order['total'];
    $orderCurrency = strtoupper($order['currency']);

    if (!isset($currentMonthSummaryByCurrency[$orderCurrency])) {
        $currentMonthSummaryByCurrency[$orderCurrency] = [
            'gross' => 0.0,
            'refund' => 0.0,
            'failed' => 0.0,
            'net' => 0.0,
            'order_count' => 0
        ];
    }
    $currentMonthSummaryByCurrency[$orderCurrency]['gross'] += $orderTotal;
    if ($order['status'] === 'refunded') {
        $currentMonthSummaryByCurrency[$orderCurrency]['refund'] += $orderTotal;
    }
    if ($order['status'] === 'failed') {
        $currentMonthSummaryByCurrency[$orderCurrency]['failed'] += $orderTotal;
    }
    $currentMonthSummaryByCurrency[$orderCurrency]['net'] = $currentMonthSummaryByCurrency[$orderCurrency]['gross'] - $currentMonthSummaryByCurrency[$orderCurrency]['refund'] - $currentMonthSummaryByCurrency[$orderCurrency]['failed'];
    $currentMonthSummaryByCurrency[$orderCurrency]['order_count']++;
}

// Calculate totals for currentMonthSummaryByCurrency
$totalGrossByCurrency = 0;
$totalRefundByCurrency = 0;
$totalFailedByCurrency = 0;
$totalNetByCurrency = 0;
$totalOrderCountByCurrency = 0;

foreach ($currentMonthSummaryByCurrency as $currencyCode => $data) {
    $totalGrossByCurrency += $data['gross'];
    $totalRefundByCurrency += $data['refund'];
    $totalFailedByCurrency += $data['failed'];
    $totalNetByCurrency += $data['net'];
    $totalOrderCountByCurrency += $data['order_count'];
}

    $rate = isset($rates[$currency]) ? (float)$rates[$currency] : 1.0; // fallback
    $grossBase = $gross * $rate;
    $refundBase = $refund * $rate;
    $failedBase = $failed * $rate;
    $netBase = $grossBase - $refundBase - $failedBase; // This line was moved from the bottom of the previous block
    $validOrdersCount = $grossCount - $refundCount - $failedCount;

    if (!isset($summary[$month])) {
        $summary[$month] = [
            'gross' => 0.0, 'gross_count' => 0,
            'refund' => 0.0, 'refund_count' => 0,
            'net' => 0.0,
            'failed' => 0.0, 'failed_count' => 0,
            'total_orders_count' => 0, 'valid_orders_count' => 0
        ];
    }

    $summary[$month]['gross'] += $grossBase;
    $summary[$month]['gross_count'] += $grossCount;
    $summary[$month]['refund'] += $refundBase;
    $summary[$month]['refund_count'] += $refundCount;
    $summary[$month]['net'] += $netBase;
    $summary[$month]['failed'] += $failedBase;
    $summary[$month]['failed_count'] += $failedCount;
    $summary[$month]['total_orders_count'] += $totalOrdersCount;
    $summary[$month]['valid_orders_count'] += $validOrdersCount;
}

// Sort months ascending for charts (old -> new)
$months = array_keys($summary);
// Sort months ascending for charts (old -> new)
// rsort($months); // currently rows ordered DESC, flip if you prefer latest last
// $months = array_reverse($months);
usort($months, function($a, $b) {
    return strtotime($a) - strtotime($b);
});

$chartMonths = [];
$grossData = [];
$grossCountData = [];
$refundData = [];
$refundCountData = [];
$netData = [];
$failedData = [];
$failedCountData = [];
$totalOrdersCountData = [];
$validOrdersCountData = [];
foreach ($months as $m) {
    $chartMonths[] = $m;
    $grossData[] = round($summary[$m]['gross'], 2);
    $grossCountData[] = $summary[$m]['gross_count'];
    $refundData[] = round($summary[$m]['refund'], 2);
    $refundCountData[] = $summary[$m]['refund_count'];
    $netData[] = round($summary[$m]['net'], 2);
    $failedData[] = round($summary[$m]['failed'], 2);
    $failedCountData[] = $summary[$m]['failed_count'];
    $totalOrdersCountData[] = $summary[$m]['total_orders_count'];
    $validOrdersCountData[] = $summary[$m]['valid_orders_count'];
}

// Calculate total gross and net sales based on filter period, with currency conversion
$sqlFilteredOrders = buildQuery("SELECT total, currency, status FROM woo_orders", $whereParts);
$filteredOrders = $pdo->query($sqlFilteredOrders)->fetchAll(PDO::FETCH_ASSOC);

$totalGrossSale = 0.0;
$totalNetSale = 0.0;

foreach ($filteredOrders as $order) {
    $orderTotal = (float)$order['total'];
    $orderCurrency = strtoupper($order['currency']);
    $rate = isset($rates[$orderCurrency]) ? (float)$rates[$orderCurrency] : 1.0;

    $convertedTotal = $orderTotal * $rate;
    $totalGrossSale += $convertedTotal;

    // Assuming net sale includes completed and processing orders
    if ($order['status'] === 'completed' || $order['status'] === 'processing') {
        $totalNetSale += $convertedTotal;
    }
}

// Calculate top currency sale
$topCurrencySale = ['currency' => '', 'gross_sale' => 0];
$sqlTopCurrency = "
    SELECT currency, SUM(total) as total_gross_sale
    FROM woo_orders
    " . buildQuery("", $whereParts) . "
    GROUP BY currency
    ORDER BY total_gross_sale DESC
    LIMIT 1
";
$stmtTopCurrency = $pdo->query($sqlTopCurrency);
$resultTopCurrency = $stmtTopCurrency->fetch(PDO::FETCH_ASSOC);

if ($resultTopCurrency) {
    $topCurrencySale['currency'] = $resultTopCurrency['currency'];
    $topCurrencySale['gross_sale'] = (float)$resultTopCurrency['total_gross_sale'];
}

// Calculate top selling products
$topSellingProducts = [];
$productsLimit = isset($_GET['products_limit']) ? (int)$_GET['products_limit'] : 10;
if ($productsLimit === 0) {
    $topProductsLimit = "";
} else {
    $topProductsLimit = " LIMIT " . $productsLimit;
}

$sqlTopProducts = "
    SELECT wop.name, SUM(wop.quantity) as total_quantity, SUM(wop.total) as total_sale
    FROM woo_order_products wop
    JOIN woo_orders wo ON wop.order_id = wo.order_id
";

// Apply date filter directly to wo.created_at
if (!empty($dateCondition)) {
    $sqlTopProducts .= " WHERE " . str_replace("created_at", "wo.created_at", $dateCondition);
}

$sqlTopProducts .= "
    GROUP BY wop.name
    ORDER BY total_quantity DESC
    " . $topProductsLimit;
$stmtTopProducts = $pdo->query($sqlTopProducts);
$topSellingProducts = $stmtTopProducts->fetchAll(PDO::FETCH_ASSOC);