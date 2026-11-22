<?php
class Database {
    // Enkapsulasi: Sifat private agar aman dari akses luar
    private $host = "127.0.0.1";
    private $user = "Indiecorp";
    private $pass = "Admin1234";
    private $db   = "bank_sampah_digital";
    public $conn;

    // Constructor: Otomatis dijalankan saat objek dibuat
    public function __construct() {
        $this->conn = new mysqli($this->host, $this->user, $this->pass, $this->db);
        if ($this->conn->connect_error) {
            die("Koneksi OOP Gagal: " . $this->conn->connect_error);
        }
    }

    // Method untuk mencegah SQL Injection (Abstraksi sederhana)
    public function escape($data) {
        return $this->conn->real_escape_string(trim($data));
    }
}
?>