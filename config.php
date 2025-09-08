<?php
// config.php
date_default_timezone_set('UTC');
// Edit these values for your environment

// WooCommerce REST API
define('WC_SITE_URL', 'https://www.nxtmotors.com');
define('WC_API_NAMESPACE', '/wp-json/wc/v3');
define('WC_CONSUMER_KEY', 'ck_e7cf3c5b7f86c23456b511aeca39b6e3552fa8e6');
define('WC_CONSUMER_SECRET', 'cs_744569d0149e9b649aec4619ebdbf66b57c69be4');

// // Authentication Credentials
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD', 'adminpass'); 

// DB (PDO)
$dbHost = '127.0.0.1';
$dbName = 'u108964440_nxtorders';
$dbUser = 'u108964440_nxtorders';
$dbPass = 'nxtOrders2@#';


try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    die('DB Connection failed: ' . $e->getMessage());
}

// per-page (Woo REST max 100)
define('WC_PER_PAGE', 100);
// Base currency for display/conversion
define('BASE_CURRENCY', 'USD');