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
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$inputData = json_decode(file_get_contents("php://input"), true);
$action = strtolower(trim($inputData['action'] ?? 'check'));
$kodeRedeem = (string)($inputData['kode_redeem'] ?? '');
$adminUsername = trim((string)($inputData['admin_username'] ?? ''));

try {
    $verifier = new AdminRedeemVerifier($conn);

    if ($action === 'check') {
        $detail = $verifier->checkCode($kodeRedeem);
        echo json_encode([
            "status"  => "success",
            "message" => $detail['status'] === 'AKTIF'
                ? "Kode redeem valid dan berstatus AKTIF (siap dicairkan)."
                : "Kode redeem sudah berstatus NON-AKTIF (sudah dicairkan).",
            "data"    => $detail
        ]);
        exit();
    }

    if ($action === 'redeem') {
        $detail = $verifier->redeemCode($kodeRedeem, $adminUsername !== '' ? $adminUsername : null);
        echo json_encode([
            "status"  => "success",
            "message" => "Kode redeem berhasil dicairkan dan Kas Bank telah dikurangi!",
            "data"    => $detail
        ]);
        exit();
    }

    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Aksi tidak dikenali."]);
} catch (Exception $e) {
    $code = $e->getCode();
    http_response_code(($code >= 400 && $code < 600) ? $code : 500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>