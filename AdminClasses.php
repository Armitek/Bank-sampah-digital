<?php
date_default_timezone_set('Asia/Makassar');
require_once 'TransactionClasses.php';

/**
 * Abstraction untuk laporan berbasis klaster periode waktu.
 */
abstract class BaseReport {
    protected mysqli $conn;
    protected int $nowTimestamp;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
        $this->nowTimestamp = time();
    }

    protected function getPeriodRanges(): array {
        return [
            "3d"  => $this->nowTimestamp - (3 * 86400),
            "7d"  => $this->nowTimestamp - (7 * 86400),
            "30d" => $this->nowTimestamp - (30 * 86400),
            "all" => null
        ];
    }

    protected function parseTimestamp(?string $rawDate): ?int {
        if (empty($rawDate)) {
            return null;
        }
        $ts = strtotime((string)$rawDate);
        return ($ts !== false) ? $ts : null;
    }

    abstract public function generate(): array;
}

/**
 * Inheritance & Polymorphism: Laporan Overview Operasional Admin
 * Menghitung Bobot Sampah, Total Masukan Sampah, dan Kas Keluar per klaster periode.
 */
class AdminOverviewReport extends BaseReport {
    private bool $hasJumlahBarang;

    public function __construct(mysqli $conn) {
        parent::__construct($conn);
        $colCheck = $this->conn->query("SHOW COLUMNS FROM transaksi LIKE 'jumlah_barang'");
        $this->hasJumlahBarang = ($colCheck && $colCheck->num_rows > 0);
    }

    private function fetchDepositRows(): array {
        $sql = "SELECT t.*, k.nama_jenis, k.harga_per_kg
                FROM transaksi t
                LEFT JOIN kategori_sampah k ON t.kode_sampah = k.kode_sampah
                ORDER BY t.id DESC";

        $result = $this->conn->query($sql);
        if (!$result) {
            throw new Exception("Gagal mengambil data transaksi: " . $this->conn->error, 500);
        }

        $deposits = [];
        while ($row = $result->fetch_assoc()) {
            $totalHarga = floatval($row['total_harga'] ?? 0);
            $tipeTrx = strtoupper(trim($row['tipe_transaksi'] ?? ''));
            $kodeSampah = trim($row['kode_sampah'] ?? '-');
            $kodeUpper = strtoupper($kodeSampah);

            $isRedeem = ($tipeTrx === 'REDEEM') || (strpos($kodeUpper, 'RDM-') === 0) || ($totalHarga < 0);
            if ($isRedeem) {
                continue;
            }

            $waktuRaw = $row['tanggal_setor']
                ?? ($row['created_at']
                ?? ($row['tanggal']
                ?? ($row['waktu'] ?? null)));

            $deposits[] = [
                "ts"            => $this->parseTimestamp($waktuRaw),
                "bobot_kg"      => max(0.0, floatval($row['berat_kg'] ?? 0)),
                "jumlah_barang" => $this->hasJumlahBarang ? max(1, intval($row['jumlah_barang'] ?? 1)) : 1,
                "masukan_uang"  => max(0.0, $totalHarga),
                "kode_sampah"   => $kodeSampah,
                "nama_jenis"    => trim($row['nama_jenis'] ?? '') !== '' ? trim($row['nama_jenis']) : $kodeSampah
            ];
        }

        return $deposits;
    }

