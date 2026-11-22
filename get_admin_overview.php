<?php
date_default_timezone_set('Asia/Makassar');
require_once 'Database.php';
require_once 'AdminClasses.php';

$dbObj = new Database();
$conn = $dbObj->conn;

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

try {
    $report = new AdminOverviewReport($conn);
    $periods = $report->generate();

    echo json_encode([
        "status"  => "success",
        "periods" => $periods
    ]);
} catch (Exception $e) {
    $code = $e->getCode();
    http_response_code(($code >= 400 && $code < 600) ? $code : 500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>