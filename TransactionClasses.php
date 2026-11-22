<?php
date_default_timezone_set('Asia/Makassar');

abstract class BaseTransaction {
    protected mysqli $conn;
    protected string $idUser;
    protected string $kodeTransaksi;
    protected string $tanggal;

    public function __construct(mysqli $conn, string $idUser, string $kodeTransaksi, string $tanggal) {
        $this->conn = $conn;
        $this->idUser = $idUser;
        $this->kodeTransaksi = $kodeTransaksi;
        $this->tanggal = $tanggal;
    }

    abstract public function getTipeTransaksi(): string;
    abstract public function getNominalPerubahanSaldo(): float;
    abstract protected function getKodeSampah(): string;
    abstract protected function getJumlahBarang(): int;
    abstract protected function getBeratKg(): float;
    abstract protected function getKeterangan(): string;

    protected function ensureKategori(string $kodeSampah, string $namaJenis, float $hargaPerKg): void {
        $stmtCheck = $this->conn->prepare("SELECT kode_sampah FROM kategori_sampah WHERE kode_sampah = ? LIMIT 1");
        $stmtCheck->bind_param("s", $kodeSampah);
        $stmtCheck->execute();
        $stmtCheck->store_result();
        $exists = $stmtCheck->num_rows > 0;
        $stmtCheck->close();

        if (!$exists) {
            $stmtIns = $this->conn->prepare(
                "INSERT INTO kategori_sampah (kode_sampah, nama_jenis, harga_per_kg) VALUES (?, ?, ?)"
            );
            $stmtIns->bind_param("ssd", $kodeSampah, $namaJenis, $hargaPerKg);
            if (!$stmtIns->execute()) {
                throw new Exception("Gagal menyiapkan kategori di kategori_sampah: " . $stmtIns->error);
            }
            $stmtIns->close();
        }
    }

    public function simpanKeRiwayat(): void {
        $kodeSampah = $this->getKodeSampah();
        $jumlahBarang = $this->getJumlahBarang();
        $beratKg = $this->getBeratKg();
        $totalHarga = $this->getNominalPerubahanSaldo();
        $tipe = $this->getTipeTransaksi();
        $keterangan = $this->getKeterangan();

        $hasTipe = $this->conn->query("SHOW COLUMNS FROM transaksi LIKE 'tipe_transaksi'")->num_rows > 0;
        $hasKet = $this->conn->query("SHOW COLUMNS FROM transaksi LIKE 'keterangan'")->num_rows > 0;
        $hasJumlah = $this->conn->query("SHOW COLUMNS FROM transaksi LIKE 'jumlah_barang'")->num_rows > 0;

        if ($hasTipe && $hasKet && $hasJumlah) {
            $stmt = $this->conn->prepare(
                "INSERT INTO transaksi (kode_transaksi, id_user, kode_sampah, jumlah_barang, berat_kg, total_harga, tanggal_setor, tipe_transaksi, keterangan)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                "sssiddsss",
                $this->kodeTransaksi,
                $this->idUser,
                $kodeSampah,
                $jumlahBarang,
                $beratKg,
                $totalHarga,
                $this->tanggal,
                $tipe,
                $keterangan
            );
        } elseif ($hasJumlah) {
            $stmt = $this->conn->prepare(
                "INSERT INTO transaksi (kode_transaksi, id_user, kode_sampah, jumlah_barang, berat_kg, total_harga, tanggal_setor)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                "sssidds",
                $this->kodeTransaksi,
                $this->idUser,
                $kodeSampah,
                $jumlahBarang,
                $beratKg,
                $totalHarga,
                $this->tanggal
            );
        } else {
            $stmt = $this->conn->prepare(
                "INSERT INTO transaksi (kode_transaksi, id_user, kode_sampah, berat_kg, total_harga, tanggal_setor)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                "sssdds",
                $this->kodeTransaksi,
                $this->idUser,
                $kodeSampah,
                $beratKg,
                $totalHarga,
                $this->tanggal
            );
        }

        if (!$stmt->execute()) {
            throw new Exception("Gagal mencatat transaksi ke riwayat: " . $stmt->error);
        }
        $stmt->close();
    }

