<?php
// =============================================================================
// BIBLIOTECH - DOCUMENT & CATALOG MANAGEMENT SYSTEM (SINGLE FILE)
// =============================================================================

// -----------------------------------------------------------------------------
// 1. CONFIGURATION & DATABASE CONNECTION
// -----------------------------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', 0); // Kept hidden for production/error handling

$dbHost = 'localhost';
$dbName = 'bibliotheque_db';
$dbUser = 'root';
$dbPass = 'root';
$dbCharset = 'utf8mb4';

$dsn = "mysql:host={$dbHost};dbname={$dbName};charset={$dbCharset}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false, // Native prepared statements
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (\PDOException $e) {
    http_response_code(500);
    die("Database Connection Error: " . htmlspecialchars($e->getMessage()));
}

// -----------------------------------------------------------------------------
// 2. HELPER FUNCTIONS
// -----------------------------------------------------------------------------
function sanitizeInput($data) {
    return htmlspecialchars(trim($data ?? ''), ENT_QUOTES, 'UTF-8');
}

function extractFileText($filePath, $extension) {
    $text = '';
    if ($extension === 'txt') {
        $text = @file_get_contents($filePath);
    } elseif ($extension === 'pdf') {
        // Attempt pdftotext extraction if installed on host
        $output = [];
        $cmd = "pdftotext " . escapeshellarg($filePath) . " -";
        @exec($cmd, $output);
        $text = implode("\n", $output);
    }
    return $text;
}

// -----------------------------------------------------------------------------
// 3. ACTION HANDLING (DOCUMENT UPLOAD & ENTRY)
// -----------------------------------------------------------------------------
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_add_book'])) {
    $titre     = sanitizeInput($_POST['titre'] ?? '');
    $auteur    = sanitizeInput($_POST['auteur'] ?? '');
    $isbn      = sanitizeInput($_POST['isbn'] ?? '');
    $cote      = sanitizeInput($_POST['cote'] ?? '');
    $categorie = sanitizeInput($_POST['categorie'] ?? '');
    $format    = sanitizeInput($_POST['format'] ?? 'PDF');
    $resume    = sanitizeInput($_POST['resume'] ?? '');

    $fulltextContent = '';
    $filePath = null;

    // Handle File Upload
    if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $fileTmpPath = $_FILES['document_file']['tmp_name'];
        $fileName    = $_FILES['document_file']['name'];
        $fileExt     = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $allowedExts = ['pdf', 'txt', 'epub', 'docx'];
        if (in_array($fileExt, $allowedExts)) {
            $newFileName = md5(time() . $fileName) . '.' . $fileExt;
            $destPath = $uploadDir . $newFileName;

            if (move_uploaded_file($fileTmpPath, $destPath)) {
                $filePath = 'uploads/' . $newFileName;
                $fulltextContent = extractFileText($destPath, $fileExt);
            }
        }
    }

    if (!empty($titre)) {
        try {
            $insertSql = "INSERT INTO livres (titre, auteur, isbn, cote, categorie, format, resume, fichier_path, fulltext_content, created_at) 
                          VALUES (:titre, :auteur, :isbn, :cote, :categorie, :format, :resume, :fichier_path, :fulltext_content, NOW())";
            $stmt = $pdo->prepare($insertSql);
            $stmt->execute([
                ':titre'            => $titre,
                ':auteur'           => $auteur,
                ':isbn'             => $isbn,
                ':cote'             => $cote,
                ':categorie'        => $categorie,
                ':format'           => $format,
                ':resume'           => $resume,
                ':fichier_path'     => $filePath,
                ':fulltext_content' => $fulltextContent
            ]);
            $message = "Document enregistré avec succès.";
            $messageType = "success";
        } catch (\PDOException $e) {
            $message = "Erreur d'enregistrement : " . $e->getMessage();
            $messageType = "danger";
        }
    } else {
        $message = "Le titre est obligatoire.";
        $messageType = "warning";
    }
}

// -----------------------------------------------------------------------------
// 4. CATALOG QUERY EXECUTION (HARDENED FULL-TEXT SEARCH & PDO FIX)
// -----------------------------------------------------------------------------
$searchQuery    = trim($_GET['q'] ?? '');
$categoryFilter = trim($_GET['cat'] ?? '');
$formatFilter   = trim($_GET['fmt'] ?? '');

$sql = "SELECT * FROM livres WHERE 1=1";
$params = [];