    private function fetchCashOutRows(): array {
        $cashOuts = [];
        $tblCheck = $this->conn->query("SHOW TABLES LIKE 'redeem_codes'");

        if ($tblCheck && $tblCheck->num_rows > 0) {
            $sql = "SELECT nominal, redeemed_at, created_at
                    FROM redeem_codes
                    WHERE UPPER(status) = 'NON_AKTIF'";
            $res = $this->conn->query($sql);
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $waktuRaw = $row['redeemed_at'] ?? ($row['created_at'] ?? null);
                    $cashOuts[] = [
                        "ts"      => $this->parseTimestamp($waktuRaw),
                        "nominal" => max(0.0, floatval($row['nominal'] ?? 0))
                    ];
                }
                return $cashOuts;
            }
        }

        $resTrx = $this->conn->query("SELECT total_harga, tanggal_setor FROM transaksi WHERE total_harga < 0 OR UPPER(tipe_transaksi) = 'REDEEM'");
        if ($resTrx) {
            while ($row = $resTrx->fetch_assoc()) {
                $cashOuts[] = [
                    "ts"      => $this->parseTimestamp($row['tanggal_setor'] ?? null),
                    "nominal" => abs(floatval($row['total_harga'] ?? 0))
                ];
            }
        }

        return $cashOuts;
    }

    public function generate(): array {
        $depositRows = $this->fetchDepositRows();
        $cashOutRows = $this->fetchCashOutRows();
        $ranges = $this->getPeriodRanges();
        $periods = [];

        foreach ($ranges as $key => $minTs) {
            $bobotSampah = 0.0;
            $totalPcs = 0;
            $totalMasukanSampah = 0.0;
            $jumlahSetoran = 0;
            $byJenis = [];

            foreach ($depositRows as $d) {
                if ($minTs !== null && ($d["ts"] === null || $d["ts"] < $minTs)) {
                    continue;
                }

                $bobotSampah += $d["bobot_kg"];
                $totalPcs += $d["jumlah_barang"];
                $totalMasukanSampah += $d["masukan_uang"];
                $jumlahSetoran++;

                $jenisKey = $d["kode_sampah"] !== '-' ? $d["kode_sampah"] : $d["nama_jenis"];
                if (!isset($byJenis[$jenisKey])) {
                    $byJenis[$jenisKey] = [
                        "kode_sampah"   => $d["kode_sampah"],
                        "nama_jenis"    => $d["nama_jenis"],
                        "jumlah_barang" => 0,
                        "bobot_kg"      => 0.0,
                        "total_uang"    => 0.0
                    ];
                }

                $byJenis[$jenisKey]["jumlah_barang"] += $d["jumlah_barang"];
                $byJenis[$jenisKey]["bobot_kg"]      += $d["bobot_kg"];
                $byJenis[$jenisKey]["total_uang"]    += $d["masukan_uang"];
            }

            $kasKeluar = 0.0;
            $jumlahCashout = 0;
            foreach ($cashOutRows as $c) {
                if ($minTs !== null && ($c["ts"] === null || $c["ts"] < $minTs)) {
                    continue;
                }
                if ($c["nominal"] > 0) {
                    $kasKeluar += $c["nominal"];
                    $jumlahCashout++;
                }
            }

            foreach ($byJenis as &$j) {
                $j["bobot_kg"] = round($j["bobot_kg"], 2);
                $j["total_uang"] = round($j["total_uang"], 2);
            }
            unset($j);

            $jenisList = array_values($byJenis);
            usort($jenisList, fn($a, $b) => $b["bobot_kg"] <=> $a["bobot_kg"]);

            $periods[$key] = [
                "bobot_sampah"         => round($bobotSampah, 2),
                "total_pcs"            => $totalPcs,
                "total_masukan_sampah" => round($totalMasukanSampah, 2),
                "kas_keluar"           => round($kasKeluar, 2),
                "jumlah_setoran"       => $jumlahSetoran,
                "jumlah_cashout"       => $jumlahCashout,
                "per_jenis"            => $jenisList
            ];
        }

        return $periods;
    }
}

/**
 * Encapsulation: Verifikasi & Pencairan Kode Redeem 12 Digit oleh Admin
 * Otomatis mengurangi saldo Kas Bank pada akun ADMIN saat pencairan berhasil.
 */
class AdminRedeemVerifier {
    private mysqli $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    private function normalizeCode(string $rawCode): string {
        $clean = strtoupper(preg_replace('/\s+/', '', trim($rawCode)));
        if ($clean === '') {
            throw new Exception("Kode redeem 12 digit wajib diisi.", 400);
        }
        return $clean;
    }

