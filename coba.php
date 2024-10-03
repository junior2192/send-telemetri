<?php

include 'db.php'; // Pastikan file db.php berisi variabel $host, $dbname, $username, dan $password

// Mengaktifkan CORS (jika dibutuhkan)
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Fungsi untuk mengirim respons JSON
function sendResponse($status_code, $data) { 
    http_response_code($status_code); // Set status HTTP
    echo json_encode($data); // Kirim data dalam format JSON
    exit(); // Hentikan eksekusi setelah mengirimkan respons
}

// Fungsi untuk menyimpan ID terakhir ke file .txt
function saveToFile($lastId) {
    $filePath = 'last_id.txt';

    // Mengecek apakah sudah hari baru, jika ya, hapus file lama
    if (file_exists($filePath)) {
        $fileModTime = date("Y-m-d", filemtime($filePath));
        $today = date("Y-m-d");

        // Jika file dari hari sebelumnya, hapus dan buat baru
        if ($fileModTime !== $today) {
            unlink($filePath);
        }
    }

    // Simpan ID terakhir ke file
    file_put_contents($filePath, $lastId);
}

// Fungsi untuk membaca ID terakhir dari file .txt
function readFromFile() {
    $filePath = 'last_id.txt';
    if (file_exists($filePath)) {
        return file_get_contents($filePath);
    }
    return null;
}

try {
    // Membuat koneksi ke MySQL menggunakan PDO
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    // Set mode error PDO ke exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Baca ID terakhir yang tersimpan di file txt
    $lastId = readFromFile();
    if (!$lastId) {
        $lastId = 0; // Jika belum ada ID, mulai dari 0
    }

    // Query untuk mengambil data baru yang ID-nya lebih besar dari ID terakhir di txt
    $query = "
    SELECT 
        rtu.name AS rtu_name, 
        rtu.address, 
        rtu.rtu_id, 
        value.date_time, 
        value.id,
        value.channel_no, 
        value.value, 
        value.dimension
    FROM 
        value
    JOIN 
        rtu ON rtu.rtu_id = value.rtu_id
    WHERE 
        value.id > :lastId
        AND MOD(MINUTE(value.date_time), 15) = 0
        AND value.date_time BETWEEN CURDATE() AND NOW()
    ORDER BY 
        value.date_time DESC;
    ";

    // Menyiapkan dan menjalankan query
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':lastId', $lastId, PDO::PARAM_INT);
    $stmt->execute();

    // Mengambil semua hasil
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($results)) {
        sendResponse(404, [
            "status" => "error",
            "message" => "No new data found."
        ]);
    }

    // Ambil ID terbesar dari data baru
    $newLastId = max(array_column($results, 'value_id'));

    // Simpan ID terakhir baru ke file .txt
    saveToFile($newLastId);

    // Mengirimkan data yang baru diambil dari database
    sendResponse(200, [
        'status' => 'success',
        'data' => $results,
    ]);

} catch (PDOException $e) {
    // Jika terjadi error koneksi atau query, tampilkan pesan error
    sendResponse(500, [
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
?>
