<?php
require_once 'Database.php';

// Instansiasi Objek OOP
$dbObj = new Database();
$conn = $dbObj->conn;

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

$username = $_GET['username'] ?? '';

if (empty($username)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Username tidak valid"]);
    exit();
}

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Gagal koneksi database"]);
    exit();
}

$stmt = $conn->prepare("SELECT id_user, username, nama, role, saldo FROM users WHERE username = ? LIMIT 1");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo json_encode([
        "status" => "success",
        "user" => $row
    ]);
} else {
    http_response_code(404);
    echo json_encode([
        "status" => "error",
        "message" => "User tidak ditemukan"
    ]);
}

$stmt->close();
$conn->close();
?>