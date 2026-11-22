[bank_sampah_digital_readme.md](https://github.com/user-attachments/files/35230733/bank_sampah_digital_readme.md)
# Bank Sampah Digital

Aplikasi web **Bank Sampah Digital** untuk mendigitalisasi pencatatan setoran sampah, pengelolaan saldo nasabah, pembuatan serta pencairan kode redeem, dan rekap laporan operasional admin per klaster periode.

## Ringkasan Fitur

- **Autentikasi & Manajemen Sesi Peran (`ADMIN` & Nasabah):** Login berbasis peran dengan penjagaan akses halaman serta sinkronisasi sesi lintas tab.
- **Pencatatan Setoran & Saldo Nasabah:** Memproses transaksi setoran sampah berdasarkan jenis sampah, bobot/jumlah, dan nilai setoran yang terhubung ke saldo serta riwayat transaksi nasabah.
- **Sistem Kode Redeem & Kas Keluar:** Nasabah dapat menukarkan saldo menjadi kode redeem, sementara admin dapat memverifikasi/mencairkan kode redeem dan memantau kas keluar.
- **Overview Operasional Admin per Klaster:** Menyaring ringkasan metrik dan rincian per jenis sampah berdasarkan periode (`3 Hari`, `7 Hari`, `Sebulan (30 Hari)`, dan `Lifetime`).
- **Ekspor Laporan Tabel Excel (`.xls`) per Klaster:** Mengunduh laporan tabel `.xls` sesuai klaster aktif yang dilengkapi ringkasan indikator, rincian jenis sampah, spasi antartabel, header berwarna, perataan sel, dan pemisah ribuan titik untuk nilai uang.

## Teknologi / Stack yang Digunakan

- **Frontend:** HTML5, CSS3, JavaScript (Vanilla JS)
- **Backend:** PHP (dengan penerapan OOP pada kelas domain transaksi, akun/redeem, pelaporan, dan koneksi database)
- **Database:** MySQL / MariaDB
- **Lingkungan Server Lokal:** Apache + PHP + MySQL (misalnya XAMPP, Laragon, atau web server lokal serupa)

## Cara Instalasi Lokal (Setup Guide)

1. **Siapkan Web Server & Database Lokal**
   - Pastikan layanan **Apache** dan **MySQL** sudah berjalan di lingkungan lokal Anda (misalnya melalui XAMPP atau Laragon).

2. **Salin Source Code ke Direktori Web Server**
   - Letakkan seluruh file proyek ke dalam folder root web server lokal Anda (contoh: `htdocs/bank-sampah-digital` pada XAMPP atau `www/bank-sampah-digital` pada Laragon).

3. **Buat Database & Tabel di MySQL**
   - Buka **phpMyAdmin** (buka tab **SQL**) atau klien MySQL Anda, lalu jalankan skrip SQL berikut untuk membuat database `bank_sampah_digital`, seluruh tabel yang diperlukan, serta data awal:

```sql
CREATE DATABASE IF NOT EXISTS `bank_sampah_digital`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;

USE `bank_sampah_digital`;

-- 1. Tabel Pengguna (Admin & Nasabah)
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `id_user` VARCHAR(50) NOT NULL,
  `username` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `nama` VARCHAR(150) NOT NULL,
  `role` ENUM('ADMIN', 'NASABAH') NOT NULL DEFAULT 'NASABAH',
  `saldo` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_id_user` (`id_user`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Tabel Kategori Sampah & Harga per Kg
CREATE TABLE IF NOT EXISTS `kategori_sampah` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `kode_sampah` VARCHAR(30) NOT NULL,
  `nama_jenis` VARCHAR(120) NOT NULL,
  `harga_per_kg` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `deskripsi` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kategori_kode_sampah` (`kode_sampah`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Tabel Riwayat Transaksi (Setoran / DEPOSIT & Penukaran / REDEEM)
CREATE TABLE IF NOT EXISTS `transaksi` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `kode_transaksi` VARCHAR(100) NOT NULL,
  `id_user` VARCHAR(50) NOT NULL,
  `kode_sampah` VARCHAR(30) NOT NULL,
  `berat_kg` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `jumlah_barang` INT(11) NOT NULL DEFAULT 0,
  `total_harga` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `tanggal_setor` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `tipe_transaksi` ENUM('DEPOSIT', 'REDEEM') NOT NULL,
  `keterangan` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_transaksi_id_user` (`id_user`),
  KEY `idx_transaksi_kode_sampah` (`kode_sampah`),
  KEY `idx_transaksi_tanggal` (`tanggal_setor`),
  CONSTRAINT `fk_transaksi_user` FOREIGN KEY (`id_user`)
    REFERENCES `users` (`id_user`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_transaksi_kategori` FOREIGN KEY (`kode_sampah`)
    REFERENCES `kategori_sampah` (`kode_sampah`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. Tabel Kode Redeem Voucher
CREATE TABLE IF NOT EXISTS `redeem_codes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `kode_redeem` VARCHAR(50) NOT NULL,
  `id_user` VARCHAR(50) NOT NULL,
  `nominal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('AKTIF', 'NON_AKTIF') NOT NULL DEFAULT 'AKTIF',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `redeemed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_redeem_kode` (`kode_redeem`),
  KEY `idx_redeem_id_user` (`id_user`),
  KEY `idx_redeem_status` (`status`),
  CONSTRAINT `fk_redeem_user` FOREIGN KEY (`id_user`)
    REFERENCES `users` (`id_user`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data Awal Kategori Sampah
INSERT IGNORE INTO `kategori_sampah` (`kode_sampah`, `nama_jenis`, `harga_per_kg`, `deskripsi`) VALUES
('PLT-01', 'Botol Plastik PET', 3000.00, 'Botol air mineral bersih'),
('KDS-01', 'Kardus Bekas', 2000.00, 'Kardus cokelat kering'),
('ALM-01', 'Kaleng Aluminium', 10000.00, 'Kaleng minuman ringan'),
('KRT-01', 'Kertas', 1500.00, 'Kertas HVS atau arsip kering'),
('TMB-01', 'Tembaga', 65000.00, 'Kabel atau logam tembaga bersih'),
('KCA-01', 'Botol Kaca', 500.00, 'Botol kaca bening atau berwarna'),
('JLT-01', 'Minyak Jelantah', 3000.00, 'Minyak goreng bekas pakai'),
('GLN-01', 'Galon', 3500.00, 'Galon air mineral bekas'),
('RDM-CASH', 'Redeem Voucher Cashout', 0.00, 'Kategori pencairan saldo voucher redeem');

-- Contoh Akun Admin & User Awal (sebaiknya ganti password setelah setup)
INSERT IGNORE INTO `users` (`id_user`, `username`, `password`, `nama`, `role`, `saldo`) VALUES
('11111', 'admin', '1', 'Petugas Utama', 'ADMIN', 0.00),
('33677', 'user', '1', 'user', 'NASABAH', 0.00);
```

4. **Sesuaikan Konfigurasi Koneksi Database**
   - Buka file koneksi database (seperti `Database.php` atau file konfigurasi terkait) lalu sesuaikan `host`, `username`, `password`, dan `nama database` (`bank_sampah_digital`) dengan pengaturan MySQL lokal Anda.

5. **Jalankan Aplikasi di Browser**
   - Akses aplikasi melalui browser, misalnya:
     ```text
     http://localhost/bank-sampah-digital/login.html
     ```

## Kredensial Login Awal

- **Admin (`ADMIN`):**
  - **Username:** `admin`
  - **Password:** `1`
- **User / Nasabah (`NASABAH`):**
  - **Username:** `user`
  - **Password:** `1`
