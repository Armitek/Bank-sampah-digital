<?php
date_default_timezone_set('Asia/Makassar');
require_once 'Database.php';
require_once 'AccountRedeemClasses.php';
require_once 'TransactionClasses.php';

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

$rawInput = file_get_contents("php://input");
$inputData = json_decode($rawInput, true);

$username = trim($inputData['username'] ?? '');
$requested_id_user = trim($inputData['id_user'] ?? '');
$nominal = intval($inputData['nominal'] ?? 0);

$redeemMgr = new RedeemCodeManager($conn);

if (!$redeemMgr->isValidNominal($nominal)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Nominal voucher tidak valid."]);
    exit();
}

$conn->begin_transaction();

try {
    $account = NasabahAccount::loadByCredentials($conn, $username, $requested_id_user, true);

    if (!$account->hasEnoughSaldo((float)$nominal)) {
        throw new Exception("Saldo tidak mencukupi untuk nominal ini.", 400);
    }

    $nowStr = date('Y-m-d H:i:s');
    $issued = $redeemMgr->issueCode($account->getIdUser(), $nominal, $nowStr);
    $kodeRedeem = $issued['kode_redeem'];

    $redeemTrx = new RedeemTransaction(
        $conn,
        $account->getIdUser(),
        $kodeRedeem,
        $nominal,
        $redeemMgr->getNominalTag($nominal),
        $nowStr
    );
    $redeemTrx->siapkanKategori();

    $newSaldo = $account->applySaldoDelta($redeemTrx->getNominalPerubahanSaldo());
    $redeemTrx->simpanKeRiwayat();

    $conn->commit();

    echo json_encode([
        "status"      => "success",
        "message"     => "Kode redeem berhasil dibuat!",
        "kode_redeem" => $kodeRedeem,
        "nominal"     => $nominal,
        "status_kode" => $issued['status'],
        "created_at"  => $nowStr,
        "saldo_baru"  => $newSaldo
    ]);
} catch (Exception $e) {
    $conn->rollback();
    $code = $e->getCode();
    http_response_code(($code >= 400 && $code < 600) ? $code : 400);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>