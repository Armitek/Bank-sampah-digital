<?php
date_default_timezone_set('Asia/Makassar');
require_once 'Database.php';
require_once 'AdminClasses.php';

$dbObj = new Database();
$conn = $dbObj->conn;

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$inputData = json_decode(file_get_contents("php://input"), true);
$idUser = (string)($inputData['id_user'] ?? '');
$items = is_array($inputData['items'] ?? null) ? $inputData['items'] : [];

try {
    $processor = new AdminDepositProcessor($conn);
    $result = $processor->processDeposit($idUser, $items);

    echo json_encode([
        "status"           => "success",
        "message"          => "Setoran berhasil diproses! Kode Transaksi: " . $result['kode_transaksi'],
        "kode_transaksi"   => $result['kode_transaksi'],
        "total_pendapatan" => $result['total_pendapatan'],
        "saldo_baru"       => $result['saldo_baru']
    ]);
} catch (Exception $e) {
    $code = $e->getCode();
    http_response_code(($code >= 400 && $code < 600) ? $code : 400);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>