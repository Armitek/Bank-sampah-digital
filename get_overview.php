<?php
date_default_timezone_set('Asia/Makassar'); // Samakan dengan zona waktu di get_riwayat.php
require_once 'Database.php';

$dbObj = new Database();
$conn = $dbObj->conn;

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

$id_user = trim($_GET['id_user'] ?? '');

if (empty($id_user)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "ID Anggota tidak valid"]);
    exit();
}

$hasJumlahBarang = false;
$colCheck = $conn->query("SHOW COLUMNS FROM transaksi LIKE 'jumlah_barang'");
if ($colCheck && $colCheck->num_rows > 0) {
    $hasJumlahBarang = true;
}

$sql = "SELECT t.*, k.nama_jenis, k.harga_per_kg
        FROM transaksi t
        LEFT JOIN kategori_sampah k ON t.kode_sampah = k.kode_sampah
        WHERE t.id_user = ?
        ORDER BY t.id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $id_user);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
$now = time();

while ($row = $result->fetch_assoc()) {
    $waktuRaw = $row['tanggal_setor']
        ?? ($row['created_at']
        ?? ($row['tanggal']
        ?? ($row['waktu'] ?? null)));

    $ts = null;
    if (!empty($waktuRaw)) {
        $parsed = strtotime((string)$waktuRaw);
        if ($parsed !== false) {
            $ts = $parsed;
        }
    }

    $berat = floatval($row['berat_kg'] ?? 0);
    $jumlahBarang = $hasJumlahBarang ? max(1, intval($row['jumlah_barang'] ?? 1)) : 1;
    $totalHarga = floatval($row['total_harga'] ?? 0);
    $kodeSampah = trim($row['kode_sampah'] ?? '-');
    $namaJenis = trim($row['nama_jenis'] ?? '') !== '' ? trim($row['nama_jenis']) : $kodeSampah;

    $rows[] = [
        "ts"            => $ts,
        "berat_kg"      => $berat,
        "jumlah_barang" => $jumlahBarang,
        "total_harga"   => $totalHarga,
        "kode_sampah"   => $kodeSampah,
        "nama_jenis"    => $namaJenis
    ];
}
$stmt->close();

$ranges = [
    "3d"  => $now - (3 * 86400),
    "7d"  => $now - (7 * 86400),
    "30d" => $now - (30 * 86400),
    "all" => null
];

$periods = [];

foreach ($ranges as $key => $minTs) {
    $totalBerat = 0;
    $totalPcs = 0;
    $totalUang = 0;
    $totalTransaksiBaris = 0;
    $byJenis = [];

    foreach ($rows as $r) {
        if ($minTs !== null) {
            if ($r["ts"] === null || $r["ts"] < $minTs) {
                continue;
            }
        }

        $totalBerat += $r["berat_kg"];
        $totalPcs += $r["jumlah_barang"];
        $totalUang += $r["total_harga"];
        $totalTransaksiBaris++;

        $jenisKey = $r["kode_sampah"] !== '-' ? $r["kode_sampah"] : $r["nama_jenis"];
        if (!isset($byJenis[$jenisKey])) {
            $byJenis[$jenisKey] = [
                "kode_sampah"   => $r["kode_sampah"],
                "nama_jenis"    => $r["nama_jenis"],
                "jumlah_barang" => 0,
                "berat_kg"      => 0,
                "total_harga"   => 0
            ];
        }

        $byJenis[$jenisKey]["jumlah_barang"] += $r["jumlah_barang"];
        $byJenis[$jenisKey]["berat_kg"]      += $r["berat_kg"];
        $byJenis[$jenisKey]["total_harga"]   += $r["total_harga"];
    }

    $jenisList = array_values($byJenis);
    usort($jenisList, function ($a, $b) {
        return $b["jumlah_barang"] <=> $a["jumlah_barang"];
    });

    $periods[$key] = [
        "total_berat"   => round($totalBerat, 2),
        "total_pcs"     => $totalPcs,
        "total_uang"    => round($totalUang, 2),
        "total_item"    => $totalTransaksiBaris,
        "per_jenis"     => $jenisList
    ];
}

echo json_encode([
    "status"  => "success",
    "id_user" => $id_user,
    "periods" => $periods
]);
?>