    private function findCodeRecord(string $kode, bool $forUpdate = false): ?array {
        $lockSql = $forUpdate ? " FOR UPDATE" : "";
        $sql = "SELECT r.id, r.kode_redeem, r.id_user, r.nominal, r.status, r.created_at, r.redeemed_at,
                       u.username, u.nama
                FROM redeem_codes r
                LEFT JOIN users u ON r.id_user = u.id_user
                WHERE r.kode_redeem = ?
                LIMIT 1{$lockSql}";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Gagal menyiapkan query kode redeem: " . $this->conn->error, 500);
        }

        $stmt->bind_param("s", $kode);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new Exception("Gagal memeriksa kode redeem: " . $err, 500);
        }

        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        return [
            "id"          => intval($row['id']),
            "kode_redeem" => (string)$row['kode_redeem'],
            "id_user"     => (string)$row['id_user'],
            "username"    => (string)($row['username'] ?? '-'),
            "nama_user"   => (string)($row['nama'] ?? 'Nasabah'),
            "nominal"     => intval($row['nominal'] ?? 0),
            "status"      => strtoupper((string)($row['status'] ?? 'AKTIF')),
            "created_at"  => $row['created_at'],
            "redeemed_at" => $row['redeemed_at']
        ];
    }

    private function deductAdminCash(float $nominal, ?string $adminUsername = null): ?float {
        $nominal = round($nominal, 2);
        if ($nominal <= 0) {
            return null;
        }

        $adminUsername = trim((string)$adminUsername);
        $rowAdm = null;

        if ($adminUsername !== '') {
            $stmtAdm = $this->conn->prepare(
                "SELECT id_user, username, saldo FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1 FOR UPDATE"
            );
            if ($stmtAdm) {
                $stmtAdm->bind_param("s", $adminUsername);
                if ($stmtAdm->execute()) {
                    $rowAdm = $stmtAdm->get_result()->fetch_assoc();
                }
                $stmtAdm->close();
            }
        }

        if (!$rowAdm) {
            $stmtAdmAny = $this->conn->prepare(
                "SELECT id_user, username, saldo FROM users WHERE LOWER(TRIM(role)) = 'admin' LIMIT 1 FOR UPDATE"
            );
            if (!$stmtAdmAny) {
                throw new Exception("Gagal menyiapkan query Kas Bank admin: " . $this->conn->error, 500);
            }
            if (!$stmtAdmAny->execute()) {
                $err = $stmtAdmAny->error;
                $stmtAdmAny->close();
                throw new Exception("Gagal mengambil saldo Kas Bank admin: " . $err, 500);
            }
            $rowAdm = $stmtAdmAny->get_result()->fetch_assoc();
            $stmtAdmAny->close();
        }

        if (!$rowAdm) {
            throw new Exception("Akun Admin (Kas Bank) tidak ditemukan.", 404);
        }

        $currentKas = floatval($rowAdm['saldo'] ?? 0);
        if ($currentKas < $nominal) {
            throw new Exception("Kas Bank tidak mencukupi untuk mencairkan kode redeem ini.", 400);
        }

        $nextKas = round($currentKas - $nominal, 2);
        $adminIdUser = (string)$rowAdm['id_user'];

        $stmtUpdKas = $this->conn->prepare("UPDATE users SET saldo = ? WHERE id_user = ?");
        if (!$stmtUpdKas) {
            throw new Exception("Gagal menyiapkan update Kas Bank: " . $this->conn->error, 500);
        }

        $stmtUpdKas->bind_param("ds", $nextKas, $adminIdUser);
        if (!$stmtUpdKas->execute()) {
            $err = $stmtUpdKas->error;
            $stmtUpdKas->close();
            throw new Exception("Gagal mengurangi Kas Bank: " . $err, 500);
        }
        $stmtUpdKas->close();

        return $nextKas;
    }

    public function checkCode(string $rawCode): array {
        $kode = $this->normalizeCode($rawCode);
        $detail = $this->findCodeRecord($kode, false);

        if (!$detail) {
            throw new Exception("Kode redeem tidak ditemukan.", 404);
        }

        return $detail;
    }

    public function redeemCode(string $rawCode, ?string $adminUsername = null): array {
        $kode = $this->normalizeCode($rawCode);
        $this->conn->begin_transaction();

        try {
            $detail = $this->findCodeRecord($kode, true);
            if (!$detail) {
                throw new Exception("Kode redeem tidak ditemukan.", 404);
            }

            if ($detail['status'] !== 'AKTIF') {
                throw new Exception("Kode ini sudah NON-AKTIF / sudah pernah dicairkan.", 400);
            }

            $sisaKasBank = $this->deductAdminCash(floatval($detail['nominal']), $adminUsername);

            $nowStr = date('Y-m-d H:i:s');
            $statusNonAktif = 'NON_AKTIF';

            $stmtUpd = $this->conn->prepare(
                "UPDATE redeem_codes SET status = ?, redeemed_at = ? WHERE id = ? AND status = 'AKTIF'"
            );
            if (!$stmtUpd) {
                throw new Exception("Gagal menyiapkan update kode redeem: " . $this->conn->error, 500);
            }

            $stmtUpd->bind_param("ssi", $statusNonAktif, $nowStr, $detail['id']);
            if (!$stmtUpd->execute()) {
                $err = $stmtUpd->error;
                $stmtUpd->close();
                throw new Exception("Gagal mencairkan kode redeem: " . $err, 500);
            }

            $affected = $stmtUpd->affected_rows;
            $stmtUpd->close();

            if ($affected <= 0) {
                throw new Exception("Status kode sudah berubah sebelumnya.", 409);
            }

            $this->conn->commit();

            $detail['status'] = $statusNonAktif;
            $detail['redeemed_at'] = $nowStr;
            if ($sisaKasBank !== null) {
                $detail['kas_bank_sisa'] = $sisaKasBank;
            }
            return $detail;
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }
}

