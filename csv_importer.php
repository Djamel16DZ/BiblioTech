<?php
// csv_importer.php
session_start();

// Configuration
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('MAX_FILE_SIZE', 50 * 1024 * 1024); // 50MB limit
define('CHUNK_SIZE', 1000); // Commit transaction every 1,000 rows

// Database Credentials
$dbHost = 'localhost';
$dbName = 'bibliotech';
$dbUser = 'root';
$dbPass = 'root';

// Database Schema Mapping Options
$dbColumns = [
    'title'     => 'Title',
    'author'    => 'Author',
    'isbn'      => 'ISBN',
    'cote'      => 'Cote / Shelfmark',
    'publisher' => 'Publisher',
    'year'      => 'Publication Year'
];

$action = $_GET['action'] ?? 'upload';
$message = '';
$errors = [];

// Ensure upload directory exists
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// -----------------------------------------------------------------------------
// STEP 1: Handle File Upload
// -----------------------------------------------------------------------------
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['csv_file']['tmp_name'];
        $fileName = $_FILES['csv_file']['name'];
        $fileSize = $_FILES['csv_file']['size'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($fileExtension !== 'csv') {
            $message = "Error: Please upload a valid CSV file.";
        } elseif ($fileSize > MAX_FILE_SIZE) {
            $message = "Error: File size exceeds the allowed limit of 50MB.";
        } else {
            $destPath = UPLOAD_DIR . uniqid('csv_', true) . '.csv';
            if (move_uploaded_file($fileTmpPath, $destPath)) {
                // Read header row
                if (($handle = fopen($destPath, 'r')) !== false) {
                    $headers = fgetcsv($handle, 2000, ',');
                    fclose($handle);

                    if ($headers !== false) {
                        $_SESSION['csv_filepath'] = $destPath;
                        $_SESSION['csv_headers'] = $headers;
                        header('Location: ?action=map');
                        exit;
                    } else {
                        $message = "Error: Unable to read headers from the CSV file.";
                    }
                }
            } else {
                $message = "Error: Failed to save uploaded file.";
            }
        }
    } else {
        $message = "Error: File upload failed with code " . ($_FILES['csv_file']['error'] ?? 'UNKNOWN');
    }
}

