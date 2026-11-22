<?php
date_default_timezone_set('Asia/Makassar');
require_once 'Database.php';
require_once 'AccountRedeemClasses.php';

$dbObj = new Database();
$conn = $dbObj->conn;

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$username = trim($_GET['username'] ?? '');
$requested_id_user = trim($_GET['id_user'] ?? '');

try {
    $account = NasabahAccount::loadByCredentials($conn, $username, $requested_id_user, false);
    $redeemMgr = new RedeemCodeManager($conn);
    $grouped = $redeemMgr->getCodesGroupedByStatus($account->getIdUser());

    echo json_encode([
        "status"    => "success",
        "user"      => $account->toArray(),
        "aktif"     => $grouped['aktif'],
        "non_aktif" => $grouped['non_aktif']
    ]);
} catch (Exception $e) {
    $code = $e->getCode();
    http_response_code(($code >= 400 && $code < 600) ? $code : 400);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>