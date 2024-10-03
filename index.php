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

// Fungsi untuk menyimpan data ke file .txt
function saveToFile($data) {
    $filePath = 'data.txt';

    // Mengecek apakah sudah hari baru, jika ya, hapus file lama
    if (file_exists($filePath)) {
        $fileModTime = date("Y-m-d", filemtime($filePath));
        $today = date("Y-m-d");

        // Jika file dari hari sebelumnya, hapus dan buat baru
        if ($fileModTime !== $today) {
            unlink($filePath);
        }
    }

    // Simpan data baru ke file
    file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT));
}

// Fungsi untuk membaca data dari file .txt
function readFromFile() {
    $filePath = 'data.txt';
    if (file_exists($filePath)) {
        $data = file_get_contents($filePath);
        return json_decode($data, true);
    }
    return [];
}

try {
    // Membuat koneksi ke MySQL menggunakan PDO
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    // Set mode error PDO ke exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Mengambil semua channel dari tabel 'channel', termasuk 'data_type'
    $channelQuery = "SELECT rtu_id, channel_no, keterangan AS channel_name, dimension AS channel_unit, data_type FROM channel";
    $channelStmt = $pdo->prepare($channelQuery);
    $channelStmt->execute();
    $channels = $channelStmt->fetchAll(PDO::FETCH_ASSOC);

    // Mengorganisir channel berdasarkan rtu_id
    $channelsByRTU = [];
    foreach ($channels as $channel) {
        $rtu_id = $channel['rtu_id'];
        $channel_no = $channel['channel_no'];
        if (!isset($channelsByRTU[$rtu_id])) {
            $channelsByRTU[$rtu_id] = [];
        }
        $channelsByRTU[$rtu_id][$channel_no] = [
            'channel_name' => $channel['channel_name'],
            'unit' => $channel['channel_unit'],
            'data_type' => $channel['data_type']
        ];
    }

    // Query utama yang diperbarui dengan JOIN ke tabel 'channel' dan mengambil data hari ini saja
    $query = "
    SELECT 
        rtu.name AS rtu_name, 
        rtu.address, 
        rtu.rtu_id, 
        rtu.config_version, 
        value.date_time, 
        value.channel_no, 
        value.value, 
        value.dimension,
        channel.keterangan AS channel_name,
        channel.dimension AS channel_unit,
        channel.data_type,
        channel.rtu_id
    FROM 
        rtu
    JOIN 
        value ON rtu.rtu_id = value.rtu_id
    JOIN
        channel ON rtu.rtu_id = channel.rtu_id AND value.channel_no = channel.channel_no
    WHERE 
        DATE(value.date_time) = CURDATE() - INTERVAL 8 DAY
    ORDER BY 
        value.date_time DESC;
    ";

    // Menyiapkan dan menjalankan query
    $stmt = $pdo->prepare($query);
    $stmt->execute();

    // Mengambil semua hasil
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($results)) {
        sendResponse(404, [
            "status" => "error",
            "message" => "No data found for today."
        ]);
    }

    // Gabungkan data berdasarkan rtu_id dan date_time
    $mergedData = [];
    foreach ($results as $row) {
        $rtu_id = $row['rtu_id'];
        $date_time = $row['date_time'];
        $channel_no = $row['channel_no'];

        // Jika rtu_id dan date_time belum ada di array mergedData, buat entry baru
        if (!isset($mergedData[$rtu_id])) {
            $mergedData[$rtu_id] = [];
        }

        if (!isset($mergedData[$rtu_id][$date_time])) {
            // Inisialisasi entry dengan semua channel di-set ke null
            $mergedData[$rtu_id][$date_time] = [
                'rtu_id' => $rtu_id,
                'name' => $row['rtu_name'],
                'address' => $row['address'],
                'date_time' => $date_time,
                'channels' => []
            ];

            // Inisialisasi semua channel berdasarkan rtu_id
            if (isset($channelsByRTU[$rtu_id])) {
                foreach ($channelsByRTU[$rtu_id] as $chn_no => $chn_info) {
                    $mergedData[$rtu_id][$date_time]['channels'][$chn_no] = null;
                }
            }
        }

        // Tambahkan data channel jika ada
        if (isset($channelsByRTU[$rtu_id][$channel_no])) {
            $mergedData[$rtu_id][$date_time]['channels'][$channel_no] = [
                'channel_no' => $channel_no,
                'channel_name' => $channelsByRTU[$rtu_id][$channel_no]['channel_name'],
                'unit' => $channelsByRTU[$rtu_id][$channel_no]['unit'],
                'value' => $row['value'],
                'dimension' => $row['dimension'],
                'data_type' => $channelsByRTU[$rtu_id][$channel_no]['data_type']
            ];
        }
    }

    // Ubah array associative ke array biasa
    $finalData = [];
    foreach ($mergedData as $rtuData) {
        foreach ($rtuData as $entry) {
            $finalData[] = $entry;
        }
    }

    // Baca data dari file
    $existingData = readFromFile();

    // Cek apakah ada data baru berdasarkan date_time
    $newData = array_udiff($finalData, $existingData, function ($a, $b) {
        return strtotime($a['date_time']) - strtotime($b['date_time']);
    });

    if (!empty($newData)) {
        // Simpan data baru ke file .txt
        saveToFile($finalData);
    }

    // Mengirimkan data dari file (data yang disimpan)
    sendResponse(200, [
        'status' => 'success',
        'data' => readFromFile(),
    ]);

} catch (PDOException $e) {
    // Jika terjadi error koneksi atau query, tampilkan pesan error
    sendResponse(500, [
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
?>
