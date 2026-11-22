<?php
require_once 'Database.php';

// Instansiasi Objek OOP
$dbObj = new Database();
$conn = $dbObj->conn;

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Koneksi database gagal"]);
    exit();
}

$result = $conn->query("SELECT kode_sampah, nama_jenis, harga_per_kg FROM kategori_sampah");
$kategori = [];

while ($row = $result->fetch_assoc()) {
    $kategori[] = $row;
}

echo json_encode([
    "status" => "success",
    "kategori" => $kategori
]);

$conn->close();
?>