/**
 * Encapsulation: Pemrosesan Transfer Saldo / Setoran Sampah oleh Admin
 */
class AdminDepositProcessor {
    private mysqli $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    private function generateUniqueTransactionCode(): string {
        $pool = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $maxIdx = strlen($pool) - 1;

        do {
            $kode = '';
            for ($i = 0; $i < 4; $i++) {
                $kode .= $pool[random_int(0, $maxIdx)];
            }
            $stmt = $this->conn->prepare("SELECT id FROM transaksi WHERE kode_transaksi = ? LIMIT 1");
            $stmt->bind_param("s", $kode);
            $stmt->execute();
            $stmt->store_result();
            $exists = $stmt->num_rows > 0;
            $stmt->close();
        } while ($exists);

        return $kode;
    }

    public function processDeposit(string $idUser, array $items): array {
        $idUser = trim($idUser);
        if ($idUser === '' || count($items) === 0) {
            throw new Exception("ID Anggota dan minimal 1 item sampah wajib diisi.", 400);
        }

        $this->conn->begin_transaction();

        try {
            $stmtUser = $this->conn->prepare("SELECT id_user, nama, saldo FROM users WHERE id_user = ? FOR UPDATE");
            $stmtUser->bind_param("s", $idUser);
            $stmtUser->execute();
            $resUser = $stmtUser->get_result();

            if (!($rowUser = $resUser->fetch_assoc())) {
                $stmtUser->close();
                throw new Exception("ID Anggota tidak ditemukan.", 404);
            }
            $stmtUser->close();

            $currentSaldo = floatval($rowUser['saldo'] ?? 0);
            $kodeTransaksi = $this->generateUniqueTransactionCode();
            $nowStr = date('Y-m-d H:i:s');
            $totalTambahSaldo = 0.0;

            $stmtKat = $this->conn->prepare("SELECT harga_per_kg FROM kategori_sampah WHERE kode_sampah = ? LIMIT 1");

            foreach ($items as $item) {
                $kodeSampah = trim($item['kode_sampah'] ?? '');
                $jumlahBarang = intval($item['jumlah_barang'] ?? 1);
                $beratKg = floatval($item['berat_kg'] ?? 0);

                if ($kodeSampah === '' || $beratKg <= 0) {
                    throw new Exception("Kode sampah dan berat (> 0 Kg) wajib valid.", 400);
                }

                $stmtKat->bind_param("s", $kodeSampah);
                $stmtKat->execute();
                $resKat = $stmtKat->get_result();
                if (!($rowKat = $resKat->fetch_assoc())) {
                    throw new Exception("Kategori sampah '$kodeSampah' tidak ditemukan.", 404);
                }
                $hargaPerKg = floatval($rowKat['harga_per_kg']);

                $depositTrx = new DepositTransaction(
                    $this->conn,
                    $idUser,
                    $kodeTransaksi,
                    $kodeSampah,
                    $jumlahBarang,
                    $beratKg,
                    $hargaPerKg,
                    $nowStr
                );

                $totalTambahSaldo += $depositTrx->getNominalPerubahanSaldo();
                $depositTrx->simpanKeRiwayat();
            }
            $stmtKat->close();

            $newSaldo = round($currentSaldo + $totalTambahSaldo, 2);
            $stmtUpd = $this->conn->prepare("UPDATE users SET saldo = ? WHERE id_user = ?");
            $stmtUpd->bind_param("ds", $newSaldo, $idUser);
            if (!$stmtUpd->execute()) {
                $stmtUpd->close();
                throw new Exception("Gagal menambahkan saldo nasabah.", 500);
            }
            $stmtUpd->close();

            $this->conn->commit();

            return [
                "kode_transaksi"   => $kodeTransaksi,
                "id_user"          => $idUser,
                "nama_nasabah"     => $rowUser['nama'] ?? 'Nasabah',
                "total_pendapatan" => round($totalTambahSaldo, 2),
                "saldo_baru"       => $newSaldo
            ];
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }
}
?>