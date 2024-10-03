<?php

include 'db.php'; // Pastikan file db.php berisi variabel $host, $dbname, $username, dan $password
date_default_timezone_set('Asia/Jakarta');

// Fungsi untuk mengirim request menggunakan cURL
function sendToApi($url, $data) {
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: Komtronik-Gateway 1.0'
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    // Mengembalikan respon dari server SIHKA
    return [
        'response' => json_decode($response, true),
        'status_code' => $http_code
    ];
}

// Fungsi untuk menyimpan ID-ID baru ke file .txt
function saveToFile($newIds) {
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

    // Simpan semua ID baru (implode untuk gabungkan jadi string)
    file_put_contents($filePath, implode("\n", $newIds));
}

// Fungsi untuk membaca ID terakhir dari file .txt
function readFromFile() {
    $filePath = 'last_id.txt';
    if (file_exists($filePath)) {
        return file($filePath, FILE_IGNORE_NEW_LINES); // Baca semua baris sebagai array
    }
    return null;
}

try {
    // Membuat koneksi ke MySQL menggunakan PDO
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Baca ID terakhir yang tersimpan di file txt
    $lastIds = readFromFile();
    if (!$lastIds) {
        $lastIds = [0]; // Jika belum ada ID, mulai dari 0
    }
    $lastId = max($lastIds); // Ambil ID tertinggi dari file txt

    // Query untuk mengambil data baru yang ID-nya lebih besar dari ID terakhir di txt, di-join dengan tabel channel
    $query = "
    SELECT 
        rtu.rtu_id, 
        rtu.name AS rtu_name, 
        rtu.address, 
        value.date_time, 
        value.id,
        value.channel_no, 
        value.value, 
        value.dimension,
        channel.keterangan AS channel_name,
        channel.dimension AS channel_unit,
        channel.data_type
    FROM 
        value
    JOIN 
        rtu ON rtu.rtu_id = value.rtu_id
    JOIN 
        channel ON value.channel_no = channel.channel_no AND value.rtu_id = channel.rtu_id
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

    // Jika tidak ada hasil
    if (empty($results)) {
        // Jika tidak ada data baru, tidak kirim apapun
        exit();
    }

    // Gabungkan data berdasarkan rtu_id dan format sesuai permintaan
    $formattedData = [];
    $newIds = []; // Array untuk menyimpan ID-ID baru
    foreach ($results as $row) {
        $rtuId = $row['rtu_id'];
        $dateTime = $row['date_time'];
        
        // Tambahkan ID baru ke dalam array $newIds
        $newIds[] = $row['id'];

        // Buat entry baru jika belum ada untuk rtu_id dan date_time
        if (!isset($formattedData[$rtuId])) {
            $formattedData[$rtuId] = [
                'date_time' => $dateTime,
                'rtu' => $row['rtu_name'],
                'Rain Fall' => null,
                'Battery' => null,
                'Water Level' => null,
            ];
        }

        // Sesuaikan channel_no dengan nama channel
        switch ($row['channel_name']) {
            case 'Rain Fall':
                $formattedData[$rtuId]['Rain Fall'] = $row['value'];
                break;
            case 'Battery':
                $formattedData[$rtuId]['Battery'] = $row['value'];
                break;
            case 'Water Level':
                $formattedData[$rtuId]['Water Level'] = $row['value'];
                break;
        }
    }

    // Simpan semua ID baru ke file .txt
    saveToFile($newIds);

    // Mengirimkan data yang sudah diformat ke API SIHKA
    $finalData = array_values($formattedData);
    foreach ($finalData as $data) {
        $apiUrl = "https://sihka.bbwscitanduy.id/api/sensor";
        $response = sendToApi($apiUrl, $data);

        // Cek hasil dari API SIHKA (bisa disimpan ke log atau database jika perlu)
        if ($response['status_code'] == 200 && $response['response']['ok']) {
            // Sukses, bisa melakukan sesuatu di sini jika perlu
        } else {
            // Gagal, bisa melakukan penanganan error di sini
        }
    }

} catch (PDOException $e) {
    // Jika terjadi error koneksi atau query, tampilkan pesan error
    error_log("Database Error: " . $e->getMessage());
}
