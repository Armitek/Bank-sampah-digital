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

// Ambil data JSON yang dikirim dari form login
$rawInput = file_get_contents("php://input");
$inputData = json_decode($rawInput, true);

$username = trim($inputData['username'] ?? '');
$password = $inputData['password'] ?? '';

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Username dan password wajib diisi!"]);
    exit();
}

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Gagal koneksi database"]);
    exit();
}

// Ambil data nama dan role berdasarkan username & password
$stmt = $conn->prepare("SELECT nama, role FROM users WHERE username = ? AND password = ? LIMIT 1");
$stmt->bind_param("ss", $username, $password);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Halo selamat datang, " . $row['nama'],
        "role" => $row['role'] // Mengirim role (ADMIN / NASABAH) ke frontend
    ]);
} else {
    http_response_code(401);
    echo json_encode([
        "status" => "error",
        "message" => "Akun belum terdaftar atau password salah !"
    ]);
}

$stmt->close();
$conn->close();
?>