if (!empty($searchQuery)) {
    // Clean special MySQL FULLTEXT operators
    $cleanQuery = preg_replace('/[+\-><()~*@"]/', ' ', $searchQuery);
    
    // Extract valid word tokens
    preg_match_all('/\b\w+\b/u', $cleanQuery, $matches);
    $words = array_filter($matches[0] ?? []);

    if (!empty($words)) {
        $ftTerms = array_map(fn($w) => $w . '*', $words);
        $ftSearchString = implode(' ', $ftTerms);

        // Unique named placeholders to comply with PDO emulate_prepares = false
        $sql .= " AND (
            MATCH(titre, auteur, resume, fulltext_content) AGAINST (:ftQuery IN BOOLEAN MODE)
            OR titre LIKE :l1 
            OR auteur LIKE :l2 
            OR isbn LIKE :l3 
            OR cote LIKE :l4
        )";
        $params[':ftQuery'] = $ftSearchString;
        $params[':l1']      = '%' . $searchQuery . '%';
        $params[':l2']      = '%' . $searchQuery . '%';
        $params[':l3']      = '%' . $searchQuery . '%';
        $params[':l4']      = '%' . $searchQuery . '%';
    } else {
        $sql .= " AND (titre LIKE :l1 OR auteur LIKE :l2 OR isbn LIKE :l3 OR cote LIKE :l4)";
        $params[':l1'] = '%' . $searchQuery . '%';
        $params[':l2'] = '%' . $searchQuery . '%';
        $params[':l3'] = '%' . $searchQuery . '%';
        $params[':l4'] = '%' . $searchQuery . '%';
    }
}

if (!empty($categoryFilter)) {
    $sql .= " AND categorie = :categorie";
    $params[':categorie'] = $categoryFilter;
}

if (!empty($formatFilter)) {
    $sql .= " AND format = :format";
    $params[':format'] = $formatFilter;
}

$sql .= " ORDER BY id DESC";

// Execute Query with Fallback Safety Net
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $books = $stmt->fetchAll();
} catch (\PDOException $e) {
    // Graceful fallback to standard SQL LIKE query
    $fallbackSql = "SELECT * FROM livres WHERE 1=1";
    $fallbackParams = [];

    if (!empty($searchQuery)) {
        $fallbackSql .= " AND (titre LIKE :fl1 OR auteur LIKE :fl2 OR isbn LIKE :fl3 OR cote LIKE :fl4 OR resume LIKE :fl5)";
        $term = '%' . $searchQuery . '%';
        $fallbackParams[':fl1'] = $term;
        $fallbackParams[':fl2'] = $term;
        $fallbackParams[':fl3'] = $term;
        $fallbackParams[':fl4'] = $term;
        $fallbackParams[':fl5'] = $term;
    }
    if (!empty($categoryFilter)) {
        $fallbackSql .= " AND categorie = :cat";
        $fallbackParams[':cat'] = $categoryFilter;
    }
    if (!empty($formatFilter)) {
        $fallbackSql .= " AND format = :fmt";
        $fallbackParams[':fmt'] = $formatFilter;
    }
    $fallbackSql .= " ORDER BY id DESC";

    $stmt = $pdo->prepare($fallbackSql);
    $stmt->execute($fallbackParams);
    $books = $stmt->fetchAll();
}

