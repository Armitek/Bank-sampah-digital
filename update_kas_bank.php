<?php
date_default_timezone_set('Asia/Makassar');
require_once 'Database.php';

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
$username = trim((string)($inputData['username'] ?? ''));
$rawSaldo = $inputData['saldo'] ?? null;

if ($username === '' || $rawSaldo === null || !is_numeric($rawSaldo)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Username admin dan nominal kas bank wajib valid."]);
    exit();
}

$newSaldo = round(floatval($rawSaldo), 2);
if ($newSaldo < 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Nominal Kas Bank tidak boleh kurang dari 0."]);
    exit();
}

$stmtCheck = $conn->prepare("SELECT id_user, role FROM users WHERE username = ? LIMIT 1");
$stmtCheck->bind_param("s", $username);
$stmtCheck->execute();
$resCheck = $stmtCheck->get_result();
$row = $resCheck->fetch_assoc();
$stmtCheck->close();

if (!$row) {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "Akun admin tidak ditemukan."]);
    exit();
}

if (strtoupper((string)($row['role'] ?? '')) !== 'ADMIN') {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Akses ditolak: Akun ini bukan ADMIN."]);
    exit();
}

$stmtUpd = $conn->prepare("UPDATE users SET saldo = ? WHERE username = ?");
$stmtUpd->bind_param("ds", $newSaldo, $username);

if ($stmtUpd->execute()) {
    echo json_encode([
        "status"  => "success",
        "message" => "Kas Bank berhasil diperbarui.",
        "saldo"   => $newSaldo
    ]);
} else {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Gagal memperbarui Kas Bank: " . $stmtUpd->error]);
}

$stmtUpd->close();
$conn->close();
?>