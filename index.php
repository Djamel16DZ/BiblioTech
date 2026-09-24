<?php
// =========================================================================
// 1. ENVIRONMENT & DATABASE CONFIGURATION
// =========================================================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$db_host = '127.0.0.1';
$db_name = 'bibliotech';
$db_user = 'root';
$db_pass = 'root';

$db_error = null;
$pdo = null;

// Dossier de stockage des e-books téléversés
$uploadDir = __DIR__ . '/uploads/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

if (!extension_loaded('pdo_mysql')) {
    $db_error = "The 'pdo_mysql' extension is disabled or missing in your PHP runtime.";
} else {
    try {
        $pdo = new \PDO("mysql:host={$db_host};charset=utf8mb4", $db_user, $db_pass, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$db_name}`");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `catalog` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `cote` VARCHAR(50) NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `author` VARCHAR(255) DEFAULT NULL,
            `category` VARCHAR(100) DEFAULT 'Uncategorized',
            `isbn` VARCHAR(30) DEFAULT NULL,
            `format` ENUM('physical', 'pdf', 'epub', 'mobi') DEFAULT 'physical',
            `pub_year` INT(4) DEFAULT NULL,
            `location` VARCHAR(100) DEFAULT NULL,
            `file_url` VARCHAR(255) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_format` (`format`),
            INDEX `idx_category` (`category`),
            FULLTEXT INDEX `ft_catalog_search` (`title`, `author`, `cote`, `isbn`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    } catch (\PDOException $e) {
        $db_error = $e->getMessage();
    }
}

// Fonction utilitaire pour gérer l'upload du fichier
function handleFileUpload() {
    global $uploadDir;
    if (isset($_FILES['ebook_file']) && $_FILES['ebook_file']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['ebook_file']['tmp_name'];
        $fileName = $_FILES['ebook_file']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        $allowedExtensions = ['pdf', 'epub', 'mobi'];
        if (in_array($fileExtension, $allowedExtensions)) {
            $newFileName = md5(time() . $fileName) . '.' . $fileExtension;
            $destPath = $uploadDir . $newFileName;
            
            if (move_uploaded_file($fileTmpPath, $destPath)) {
                return 'uploads/' . $newFileName;
            }
        }
    }
    return null;
}

// =========================================================================
// 2. SERVER-SIDE ISBN PROXY ENDPOINT
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'lookup_isbn') {
    header('Content-Type: application/json');
    $isbn = preg_replace('/[^0-9X]/i', '', $_GET['isbn'] ?? '');

    if (empty($isbn)) {
        echo json_encode(['success' => false, 'error' => 'Invalid or empty ISBN provided.']);
        exit;
    }

    $url = "https://openlibrary.org/api/books?bibkeys=ISBN:{$isbn}&jscmd=data&format=json";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'BiblioTech-LMS/1.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        echo json_encode(['success' => false, 'error' => 'Server could not reach Open Library API.']);
        exit;
    }

    $data = json_decode($response, true);
    $key = "ISBN:{$isbn}";

    if (isset($data[$key])) {
        $book = $data[$key];
        $authors = [];
        if (!empty($book['authors'])) {
            foreach ($book['authors'] as $a) {
                $authors[] = $a['name'];
            }
        }

        $pubYear = null;
        if (!empty($book['publish_date'])) {
            if (preg_match('/\d{4}/', $book['publish_date'], $matches)) {
                $pubYear = $matches[0];
            }
        }

        $category = !empty($book['subjects']) ? $book['subjects'][0]['name'] : 'Uncategorized';

        echo json_encode([
            'success'  => true,
            'title'    => $book['title'] ?? '',
            'author'   => implode(', ', $authors),
            'pub_year' => $pubYear,
            'category' => $category,
            'isbn'     => $isbn
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'ISBN not found in Open Library repository.']);
    }
    exit;
}