// -----------------------------------------------------------------------------
// STEP 2: Process CSV Data with Chunked Transactions
// -----------------------------------------------------------------------------
if ($action === 'process' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(600); // Extend execution limit to 10 minutes
    ini_set('memory_limit', '256M');

    $filePath = $_SESSION['csv_filepath'] ?? null;
    $mapping = $_POST['mapping'] ?? [];

    if (!$filePath || !file_exists($filePath)) {
        header('Location: ?action=upload');
        exit;
    }

    try {
        $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);

        $handle = fopen($filePath, 'r');
        fgetcsv($handle); // Skip header row

        $rowCount = 0;
        $importedCount = 0;

        $stmt = $pdo->prepare("
            INSERT INTO catalog (title, author, isbn, cote, publisher, year) 
            VALUES (:title, :author, :isbn, :cote, :publisher, :year)
        ");

        $pdo->beginTransaction();

        while (($row = fgetcsv($handle, 4096, ',')) !== false) {
            $rowCount++;

            // Map data dynamically from field mapping configuration
            $data = [
                'title'     => ($mapping['title'] !== '') ? ($row[$mapping['title']] ?? null) : null,
                'author'    => ($mapping['author'] !== '') ? ($row[$mapping['author']] ?? null) : null,
                'isbn'      => ($mapping['isbn'] !== '') ? trim($row[$mapping['isbn']] ?? '') : null,
                'cote'      => ($mapping['cote'] !== '') ? trim($row[$mapping['cote']] ?? '') : null,
                'publisher' => ($mapping['publisher'] !== '') ? ($row[$mapping['publisher']] ?? null) : null,
                'year'      => ($mapping['year'] !== '') ? ($row[$mapping['year']] ?? null) : null,
            ];

            // Validation: ISBN check (10 or 13 characters ignoring hyphens)
            if (!empty($data['isbn'])) {
                $cleanIsbn = str_replace(['-', ' '], '', $data['isbn']);
                if (!preg_match('/^(?:\d{9}[\dX]|\d{13})$/i', $cleanIsbn)) {
                    $errors[] = "Row {$rowCount}: Invalid ISBN format ('{$data['isbn']}'). Skipped.";
                    continue;
                }
            }

            // Validation: Cote required
            if (empty($data['cote'])) {
                $errors[] = "Row {$rowCount}: Missing mandatory field 'Cote'. Skipped.";
                continue;
            }

            try {
                $stmt->execute($data);
                $importedCount++;
            } catch (PDOException $e) {
                if ($e->getCode() == '23000') { // Unique constraint / duplicate entry error
                    $errors[] = "Row {$rowCount}: Duplicate Cote or ISBN ('{$data['cote']}' / '{$data['isbn']}'). Skipped.";
                } else {
                    $errors[] = "Row {$rowCount}: Database Error - " . $e->getMessage();
                }
            }

            // Transaction batching
            if ($rowCount % CHUNK_SIZE === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
            }
        }

        // Commit remaining pending inserts
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        fclose($handle);
        @unlink($filePath); // Cleanup temp file
        unset($_SESSION['csv_filepath'], $_SESSION['csv_headers']);

        $summary = [
            'total' => $rowCount,
            'imported' => $importedCount,
            'failed' => count($errors)
        ];

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = "Fatal processing error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSV Batch Data Ingestion System</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-800 font-sans min-h-screen p-8">

    <div class="max-w-3xl mx-auto bg-white rounded-xl shadow-md p-6">
        <h1 class="text-2xl font-bold mb-4 text-slate-900 border-b pb-2">CSV Batch Data Ingestion System</h1>

        <?php if (!empty($message)): ?>
            <div class="mb-4 p-4 text-red-700 bg-red-100 rounded-lg text-sm"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- STEP 1: UPLOAD FORM -->
        <?php if ($action === 'upload'): ?>
            <form action="?action=upload" method="POST" enctype="multipart/form-data" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Select CSV File</label>
                    <input type="file" name="csv_file" accept=".csv" required 
                           class="block w-full text-sm text-slate-500 border border-slate-300 rounded-lg cursor-pointer p-2.5">
                </div>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg">
                    Upload & Map File
                </button>
            </form>

        <!-- STEP 2: COLUMN MAPPING FORM -->
        <?php elseif ($action === 'map'): ?>
            <?php 
                $headers = $_SESSION['csv_headers'] ?? [];
                if (empty($headers)) {
                    header('Location: ?action=upload');
                    exit;
                }
            ?>
            <form action="?action=process" method="POST" class="space-y-4">
                <p class="text-sm text-slate-600">Match database column fields to the corresponding CSV headers:</p>
                
                <div class="grid grid-cols-1 gap-4">
                    <?php foreach ($dbColumns as $dbKey => $dbLabel): ?>
                        <div class="flex items-center justify-between border-b pb-2">
                            <label for="col_<?= $dbKey ?>" class="text-sm font-semibold text-slate-700"><?= $dbLabel ?></label>
                            <select name="mapping[<?= $dbKey ?>]" id="col_<?= $dbKey ?>" class="border border-slate-300 rounded-md p-2 text-sm w-1/2">
                                <option value="">-- Do Not Import --</option>
                                <?php foreach ($headers as $idx => $header): ?>
                                    <option value="<?= $idx ?>"><?= htmlspecialchars($header) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="flex gap-2 pt-4">
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-lg">
                        Execute Batch Ingestion
                    </button>
                    <a href="?action=upload" class="bg-slate-300 hover:bg-slate-400 text-slate-800 font-medium py-2 px-4 rounded-lg">
                        Cancel
                    </a>
                </div>
            </form>

        <!-- STEP 3: RESULTS SUMMARY & ERROR LOG -->
        <?php elseif ($action === 'process' && isset($summary)): ?>
            <div class="space-y-4">
                <div class="grid grid-cols-3 gap-4 text-center">
                    <div class="bg-slate-100 p-4 rounded-lg border">
                        <div class="text-xl font-bold text-slate-800"><?= $summary['total'] ?></div>
                        <div class="text-xs text-slate-500">Processed</div>
                    </div>
                    <div class="bg-emerald-50 p-4 rounded-lg border border-emerald-200">
                        <div class="text-xl font-bold text-emerald-600"><?= $summary['imported'] ?></div>
                        <div class="text-xs text-emerald-600">Imported</div>
                    </div>
                    <div class="bg-rose-50 p-4 rounded-lg border border-rose-200">
                        <div class="text-xl font-bold text-rose-600"><?= $summary['failed'] ?></div>
                        <div class="text-xs text-rose-600">Skipped/Failed</div>
                    </div>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="mt-6">
                        <h3 class="text-md font-semibold text-slate-800 mb-2">Ingestion Exception Log</h3>
                        <div class="bg-slate-900 text-slate-200 text-xs font-mono p-4 rounded-lg max-h-64 overflow-y-auto space-y-1">
                            <?php foreach ($errors as $err): ?>
                                <div class="text-rose-400"><?= htmlspecialchars($err) ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="pt-4">
                    <a href="?action=upload" class="inline-block bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg">
                        Import Another File
                    </a>
                </div>
            </div>
        <?php endif; ?>

    </div>

</body>
</html>