<?php
session_start();

$syncMessage = '';
$lastSyncFile = __DIR__ . '/last_sync.txt';
$lastSyncTime = file_exists($lastSyncFile) ? file_get_contents($lastSyncFile) : 'Never';

// Initialize variables to prevent "Undefined variable" warnings
$totalOrders = 0;
$completedOrders = 0;
$processingOrders = 0;
$canceledOrders = 0;
$failedOrders = 0;
$refundedOrders = 0;
$topCurrencySale = ['currency' => 'USD', 'gross_sale' => 0]; // Default to USD or a sensible default
$totalGrossSale = 0;
$totalNetSale = 0;
$totalProducts = 0;
$currentMonthOrders = [];
$currentMonthSummaryByCurrency = [];
$totalGrossByCurrency = 0;
$totalRefundByCurrency = 0;
$totalFailedByCurrency = 0;
$totalNetByCurrency = 0;
$totalOrderCountByCurrency = 0;
$chartMonths = [];
$summary = [];
$grossData = [];
$refundData = [];
$netData = [];
$failedData = [];
$grossCountData = [];
$refundCountData = [];
$validOrdersCountData = [];
$rates = [];


// Check if the user is logged in
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: login.php');
    exit;
} else {
    $isLoggedIn = true;

    // index.php — single page UI
    require_once __DIR__ . '/functions.php';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_orders'])) {
        $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : null;
        $endDate = isset($_GET['end_date']) ? $_GET['end_date'] : null;
        $inserted = syncAllOrders($pdo, WC_PER_PAGE, $startDate, $endDate);
        file_put_contents($lastSyncFile, date('Y-m-d H:i:s')); // Update last sync time
        header('Content-Type: application/json');
        echo json_encode(['synced' => (int)$inserted]);
        exit;
    }

    if (isset($_GET['synced'])) {
        $syncCount = (int)$_GET['synced'];
        $syncMessage = "Sync complete — new orders inserted: {$syncCount}";
    }

    // Load analytics
    require_once __DIR__ . '/analytics.php';
}