    protected static function fetchTransactionRowsByUser(mysqli $conn, string $idUser): array {
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

        if (!$stmt) {
            throw new Exception("Gagal menyiapkan query riwayat transaksi: " . $conn->error, 500);
        }

        $stmt->bind_param("s", $idUser);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new Exception("Gagal mengambil riwayat transaksi: " . $err, 500);
        }

        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    public static function getGroupedHistoryByUser(mysqli $conn, string $idUser): array {
        $rows = self::fetchTransactionRowsByUser($conn, $idUser);
        $groups = [];

        foreach ($rows as $row) {
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

        return $riwayatFinal;
    }

    public static function getDepositOverviewPeriods(mysqli $conn, string $idUser): array {
        $rows = self::fetchTransactionRowsByUser($conn, $idUser);
        $now = time();
        $parsedRows = [];

        foreach ($rows as $row) {
            $totalHarga = floatval($row['total_harga'] ?? 0);
            $tipeTrx = strtoupper(trim($row['tipe_transaksi'] ?? ''));
            $kodeSampah = trim($row['kode_sampah'] ?? '-');
            $kodeUpper = strtoupper($kodeSampah);

            $isRedeemExpense = ($tipeTrx === 'REDEEM') || (strpos($kodeUpper, 'RDM-') === 0) || ($totalHarga < 0);
            if ($isRedeemExpense) {
                continue;
            }

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
            $jumlahBarang = max(1, intval($row['jumlah_barang'] ?? 1));
            $namaJenis = trim($row['nama_jenis'] ?? '') !== '' ? trim($row['nama_jenis']) : $kodeSampah;

            $parsedRows[] = [
                "ts"            => $ts,
                "berat_kg"      => $berat,
                "jumlah_barang" => $jumlahBarang,
                "total_harga"   => $totalHarga,
                "kode_sampah"   => $kodeSampah,
                "nama_jenis"    => $namaJenis
            ];
        }

        $ranges = [
            "3d"  => $now - (3 * 86400),
            "7d"  => $now - (7 * 86400),
            "30d" => $now - (30 * 86400),
            "all" => null
        ];

        $periods = [];
        foreach ($ranges as $key => $minTs) {
            $totalBerat = 0.0;
            $totalPcs = 0;
            $totalUang = 0.0;
            $totalTransaksiBaris = 0;
            $byJenis = [];

            foreach ($parsedRows as $r) {
                if ($minTs !== null && ($r["ts"] === null || $r["ts"] < $minTs)) {
                    continue;
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
                "total_berat" => round($totalBerat, 2),
                "total_pcs"   => $totalPcs,
                "total_uang"  => round($totalUang, 2),
                "total_item"  => $totalTransaksiBaris,
                "per_jenis"   => $jenisList
            ];
        }

        return $periods;
    }
}

class RedeemTransaction extends BaseTransaction {
    private int $nominalCashout;
    private string $tagNominal;

    public function __construct(
        mysqli $conn,
        string $idUser,
        string $kodeRedeem,
        int $nominalCashout,
        string $tagNominal,
        string $tanggal
    ) {
        parent::__construct($conn, $idUser, $kodeRedeem, $tanggal);
        $this->nominalCashout = $nominalCashout;
        $this->tagNominal = $tagNominal;
    }

    public function siapkanKategori(): void {
        $this->ensureKategori('RDM-CASH', 'Redeem Voucher Cashout', 0.0);
    }

    public function getTipeTransaksi(): string {
        return 'REDEEM';
    }

    public function getNominalPerubahanSaldo(): float {
        return -1.0 * floatval($this->nominalCashout);
    }

    protected function getKodeSampah(): string {
        return 'RDM-CASH';
    }

    protected function getJumlahBarang(): int {
        return 1;
    }

    protected function getBeratKg(): float {
        return 0.0;
    }

    protected function getKeterangan(): string {
        return "Redeem Voucher Cashout ({$this->tagNominal}) {$this->kodeTransaksi}";
    }
}

class DepositTransaction extends BaseTransaction {
    private string $kodeSampah;
    private int $jumlahBarang;
    private float $beratKg;
    private float $hargaPerKg;

    public function __construct(
        mysqli $conn,
        string $idUser,
        string $kodeTransaksi,
        string $kodeSampah,
        int $jumlahBarang,
        float $beratKg,
        float $hargaPerKg,
        string $tanggal
    ) {
        parent::__construct($conn, $idUser, $kodeTransaksi, $tanggal);
        $this->kodeSampah = $kodeSampah;
        $this->jumlahBarang = max(1, $jumlahBarang);
        $this->beratKg = max(0.0, $beratKg);
        $this->hargaPerKg = max(0.0, $hargaPerKg);
    }

    public function getTipeTransaksi(): string {
        return 'DEPOSIT';
    }

    public function getNominalPerubahanSaldo(): float {
        return round($this->beratKg * $this->hargaPerKg, 2);
    }

    protected function getKodeSampah(): string {
        return $this->kodeSampah;
    }

    protected function getJumlahBarang(): int {
        return $this->jumlahBarang;
    }

    protected function getBeratKg(): float {
        return $this->beratKg;
    }

    protected function getKeterangan(): string {
        return "Setoran Sampah {$this->kodeSampah} ({$this->beratKg} Kg)";
    }
}