// =========================================================================
// 3. POST ENDPOINT: SAVE NEW ITEM
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_item') {
    header('Content-Type: application/json');
    if ($pdo === null) {
        echo json_encode(['success' => false, 'error' => $db_error ?? 'Database not connected']);
        exit;
    }

    try {
        $fileUrl = handleFileUpload();

        $stmt = $pdo->prepare("INSERT INTO catalog (cote, title, author, category, isbn, format, pub_year, location, file_url) 
                               VALUES (:cote, :title, :author, :category, :isbn, :format, :pub_year, :location, :file_url)");
        
        $stmt->execute([
            ':cote'     => trim($_POST['cote']),
            ':title'    => trim($_POST['title']),
            ':author'   => trim($_POST['author']) ?: null,
            ':category' => trim($_POST['category']) ?: 'Uncategorized',
            ':isbn'     => trim($_POST['isbn']) ?: null,
            ':format'   => trim($_POST['format']) ?: 'physical',
            ':pub_year' => !empty($_POST['pub_year']) ? (int)$_POST['pub_year'] : null,
            ':location' => trim($_POST['location']) ?: null,
            ':file_url' => $fileUrl,
        ]);

        echo json_encode(['success' => true]);
    } catch (\PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// 3.1. POST ENDPOINT: UPDATE EXISTING ITEM
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_item') {
    header('Content-Type: application/json');
    if ($pdo === null) {
        echo json_encode(['success' => false, 'error' => $db_error ?? 'Database not connected']);
        exit;
    }

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid ID provided for update.']);
        exit;
    }

    try {
        $stmtOld = $pdo->prepare("SELECT file_url FROM catalog WHERE id = :id");
        $stmtOld->execute([':id' => $id]);
        $existingRecord = $stmtOld->fetch();
        $fileUrl = $existingRecord ? $existingRecord['file_url'] : null;

        $newUploadedFile = handleFileUpload();
        if ($newUploadedFile) {
            $fileUrl = $newUploadedFile;
        }

        $stmt = $pdo->prepare("UPDATE catalog SET 
                               cote = :cote, 
                               title = :title, 
                               author = :author, 
                               category = :category, 
                               isbn = :isbn, 
                               format = :format, 
                               pub_year = :pub_year, 
                               location = :location,
                               file_url = :file_url 
                               WHERE id = :id");
        
        $stmt->execute([
            ':id'       => $id,
            ':cote'     => trim($_POST['cote']),
            ':title'    => trim($_POST['title']),
            ':author'   => trim($_POST['author']) ?: null,
            ':category' => trim($_POST['category']) ?: 'Uncategorized',
            ':isbn'     => trim($_POST['isbn']) ?: null,
            ':format'   => trim($_POST['format']) ?: 'physical',
            ':pub_year' => !empty($_POST['pub_year']) ? (int)$_POST['pub_year'] : null,
            ':location' => trim($_POST['location']) ?: null,
            ':file_url' => $fileUrl,
        ]);

        echo json_encode(['success' => true]);
    } catch (\PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// 4. POST ENDPOINT: DELETE ITEM
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_item') {
    header('Content-Type: application/json');
    if ($pdo === null) {
        echo json_encode(['success' => false, 'error' => $db_error ?? 'Database not connected']);
        exit;
    }

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid ID provided.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM catalog WHERE id = :id");
        $stmt->execute([':id' => $id]);

        echo json_encode(['success' => true]);
    } catch (\PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// 5. FETCH DASHBOARD & CATALOG METRICS (OVERKILL ANALYTICS)
// =========================================================================
$totalRecords = 0;
$countPhysical = 0;
$countPdf = 0;
$countEpub = 0;
$countMobi = 0;
$totalCategories = 0;
$filesAttachedCount = 0;
$recentRecords = [];
$topCategories = [];
$oldestYear = null;
$newestYear = null;

if ($pdo !== null) {
    try {
        $totalRecords = (int)$pdo->query("SELECT COUNT(*) FROM catalog")->fetchColumn();
        $countPhysical = (int)$pdo->query("SELECT COUNT(*) FROM catalog WHERE format = 'physical'")->fetchColumn();
        $countPdf = (int)$pdo->query("SELECT COUNT(*) FROM catalog WHERE format = 'pdf'")->fetchColumn();
        $countEpub = (int)$pdo->query("SELECT COUNT(*) FROM catalog WHERE format = 'epub'")->fetchColumn();
        $countMobi = (int)$pdo->query("SELECT COUNT(*) FROM catalog WHERE format = 'mobi'")->fetchColumn();
        $totalCategories = (int)$pdo->query("SELECT COUNT(DISTINCT category) FROM catalog")->fetchColumn();
        $filesAttachedCount = (int)$pdo->query("SELECT COUNT(*) FROM catalog WHERE file_url IS NOT NULL AND file_url != ''")->fetchColumn();
        
        $oldestYear = $pdo->query("SELECT MIN(pub_year) FROM catalog WHERE pub_year > 0")->fetchColumn();
        $newestYear = $pdo->query("SELECT MAX(pub_year) FROM catalog WHERE pub_year > 0")->fetchColumn();

        $recentStmt = $pdo->query("SELECT * FROM catalog ORDER BY id DESC LIMIT 6");
        $recentRecords = $recentStmt->fetchAll();

        $catStmt = $pdo->query("SELECT category, COUNT(*) as total FROM catalog GROUP BY category ORDER BY total DESC LIMIT 5");
        $topCategories = $catStmt->fetchAll();
    } catch (\PDOException $e) {
        $db_error = "Database Metrics Error: " . $e->getMessage();
    }
}

$totalDigital = $countPdf + $countEpub + $countMobi;
$digitalPercentage = $totalRecords > 0 ? round(($totalDigital / $totalRecords) * 100, 1) : 0;
$physicalPercentage = $totalRecords > 0 ? round(($countPhysical / $totalRecords) * 100, 1) : 0;
$attachmentRate = $totalDigital > 0 ? round(($filesAttachedCount / $totalDigital) * 100, 1) : 0;

// =========================================================================
// 6. QUERY & PAGINATION LOGIC (FOR AJAX CATALOGUE)
// =========================================================================
$search   = isset($_GET['q']) ? trim($_GET['q']) : '';
$format   = isset($_GET['format']) ? trim($_GET['format']) : 'all';
$category = isset($_GET['category']) ? trim($_GET['category']) : 'all';
$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit    = 50;
$offset   = ($page - 1) * $limit;

$whereClauses = [];
$params       = [];

if (!empty($search)) {
    $whereClauses[] = "(COALESCE(title, '') LIKE :s1 
                       OR COALESCE(author, '') LIKE :s2 
                       OR COALESCE(cote, '') LIKE :s3 
                       OR COALESCE(isbn, '') LIKE :s4)";
    
    $searchTerm = '%' . $search . '%';
    $params[':s1'] = $searchTerm;
    $params[':s2'] = $searchTerm;
    $params[':s3'] = $searchTerm;
    $params[':s4'] = $searchTerm;
}

if ($format !== 'all' && !empty($format)) {
    $whereClauses[] = "LOWER(format) = LOWER(:format)";
    $params[':format'] = $format;
}

if ($category !== 'all' && !empty($category)) {
    $whereClauses[] = "category = :category";
    $params[':category'] = $category;
}

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$filteredRecordsCount = 0;
$records = [];

if ($pdo !== null) {
    try {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM catalog {$whereSql}");
        foreach ($params as $key => $val) {
            $countStmt->bindValue($key, $val, \PDO::PARAM_STR);
        }
        $countStmt->execute();
        $filteredRecordsCount = (int)$countStmt->fetchColumn();

        $sql = "SELECT * FROM catalog {$whereSql} ORDER BY id DESC LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val, \PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', (int)$limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, \PDO::PARAM_INT);
        $stmt->execute();
        $records = $stmt->fetchAll();
    } catch (\PDOException $e) {
        $db_error = "Database Query Error: " . $e->getMessage();
    }
}

$totalPages = max(1, ceil($filteredRecordsCount / $limit));

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'totalRecords' => $filteredRecordsCount,
        'page'         => $page,
        'totalPages'   => $totalPages,
        'records'      => $records,
        'db_error'     => $db_error
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950 text-zinc-100 font-sans antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BiblioTech - Overkill LMS Command Center</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #09090b; }
        ::-webkit-scrollbar-thumb { background: #27272a; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #3f3f46; }

        #sidebar { transition: margin-left 0.25s ease-in-out; }
        #sidebar.collapsed { margin-left: -15rem; }
    </style>
</head>
<body class="h-full flex overflow-hidden text-xs select-none">

    <!-- SIDEBAR -->
    <aside id="sidebar" class="w-60 bg-zinc-900 border-r border-zinc-800 flex flex-col shrink-0 z-20">
        <div class="h-12 border-b border-zinc-800 flex items-center px-4 space-x-2.5">
            <div class="w-6 h-6 rounded bg-gradient-to-tr from-indigo-600 to-purple-600 flex items-center justify-center text-white font-bold text-xs shadow-lg shadow-indigo-500/30">
                <i class="fa-solid fa-book-open"></i>
            </div>
            <span class="font-bold text-white tracking-tight text-sm">Biblio<span class="text-indigo-400">Tech</span></span>
            <span class="ml-auto text-[10px] font-mono px-1.5 py-0.5 rounded bg-indigo-500/10 text-indigo-400 border border-indigo-500/20">PRO</span>
        </div>

        <div class="flex-1 overflow-y-auto p-2.5 space-y-4">
            <div>
                <div class="px-2 text-[10px] font-bold uppercase tracking-wider text-zinc-500 mb-1">Command Center</div>
                <nav class="space-y-0.5">
                    <a href="#dashboard" onclick="switchTab('dashboard')" id="nav-dashboard" class="nav-item flex items-center justify-between px-2.5 py-1.5 rounded-md bg-gradient-to-r from-indigo-600/20 to-purple-600/10 text-indigo-300 font-semibold border border-indigo-500/30 shadow-sm">
                        <div class="flex items-center space-x-2.5">
                            <i class="fa-solid fa-chart-pie w-4 text-center text-indigo-400"></i>
                            <span>Dashboard Overkill</span>
                        </div>
                        <span class="w-2 h-2 rounded-full bg-indigo-400 animate-ping"></span>
                    </a>
                    <a href="#catalog" onclick="switchTab('catalog')" id="nav-catalog" class="nav-item flex items-center justify-between px-2.5 py-1.5 rounded-md text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200 transition">
                        <div class="flex items-center space-x-2.5">
                            <i class="fa-solid fa-table-cells w-4 text-center"></i>
                            <span>Master Catalog</span>
                        </div>
                        <span id="sidebar-total-badge" class="font-mono text-[10px] px-1.5 py-0.2 rounded bg-zinc-800 text-zinc-300"><?= number_format($totalRecords) ?></span>
                    </a>
                </nav>
            </div>

            <div>
                <div class="px-2 text-[10px] font-bold uppercase tracking-wider text-zinc-500 mb-1">Holdings Filter</div>
                <nav class="space-y-0.5">
                    <a href="#physical" onclick="filterByFormat('physical')" class="nav-item flex items-center justify-between px-2.5 py-1.5 rounded-md text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200 transition">
                        <div class="flex items-center space-x-2.5">
                            <i class="fa-solid fa-box-archive w-4 text-center text-amber-500"></i>
                            <span>Physical Books</span>
                        </div>
                        <span class="font-mono text-[10px] text-amber-400"><?= $countPhysical ?></span>
                    </a>
                    <a href="#digital" onclick="filterByFormat('pdf')" class="nav-item flex items-center justify-between px-2.5 py-1.5 rounded-md text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200 transition">
                        <div class="flex items-center space-x-2.5">
                            <i class="fa-solid fa-file-pdf w-4 text-center text-sky-400"></i>
                            <span>PDF E-Books</span>
                        </div>
                        <span class="font-mono text-[10px] text-sky-400"><?= $countPdf ?></span>
                    </a>
                    <a href="#epub" onclick="filterByFormat('epub')" class="nav-item flex items-center justify-between px-2.5 py-1.5 rounded-md text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200 transition">
                        <div class="flex items-center space-x-2.5">
                            <i class="fa-solid fa-book-bookmark w-4 text-center text-purple-400"></i>
                            <span>EPUB Files</span>
                        </div>
                        <span class="font-mono text-[10px] text-purple-400"><?= $countEpub ?></span>
                    </a>
                    <a href="#mobi" onclick="filterByFormat('mobi')" class="nav-item flex items-center justify-between px-2.5 py-1.5 rounded-md text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200 transition">
                        <div class="flex items-center space-x-2.5">
                            <i class="fa-solid fa-tablet-screen-button w-4 text-center text-emerald-400"></i>
                            <span>MOBI Files</span>
                        </div>
                        <span class="font-mono text-[10px] text-emerald-400"><?= $countMobi ?></span>
                    </a>
                </nav>
            </div>
        </div>

        <div class="p-2.5 border-t border-zinc-800 text-[10px] font-mono text-zinc-500 flex justify-between items-center bg-zinc-900/50">
            <span class="flex items-center space-x-1.5">
                <span class="w-2 h-2 rounded-full <?= $pdo !== null && $db_error === null ? 'bg-emerald-500 shadow-lg shadow-emerald-500/50' : 'bg-rose-500' ?>"></span>
                <span class="text-zinc-300">MariaDB v10.4+</span>
            </span>
            <span class="text-indigo-400 font-bold">LMS v2.0</span>
        </div>
    </aside>

    <!-- MAIN CONTENT AREA -->
    <main class="flex-1 flex flex-col min-w-0 bg-zinc-950">
        <header class="h-12 border-b border-zinc-800 bg-zinc-900/60 px-4 flex items-center justify-between shrink-0 space-x-3">
            <div class="flex items-center space-x-3 flex-1 max-w-2xl">
                <button onclick="toggleSidebar()" id="sidebarToggleBtn" class="p-1.5 text-zinc-400 hover:text-white hover:bg-zinc-800 rounded-md border border-zinc-800 transition shrink-0">
                    <i class="fa-solid fa-bars-staggered text-xs"></i>
                </button>

                <div class="relative flex-1">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-zinc-500 text-xs"></i>
                    <input type="text" id="searchInput" value="<?= htmlspecialchars($search) ?>" placeholder="Search title, author, ISBN, or Cote..." 
                           oninput="triggerLiveSearch()"
                           class="w-full bg-zinc-950 border border-zinc-800 rounded-md pl-8 pr-3 py-1.5 text-xs text-zinc-200 placeholder-zinc-500 focus:outline-none focus:border-indigo-500 transition">
                </div>
                
                <select id="formatFilter" onchange="triggerLiveSearch()" class="bg-zinc-950 border border-zinc-800 rounded-md px-2.5 py-1.5 text-xs text-zinc-300 focus:outline-none focus:border-indigo-500">
                    <option value="all" <?= $format === 'all' ? 'selected' : '' ?>>All Formats</option>
                    <option value="physical" <?= $format === 'physical' ? 'selected' : '' ?>>Physical Books</option>
                    <option value="pdf" <?= $format === 'pdf' ? 'selected' : '' ?>>PDF E-Books</option>
                    <option value="epub" <?= $format === 'epub' ? 'selected' : '' ?>>EPUB Files</option>
                    <option value="mobi" <?= $format === 'mobi' ? 'selected' : '' ?>>MOBI Files</option>
                </select>
            </div>

            <div class="flex items-center space-x-2">
                <button onclick="openItemModal()" class="px-3 py-1.5 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white rounded-md font-semibold flex items-center space-x-1.5 shadow-lg shadow-indigo-600/20 transition">
                    <i class="fa-solid fa-plus text-xs"></i>
                    <span>Add Item</span>
                </button>
            </div>
        </header>

        <div class="flex-1 flex overflow-hidden relative">

            <!-- ========================================================= -->
            <!-- VIEW: DASHBOARD (OVERKILL & COLORFUL) -->
            <!-- ========================================================= -->
            <div id="view-dashboard" class="absolute inset-0 overflow-y-auto p-6 space-y-6 bg-zinc-950">
                <div class="flex justify-between items-center bg-gradient-to-r from-indigo-950/40 via-purple-950/20 to-zinc-900/60 p-5 rounded-xl border border-indigo-500/20 shadow-xl">
                    <div class="space-y-1">
                        <div class="flex items-center space-x-2">
                            <span class="px-2 py-0.5 rounded text-[10px] font-mono bg-indigo-500/20 text-indigo-300 border border-indigo-500/30">SYSTEM ONLINE</span>
                            <span class="text-zinc-400 text-[11px]"><i class="fa-regular fa-clock mr-1"></i><?= date('Y-m-d H:i') ?></span>
                        </div>
                        <h1 class="text-lg font-black text-white tracking-tight flex items-center space-x-2">
                            <span>Bibliotech Command Center</span>
                            <i class="fa-solid fa-shield-halved text-indigo-400 text-sm"></i>
                        </h1>
                        <p class="text-zinc-400 text-xs">Analyse globale, métriques de stockage, taux de numérisation et état de santé du catalogue.</p>
                    </div>
                    <button onclick="switchTab('catalog')" class="px-3.5 py-2 bg-zinc-900 border border-zinc-700 hover:bg-zinc-800 text-zinc-200 rounded-lg font-semibold transition flex items-center space-x-2 shadow-md">
                        <i class="fa-solid fa-table-cells text-indigo-400"></i>
                        <span>Ouvrir le Catalogue Complet</span>
                    </button>
                </div>

                <!-- OVERKILL KPI CARDS GRID -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- Total Catalog -->
                    <div class="bg-gradient-to-br from-zinc-900 via-zinc-900 to-indigo-950/30 border border-indigo-500/30 rounded-xl p-4 flex flex-col justify-between shadow-lg relative overflow-hidden group hover:border-indigo-500/60 transition">
                        <div class="absolute -right-4 -bottom-4 w-24 h-24 bg-indigo-500/10 rounded-full blur-xl group-hover:bg-indigo-500/20 transition"></div>
                        <div class="flex items-center justify-between text-zinc-400 mb-2">
                            <span class="font-semibold text-zinc-300 uppercase tracking-wider text-[10px]">Total Catalog Entries</span>
                            <div class="w-8 h-8 rounded-lg bg-indigo-500/20 text-indigo-400 flex items-center justify-center">
                                <i class="fa-solid fa-database"></i>
                            </div>
                        </div>
                        <div class="text-3xl font-black font-mono text-white tracking-tight my-1"><?= number_format($totalRecords) ?></div>
                        <div class="flex items-center justify-between text-[11px] text-zinc-400 pt-2 border-t border-zinc-800/80">
                            <span>Database Capacity</span>
                            <span class="text-indigo-400 font-mono font-bold">Optimal</span>
                        </div>
                    </div>

                    <!-- Physical Holdings -->
                    <div class="bg-gradient-to-br from-zinc-900 via-zinc-900 to-amber-950/30 border border-amber-500/30 rounded-xl p-4 flex flex-col justify-between shadow-lg relative overflow-hidden group hover:border-amber-500/60 transition">
                        <div class="absolute -right-4 -bottom-4 w-24 h-24 bg-amber-500/10 rounded-full blur-xl group-hover:bg-amber-500/20 transition"></div>
                        <div class="flex items-center justify-between text-zinc-400 mb-2">
                            <span class="font-semibold text-zinc-300 uppercase tracking-wider text-[10px]">Physical Assets</span>
                            <div class="w-8 h-8 rounded-lg bg-amber-500/20 text-amber-400 flex items-center justify-center">
                                <i class="fa-solid fa-box-archive"></i>
                            </div>
                        </div>
                        <div class="text-3xl font-black font-mono text-white tracking-tight my-1"><?= number_format($countPhysical) ?></div>
                        <div class="flex items-center justify-between text-[11px] text-zinc-400 pt-2 border-t border-zinc-800/80">
                            <span>Share of Collection</span>
                            <span class="text-amber-400 font-mono font-bold"><?= $physicalPercentage ?>%</span>
                        </div>
                    </div>

                    <!-- Digital E-Books -->
                    <div class="bg-gradient-to-br from-zinc-900 via-zinc-900 to-sky-950/30 border border-sky-500/30 rounded-xl p-4 flex flex-col justify-between shadow-lg relative overflow-hidden group hover:border-sky-500/60 transition">
                        <div class="absolute -right-4 -bottom-4 w-24 h-24 bg-sky-500/10 rounded-full blur-xl group-hover:bg-sky-500/20 transition"></div>
                        <div class="flex items-center justify-between text-zinc-400 mb-2">
                            <span class="font-semibold text-zinc-300 uppercase tracking-wider text-[10px]">Digital E-Books</span>
                            <div class="w-8 h-8 rounded-lg bg-sky-500/20 text-sky-400 flex items-center justify-center">
                                <i class="fa-solid fa-file-pdf"></i>
                            </div>
                        </div>
                        <div class="text-3xl font-black font-mono text-white tracking-tight my-1"><?= number_format($totalDigital) ?></div>
                        <div class="flex items-center justify-between text-[11px] text-zinc-400 pt-2 border-t border-zinc-800/80">
                            <span>Share of Collection</span>
                            <span class="text-sky-400 font-mono font-bold"><?= $digitalPercentage ?>%</span>
                        </div>
                    </div>

                    <!-- Categories Count -->
                    <div class="bg-gradient-to-br from-zinc-900 via-zinc-900 to-emerald-950/30 border border-emerald-500/30 rounded-xl p-4 flex flex-col justify-between shadow-lg relative overflow-hidden group hover:border-emerald-500/60 transition">
                        <div class="absolute -right-4 -bottom-4 w-24 h-24 bg-emerald-500/10 rounded-full blur-xl group-hover:bg-emerald-500/20 transition"></div>
                        <div class="flex items-center justify-between text-zinc-400 mb-2">
                            <span class="font-semibold text-zinc-300 uppercase tracking-wider text-[10px]">Active Categories</span>
                            <div class="w-8 h-8 rounded-lg bg-emerald-500/20 text-emerald-400 flex items-center justify-center">
                                <i class="fa-solid fa-folder-tree"></i>
                            </div>
                        </div>
                        <div class="text-3xl font-black font-mono text-white tracking-tight my-1"><?= number_format($totalCategories) ?></div>
                        <div class="flex items-center justify-between text-[11px] text-zinc-400 pt-2 border-t border-zinc-800/80">
                            <span>Taxonomy Tree</span>
                            <span class="text-emerald-400 font-mono font-bold">Structured</span>
                        </div>
                    </div>
                </div>

                <!-- SECONDARY METRICS & PROGRESS BARS SECTION -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    
                    <!-- Format Breakdown Progress Bars -->
                    <div class="bg-zinc-900/80 border border-zinc-800 rounded-xl p-5 space-y-4 shadow-lg">
                        <div class="flex items-center justify-between pb-2 border-b border-zinc-800">
                            <h2 class="font-bold text-white text-xs flex items-center space-x-2">
                                <i class="fa-solid fa-chart-pie text-purple-400"></i>
                                <span>Répartition des Formats</span>
                            </h2>
                            <span class="text-[10px] font-mono text-zinc-400">Distribution</span>
                        </div>

                        <div class="space-y-3 pt-1">
                            <div>
                                <div class="flex justify-between text-[11px] mb-1">
                                    <span class="text-amber-400 font-semibold flex items-center space-x-1.5"><i class="fa-solid fa-box-archive text-[10px]"></i><span>Physical Books</span></span>
                                    <span class="font-mono text-zinc-300"><?= $countPhysical ?> (<?= $physicalPercentage ?>%)</span>
                                </div>
                                <div class="w-full bg-zinc-950 rounded-full h-2 overflow-hidden border border-zinc-800">
                                    <div class="bg-amber-500 h-full rounded-full transition-all duration-500" style="width: <?= $physicalPercentage ?>%"></div>
                                </div>
                            </div>

                            <div>
                                <div class="flex justify-between text-[11px] mb-1">
                                    <span class="text-sky-400 font-semibold flex items-center space-x-1.5"><i class="fa-solid fa-file-pdf text-[10px]"></i><span>PDF E-Books</span></span>
                                    <span class="font-mono text-zinc-300"><?= $countPdf ?> (<?= $totalRecords > 0 ? round(($countPdf / $totalRecords) * 100, 1) : 0 ?>%)</span>
                                </div>
                                <div class="w-full bg-zinc-950 rounded-full h-2 overflow-hidden border border-zinc-800">
                                    <div class="bg-sky-500 h-full rounded-full transition-all duration-500" style="width: <?= $totalRecords > 0 ? ($countPdf / $totalRecords) * 100 : 0 ?>%"></div>
                                </div>
                            </div>

                            <div>
                                <div class="flex justify-between text-[11px] mb-1">
                                    <span class="text-purple-400 font-semibold flex items-center space-x-1.5"><i class="fa-solid fa-book-bookmark text-[10px]"></i><span>EPUB Files</span></span>
                                    <span class="font-mono text-zinc-300"><?= $countEpub ?> (<?= $totalRecords > 0 ? round(($countEpub / $totalRecords) * 100, 1) : 0 ?>%)</span>
                                </div>
                                <div class="w-full bg-zinc-950 rounded-full h-2 overflow-hidden border border-zinc-800">
                                    <div class="bg-purple-500 h-full rounded-full transition-all duration-500" style="width: <?= $totalRecords > 0 ? ($countEpub / $totalRecords) * 100 : 0 ?>%"></div>
                                </div>
                            </div>

                            <div>
                                <div class="flex justify-between text-[11px] mb-1">
                                    <span class="text-emerald-400 font-semibold flex items-center space-x-1.5"><i class="fa-solid fa-tablet-screen-button text-[10px]"></i><span>MOBI Files</span></span>
                                    <span class="font-mono text-zinc-300"><?= $countMobi ?> (<?= $totalRecords > 0 ? round(($countMobi / $totalRecords) * 100, 1) : 0 ?>%)</span>
                                </div>
                                <div class="w-full bg-zinc-950 rounded-full h-2 overflow-hidden border border-zinc-800">
                                    <div class="bg-emerald-500 h-full rounded-full transition-all duration-500" style="width: <?= $totalRecords > 0 ? ($countMobi / $totalRecords) * 100 : 0 ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Digital Attachment Health Status -->
                    <div class="bg-zinc-900/80 border border-zinc-800 rounded-xl p-5 space-y-4 shadow-lg flex flex-col justify-between">
                        <div>
                            <div class="flex items-center justify-between pb-2 border-b border-zinc-800 mb-4">
                                <h2 class="font-bold text-white text-xs flex items-center space-x-2">
                                    <i class="fa-solid fa-cloud-arrow-up text-indigo-400"></i>
                                    <span>Taux d'Attachement Numérique</span>
                                </h2>
                                <span class="text-[10px] font-mono text-emerald-400 bg-emerald-500/10 px-2 py-0.5 rounded border border-emerald-500/25">Actif</span>
                            </div>

                            <div class="flex items-center justify-center py-4">
                                <div class="relative w-28 h-28 rounded-full bg-zinc-950 border-4 border-indigo-500/30 flex flex-col items-center justify-center shadow-inner">
                                    <span class="text-2xl font-black font-mono text-white"><?= $attachmentRate ?>%</span>
                                    <span class="text-[9px] text-zinc-400 uppercase tracking-widest mt-0.5">Liés au Serveur</span>
                                </div>
                            </div>

                            <div class="space-y-1.5 text-zinc-400 text-[11px] pt-2">
                                <div class="flex justify-between">
                                    <span>Fichiers uploadés :</span>
                                    <strong class="text-white font-mono"><?= $filesAttachedCount ?> / <?= $totalDigital ?></strong>
                                </div>
                                <div class="flex justify-between">
                                    <span>Plage temporelle :</span>
                                    <strong class="text-white font-mono"><?= $oldestYear ?? 'N/A' ?> - <?= $newestYear ?? 'N/A' ?></strong>
                                </div>
                            </div>
                        </div>

                        <div class="bg-indigo-950/40 border border-indigo-500/20 rounded-lg p-2.5 text-[10px] text-indigo-300 flex items-center space-x-2">
                            <i class="fa-solid fa-circle-info text-indigo-400 shrink-0"></i>
                            <span>Les fichiers e-books sont stockés localement dans le répertoire <code class="font-mono text-indigo-200">/uploads/</code>.</span>
                        </div>
                    </div>

                    <!-- Top Categories Widget -->
                    <div class="bg-zinc-900/80 border border-zinc-800 rounded-xl p-5 space-y-4 shadow-lg">
                        <div class="flex items-center justify-between pb-2 border-b border-zinc-800">
                            <h2 class="font-bold text-white text-xs flex items-center space-x-2">
                                <i class="fa-solid fa-tags text-emerald-400"></i>
                                <span class="truncate">Top Catégories Actives</span>
                            </h2>
                            <span class="text-[10px] font-mono text-zinc-400">Classement</span>
                        </div>

                        <div class="space-y-2 pt-1">
                            <?php if (empty($topCategories)): ?>
                                <div class="text-zinc-500 text-center py-6 italic text-xs">Aucune catégorie enregistrée.</div>
                            <?php else: ?>
                                <?php foreach ($topCategories as $cat): ?>
                                <div class="flex items-center justify-between p-2 rounded-lg bg-zinc-950/60 border border-zinc-800/80 hover:border-zinc-700 transition">
                                    <div class="flex items-center space-x-2 truncate">
                                        <i class="fa-solid fa-folder text-sky-400 text-xs shrink-0"></i>
                                        <span class="text-zinc-200 font-medium truncate"><?= htmlspecialchars($cat['category']) ?></span>
                                    </div>
                                    <span class="font-mono text-[11px] px-2 py-0.5 rounded bg-sky-500/10 text-sky-300 border border-sky-500/20"><?= $cat['total'] ?></span>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- RECENT ENTRIES TABLE WIDGET -->
                <div class="bg-zinc-900/80 border border-zinc-800 rounded-xl p-5 space-y-4 shadow-lg">
                    <div class="flex items-center justify-between pb-2 border-b border-zinc-800">
                        <h2 class="font-bold text-white text-xs flex items-center space-x-2">
                            <i class="fa-solid fa-clock-rotate-left text-indigo-400"></i>
                            <span>Dernières Injections au Catalogue</span>
                        </h2>
                        <button onclick="switchTab('catalog')" class="text-indigo-400 hover:text-indigo-300 text-[11px] font-semibold transition">Voir tout &rarr;</button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="text-[10px] font-bold uppercase tracking-wider text-zinc-500 border-b border-zinc-800">
                                    <th class="py-2 px-3 font-mono">Cote</th>
                                    <th class="py-2 px-3">Title</th>
                                    <th class="py-2 px-3">Author</th>
                                    <th class="py-2 px-3">Category</th>
                                    <th class="py-2 px-3 text-center">Format</th>
                                    <th class="py-2 px-3 text-right font-mono">Year</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-800/50 text-zinc-300">
                                <?php if (empty($recentRecords)): ?>
                                <tr>
                                    <td colspan="6" class="py-4 text-center text-zinc-500 italic">Aucun enregistrement récent.</td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($recentRecords as $row): ?>
                                    <tr class="hover:bg-zinc-900 transition">
                                        <td class="py-2 px-3 font-mono text-zinc-400"><span class="px-1.5 py-0.5 rounded bg-zinc-800 border border-zinc-700"><?= htmlspecialchars($row['cote']) ?></span></td>
                                        <td class="py-2 px-3 font-semibold text-white">
                                            <?php if ($row['format'] !== 'physical' && !empty($row['file_url'])): ?>
                                                <a href="<?= htmlspecialchars($row['file_url']) ?>" target="_blank" class="text-indigo-400 hover:underline flex items-center space-x-1">
                                                    <i class="fa-solid fa-arrow-up-right-from-square text-[9px]"></i>
                                                    <span><?= htmlspecialchars($row['title']) ?></span>
                                                </a>
                                            <?php else: ?>
                                                <?= htmlspecialchars($row['title']) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-2 px-3 text-zinc-400"><?= htmlspecialchars($row['author'] ?? 'N/A') ?></td>
                                        <td class="py-2 px-3"><span class="px-2 py-0.5 rounded-full text-[10px] bg-sky-500/10 text-sky-400 border border-sky-500/20"><?= htmlspecialchars($row['category'] ?? 'Uncategorized') ?></span></td>
                                        <td class="py-2 px-3 text-center"><span class="px-1.5 py-0.5 rounded text-[10px] <?= $row['format'] === 'physical' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/20' : 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20' ?>"><?= strtoupper($row['format']) ?></span></td>
                                        <td class="py-2 px-3 text-right font-mono text-zinc-400"><?= $row['pub_year'] ?? 'N/A' ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ========================================================= -->
            <!-- VIEW: MASTER CATALOG -->
            <!-- ========================================================= -->
            <div id="view-catalog" class="hidden absolute inset-0 flex flex-col min-w-0 bg-zinc-950">
                <?php if ($db_error !== null): ?>
                <div class="bg-rose-500/10 border-b border-rose-500/20 p-3 text-rose-400 text-xs flex items-start space-x-2 font-mono">
                    <i class="fa-solid fa-triangle-exclamation mt-0.5 text-rose-500"></i>
                    <div><strong>Database Alert:</strong> <?= htmlspecialchars($db_error) ?></div>
                </div>
                <?php endif; ?>

                <div class="flex-1 overflow-auto">
                    <table class="w-full text-left border-collapse font-sans">
                        <thead class="bg-zinc-900/90 sticky top-0 border-b border-zinc-800 backdrop-blur z-10 text-[11px] font-semibold text-zinc-400">
                            <tr>
                                <th class="py-2 px-3 w-10 text-center"><input type="checkbox" class="rounded border-zinc-800 bg-zinc-950 text-indigo-600"></th>
                                <th class="py-2 px-3 w-28 font-mono">Cote</th>
                                <th class="py-2 px-3">Title</th>
                                <th class="py-2 px-3">Author(s)</th>
                                <th class="py-2 px-3 w-36">Category</th>
                                <th class="py-2 px-3 w-32 font-mono">ISBN</th>
                                <th class="py-2 px-3 w-20 text-center">Format</th>
                                <th class="py-2 px-3 w-16 text-right">Year</th>
                                <th class="py-2 px-3 w-24 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="catalogTableBody" class="divide-y divide-zinc-800/60 font-normal text-zinc-300">
                            <?php if (empty($records)): ?>
                            <tr>
                                <td colspan="9" class="py-8 text-center text-zinc-500 italic">No entries found in catalog.</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($records as $row): ?>
                                <tr class="hover:bg-zinc-900/60 transition group">
                                    <td class="py-2 px-3 text-center"><input type="checkbox" class="rounded border-zinc-800 bg-zinc-950 text-indigo-600"></td>
                                    <td class="py-2 px-3 font-mono text-zinc-400 text-[11px]"><span class="px-1.5 py-0.5 rounded bg-zinc-800 border border-zinc-700/50"><?= htmlspecialchars($row['cote']) ?></span></td>
                                    <td class="py-2 px-3 font-semibold text-zinc-100 group-hover:text-indigo-400 transition">
                                        <?php if ($row['format'] !== 'physical' && !empty($row['file_url'])): ?>
                                            <a href="<?= htmlspecialchars($row['file_url']) ?>" target="_blank" class="hover:underline flex items-center space-x-1.5 text-indigo-400" title="Ouvrir le fichier e-book">
                                                <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                                                <span><?= htmlspecialchars($row['title']) ?></span>
                                            </a>
                                        <?php else: ?>
                                            <?= htmlspecialchars($row['title']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2 px-3 text-zinc-400"><?= htmlspecialchars($row['author'] ?? 'N/A') ?></td>
                                    <td class="py-2 px-3"><span class="inline-block px-2 py-0.5 rounded-full text-[10px] bg-sky-500/10 text-sky-400 border border-sky-500/20 truncate"><?= htmlspecialchars($row['category'] ?? 'Uncategorized') ?></span></td>
                                    <td class="py-2 px-3 font-mono text-zinc-400 text-[11px]"><?= htmlspecialchars($row['isbn'] ?? 'N/A') ?></td>
                                    <td class="py-2 px-3 text-center"><span class="px-1.5 py-0.5 rounded text-[10px] <?= $row['format'] === 'physical' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/20' : 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20' ?>"><?= strtoupper($row['format']) ?></span></td>
                                    <td class="py-2 px-3 text-right font-mono text-zinc-400"><?= $row['pub_year'] ?></td>
                                    <td class="py-2 px-3 text-center space-x-1">
                                        <button onclick='editItem(<?= json_encode($row) ?>)' class="p-1 text-zinc-500 hover:text-indigo-400 transition" title="Edit Entry">
                                            <i class="fa-solid fa-pen-to-square text-xs"></i>
                                        </button>
                                        <button onclick="deleteItem(<?= $row['id'] ?>)" class="p-1 text-zinc-500 hover:text-rose-400 transition" title="Delete Entry">
                                            <i class="fa-solid fa-trash-can text-xs"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <footer class="h-10 border-t border-zinc-800 bg-zinc-900/80 px-4 flex items-center justify-between font-mono text-[11px] text-zinc-400 shrink-0">
                    <div id="paginationSummary">
                        Showing <strong class="text-zinc-200"><?= $filteredRecordsCount > 0 ? $offset + 1 : 0 ?> - <?= min($offset + $limit, $filteredRecordsCount) ?></strong> of <strong class="text-zinc-200"><?= number_format($filteredRecordsCount) ?></strong> catalog entries
                    </div>
                    <div class="flex items-center space-x-2">
                        <button id="prevBtn" onclick="changePage(currentPage - 1)" <?= $page <= 1 ? 'disabled' : '' ?> class="px-2 py-1 bg-zinc-800 rounded hover:bg-zinc-700 disabled:opacity-40 text-zinc-300 transition">Previous</button>
                        <span>Page <strong id="currentPageDisplay" class="text-zinc-200"><?= $page ?></strong> of <span id="totalPagesDisplay"><?= $totalPages ?></span></span>
                        <button id="nextBtn" onclick="changePage(currentPage + 1)" <?= $page >= $totalPages ? 'disabled' : '' ?> class="px-2 py-1 bg-zinc-800 rounded hover:bg-zinc-700 disabled:opacity-40 text-zinc-300 transition">Next</button>
                    </div>
                </footer>
            </div>
        </div>
    </main>

    <!-- ADD / EDIT ITEM & ISBN LOOKUP MODAL -->
    <div id="itemModal" class="hidden fixed inset-0 bg-black/70 backdrop-blur-sm flex items-center justify-center z-50 p-4">
        <div class="bg-zinc-900 border border-zinc-800 w-full max-w-lg rounded-lg shadow-2xl flex flex-col overflow-hidden">
            <div class="px-4 py-3 border-b border-zinc-800 flex justify-between items-center bg-zinc-900/80">
                <span id="modalTitle" class="font-bold text-white text-xs flex items-center space-x-2">
                    <i class="fa-solid fa-book-medical text-indigo-400"></i>
                    <span>Catalog Ingestion & E-Book Upload</span>
                </span>
                <button onclick="closeItemModal()" class="text-zinc-500 hover:text-white transition"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <div class="p-4 space-y-4 overflow-y-auto max-h-[80vh]">
                <div id="isbnSection" class="p-3 bg-indigo-950/30 border border-indigo-500/20 rounded-md space-y-2">
                    <label class="block text-[10px] font-bold uppercase tracking-wider text-indigo-300">Fetch via ISBN (Server Proxy)</label>
                    <div class="flex space-x-2">
                        <input type="text" id="isbnLookupInput" placeholder="Enter ISBN-10 or ISBN-13..." class="flex-1 bg-zinc-950 border border-zinc-800 rounded px-2.5 py-1.5 text-xs text-white placeholder-zinc-500 focus:outline-none focus:border-indigo-500">
                        <button onclick="lookupISBN()" id="isbnFetchBtn" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded font-semibold transition flex items-center space-x-1.5">
                            <i class="fa-solid fa-bolt text-xs"></i>
                            <span>Fetch Data</span>
                        </button>
                    </div>
                    <div id="isbnFetchStatus" class="text-[10px] text-zinc-400 hidden"></div>
                </div>

                <form id="addItemForm" onsubmit="saveItem(event)" enctype="multipart/form-data" class="space-y-3">
                    <input type="hidden" name="id" id="formItemId" value="">
                    
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-zinc-400 mb-1">Cote / Shelf Ref <span class="text-rose-400">*</span></label>
                            <input type="text" name="cote" id="formCote" required placeholder="e.g. INF-2024-001" class="w-full bg-zinc-950 border border-zinc-800 rounded px-2.5 py-1.5 text-xs text-white focus:outline-none focus:border-indigo-500">
                        </div>
                        <div>
                            <label class="block text-zinc-400 mb-1">Format</label>
                            <select name="format" id="formFormat" class="w-full bg-zinc-950 border border-zinc-800 rounded px-2.5 py-1.5 text-xs text-white focus:outline-none focus:border-indigo-500">
                                <option value="physical">Physical Book</option>
                                <option value="pdf">PDF E-Book</option>
                                <option value="epub">EPUB File</option>
                                <option value="mobi">MOBI File</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-zinc-400 mb-1">Book Title <span class="text-rose-400">*</span></label>
                        <input type="text" name="title" id="formTitle" required placeholder="e.g. Clean Code" class="w-full bg-zinc-950 border border-zinc-800 rounded px-2.5 py-1.5 text-xs text-white focus:outline-none focus:border-indigo-500">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-zinc-400 mb-1">Author(s)</label>
                            <input type="text" name="author" id="formAuthor" placeholder="e.g. Robert C. Martin" class="w-full bg-zinc-950 border border-zinc-800 rounded px-2.5 py-1.5 text-xs text-white focus:outline-none focus:border-indigo-500">
                        </div>
                        <div>
                            <label class="block text-zinc-400 mb-1">Category</label>
                            <input type="text" name="category" id="formCategory" placeholder="e.g. Engineering & Tech" class="w-full bg-zinc-950 border border-zinc-800 rounded px-2.5 py-1.5 text-xs text-white focus:outline-none focus:border-indigo-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-zinc-400 mb-1">ISBN</label>
                            <input type="text" name="isbn" id="formIsbn" placeholder="ISBN-10/13" class="w-full bg-zinc-950 border border-zinc-800 rounded px-2.5 py-1.5 text-xs text-white focus:outline-none focus:border-indigo-500">
                        </div>
                        <div>
                            <label class="block text-zinc-400 mb-1">Pub. Year</label>
                            <input type="number" name="pub_year" id="formPubYear" placeholder="2024" class="w-full bg-zinc-950 border border-zinc-800 rounded px-2.5 py-1.5 text-xs text-white focus:outline-none focus:border-indigo-500">
                        </div>
                        <div>
                            <label class="block text-zinc-400 mb-1">Location</label>
                            <input type="text" name="location" id="formLocation" placeholder="Shelf B3" class="w-full bg-zinc-950 border border-zinc-800 rounded px-2.5 py-1.5 text-xs text-white focus:outline-none focus:border-indigo-500">
                        </div>
                    </div>

                    <div>
                        <label class="block text-zinc-400 mb-1">Téléverser le fichier E-Book (PDF, EPUB, MOBI)</label>
                        <input type="file" name="ebook_file" id="formEbookFile" accept=".pdf,.epub,.mobi" class="w-full bg-zinc-950 border border-zinc-800 rounded px-2 py-1 text-xs text-zinc-300 file:mr-4 file:py-1 file:px-3 file:rounded file:border-0 file:text-xs file:font-semibold file:bg-indigo-600 file:text-white hover:file:bg-indigo-500">
                        <div id="currentFileIndicator" class="text-[10px] text-zinc-500 mt-1"></div>
                    </div>

                    <div class="pt-2 border-t border-zinc-800 flex justify-end space-x-2">
                        <button type="button" onclick="closeItemModal()" class="px-3 py-1.5 bg-zinc-800 hover:bg-zinc-700 text-zinc-300 rounded font-semibold transition">Cancel</button>
                        <button type="submit" id="formSubmitBtn" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded font-semibold transition">Save to Catalog</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- JAVASCRIPT CONTROLLERS -->
    <script>
        let currentPage = <?= $page ?>;
        let totalPages = <?= $totalPages ?>;
        let searchDebounceTimer = null;

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        }

        function switchTab(tab) {
            const dashboardView = document.getElementById('view-dashboard');
            const catalogView = document.getElementById('view-catalog');
            const navDashboard = document.getElementById('nav-dashboard');
            const navCatalog = document.getElementById('nav-catalog');

            const activeDashboardClass = "flex items-center justify-between px-2.5 py-1.5 rounded-md bg-gradient-to-r from-indigo-600/20 to-purple-600/10 text-indigo-300 font-semibold border border-indigo-500/30 shadow-sm";
            const activeCatalogClass = "flex items-center justify-between px-2.5 py-1.5 rounded-md bg-indigo-600/10 text-indigo-400 font-semibold border border-indigo-500/20";
            const inactiveClass = "flex items-center justify-between px-2.5 py-1.5 rounded-md text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200 transition";

            if (tab === 'dashboard') {
                dashboardView.classList.remove('hidden');
                catalogView.classList.add('hidden');
                navDashboard.className = activeDashboardClass;
                navCatalog.className = inactiveClass;
            } else {
                dashboardView.classList.add('hidden');
                catalogView.classList.remove('hidden');
                navDashboard.className = inactiveClass;
                navCatalog.className = activeCatalogClass;
            }
        }

        function openItemModal() {
            document.getElementById('formItemId').value = '';
            document.getElementById('addItemForm').reset();
            document.getElementById('currentFileIndicator').innerText = '';
            document.getElementById('modalTitle').innerHTML = `<i class="fa-solid fa-book-medical text-indigo-400"></i><span>Catalog Ingestion & E-Book Upload</span>`;
            document.getElementById('formSubmitBtn').innerText = 'Save to Catalog';
            document.getElementById('isbnSection').style.display = 'block';
            document.getElementById('itemModal').classList.remove('hidden');
        }

        function editItem(row) {
            document.getElementById('formItemId').value = row.id;
            document.getElementById('formCote').value = row.cote || '';
            document.getElementById('formFormat').value = row.format || 'physical';
            document.getElementById('formTitle').value = row.title || '';
            document.getElementById('formAuthor').value = row.author || '';
            document.getElementById('formCategory').value = row.category || '';
            document.getElementById('formIsbn').value = row.isbn || '';
            document.getElementById('formPubYear').value = row.pub_year || '';
            document.getElementById('formLocation').value = row.location || '';
            
            if (row.file_url) {
                document.getElementById('currentFileIndicator').innerHTML = `Fichier actuel : <a href="${row.file_url}" target="_blank" class="text-indigo-400 underline">${row.file_url}</a>`;
            } else {
                document.getElementById('currentFileIndicator').innerText = 'Aucun fichier associé.';
            }

            document.getElementById('modalTitle').innerHTML = `<i class="fa-solid fa-pen-to-square text-indigo-400"></i><span>Edit Catalog Entry #${row.id}</span>`;
            document.getElementById('formSubmitBtn').innerText = 'Update Catalog Entry';
            document.getElementById('isbnSection').style.display = 'none'; 
            document.getElementById('itemModal').classList.remove('hidden');
        }

        function closeItemModal() {
            document.getElementById('itemModal').classList.add('hidden');
            document.getElementById('isbnFetchStatus').classList.add('hidden');
        }

        function triggerLiveSearch() {
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(() => {
                currentPage = 1;
                fetchCatalogData();
            }, 200);
        }

        function changePage(newPage) {
            if (newPage < 1 || newPage > totalPages) return;
            currentPage = newPage;
            fetchCatalogData();
        }

        function filterByFormat(fmt) {
            switchTab('catalog');
            document.getElementById('formatFilter').value = fmt;
            triggerLiveSearch();
        }

        function fetchCatalogData() {
            const q = document.getElementById('searchInput').value;
            const format = document.getElementById('formatFilter').value;
            const url = `index.php?ajax=1&q=${encodeURIComponent(q)}&format=${encodeURIComponent(format)}&page=${currentPage}`;

            fetch(url)
                .then(res => res.json())
                .then(data => {
                    renderTableRows(data.records);
                    updatePaginationUI(data.totalRecords, data.page, data.totalPages);
                })
                .catch(err => console.error('Data Fetch Error:', err));
        }

        function renderTableRows(records) {
            const tbody = document.getElementById('catalogTableBody');
            if (!records || records.length === 0) {
                tbody.innerHTML = `<tr><td colspan="9" class="py-8 text-center text-zinc-500 italic">No entries found matching current query filters.</td></tr>`;
                return;
            }

            tbody.innerHTML = records.map(row => `
                <tr class="hover:bg-zinc-900/65 transition group">
                    <td class="py-2 px-3 text-center"><input type="checkbox" class="rounded border-zinc-800 bg-zinc-950 text-indigo-600"></td>
                    <td class="py-2 px-3 font-mono text-zinc-400 text-[11px]"><span class="px-1.5 py-0.5 rounded bg-zinc-800 border border-zinc-700/50">${escapeHtml(row.cote || '')}</span></td>
                    <td class="py-2 px-3 font-semibold text-zinc-100 group-hover:text-indigo-400 transition">
                        ${(row.format !== 'physical' && row.file_url) ? `
                            <a href="${escapeHtml(row.file_url)}" target="_blank" class="hover:underline flex items-center space-x-1.5 text-indigo-400" title="Ouvrir le fichier e-book">
                                <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                                <span>${escapeHtml(row.title || '')}</span>
                            </a>
                        ` : escapeHtml(row.title || '')}
                    </td>
                    <td class="py-2 px-3 text-zinc-400">${escapeHtml(row.author || 'N/A')}</td>
                    <td class="py-2 px-3"><span class="inline-block px-2 py-0.5 rounded-full text-[10px] bg-sky-500/10 text-sky-400 border border-sky-500/20 truncate">${escapeHtml(row.category || 'Uncategorized')}</span></td>
                    <td class="py-2 px-3 font-mono text-zinc-400 text-[11px]">${escapeHtml(row.isbn || 'N/A')}</td>
                    <td class="py-2 px-3 text-center"><span class="px-1.5 py-0.5 rounded text-[10px] ${row.format === 'physical' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/20' : 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20'}">${(row.format || 'physical').toUpperCase()}</span></td>
                    <td class="py-2 px-3 text-right font-mono text-zinc-400">${row.pub_year || 'N/A'}</td>
                    <td class="py-2 px-3 text-center space-x-1">
                        <button onclick='editItem(${JSON.stringify(row)})' class="p-1 text-zinc-500 hover:text-indigo-400 transition" title="Edit Entry">
                            <i class="fa-solid fa-pen-to-square text-xs"></i>
                        </button>
                        <button onclick="deleteItem(${row.id})" class="p-1 text-zinc-500 hover:text-rose-400 transition" title="Delete Entry">
                            <i class="fa-solid fa-trash-can text-xs"></i>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        function updatePaginationUI(total, page, totalP) {
            currentPage = page;
            totalPages = totalP;
            const limit = 50;
            const start = total > 0 ? ((page - 1) * limit) + 1 : 0;
            const end = Math.min(page * limit, total);

            document.getElementById('paginationSummary').innerHTML = `Showing <strong class="text-zinc-200">${start} - ${end}</strong> of <strong class="text-zinc-200">${total.toLocaleString()}</strong> catalog entries`;
            document.getElementById('sidebar-total-badge').innerText = total.toLocaleString();
            document.getElementById('currentPageDisplay').innerText = page;
            document.getElementById('totalPagesDisplay').innerText = totalPages;

            document.getElementById('prevBtn').disabled = (page <= 1);
            document.getElementById('nextBtn').disabled = (page >= totalPages);
        }

        async function lookupISBN() {
            const input = document.getElementById('isbnLookupInput');
            const status = document.getElementById('isbnFetchStatus');
            const btn = document.getElementById('isbnFetchBtn');
            const isbn = input.value.trim().replace(/[- ]/g, '');

            if (!isbn) {
                status.innerText = "Please enter a valid ISBN code.";
                status.className = "text-[10px] text-amber-400 block";
                return;
            }

            status.innerText = "Fetching metadata via server proxy...";
            status.className = "text-[10px] text-indigo-400 block";
            btn.disabled = true;

            try {
                const response = await fetch(`index.php?action=lookup_isbn&isbn=${encodeURIComponent(isbn)}`);
                const res = await response.json();

                if (res.success) {
                    document.getElementById('formTitle').value = res.title || '';
                    document.getElementById('formAuthor').value = res.author || '';
                    document.getElementById('formIsbn').value = res.isbn || isbn;
                    if (res.pub_year) document.getElementById('formPubYear').value = res.pub_year;
                    if (res.category) document.getElementById('formCategory').value = res.category;

                    if (!document.getElementById('formCote').value) {
                        const prefix = (res.title || 'LIB').substring(0, 3).toUpperCase();
                        const rand = Math.floor(100 + Math.random() * 900);
                        document.getElementById('formCote').value = `${prefix}-${rand}`;
                    }

                    status.innerText = "Metadata successfully fetched and pre-filled!";
                    status.className = "text-[10px] text-emerald-400 block";
                } else {
                    status.innerText = res.error || "No record found for this ISBN.";
                    status.className = "text-[10px] text-rose-400 block";
                }
            } catch (err) {
                status.innerText = "Server communication error. Check PHP connection.";
                status.className = "text-[10px] text-rose-400 block";
            } finally {
                btn.disabled = false;
            }
        }

        function saveItem(e) {
            e.preventDefault();
            const form = document.getElementById('addItemForm');
            const formData = new FormData(form);
            const id = document.getElementById('formItemId').value;

            const action = id ? 'update_item' : 'save_item';
            formData.append('action', action);

            fetch('index.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(res => {
                    if (res.success) {
                        closeItemModal();
                        form.reset();
                        location.reload(); 
                    } else {
                        alert('Error saving record: ' + res.error);
                    }
                })
                .catch(err => console.error('Save Exception:', err));
        }

        function deleteItem(id) {
            if (!confirm("Are you sure you want to delete this catalog entry?")) return;

            const formData = new FormData();
            formData.append('action', 'delete_item');
            formData.append('id', id);

            fetch('index.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(res => {
                    if (res.success) {
                        location.reload();
                    } else {
                        alert('Error deleting record: ' + res.error);
                    }
                })
                .catch(err => console.error('Delete Exception:', err));
        }

        function escapeHtml(str) {
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }
    </script>
</body>
</html>