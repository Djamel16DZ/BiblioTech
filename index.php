<?php
/**
 * BiblioTech - Full-Stack Digital Library Application (Single-File PHP / MariaDB)
 * Complete Implementation: Steps 1 through 5
 */

// -----------------------------------------------------------------------------
// 1. CONFIGURATION & DATABASE INITIALIZATION
// -----------------------------------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'bibliotheque_db');
define('DB_USER', 'root');
define('DB_PASS', 'root');
define('DB_CHARSET', 'utf8mb4');
define('UPLOAD_DIR', __DIR__ . '/uploads/books/');

// Ensure upload directory exists
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// Database Connection & Auto-Migration
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

    // Auto-create table if not existing
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS livres (
            id INT AUTO_INCREMENT PRIMARY KEY,
            titre VARCHAR(255) NOT NULL,
            auteur VARCHAR(255) NOT NULL,
            isbn VARCHAR(50) DEFAULT NULL,
            cote VARCHAR(50) DEFAULT NULL,
            editeur VARCHAR(100) DEFAULT NULL,
            annee INT DEFAULT NULL,
            format VARCHAR(10) NOT NULL,
            categorie VARCHAR(100) NOT NULL,
            pages INT DEFAULT NULL,
            resume TEXT DEFAULT NULL,
            fichier VARCHAR(255) NOT NULL,
            fulltext_content LONGTEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_isbn (isbn),
            INDEX idx_cote (cote),
            INDEX idx_format (format),
            INDEX idx_categorie (categorie)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (\PDOException $e) {
    die("Database Connection Error: " . htmlspecialchars($e->getMessage()));
}

// -----------------------------------------------------------------------------
// 2. HELPER FUNCTIONS
// -----------------------------------------------------------------------------

/**
 * Extracts raw uncompressed text streams from uploaded PDF files.
 */
function extractTextFromPdf(string $filePath): string {
    if (!file_exists($filePath)) return '';
    $content = @file_get_contents($filePath);
    if (!$content) return '';

    $text = '';
    preg_match_all('/stream[\r\n]+(.*?)[\r\n]+endstream/s', $content, $matches);
    
    foreach ($matches[1] as $stream) {
        $data = @gzuncompress($stream);
        if ($data === false) {
            $data = $stream;
        }
        
        preg_match_all('/(?:\[(.*?)\]\s*TJ|\((.*?)\)\s*Tj)/s', $data, $textMatches);
        foreach ($textMatches[0] as $tm) {
            preg_match_all('/\((.*?)\)/s', $tm, $strMatches);
            foreach ($strMatches[1] as $str) {
                $text .= $str . ' ';
            }
        }
    }

    $text = preg_replace('/[^\x20-\x7E\x0A\x0D]/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

/**
 * Performs asynchronous ISBN lookup via Google Books and Open Library APIs.
 */
function lookupISBN(string $isbn): array {
    $cleanIsbn = preg_replace('/[^0-9X]/i', '', $isbn);
    if (empty($cleanIsbn)) return [];

    // Attempt 1: Google Books API
    $googleUrl = "https://www.googleapis.com/books/v1/volumes?q=isbn:" . urlencode($cleanIsbn);
    $response = @file_get_contents($googleUrl);
    if ($response) {
        $data = json_decode($response, true);
        if (!empty($data['items'][0]['volumeInfo'])) {
            $info = $data['items'][0]['volumeInfo'];
            return [
                'titre'     => $info['title'] ?? '',
                'auteur'    => isset($info['authors']) ? implode(', ', $info['authors']) : '',
                'editeur'   => $info['publisher'] ?? '',
                'annee'     => isset($info['publishedDate']) ? (int)substr($info['publishedDate'], 0, 4) : null,
                'pages'     => $info['pageCount'] ?? null,
                'resume'    => $info['description'] ?? '',
                'categorie' => isset($info['categories']) ? $info['categories'][0] : 'General'
            ];
        }
    }

    // Attempt 2: Open Library API Fallback
    $openLibUrl = "https://openlibrary.org/api/books?bibkeys=ISBN:" . urlencode($cleanIsbn) . "&jscmd=data&format=json";
    $response = @file_get_contents($openLibUrl);
    if ($response) {
        $data = json_decode($response, true);
        $key = "ISBN:" . $cleanIsbn;
        if (!empty($data[$key])) {
            $info = $data[$key];
            $authors = [];
            if (!empty($info['authors'])) {
                foreach ($info['authors'] as $auth) {
                    $authors[] = $auth['name'];
                }
            }
            return [
                'titre'     => $info['title'] ?? '',
                'auteur'    => implode(', ', $authors),
                'editeur'   => isset($info['publishers'][0]['name']) ?$info['publishers'][0]['name'] : '',
                'annee'     => isset($info['publish_date']) ? (int)preg_replace('/[^0-9]/', '',$info['publish_date']) : null,
                'pages'     => $info['number_of_pages'] ?? null,
                'resume'    => '',
                'categorie' => isset($info['subjects'][0]['name']) ?$info['subjects'][0]['name'] : 'General'
            ];
        }
    }

    return [];
}

// -----------------------------------------------------------------------------
// 3. ROUTING & CONTROLLER ACTIONS
// -----------------------------------------------------------------------------

// API Endpoint: Async ISBN Lookup (AJAX)
if (isset($_GET['action']) &&$_GET['action'] === 'lookup_isbn') {
    header('Content-Type: application/json');
    $isbn =$_GET['isbn'] ?? '';
    $data = lookupISBN($isbn);
    echo json_encode($data);
    exit;
}

// Controller Action: Handle Book Upload (POST)
$flashMessage = '';$flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_book'])) {$titre     = trim($_POST['titre'] ?? '');$auteur    = trim($_POST['auteur'] ?? '');$isbn      = trim($_POST['isbn'] ?? '');$cote      = trim($_POST['cote'] ?? '');$editeur   = trim($_POST['editeur'] ?? '');$annee     = !empty($_POST['annee']) ? (int)$_POST['annee'] : null;
    $categorie = trim($_POST['categorie'] ?? 'General');$pages     = !empty($_POST['pages']) ? (int)$_POST['pages'] : null;
    $resume    = trim($_POST['resume'] ?? '');

    if (empty($titre) || empty($auteur) || !isset($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {$flashMessage = "Please complete all required fields and select a valid file.";
        $flashType = "error";
    } else {
        $file = $_FILES['fichier'];$finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType =$finfo->file($file['tmp_name']);$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));$allowedExtensions = ['pdf', 'epub', 'mobi'];
        
        if (!in_array($extension, $allowedExtensions)) {$flashMessage = "Invalid file type. Only PDF, EPUB, and MOBI files are accepted.";
            $flashType = "error";
        } else {
            // Cryptographically secure filename generation
            $newFileName = bin2hex(random_bytes(16)) . '.' .$extension;
            $targetPath = UPLOAD_DIR .$newFileName;

            if (move_uploaded_file($file['tmp_name'],$targetPath)) {
                // Extract PDF full-text content if applicable
                $extractedText = '';
                if ($extension === 'pdf') {
                    $extractedText = extractTextFromPdf($targetPath);
                }

                $stmt =$pdo->prepare("
                    INSERT INTO livres (titre, auteur, isbn, cote, editeur, annee, format, categorie, pages, resume, fichier, fulltext_content)
                    VALUES (:titre, :auteur, :isbn, :cote, :editeur, :annee, :format, :categorie, :pages, :resume, :fichier, :fulltext_content)
                ");
                
                $stmt->execute([
                    ':titre'            => $titre,
                    ':auteur'           => $auteur,
                    ':isbn'             => $isbn,
                    ':cote'             => $cote,
                    ':editeur'          => $editeur,
                    ':annee'            => $annee,
                    ':format'           => $extension,
                    ':categorie'        => $categorie,
                    ':pages'            => $pages,
                    ':resume'           => $resume,
                    ':fichier'          => $newFileName,
                    ':fulltext_content' => $extractedText
                ]);

                $flashMessage = "Publication successfully indexed into digital library.";
                $flashType = "success";
            } else {
                $flashMessage = "Failed to store uploaded publication on server.";
                $flashType = "error";
            }
        }
    }
}

// Query Execution: Catalog Fetching & Full-Text Search
$searchQuery = trim($_GET['q'] ?? '');
$categoryFilter = trim($_GET['cat'] ?? '');
$formatFilter = trim($_GET['fmt'] ?? '');

$sql = "SELECT * FROM livres WHERE 1=1";
$params = [];

if (!empty($searchQuery)) {
    // Attempt Full-Text Match query with fallback to LIKE operator
    $sql .= " AND (
        MATCH(titre, auteur, resume, fulltext_content) AGAINST (:searchInBool IN BOOLEAN MODE)
        OR titre LIKE :searchLike 
        OR auteur LIKE :searchLike 
        OR isbn LIKE :searchLike 
        OR cote LIKE :searchLike
    )";
    $params[':searchInBool'] =$searchQuery . '*';
    $params[':searchLike'] = '%' .$searchQuery . '%';
}

if (!empty($categoryFilter)) {$sql .= " AND categorie = :categorie";
    $params[':categorie'] =$categoryFilter;
}

if (!empty($formatFilter)) {$sql .= " AND format = :format";
    $params[':format'] =$formatFilter;
}

$sql .= " ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$books =$stmt->fetchAll();

// Fetch filter values
$categories =$pdo->query("SELECT DISTINCT categorie FROM livres WHERE categorie IS NOT NULL AND categorie != '' ORDER BY categorie ASC")->fetchAll(PDO::FETCH_COLUMN);
$formats =$pdo->query("SELECT DISTINCT format FROM livres WHERE format IS NOT NULL AND format != '' ORDER BY format ASC")->fetchAll(PDO::FETCH_COLUMN);

?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BiblioTech - Digital Library System</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- FontAwesome CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- ePub.js & JSZip CDNs -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/epubjs/dist/epub.min.js"></script>
</head>
<body class="h-full flex flex-col font-sans text-slate-800">

    <!-- Top Navigation Header -->
    <header class="bg-slate-900 text-white shadow-md sticky top-0 z-30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <i class="fa-solid fa-book-bookmark text-indigo-400 text-2xl"></i>
                <span class="text-xl font-bold tracking-tight">BiblioTech</span>
            </div>
            <button onclick="toggleDrawer()" class="bg-indigo-600 hover:bg-indigo-500 text-white px-4 py-2 rounded-lg text-sm font-medium transition flex items-center shadow-sm">
                <i class="fa-solid fa-plus mr-2"></i> Add Publication
            </button>
        </div>
    </header>

    <div class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 flex gap-8">
        
        <!-- Sidebar Filters -->
        <aside class="w-64 flex-shrink-0 hidden md:block">
            <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 sticky top-24">
                <h3 class="font-bold text-slate-900 mb-4 flex items-center text-sm uppercase tracking-wider">
                    <i class="fa-solid fa-filter mr-2 text-indigo-500"></i> Catalog Filters
                </h3>
                <form method="GET" action="index.php" class="space-y-4">
                    <?php if (!empty($searchQuery)): ?>
                        <input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery) ?>">
                    <?php endif; ?>

                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase mb-2">Category</label>
                        <select name="cat" onchange="this.form.submit()" class="w-full bg-slate-50 border border-slate-300 rounded-lg p-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as$cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>" <?= $categoryFilter ===$cat ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase mb-2">Format</label>
                        <select name="fmt" onchange="this.form.submit()" class="w-full bg-slate-50 border border-slate-300 rounded-lg p-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                            <option value="">All Formats</option>
                            <?php foreach ($formats as$fmt): ?>
                                <option value="<?= htmlspecialchars($fmt) ?>" <?= $formatFilter ===$fmt ? 'selected' : '' ?>>
                                    <?= strtoupper(htmlspecialchars($fmt)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if (!empty($categoryFilter) || !empty($formatFilter) || !empty($searchQuery)): ?>
                        <a href="index.php" class="block text-center text-xs text-indigo-600 hover:text-indigo-800 font-medium pt-2">
                            Clear all filters
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </aside>

        <!-- Main Content View -->
        <main class="flex-1">
            <!-- Search & Control Bar -->
            <div class="bg-white p-4 rounded-xl shadow-sm border border-slate-200 mb-6 flex flex-col sm:flex-row gap-4 items-center justify-between">
                <form method="GET" action="index.php" class="relative w-full">
                    <?php if (!empty($categoryFilter)): ?><input type="hidden" name="cat" value="<?= htmlspecialchars($categoryFilter) ?>"><?php endif; ?>
                    <?php if (!empty($formatFilter)): ?><input type="hidden" name="fmt" value="<?= htmlspecialchars($formatFilter) ?>"><?php endif; ?>
                    <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
                    <input type="text" name="q" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Full-text search by title, author, contents, or cote..." class="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                </form>
            </div>

            <!-- Flash Notifications -->
            <?php if (!empty($flashMessage)): ?>
                <div class="p-4 mb-6 rounded-xl text-sm font-medium border flex items-center justify-between <?= $flashType === 'success' ? 'bg-emerald-50 text-emerald-800 border-emerald-200' : 'bg-rose-50 text-rose-800 border-rose-200' ?>">
                    <div class="flex items-center">
                        <i class="fa-solid <?= $flashType === 'success' ? 'fa-circle-check text-emerald-500' : 'fa-circle-exclamation text-rose-500' ?> mr-3 text-lg"></i>
                        <?= htmlspecialchars($flashMessage) ?>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark"></i></button>
                </div>
            <?php endif; ?>

            <!-- Digital Books Grid -->
            <?php if (empty($books)): ?>
                <div class="bg-white rounded-xl border border-slate-200 p-12 text-center">
                    <i class="fa-solid fa-folder-open text-4xl text-slate-300 mb-3 block"></i>
                    <h4 class="text-slate-700 font-semibold mb-1">No publications located</h4>
                    <p class="text-slate-500 text-sm">Try clearing search parameters or indexing new materials.</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($books as$book): ?>
                        <div class="bg-white rounded-xl border border-slate-200 shadow-sm hover:shadow-md transition flex flex-col justify-between overflow-hidden">
                            <div class="p-5">
                                <div class="flex items-start justify-between mb-3">
                                    <span class="inline-block px-2.5 py-1 text-xs font-bold rounded-md uppercase tracking-wider <?= $book['format'] === 'pdf' ? 'bg-rose-100 text-rose-700' : ($book['format'] === 'epub' ? 'bg-indigo-100 text-indigo-700' : 'bg-amber-100 text-amber-700') ?>">
                                        <?= htmlspecialchars($book['format']) ?>
                                    </span>
                                    <?php if (!empty($book['cote'])): ?>
                                        <span class="text-xs font-mono bg-slate-100 text-slate-600 px-2 py-0.5 rounded border border-slate-200">
                                            <?= htmlspecialchars($book['cote']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <h4 class="font-bold text-slate-900 text-base mb-1 line-clamp-2"><?= htmlspecialchars($book['titre']) ?></h4>
                                <p class="text-slate-600 text-sm mb-3"><i class="fa-regular fa-user mr-1 text-slate-400"></i> <?= htmlspecialchars($book['auteur']) ?></p>
                                
                                <div class="text-xs text-slate-500 space-y-1 mb-4 border-t border-slate-100 pt-3">
                                    <?php if (!empty($book['categorie'])): ?><div><span class="font-semibold text-slate-600">Category:</span> <?= htmlspecialchars($book['categorie']) ?></div><?php endif; ?>
                                    <?php if (!empty($book['editeur'])): ?><div><span class="font-semibold text-slate-600">Publisher:</span> <?= htmlspecialchars($book['editeur']) ?> (<?= htmlspecialchars($book['annee'] ?? 'N/A') ?>)</div><?php endif; ?>
                                    <?php if (!empty($book['isbn'])): ?><div><span class="font-semibold text-slate-600">ISBN:</span> <?= htmlspecialchars($book['isbn']) ?></div><?php endif; ?>
                                </div>

                                <?php if (!empty($book['resume'])): ?>
                                    <p class="text-slate-600 text-xs line-clamp-3 bg-slate-50 p-2.5 rounded-lg border border-slate-100 italic">
                                        "<?= htmlspecialchars($book['resume']) ?>"
                                    </p>
                                <?php endif; ?>
                            </div>

                            <div class="bg-slate-50 px-5 py-3 border-t border-slate-100 flex items-center justify-between">
                                <span class="text-xs text-slate-400">
                                    <?= !empty($book['pages']) ? htmlspecialchars($book['pages']) . ' pages' : '' ?>
                                </span>
                                <button onclick="openReaderModal('uploads/books/<?= urlencode($book['fichier']) ?>', '<?= htmlspecialchars($book['format']) ?>', '<?= htmlspecialchars(addslashes($book['titre'])) ?>')" class="bg-slate-900 hover:bg-slate-800 text-white text-xs font-medium px-3.5 py-1.5 rounded-lg transition flex items-center">
                                    <i class="fa-solid fa-book-open mr-1.5"></i> Read
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Side Drawer Form for Book Upload -->
    <div id="drawer-overlay" onclick="toggleDrawer()" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40 hidden transition-opacity"></div>
    <div id="drawer-panel" class="fixed right-0 top-0 h-full w-full max-w-md bg-white shadow-2xl z-50 transform translate-x-full transition-transform duration-300 ease-in-out flex flex-col">
        <div class="p-6 bg-slate-900 text-white flex items-center justify-between">
            <h3 class="font-bold text-lg flex items-center"><i class="fa-solid fa-plus-circle mr-2 text-indigo-400"></i> Add Publication</h3>
            <button onclick="toggleDrawer()" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark text-xl"></i></button>
        </div>
        
        <form method="POST" action="index.php" enctype="multipart/form-data" class="p-6 flex-1 overflow-y-auto space-y-4">
            <input type="hidden" name="add_book" value="1">
            
            <!-- ISBN Auto Lookup Field -->
            <div class="bg-indigo-50 p-4 rounded-xl border border-indigo-100 mb-4">
                <label class="block text-xs font-bold text-indigo-900 uppercase mb-1">ISBN Auto-Fill Lookup</label>
                <div class="flex gap-2">
                    <input type="text" id="isbn-input" placeholder="e.g. 9780131103627" class="flex-1 bg-white border border-indigo-200 rounded-lg px-3 py-1.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500">
                    <button type="button" onclick="fetchISBNData()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded-lg text-xs font-semibold transition">
                        Fetch
                    </button>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Title *</label>
                <input type="text" name="titre" id="field-titre" required class="w-full border border-slate-300 rounded-lg p-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Author *</label>
                <input type="text" name="auteur" id="field-auteur" required class="w-full border border-slate-300 rounded-lg p-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">ISBN</label>
                    <input type="text" name="isbn" id="field-isbn" class="w-full border border-slate-300 rounded-lg p-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Cote (Shelf Mark)</label>
                    <input type="text" name="cote" id="field-cote" placeholder="e.g. QA76.73" class="w-full border border-slate-300 rounded-lg p-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Publisher</label>
                    <input type="text" name="editeur" id="field-editeur" class="w-full border border-slate-300 rounded-lg p-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Year</label>
                    <input type="number" name="annee" id="field-annee" class="w-full border border-slate-300 rounded-lg p-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Category</label>
                    <input type="text" name="categorie" id="field-categorie" placeholder="e.g. Software" class="w-full border border-slate-300 rounded-lg p-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Pages</label>
                    <input type="number" name="pages" id="field-pages" class="w-full border border-slate-300 rounded-lg p-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Summary / Abstract</label>
                <textarea name="resume" id="field-resume" rows="3" class="w-full border border-slate-300 rounded-lg p-2 text-sm outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Digital File (PDF, EPUB, MOBI) *</label>
                <input type="file" name="fichier" accept=".pdf,.epub,.mobi" required class="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
            </div>

            <div class="pt-4 border-t border-slate-200">
                <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2.5 rounded-lg shadow-sm transition">
                    Save Publication
                </button>
            </div>
        </form>
    </div>

    <!-- Integrated Reader Modal -->
    <div id="reader-modal" class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-50 hidden flex flex-col p-4 sm:p-6">
        <div class="bg-slate-900 text-white p-4 rounded-t-xl flex items-center justify-between border-b border-slate-800">
            <h3 id="modal-title" class="font-bold text-base truncate max-w-xl">Document Reader</h3>
            <button onclick="closeReaderModal()" class="text-slate-400 hover:text-white p-1"><i class="fa-solid fa-xmark text-xl"></i></button>
        </div>
        
        <div class="bg-slate-950 flex-1 rounded-b-xl overflow-hidden relative flex items-center justify-center">
            <!-- PDF Direct Stream Viewer -->
            <iframe id="pdf-frame" class="w-full h-full border-0 hidden"></iframe>
            
            <!-- EPUB ePub.js Rendering Canvas -->
            <div id="epub-viewer" class="w-full h-full bg-white hidden"></div>

            <!-- Fallback Interface (MOBI / Direct Download) -->
            <div id="fallback-view" class="text-center p-8 hidden">
                <i class="fa-solid fa-file-arrow-down text-5xl text-indigo-400 mb-4 block"></i>
                <h4 class="text-white font-bold mb-2">In-Browser Preview Unavailable</h4>
                <p class="text-slate-400 text-sm mb-6 max-w-md">This publication format cannot be rendered dynamically in-browser. Download file directly to view locally.</p>
                <a id="download-link" href="#" download class="inline-flex items-center bg-indigo-600 hover:bg-indigo-500 text-white px-5 py-2.5 rounded-lg font-medium text-sm transition">
                    <i class="fa-solid fa-download mr-2"></i> Download File
                </a>
            </div>
        </div>
    </div>

    <!-- JavaScript Controller Logic -->
    <script>
        let ePubRendition = null;

        function toggleDrawer() {
            const overlay = document.getElementById('drawer-overlay');
            const panel = document.getElementById('drawer-panel');
            
            if (panel.classList.contains('translate-x-full')) {
                overlay.classList.remove('hidden');
                panel.classList.remove('translate-x-full');
            } else {
                overlay.classList.add('hidden');
                panel.classList.add('translate-x-full');
            }
        }

        async function fetchISBNData() {
            const isbn = document.getElementById('isbn-input').value.trim();
            if (!isbn) return;

            try {
                const response = await fetch(`index.php?action=lookup_isbn&isbn=${encodeURIComponent(isbn)}`);
                const data = await response.json();

                if (data && data.titre) {
                    document.getElementById('field-titre').value = data.titre || '';
                    document.getElementById('field-auteur').value = data.auteur || '';
                    document.getElementById('field-isbn').value = isbn;
                    document.getElementById('field-editeur').value = data.editeur || '';
                    document.getElementById('field-annee').value = data.annee || '';
                    document.getElementById('field-categorie').value = data.categorie || '';
                    document.getElementById('field-pages').value = data.pages || '';
                    document.getElementById('field-resume').value = data.resume || '';
                } else {
                    alert('Publication details not found for provided ISBN.');
                }
            } catch (err) {
                alert('An error occurred while communicating with lookup service.');
            }
        }

        function openReaderModal(filePath, format, title) {
            document.getElementById('modal-title').innerText = title;
            const pdfIframe = document.getElementById('pdf-frame');
            const epubContainer = document.getElementById('epub-viewer');
            const fallbackContainer = document.getElementById('fallback-view');

            // Hide previous instances
            pdfIframe.classList.add('hidden');
            epubContainer.classList.add('hidden');
            fallbackContainer.classList.add('hidden');

            if (format === 'pdf') {
                pdfIframe.src = filePath;
                pdfIframe.classList.remove('hidden');
            } else if (format === 'epub') {
                epubContainer.classList.remove('hidden');
                epubContainer.innerHTML = '';
                
                const book = ePub(filePath);
                ePubRendition = book.renderTo("epub-viewer", {
                    width: "100%",
                    height: "100%",
                    spread: "always"
                });
                ePubRendition.display();
            } else {
                document.getElementById('download-link').href = filePath;
                fallbackContainer.classList.remove('hidden');
            }

            document.getElementById('reader-modal').classList.remove('hidden');
        }

        function closeReaderModal() {
            document.getElementById('reader-modal').classList.add('hidden');
            document.getElementById('pdf-frame').src = '';
            document.getElementById('epub-viewer').innerHTML = '';
            ePubRendition = null;
        }
    </script>
</body>
</html>