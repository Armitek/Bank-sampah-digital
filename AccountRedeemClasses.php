<?php
date_default_timezone_set('Asia/Makassar');

class NasabahAccount {
    private $conn;
    private $idUser;
    private $username;
    private $nama;
    private $saldo;

    private function __construct(mysqli $conn, array $row) {
        $this->conn = $conn;
        $this->idUser = (string)$row['id_user'];
        $this->username = (string)$row['username'];
        $this->nama = (string)($row['nama'] ?? $row['username']);
        $this->saldo = floatval($row['saldo'] ?? 0);
    }

    public static function loadByCredentials(mysqli $conn, string $username, string $requestedIdUser = '', bool $forUpdate = false): self {
        if ($username === '' && $requestedIdUser === '') {
            throw new Exception("Sesi login tidak ditemukan.", 401);
        }

        $lockSql = $forUpdate ? " FOR UPDATE" : "";

        if ($username !== '') {
            $stmt = $conn->prepare("SELECT id_user, username, nama, saldo FROM users WHERE username = ? LIMIT 1{$lockSql}");
            if (!$stmt) {
                throw new Exception("Gagal menyiapkan query pengguna: " . $conn->error, 500);
            }
            $stmt->bind_param("s", $username);
        } else {
            $stmt = $conn->prepare("SELECT id_user, username, nama, saldo FROM users WHERE id_user = ? LIMIT 1{$lockSql}");
            if (!$stmt) {
                throw new Exception("Gagal menyiapkan query pengguna: " . $conn->error, 500);
            }
            $stmt->bind_param("s", $requestedIdUser);
        }

        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new Exception("Gagal mengambil data pengguna: " . $err, 500);
        }

        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            throw new Exception("Akun tidak ditemukan.", 404);
        }

        $account = new self($conn, $row);

        if ($requestedIdUser !== '' && $requestedIdUser !== $account->getIdUser()) {
            throw new Exception("Akses ditolak: ID Anggota tidak sesuai dengan akun yang login.", 403);
        }

        return $account;
    }

    public function getIdUser(): string {
        return $this->idUser;
    }

    public function getUsername(): string {
        return $this->username;
    }

    public function getNama(): string {
        return $this->nama;
    }

    public function getSaldo(): float {
        return $this->saldo;
    }

    public function hasEnoughSaldo(float $nominal): bool {
        return $this->saldo >= $nominal;
    }

    public function applySaldoDelta(float $delta): float {
        $nextSaldo = round($this->saldo + $delta, 2);
        if ($nextSaldo < 0) {
            throw new Exception("Saldo tidak mencukupi untuk transaksi ini.", 400);
        }

        $stmt = $this->conn->prepare("UPDATE users SET saldo = ? WHERE id_user = ?");
        if (!$stmt) {
            throw new Exception("Gagal menyiapkan update saldo anggota: " . $this->conn->error, 500);
        }
        $stmt->bind_param("ds", $nextSaldo, $this->idUser);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new Exception("Gagal memperbarui saldo anggota: " . $err, 500);
        }
        $stmt->close();

        $this->saldo = $nextSaldo;
        return $this->saldo;
    }

    public function toArray(): array {
        return [
            "id_user"  => $this->idUser,
            "username" => $this->username,
            "nama"     => $this->nama,
            "saldo"    => $this->saldo
        ];
    }
}

class RedeemCodeManager {
    private $conn;
    private const ALLOWED_NOMINALS = [5000, 10000, 20000, 50000, 100000, 250000, 500000, 1000000];
    private const NOMINAL_TAGS = [
        5000    => '005K',
        10000   => '010K',
        20000   => '020K',
        50000   => '050K',
        100000  => '100K',
        250000  => '250K',
        500000  => '500K',
        1000000 => '001M',
    ];

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    public function isValidNominal(int $nominal): bool {
        return in_array($nominal, self::ALLOWED_NOMINALS, true);
    }

    public function getNominalTag(int $nominal): string {
        return self::NOMINAL_TAGS[$nominal] ?? 'VCH0';
    }

    private function generateUniqueCode12(): string {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $maxIdx = strlen($chars) - 1;

        do {
            $kode = '';
            for ($i = 0; $i < 12; $i++) {
                $kode .= $chars[random_int(0, $maxIdx)];
            }

            $stmt = $this->conn->prepare("SELECT id FROM redeem_codes WHERE kode_redeem = ? LIMIT 1");
            $stmt->bind_param("s", $kode);
            $stmt->execute();
            $stmt->store_result();
            $exists = $stmt->num_rows > 0;
            $stmt->close();
        } while ($exists);

        return $kode;
    }

    public function issueCode(string $idUser, int $nominal, string $tanggal): array {
        if (!$this->isValidNominal($nominal)) {
            throw new Exception("Nominal voucher tidak valid.", 400);
        }

        $kodeRedeem = $this->generateUniqueCode12();
        $statusAktif = 'AKTIF';

        $stmt = $this->conn->prepare(
            "INSERT INTO redeem_codes (kode_redeem, id_user, nominal, status, created_at) VALUES (?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            throw new Exception("Gagal menyiapkan simpan kode redeem: " . $this->conn->error, 500);
        }
        $stmt->bind_param("ssiss", $kodeRedeem, $idUser, $nominal, $statusAktif, $tanggal);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new Exception("Gagal menyimpan kode redeem: " . $err, 500);
        }
        $stmt->close();

        return [
            "kode_redeem" => $kodeRedeem,
            "nominal"     => $nominal,
            "status"      => $statusAktif,
            "created_at"  => $tanggal
        ];
    }

    public function getCodesGroupedByStatus(string $idUser): array {
        $aktif = [];
        $nonAktif = [];

        $tblCheck = $this->conn->query("SHOW TABLES LIKE 'redeem_codes'");
        if ($tblCheck && $tblCheck->num_rows > 0) {
            $stmt = $this->conn->prepare(
                "SELECT id, kode_redeem, nominal, status, created_at, redeemed_at
                 FROM redeem_codes
                 WHERE id_user = ?
                 ORDER BY id DESC"
            );
            if (!$stmt) {
                throw new Exception("Gagal menyiapkan query kode redeem: " . $this->conn->error, 500);
            }
            $stmt->bind_param("s", $idUser);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new Exception("Gagal mengambil daftar kode redeem: " . $err, 500);
            }
            $res = $stmt->get_result();

            while ($res && ($row = $res->fetch_assoc())) {
                $item = [
                    "id"          => intval($row['id']),
                    "kode_redeem" => $row['kode_redeem'],
                    "nominal"     => intval($row['nominal']),
                    "status"      => strtoupper($row['status'] ?? 'AKTIF'),
                    "created_at"  => $row['created_at'],
                    "redeemed_at" => $row['redeemed_at']
                ];
                if ($item["status"] === 'NON_AKTIF') {
                    $nonAktif[] = $item;
                } else {
                    $aktif[] = $item;
                }
            }
            $stmt->close();
        }

        return [
            "aktif"     => $aktif,
            "non_aktif" => $nonAktif
        ];
    }
}
?>