?>
<!doctype html>
<html lang="bn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>NXT Order Analytics</title>
    <style>
        body{font-family: Arial, Helvetica, sans-serif; margin:20px; max-width: 1200px; margin: 20px auto; background-color: #f0f2f5; color: #333;}
        body.dark-mode { background-color: #121212; color: #e0e0e0; }

        .card{padding:15px;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,0.18);margin-bottom:12px; background-color: #fff;}
        body.dark-mode .card { background-color: #1e1e1e; border: 1px solid #333; }

        table{width:100%;border-collapse:collapse}
        table th, table td{padding:8px;border:1px solid #ddd;text-align:left}
        body.dark-mode table th { border-color: #444; color: #e0e0e0; }
        body.dark-mode table td { border-color: #444; color: #000; }
        body.dark-mode .responsive-table thead th { background-color: #333; }
        .top-row{display:flex;gap:12px;align-items:center}
        .small{font-size:14px;color:#666}
        .highlight-top { background-color: #d4edda; } /* Green for top */
        .highlight-bottom { background-color: #f8d7da; } /* Red for bottom */
        .status-boxes { display: flex; flex-wrap: wrap;align-items: center; gap: 15px; justify-content: space-around; margin-top: 20px; }
        .status-box {
            flex-basis: 21%; /* Adjust as needed */
            padding: 15px;
            border-radius: 8px;
            color: white;
            text-align: center;
            font-weight: bold;
            min-width: 120px;
            min-height: 120px;
        }
         .status-box .months{
            font-size: 16px !important;
         }
        .card form {
            padding: 14px 10px;
            border-radius: 8px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .filter-group {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .card form select {
            padding: 8px;
            border-radius: 4px;
            border: 1px solid #ccc;
        }
        .card form button {
            padding: 8px 12px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            background-color: #007bff;
            color: white;
        }
        .card form button.clear-filter {
            background-color: #f44336;
        }
        .status-box h4 { margin-top: 0; color: white; }
        .status-box .count { font-size: 2em; margin-bottom: 5px; }
        .status-completed { background-color: #28a745; } /* Green */
        .status-processing { background-color: #007bff; } /* Blue */
        .status-canceled { background-color: #dc3545; } /* Red */
        .status-failed { background-color: #dc3545; } /* Red */
        .status-refunded { background-color: #ffc107; } /* Yellow/Orange */
        .status-top-month { background-color: #6f42c1; } /* Purple */
        .status-gross-sale { background-color: #fd7e14; } /* Orange */
        .status-net-sale { background-color: #20c997; } /* Teal */
        .skeleton {
            animation: skeleton-loading 1s linear infinite alternate;
        }
        @keyframes skeleton-loading {
            0% { background-color: hsl(200, 20%, 80%); }
            100% { background-color: hsl(200, 20%, 95%); }
        }
        .skeleton-text {
            width: 100%;
            height: 1.2rem;
            margin-bottom: .5rem;
            border-radius: .25rem;
        }
        .skeleton-card .skeleton-text:last-child {
            width: 80%;
        }
        .loading-percentage {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 3em; /* Increased font size */
            font-weight: bold;
            color: #0056b3; /* Slightly darker blue for better contrast */
            background-color: rgba(255, 255, 255, 0.9); /* Semi-transparent white background */
            padding: 20px 30px;
            border-radius: 10px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.2);
            z-index: 1000;
        }
        .table-wrap {
            width: 100%;
            overflow-x: auto;            /* enable horizontal scroll on small screens */
            -webkit-overflow-scrolling: touch;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
            padding: 8px;
            }

            .responsive-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 720px;            /* prevents squashing; adjust as needed */
            }

            .responsive-table th,
            .responsive-table td {
            padding: 10px 12px;
            border: 1px solid #e6e6e6;
            text-align: left;
            white-space: nowrap;
            font-size: 14px;
            }

            .responsive-table thead th {
            background: #f7f7f7;
            font-weight: 600;
            }
            .status-boxes- {
                display: flex;
                align-items: center;
                justify-content: space-between;
                text-align: center;
            }
            .dashboard-container {
                display: flex;
                flex-wrap: wrap;
                gap: 20px;
                margin-bottom: 20px;
                width: 100%; /* Make it full width */
            }
 
            .dashboard-container .card {
                flex: 1;
                min-width: 300px;
                width: 100%; /* Ensure cards take full width if wrapped */
            }
            @media only screen and (max-width: 768px) {
                .status-box .count {
                    font-size: 1.82em;
                    margin-bottom: 5px;
                }
                .status-box {
                flex-basis: 38%;
                padding: 15px;
                border-radius: 8px;
                color: white;
                text-align: center;
                font-weight: bold;
                min-width: 120px;
                min-height: 120px;
            }
            }
        .theme-switch-wrapper { display: flex; align-items: center; justify-content: flex-end; margin-bottom: 1rem; }
        .theme-switch { display: inline-block; height: 34px; position: relative; width: 60px; }
        .theme-switch input { display:none; }
        .slider { background-color: #ccc; bottom: 0; cursor: pointer; left: 0; position: absolute; right: 0; top: 0; transition: .4s; }
        .slider:before { background-color: #fff; bottom: 4px; content: ""; height: 26px; left: 4px; position: absolute; transition: .4s; width: 26px; }
        input:checked + .slider { background-color: #007bff; }
        input:checked + .slider:before { transform: translateX(26px); }
        .slider.round { border-radius: 34px; }
        .slider.round:before { border-radius: 50%; }
    </style>
</head>
<body>
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <h1 style="text-align: center; flex-grow: 1;">NXT Order Analytics</h1>
        <div class="theme-switch-wrapper">
            <label class="theme-switch" for="checkbox">
                <input type="checkbox" id="checkbox" />
                <div class="slider round"></div>
            </label>
        </div>
    </div>

    <div id="loadingPercentage" class="loading-percentage" style="display:none;">0%</div>
    <div id="main-content">
        <div class="card">
        <div class="top-row">
            <div>
                <h3>Total Orders</h3>
                <div style="font-size:24px;font-weight:700"><?= htmlspecialchars($totalOrders) ?></div>
                </div>
                <div style="margin-left: 20px;">
                <h3>Total Products</h3>
                <div style="font-size:24px;font-weight:700"><?= htmlspecialchars($totalProducts) ?></div>
                </div>
                <div style="margin-left:auto; text-align: right;">
                <button onclick="confirmSync()" style="margin-bottom: 5px;background-color: #007bff; color: white; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer;">Full Sync</button>
                <button onclick="syncByDate()" style="margin-bottom: 5px;background-color: #17a2b8; color: white; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer;">Sync by Date</button>
                <div id="syncMessage" class="small"><?= htmlspecialchars($syncMessage) ?></div>
                <div class="small">Last Updated: <span id="lastSyncTime"><?= htmlspecialchars($lastSyncTime) ?></span></div>
            </div>
        </div>
    </div>

    <div class="dashboard-container">
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <h3>Order Summary</h3>
                <form method="GET" action="" style="display: flex; gap: 10px; align-items: center; margin-left: auto;">
                    <div class="filter-group">
                        <label for="periodFilter">Filter by Period:</label>
                        <select name="period" id="periodFilter" onchange="this.form.submit()">
                            <option value="all" <?= (!isset($_GET['period']) || $_GET['period'] === 'all') ? 'selected' : '' ?>>All Time</option>
                            <option value="7days" <?= (isset($_GET['period']) && $_GET['period'] === '7days') ? 'selected' : '' ?>>Last 7 Days</option>
                            <option value="10days" <?= (isset($_GET['period']) && $_GET['period'] === '10days') ? 'selected' : '' ?>>Last 10 Days</option>
                            <option value="15days" <?= (isset($_GET['period']) && $_GET['period'] === '15days') ? 'selected' : '' ?>>Last 15 Days</option>
                            <option value="30days" <?= (isset($_GET['period']) && $_GET['period'] === '30days') ? 'selected' : '' ?>>Last 30 Days</option>
                            <option value="6months" <?= (isset($_GET['period']) && $_GET['period'] === '6months') ? 'selected' : '' ?>>Last 6 Months</option>
                            <option value="last_year" <?= (isset($_GET['period']) && $_GET['period'] === 'last_year') ? 'selected' : '' ?>>Last Year</option>
                            <?php
                            $allMonths = array_keys($summary);
                            rsort($allMonths);
                            foreach ($allMonths as $mOption) {
                                $selected = (isset($_GET['period']) && $_GET['period'] === $mOption) ? 'selected' : '';
                                echo "<option value=\"{$mOption}\" {$selected}>" . htmlspecialchars(date('F Y', strtotime($mOption))) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <button type="button" onclick="window.location.href='index.php'" class="clear-filter">Clear Filter</button>
                </form>
            </div>
            <div class="status-boxes">
                <div class="status-box status-completed">
                    <h4>Completed</h4>
                    <div class="count"><?= htmlspecialchars($completedOrders) ?></div>
                </div>
                <div class="status-box status-processing">
                    <h4>Processing</h4>
                    <div class="count"><?= htmlspecialchars($processingOrders) ?></div>
                </div>
                <div class="status-box status-canceled">
                    <h4>Canceled</h4>
                    <div class="count"><?= htmlspecialchars($canceledOrders) ?></div>
                </div>
                <div class="status-box status-failed">
                    <h4>Failed</h4>
                    <div class="count"><?= htmlspecialchars($failedOrders) ?></div>
                </div>
                <div class="status-box status-refunded">
                    <h4>Refunded</h4>
                    <div class="count"><?= htmlspecialchars($refundedOrders) ?></div>
                </div>
                <div class="status-box status-top-month">
                    <h4>Top Currency (<?= htmlspecialchars($topCurrencySale['currency']) ?>) </h4>
                    <div class="count"><?= number_format($topCurrencySale['gross_sale'], 2) ?></div>
                </div>
                <div class="status-box status-gross-sale">
                    <h4>Total Gross Sale</h4>
                    <div class="count"><?= number_format($totalGrossSale, 2) ?></div>
                </div>
                <div class="status-box status-net-sale">
                    <h4>Total Net Sale</h4>
                    <div class="count"><?= number_format($totalNetSale, 2) ?></div>
                                    </div>
                                </div>
            <canvas id="barChart" width="800" height="300" style="margin-top: 40px;"></canvas>
        </div>
    </div>

    <div class="card" id="latest-orders">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <h3><?= ($filterPeriod === 'all' && !isset($_GET['period'])) ? 'Latest Orders' : 'Orders' ?></h3>
            <form method="GET" action="#latest-orders" style="display: flex; gap: 10px; align-items: center;">
                <input type="hidden" name="period" value="<?= htmlspecialchars($filterPeriod) ?>">
                <div class="filter-group">
                    <label for="orders_limit">Show:</label>
                    <select name="orders_limit" id="orders_limit" onchange="this.form.submit()">
                        <option value="10" <?= (!isset($_GET['orders_limit']) || $_GET['orders_limit'] == 10) ? 'selected' : '' ?>>10</option>
                        <option value="20" <?= (isset($_GET['orders_limit']) && $_GET['orders_limit'] == 20) ? 'selected' : '' ?>>20</option>
                        <option value="30" <?= (isset($_GET['orders_limit']) && $_GET['orders_limit'] == 30) ? 'selected' : '' ?>>30</option>
                        <option value="40" <?= (isset($_GET['orders_limit']) && $_GET['orders_limit'] == 40) ? 'selected' : '' ?>>40</option>
                        <option value="50" <?= (isset($_GET['orders_limit']) && $_GET['orders_limit'] == 50) ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= (isset($_GET['orders_limit']) && $_GET['orders_limit'] == 100) ? 'selected' : '' ?>>100</option>
                        <option value="0" <?= (isset($_GET['orders_limit']) && $_GET['orders_limit'] == 0) ? 'selected' : '' ?>>All</option>
                    </select>
                </div>
            </form>
        </div>
        <div class="table-wrap">
            <table class="responsive-table">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Currency</th>
                        <th>Created At</th>
                        <th>User Email</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($currentMonthOrders)): ?>
                        <tr>
                            <td colspan="6">No orders found for the current month.</td>
                        </tr>
                    <?php else: ?>
                        <?php
                        $currentMonthTotal = 0;
                        foreach ($currentMonthOrders as $order):
                            $currentMonthTotal += $order['total'];
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($order['order_id']) ?></td>
                                <td><?= htmlspecialchars(number_format($order['total'], 2)) ?></td>
                                <td><?= htmlspecialchars($order['status']) ?></td>
                                <td><?= htmlspecialchars($order['currency']) ?></td>
                                <td><?= htmlspecialchars($order['created_at']) ?></td>
                                <td><?= htmlspecialchars($order['customer_email']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td><b>Total</b></td>
                            <td><b><?= htmlspecialchars(number_format($currentMonthTotal, 2)) ?></b></td>
                            <td colspan="4"></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <h3><?= ($filterPeriod === 'all' && !isset($_GET['period'])) ? 'Latest Orders Summary (By Currency)' : 'Orders Summary (By Currency)' ?></h3>
        </div>
        <div class="table-wrap">
            <table class="responsive-table">
                <thead>
                    <tr>
                        <th>Currency</th>
                        <th>Gross</th>
                        <th>Refund</th>
                        <th>Failed</th>
                        <th>Net</th>
                        <th>Order Count</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($currentMonthSummaryByCurrency)): ?>
                        <tr>
                            <td colspan="6">No orders found for the current month.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($currentMonthSummaryByCurrency as $currencyCode => $data): ?>
                            <tr>
                                <td><?= htmlspecialchars($currencyCode) ?></td>
                                <td><?= number_format($data['gross'], 2) ?></td>
                                <td><?= number_format($data['refund'], 2) ?></td>
                                <td><?= number_format($data['failed'], 2) ?></td>
                                <td><?= number_format($data['net'], 2) ?></td>
                                <td><?= htmlspecialchars($data['order_count']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td><b>Total</b></td>
                            <td><b><?= number_format($totalGrossByCurrency, 2) ?></b></td>
                            <td><b><?= number_format($totalRefundByCurrency, 2) ?></b></td>
                            <td><b><?= number_format($totalFailedByCurrency, 2) ?></b></td>
                            <td><b><?= number_format($totalNetByCurrency, 2) ?></b></td>
                            <td><b><?= htmlspecialchars($totalOrderCountByCurrency) ?></b></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" id="top-selling-products">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <h3>Top Selling Products</h3>
            <form method="GET" action="#top-selling-products" style="display: flex; gap: 10px; align-items: center;">
                <input type="hidden" name="period" value="<?= htmlspecialchars($filterPeriod) ?>">
                <div class="filter-group">
                    <label for="products_limit">Show:</label>
                    <select name="products_limit" id="products_limit" onchange="this.form.submit()">
                        <option value="10" <?= (!isset($_GET['products_limit']) || $_GET['products_limit'] == 10) ? 'selected' : '' ?>>10</option>
                        <option value="20" <?= (isset($_GET['products_limit']) && $_GET['products_limit'] == 20) ? 'selected' : '' ?>>20</option>
                        <option value="30" <?= (isset($_GET['products_limit']) && $_GET['products_limit'] == 30) ? 'selected' : '' ?>>30</option>
                        <option value="40" <?= (isset($_GET['products_limit']) && $_GET['products_limit'] == 40) ? 'selected' : '' ?>>40</option>
                        <option value="50" <?= (isset($_GET['products_limit']) && $_GET['products_limit'] == 50) ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= (isset($_GET['products_limit']) && $_GET['products_limit'] == 100) ? 'selected' : '' ?>>100</option>
                        <option value="0" <?= (isset($_GET['products_limit']) && $_GET['products_limit'] == 0) ? 'selected' : '' ?>>All</option>
                    </select>
                </div>
            </form>
        </div>
        <div class="table-wrap">
            <table class="responsive-table">
                <thead>
                    <tr>
                        <th>Product Name</th>
                        <th>Total Quantity</th>
                        <th>Total Sale</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($topSellingProducts)): ?>
                        <tr>
                            <td colspan="3">No products found for the selected period.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($topSellingProducts as $product): ?>
                            <tr>
                                <td><?= htmlspecialchars($product['name']) ?></td>
                                <td><?= htmlspecialchars($product['total_quantity']) ?></td>
                                <td><?= htmlspecialchars(number_format($product['total_sale'], 2)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Monthly Summary (<?= BASE_CURRENCY ?>) All Months</h3>
        <div class="table-wrap">
        <table class="responsive-table">
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Gross (<?= BASE_CURRENCY ?>)</th>
                    <th>Refund (<?= BASE_CURRENCY ?>)</th>
                    <th>Failed (<?= BASE_CURRENCY ?>)</th>
                    <th>Net (<?= BASE_CURRENCY ?>)</th>
                    <th>Valid Orders</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $filteredMonths = $chartMonths; // This will already be filtered by period if applicable

                $netValues = [];
                $grossValues = [];
                $refundValues = [];
                $failedValues = [];

                foreach ($filteredMonths as $m) {
                    $netValues[] = round($summary[$m]['net'], 2);
                    $grossValues[] = round($summary[$m]['gross'], 2);
                    $refundValues[] = round($summary[$m]['refund'], 2);
                    $failedValues[] = round($summary[$m]['failed'], 2);
                }

                $minNet = !empty($netValues) ? min($netValues) : null;
                $maxNet = !empty($netValues) ? max($netValues) : null;

                foreach ($chartMonths as $i => $m):
                    // Use the filteredMonths from above if a specific month is selected
                    if (!in_array($m, $filteredMonths)) {
                        continue;
                    }
                    $rowClass = '';
                    if (round($summary[$m]['net'], 2) == $maxNet && $maxNet !== null) {
                        $rowClass = 'highlight-top';
                    } elseif (round($summary[$m]['net'], 2) == $minNet && $minNet !== null) {
                        $rowClass = 'highlight-bottom';
                    }
                ?>
                    <tr class="<?= $rowClass ?>">
                        <td><?= htmlspecialchars(date('F Y', strtotime($m))) ?></td>
                        <td><?= number_format($grossData[$i], 2) ?> (<?= $grossCountData[$i] ?>)</td>
                        <td><?= number_format($refundData[$i], 2) ?> (<?= $refundCountData[$i] ?>)</td>
                        <td><?= number_format($failedData[$i], 2) ?> (<?= $failedCountData[$i] ?>)</td>
                        <td><?= number_format($netData[$i], 2) ?></td>
                        <td><?= $validOrdersCountData[$i] ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td><b>Total</b></td>
                    <td><b><?= number_format(array_sum($grossValues), 2) ?> (<?= array_sum($grossCountData) ?>)</b></td>
                    <td><b><?= number_format(array_sum($refundValues), 2) ?> (<?= array_sum($refundCountData) ?>)</b></td>
                    <td><b><?= number_format(array_sum($failedValues), 2) ?> (<?= array_sum($failedCountData) ?>)</b></td>
                    <td><b><?= number_format(array_sum($netValues), 2) ?></b></td>
                    <td><b><?= array_sum($validOrdersCountData) ?></b></td>
                </tr>
            </tbody>
        </table>
        </div>
    </div>
 
    <div class="card">
        <h3>Currency Conversion Rates (1 <?= BASE_CURRENCY ?> equals)</h3>
        <div class="table-wrap">
            <table class="responsive-table">
                <thead>
                    <tr>
                        <th>Currency</th>
                        <th>Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rates as $currencyCode => $rate): ?>
                        <tr>
                            <td><?= htmlspecialchars($currencyCode) ?></td>
                            <td><?= htmlspecialchars($rate) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
</div>
<div id="skeleton-loader" style="display: none;">
    <div class="card skeleton"><div class="skeleton-text"></div><div class="skeleton-text"></div><div class="skeleton-text"></div></div>
    <div class="card skeleton"><div class="skeleton-text"></div><div class="skeleton-text"></div><div class="skeleton-text"></div></div>
     <div class="card skeleton"><div class="skeleton-text"></div><div class="skeleton-text"></div><div class="skeleton-text"></div></div>
    <div class="card skeleton"><div class="skeleton-text"></div><div class="skeleton-text"></div><div class="skeleton-text"></div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>

    function syncByDate() {
        Swal.fire({
            title: 'Select a date range to sync',
            html: `
                <div style="display: flex; align-items: center; justify-content: center;">
                    <input type="date" id="start_date" class="swal2-input" style="width: 45%;" max="${new Date().toISOString().split('T')[0]}">
                    <span style="margin: 0 10px;">To</span>
                    <input type="date" id="end_date" class="swal2-input" style="width: 45%;" max="${new Date().toISOString().split('T')[0]}">
                </div>
            `,
            confirmButtonText: 'Sync',
            showCancelButton: true,
            preConfirm: () => {
                const startDate = document.getElementById('start_date').value;
                const endDate = document.getElementById('end_date').value;
                if (!startDate || !endDate) {
                    Swal.showValidationMessage('Please select a start and end date');
                }
                return { startDate, endDate };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                const { startDate, endDate } = result.value;
                handleSync(`?sync_orders=1&start_date=${startDate}&end_date=${endDate}`, `Successfully synced orders from ${startDate} to ${endDate}.`);
            }
        });
    }

    function confirmSync() {
        Swal.fire({
            title: 'Are you sure?',
            text: "You are about to sync new orders!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, sync it!'
        }).then((result) => {
            if (result.isConfirmed) {
                handleSync('?sync_orders=1', 'Successfully synced new orders.');
            }
        });
    }

    function handleSync(url, successMessage) {
        document.getElementById('syncMessage').innerText = 'Syncing...';
        showSkeletonLoader(true);
        document.getElementById('loadingPercentage').innerText = '0%';
        document.getElementById('loadingPercentage').style.display = 'block';

        let percentage = 0;
        const progressInterval = setInterval(() => {
            percentage += Math.floor(Math.random() * 10) + 1; // Simulate progress
            if (percentage > 90) percentage = 90; // Don't reach 100% until actual completion
            document.getElementById('loadingPercentage').innerText = `${percentage}%`;
        }, 200);

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'sync_orders=1'
        })
        .then(response => response.json())
        .then(data => {
            clearInterval(progressInterval);
            document.getElementById('loadingPercentage').innerText = '100%';
            document.getElementById('syncMessage').innerText = `Sync complete — new orders inserted: ${data.synced}`;
            document.getElementById('lastSyncTime').innerText = new Date().toLocaleString();
            showSkeletonLoader(false);
            Swal.fire({
                title: 'Sync Complete!',
                text: successMessage,
                icon: 'success',
                confirmButtonText: 'OK'
            }).then(() => {
                window.location.reload();
            });
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('syncMessage').innerText = 'Sync failed.';
            clearInterval(progressInterval);
            document.getElementById('loadingPercentage').innerText = 'Error!';
            showSkeletonLoader(false);
        });
    }

        function showSkeletonLoader(show) {
            const mainContent = document.getElementById('main-content');
            const skeletonLoader = document.getElementById('skeleton-loader');
            const loadingPercentage = document.getElementById('loadingPercentage');
            if (show) {
                if (mainContent) mainContent.style.display = 'none';
                if (skeletonLoader) skeletonLoader.style.display = 'block';
                if (loadingPercentage) loadingPercentage.style.display = 'block';
            } else {
                if (mainContent) mainContent.style.display = 'block';
                if (skeletonLoader) skeletonLoader.style.display = 'none';
                if (loadingPercentage) loadingPercentage.style.display = 'none';
            }
        }

        const labels = <?= json_encode($chartMonths) ?>;
        const grossData = <?= json_encode($grossData) ?>;
        const refundData = <?= json_encode($refundData) ?>;
        const netData = <?= json_encode($netData) ?>;
        const failedData = <?= json_encode($failedData) ?>;

        // Bar chart — Gross vs Refund
        new Chart(document.getElementById('barChart'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Gross (<?= BASE_CURRENCY ?>)', data: grossData },
                    { label: 'Refund (<?= BASE_CURRENCY ?>)', data: refundData },
                    { label: 'Failed (<?= BASE_CURRENCY ?>)', data: failedData },
                    { label: 'Net (<?= BASE_CURRENCY ?>)', data: netData }
                ]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'top' } },
                scales: { y: { beginAtZero: true } }
            }
        });

        const toggleSwitch = document.querySelector('.theme-switch input[type="checkbox"]');
        const currentTheme = localStorage.getItem('theme');

        if (currentTheme) {
            document.body.classList.add(currentTheme);
        
            if (currentTheme === 'dark-mode') {
                toggleSwitch.checked = true;
            }
        }

        function switchTheme(e) {
            if (e.target.checked) {
                document.body.classList.add('dark-mode');
                localStorage.setItem('theme', 'dark-mode');
            } else {
                document.body.classList.remove('dark-mode');
                localStorage.setItem('theme', 'light-mode');
            }
        }

        toggleSwitch.addEventListener('change', switchTheme, false);
    </script>
</body>
</html>