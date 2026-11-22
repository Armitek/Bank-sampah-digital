<?php
date_default_timezone_set('Asia/Makassar');
require_once 'Database.php';

$dbObj = new Database();
$conn = $dbObj->conn;

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$id_user = trim($_GET['id_user'] ?? '');

if (empty($id_user)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "ID Anggota tidak valid"]);
    exit();
}

$sql = "SELECT t.*, k.nama_jenis, k.harga_per_kg
        FROM transaksi t
        LEFT JOIN kategori_sampah k ON t.kode_sampah = k.kode_sampah
        WHERE t.id_user = ?
        ORDER BY t.id DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    $sql = "SELECT t.*, k.nama_jenis, k.harga_per_kg
            FROM transaksi t
            LEFT JOIN kategori_sampah k ON t.kode_sampah = k.kode_sampah
            WHERE t.id_user = ?";
    $stmt = $conn->prepare($sql);
}

$stmt->bind_param("s", $id_user);
$stmt->execute();
$result = $stmt->get_result();

$groups = [];

while ($row = $result->fetch_assoc()) {
    $kodeTrx = trim($row['kode_transaksi'] ?? '');
    $groupKey = $kodeTrx !== '' ? strtoupper($kodeTrx) : ('ROW_' . $row['id']);
    $kodeTampil = $kodeTrx !== '' ? strtoupper($kodeTrx) : ('TRX' . str_pad((string)$row['id'], 2, '0', STR_PAD_LEFT));

    $waktuRaw = $row['tanggal_setor']
        ?? ($row['created_at']
        ?? ($row['tanggal']
        ?? ($row['waktu'] ?? null)));

    $ts = !empty($waktuRaw) ? strtotime((string)$waktuRaw) : false;
    $tsValid = ($ts !== false) ? $ts : null;

    $totalHarga = floatval($row['total_harga'] ?? 0);
    $tipeTrx = strtoupper(trim($row['tipe_transaksi'] ?? ''));
    $kodeSampahRaw = strtoupper(trim($row['kode_sampah'] ?? '-'));
    $isRedeemExpense = ($tipeTrx === 'REDEEM') || (strpos($kodeSampahRaw, 'RDM-') === 0) || ($totalHarga < 0);

    $berat = $isRedeemExpense ? 0.0 : floatval($row['berat_kg'] ?? 0);
    $jumlahBarang = $isRedeemExpense ? 0 : max(1, intval($row['jumlah_barang'] ?? 1));
    $namaJenis = $isRedeemExpense
        ? (!empty($row['keterangan']) ? $row['keterangan'] : ('Redeem Cashout (' . $kodeTampil . ')'))
        : ($row['nama_jenis'] ?? ($row['kode_sampah'] ?? '-'));
    $hargaPerKg = (!$isRedeemExpense && $berat > 0) ? floatval($row['harga_per_kg'] ?? ($totalHarga / $berat)) : 0;

    if (!isset($groups[$groupKey])) {
        $groups[$groupKey] = [
            "kode_sesi"      => $groupKey,
            "kode_transaksi" => $kodeTampil,
            "waktu"          => $waktuRaw,
            "_min_ts"        => $tsValid,
            "is_expense"     => $isRedeemExpense,
            "total_berat"    => 0,
            "total_barang"   => 0,
            "total_uang"     => 0,
            "items_map"      => []
        ];
    } else {
        if ($tsValid !== null && ($groups[$groupKey]["_min_ts"] === null || $tsValid < $groups[$groupKey]["_min_ts"])) {
            $groups[$groupKey]["_min_ts"] = $tsValid;
            $groups[$groupKey]["waktu"] = $waktuRaw;
        } elseif (empty($groups[$groupKey]["waktu"]) && !empty($waktuRaw)) {
            $groups[$groupKey]["waktu"] = $waktuRaw;
        }
        if ($isRedeemExpense) {
            $groups[$groupKey]["is_expense"] = true;
        }
    }

    $groups[$groupKey]["total_berat"]  += $berat;
    $groups[$groupKey]["total_barang"] += $jumlahBarang;
    $groups[$groupKey]["total_uang"]   += $totalHarga;

    $itemKey = $kodeSampahRaw !== '-' ? $kodeSampahRaw : $namaJenis;
    if (!isset($groups[$groupKey]["items_map"][$itemKey])) {
        $groups[$groupKey]["items_map"][$itemKey] = [
            "kode_sampah"   => $row['kode_sampah'] ?? '-',
            "nama_jenis"    => $namaJenis,
            "jumlah_barang" => $jumlahBarang,
            "berat_kg"      => $berat,
            "harga_per_kg"  => $hargaPerKg,
            "total_harga"   => $totalHarga,
            "is_expense"    => $isRedeemExpense,
            "waktu"         => $waktuRaw
        ];
    } else {
        $groups[$groupKey]["items_map"][$itemKey]["jumlah_barang"] += $jumlahBarang;
        $groups[$groupKey]["items_map"][$itemKey]["berat_kg"]      += $berat;
        $groups[$groupKey]["items_map"][$itemKey]["total_harga"]   += $totalHarga;
    }
}

$stmt->close();

$riwayatFinal = [];
foreach ($groups as $g) {
    $riwayatFinal[] = [
        "kode_sesi"      => $g["kode_sesi"],
        "kode_transaksi" => $g["kode_transaksi"],
        "waktu"          => $g["waktu"],
        "is_expense"     => $g["is_expense"] || ($g["total_uang"] < 0),
        "total_berat"    => $g["total_berat"],
        "total_barang"   => $g["total_barang"],
        "total_uang"     => $g["total_uang"],
        "items"          => array_values($g["items_map"])
    ];
}

echo json_encode([
    "status"  => "success",
    "riwayat" => $riwayatFinal
]);
?>