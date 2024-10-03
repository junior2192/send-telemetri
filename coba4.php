<?php

include 'db.php'; // Pastikan file db.php berisi variabel $host, $dbname, $username, dan $password
date_default_timezone_set('Asia/Jakarta'); 

// Mengaktifkan CORS (jika dibutuhkan)
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Fungsi untuk mengirim respons JSON
function sendResponse($status_code, $data) { 
    http_response_code($status_code); // Set status HTTP
    echo json_encode($data, JSON_PRETTY_PRINT); // Kirim data dalam format JSON dengan indentasi
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
        sendResponse(404, [
            "status" => "error",
            "message" => "No new data found."
        ]);
    }

    // Gabungkan data berdasarkan rtu_id dan format sesuai permintaan
    $formattedData = [];
    foreach ($results as $row) {
        $rtuId = $row['rtu_id'];
        $dateTime = $row['date_time'];
        
        // Buat entry baru jika belum ada untuk rtu_id dan date_time
        if (!isset($formattedData[$rtuId])) {
            $formattedData[$rtuId] = [
                'rtu_id' => $rtuId,
                'name' => $row['rtu_name'],
                'date_time' => $dateTime,
                'Rain Fall' => null,
                'Battery' => null,
                'Water Level' => null,
            ];
        }

        // Sesuaikan channel_no dengan nama channel
        switch ($row['channel_name']) {
            case 'Rain Fall':
                $formattedData[$rtuId]['Rain Fall'] = [
                    'channel_no' => $row['channel_no'],
                    'unit' => $row['channel_unit'],
                    'value' => $row['value'],
                    'dimension' => $row['dimension'],
                    'data_type' => $row['data_type']
                ];
                break;
            case 'Battery':
                $formattedData[$rtuId]['Battery'] = [
                    'channel_no' => $row['channel_no'],
                    'unit' => $row['channel_unit'],
                    'value' => $row['value'],
                    'dimension' => $row['dimension'],
                    'data_type' => $row['data_type']
                ];
                break;
            case 'Water Level':
                $formattedData[$rtuId]['Water Level'] = [
                    'channel_no' => $row['channel_no'],
                    'unit' => $row['channel_unit'],
                    'value' => $row['value'],
                    'dimension' => $row['dimension'],
                    'data_type' => $row['data_type']
                ];
                break;
        }
    }

    // Ubah array associative ke array biasa agar JSON formatnya sesuai
    $finalData = array_values($formattedData);

    // Simpan ID terakhir baru ke file .txt
    $newLastId = max(array_column($results, 'id'));
    saveToFile($newLastId);

    // Mengirimkan data yang sudah diformat
    sendResponse(200, [
        'status' => 'success',
        'data' => $finalData,
    ]);

} catch (PDOException $e) {
    // Jika terjadi error koneksi atau query, tampilkan pesan error
    sendResponse(500, [
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