// Fetch categories for the filter dropdown
try {
    $catStmt = $pdo->query("SELECT DISTINCT categorie FROM livres WHERE categorie IS NOT NULL AND categorie != '' ORDER BY categorie ASC");
    $categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (\PDOException $e) {
    $categories = [];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BiblioTech - Gestion Documentaire</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-800 antialiased min-h-screen">

    <!-- Header Navigation -->
    <header class="bg-slate-900 text-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 py-4 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <span class="text-2xl font-bold tracking-wider text-indigo-400">BiblioTech</span>
                <span class="text-xs bg-slate-800 text-slate-400 px-2 py-1 rounded">v2.0</span>
            </div>
            <div class="text-sm text-slate-400">
                Portail de Gestion Documentaire
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-8 space-y-8">

        <!-- Notification Banner -->
        <?php if (!empty($message)): ?>
            <div class="p-4 rounded-lg shadow-sm font-medium border <?php echo $messageType === 'success' ? 'bg-emerald-50 text-emerald-800 border-emerald-200' : ($messageType === 'warning' ? 'bg-amber-50 text-amber-800 border-amber-200' : 'bg-rose-50 text-rose-800 border-rose-200'); ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- Search & Filter Bar -->
        <section class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
            <h1 class="text-xl font-semibold mb-4 text-slate-900">Rechercher dans le catalogue</h1>
            <form method="GET" action="index.php" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="md:col-span-2">
                    <label for="q" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Mots-clés / Titre / Auteur / ISBN</label>
                    <input type="text" id="q" name="q" value="<?php echo sanitizeInput($searchQuery); ?>" placeholder="Rechercher..." class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label for="cat" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Catégorie</label>
                    <select id="cat" name="cat" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white">
                        <option value="">Toutes les catégories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo sanitizeInput($cat); ?>" <?php echo $categoryFilter === $cat ? 'selected' : ''; ?>>
                                <?php echo sanitizeInput($cat); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="fmt" class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Format</label>
                    <select id="fmt" name="fmt" class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white">
                        <option value="">Tous les formats</option>
                        <option value="PDF" <?php echo $formatFilter === 'PDF' ? 'selected' : ''; ?>>PDF</option>
                        <option value="EPUB" <?php echo $formatFilter === 'EPUB' ? 'selected' : ''; ?>>EPUB</option>
                        <option value="PAPIER" <?php echo $formatFilter === 'PAPIER' ? 'selected' : ''; ?>>PAPIER</option>
                    </select>
                </div>
                <div class="md:col-span-4 flex justify-end space-x-3">
                    <?php if (!empty($searchQuery) || !empty($categoryFilter) || !empty($formatFilter)): ?>
                        <a href="index.php" class="px-4 py-2 border border-slate-300 rounded-lg text-slate-600 hover:bg-slate-50 transition">Réinitialiser</a>
                    <?php endif; ?>
                    <button type="submit" class="px-6 py-2 bg-indigo-600 text-white font-medium rounded-lg hover:bg-indigo-700 transition">Filtrer</button>
                </div>
            </form>
        </section>

        <!-- Main Layout: Results & Upload Form -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- Documents List (2 Columns) -->
            <section class="lg:col-span-2 space-y-4">
                <div class="flex justify-between items-center">
                    <h2 class="text-lg font-bold text-slate-900">Documents trouvés (<?php echo count($books); ?>)</h2>
                </div>

                <?php if (empty($books)): ?>
                    <div class="bg-white p-8 rounded-xl text-center border border-slate-200 text-slate-500">
                        Aucun document ne correspond à votre recherche.
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 gap-4">
                        <?php foreach ($books as $book): ?>
                            <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm hover:shadow-md transition">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <span class="inline-block px-2 py-0.5 text-xs font-semibold rounded bg-slate-100 text-slate-600 mb-2">
                                            <?php echo sanitizeInput($book['categorie'] ?? 'Général'); ?>
                                        </span>
                                        <h3 class="text-base font-bold text-slate-900"><?php echo sanitizeInput($book['titre']); ?></h3>
                                        <p class="text-sm text-slate-600">Par <?php echo sanitizeInput($book['auteur'] ?? 'Auteur inconnu'); ?></p>
                                    </div>
                                    <span class="px-2 py-1 text-xs font-bold rounded <?php echo $book['format'] === 'PDF' ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700'; ?>">
                                        <?php echo sanitizeInput($book['format']); ?>
                                    </span>
                                </div>
                                
                                <?php if (!empty($book['resume'])): ?>
                                    <p class="text-sm text-slate-500 mt-3 line-clamp-2"><?php echo sanitizeInput($book['resume']); ?></p>
                                <?php endif; ?>

                                <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-400">
                                    <span>Cote: <?php echo sanitizeInput($book['cote'] ?? 'N/A'); ?> | ISBN: <?php echo sanitizeInput($book['isbn'] ?? 'N/A'); ?></span>
                                    <?php if (!empty($book['fichier_path'])): ?>
                                        <a href="<?php echo sanitizeInput($book['fichier_path']); ?>" target="_blank" class="text-indigo-600 font-medium hover:underline flex items-center gap-1">
                                            Télécharger / Voir
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Document Registration Form (1 Column) -->
            <section class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm h-fit">
                <h2 class="text-lg font-bold text-slate-900 mb-4">Ajouter un document</h2>
                <form method="POST" action="index.php" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="action_add_book" value="1">
                    
                    <div>
                        <label for="titre" class="block text-xs font-semibold text-slate-500 uppercase mb-1">Titre *</label>
                        <input type="text" id="titre" name="titre" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div>
                        <label for="auteur" class="block text-xs font-semibold text-slate-500 uppercase mb-1">Auteur</label>
                        <input type="text" id="auteur" name="auteur" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="isbn" class="block text-xs font-semibold text-slate-500 uppercase mb-1">ISBN</label>
                            <input type="text" id="isbn" name="isbn" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label for="cote" class="block text-xs font-semibold text-slate-500 uppercase mb-1">Cote</label>
                            <input type="text" id="cote" name="cote" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="categorie" class="block text-xs font-semibold text-slate-500 uppercase mb-1">Catégorie</label>
                            <input type="text" id="categorie" name="categorie" placeholder="Ex: Informatique" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label for="format" class="block text-xs font-semibold text-slate-500 uppercase mb-1">Format</label>
                            <select id="format" name="format" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 bg-white">
                                <option value="PDF">PDF</option>
                                <option value="EPUB">EPUB</option>
                                <option value="PAPIER">PAPIER</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label for="resume" class="block text-xs font-semibold text-slate-500 uppercase mb-1">Résumé</label>
                        <textarea id="resume" name="resume" rows="3" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500"></textarea>
                    </div>

                    <div>
                        <label for="document_file" class="block text-xs font-semibold text-slate-500 uppercase mb-1">Fichier (PDF, TXT)</label>
                        <input type="file" id="document_file" name="document_file" accept=".pdf,.txt,.epub,.docx" class="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                    </div>

                    <button type="submit" class="w-full py-2 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 transition">
                        Enregistrer
                    </button>
                </form>
            </section>

        </div>
    </main>

</body>
</html>