# Bank Sampah Digital

Aplikasi web **Bank Sampah Digital** untuk mendigitalisasi pencatatan setoran sampah, pengelolaan saldo nasabah, pembuatan serta pencairan kode redeem, dan rekap laporan operasional admin per klaster periode.

## Ringkasan Fitur

* **Autentikasi & Manajemen Sesi Peran (`ADMIN` & Nasabah):** Login berbasis peran dengan penjagaan akses halaman serta sinkronisasi sesi lintas tab.
* **Pencatatan Setoran & Saldo Nasabah:** Memproses transaksi setoran sampah berdasarkan jenis sampah, bobot/jumlah, dan nilai setoran yang terhubung ke saldo serta riwayat transaksi nasabah.
* **Sistem Kode Redeem & Kas Keluar:** Nasabah dapat menukarkan saldo menjadi kode redeem, sementara admin dapat memverifikasi/mencairkan kode redeem dan memantau kas keluar.
* **Overview Operasional Admin per Klaster:** Menyaring ringkasan metrik dan rincian per jenis sampah berdasarkan periode (`3 Hari`, `7 Hari`, `Sebulan (30 Hari)`, dan `Lifetime`).
* **Ekspor Laporan Tabel Excel (`.xls`) per Klaster:** Mengunduh laporan tabel `.xls` sesuai klaster aktif yang dilengkapi ringkasan indikator, rincian jenis sampah, spasi antartabel, header berwarna, perataan sel, dan pemisah ribuan titik untuk nilai uang.

## Teknologi / Stack yang Digunakan

* **Frontend:** HTML5, CSS3, JavaScript (Vanilla JS)
* **Backend:** PHP (dengan penerapan OOP pada kelas domain transaksi, akun/redeem, pelaporan, dan koneksi database)
* **Database:** MySQL / MariaDB
* **Lingkungan Server Lokal:** Apache + PHP + MySQL (misalnya XAMPP, Laragon, atau web server lokal serupa)

## Cara Instalasi Lokal (Setup Guide)

1. **Siapkan Web Server & Database Lokal**
   * Pastikan layanan **Apache** dan **MySQL** sudah berjalan di lingkungan lokal Anda (misalnya melalui XAMPP atau Laragon).
2. **Salin Source Code ke Direktori Web Server**
   * Letakkan seluruh file proyek ke dalam folder root web server lokal Anda (contoh: `htdocs/bank-sampah-digital` pada XAMPP atau `www/bank-sampah-digital` pada Laragon).
3. **Buat & Konfigurasi Database MySQL**
   * Buka **phpMyAdmin** atau klien MySQL, lalu buat database baru sesuai konfigurasi proyek Anda.
   * Impor skema/file SQL database proyek (jika tersedia) atau sesuaikan tabel yang dibutuhkan.
   * Buka file koneksi database (seperti `Database.php` atau file konfigurasi terkait) lalu sesuaikan `host`, `username`, `password`, dan `nama database` dengan pengaturan MySQL lokal Anda.
4. **Jalankan Aplikasi di Browser**
   * Akses aplikasi melalui browser, misalnya:
     ```text
     http://localhost/bank-sampah-digital/login.html
     ```
   * Masuk menggunakan akun **Admin** atau **Nasabah** yang terdaftar di database untuk mulai menguji alur setoran, redeem, dan laporan klaster.
