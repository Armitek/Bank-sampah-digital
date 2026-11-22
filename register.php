<?php
require_once 'Database.php';

// Instansiasi Objek OOP
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

$rawInput = file_get_contents("php://input");
$inputData = json_decode($rawInput, true);

$nama = trim($inputData['nama'] ?? '');
$username = trim($inputData['username'] ?? ''); 
$password = $inputData['password'] ?? '';

if (empty($nama) || empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Semua kolom wajib diisi!"]);
    exit();
}

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Gagal koneksi database"]);
    exit();
}

// 1. Cek apakah username sudah terdaftar
$stmtCheck = $conn->prepare("SELECT username FROM users WHERE username = ? LIMIT 1");
$stmtCheck->bind_param("s", $username);
$stmtCheck->execute();
$stmtCheck->store_result();

if ($stmtCheck->num_rows > 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Username tersebut sudah terdaftar! Gunakan yang lain."]);
    $stmtCheck->close();
    $conn->close();
    exit();
}
$stmtCheck->close();

// 2. Generate ID User pendek (5 angka acak, misal: 49823) dan pastikan unik
do {
    $id_user = strval(rand(10000, 99999));
    $stmtIdCheck = $conn->prepare("SELECT id_user FROM users WHERE id_user = ? LIMIT 1");
    $stmtIdCheck->bind_param("s", $id_user);
    $stmtIdCheck->execute();
    $stmtIdCheck->store_result();
    $exists = $stmtIdCheck->num_rows > 0;
    $stmtIdCheck->close();
} while ($exists);

$role = "NASABAH";
$saldo = 0;

$stmtInsert = $conn->prepare("INSERT INTO users (id_user, username, password, nama, role, saldo) VALUES (?, ?, ?, ?, ?, ?)");
if (!$stmtInsert) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Prepare failed: " . $conn->error]);
    $conn->close();
    exit();
}

$stmtInsert->bind_param("sssssd", $id_user, $username, $password, $nama, $role, $saldo);

if ($stmtInsert->execute()) {
    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Registrasi berhasil! ID Anggota Anda: " . $id_user
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Gagal menyimpan ke database: " . $stmtInsert->error
    ]);
}

$stmtInsert->close();
$conn->close();
?>