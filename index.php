<?php
// ==========================================
// API INTERNE DE RECHERCHE ISBN (AJAX PHP)
// ==========================================
if (isset($_GET['ajax_isbn'])) {
    header('Content-Type: application/json');
    $isbn = preg_replace('/[^0-9X]/i', '', $_GET['ajax_isbn']);
    
    if (empty($isbn)) {
        echo json_encode(['success' => false, 'error' => 'ISBN invalide']);
        exit;
    }

    $bookData = null;

    // 1. Tentative Google Books
    $gBooksUrl = "https://www.googleapis.com/books/v1/volumes?q=isbn:" . urlencode($isbn);
    $context = stream_context_create(['http' => ['method' => 'GET', 'header' => "User-Agent: Mozilla/5.0\r\n"]]);
    $response = @file_get_contents($gBooksUrl, false, $context);
    
    if ($response) {
        $data = json_decode($response, true);
        if (!empty($data['items'])) {
            $info = $data['items'][0]['volumeInfo'];
            $bookData = [
                'titre' => $info['title'] ?? '',
                'auteur' => isset($info['authors']) ? implode(', ', $info['authors']) : '',
                'editeur' => $info['publisher'] ?? '',
                'annee' => isset($info['publishedDate']) ? substr($info['publishedDate'], 0, 4) : '',
                'pages' => $info['pageCount'] ?? '',
                'resume' => strip_tags($info['description'] ?? '')
            ];
        }
    }

    // 2. Si Google Books échoue, tentative Open Library
    if (!$bookData) {
        $olUrl = "https://openlibrary.org/isbn/" . urlencode($isbn) . ".json";
        $responseOL = @file_get_contents($olUrl, false, $context);
        
        if ($responseOL) {
            $dataOL = json_decode($responseOL, true);
            $title = $dataOL['title'] ?? '';
            $publishDate = $dataOL['publish_date'] ?? '';
            $numberPages = $dataOL['number_of_pages'] ?? '';
            
            $authorsArr = [];
            if (!empty($dataOL['authors'])) {
                foreach ($dataOL['authors'] as $authRef) {
                    if (isset($authRef['key'])) {
                        $authUrl = "https://openlibrary.org" . $authRef['key'] . ".json";
                        $respAuth = @file_get_contents($authUrl, false, $context);
                        if ($respAuth) {
                            $authData = json_decode($respAuth, true);
                            if (isset($authData['name'])) {
                                $authorsArr[] = $authData['name'];
                            }
                        }
                    }
                }
            }

            $publishersArr = $dataOL['publishers'] ?? [];

            $bookData = [
                'titre' => $title,
                'auteur' => implode(', ', $authorsArr),
                'editeur' => implode(', ', $publishersArr),
                'annee' => preg_match('/\d{4}/', $publishDate, $m) ? $m[0] : '',
                'pages' => $numberPages,
                'resume' => ''
            ];
        }
    }

    if ($bookData && !empty($bookData['titre'])) {
        echo json_encode(['success' => true, 'data' => $bookData]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Aucun livre trouvé pour cet ISBN.']);
    }
    exit;
}

// ==========================================
// CONFIGURATION & CONNEXION BASE DE DONNÉES
// ==========================================
$host = 'localhost';
$db   = 'bibliotheque_db';
$user = 'root';
$pass = 'root';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Erreur de connexion à la base de données : " . $e->getMessage());
}

$pdo->exec("CREATE TABLE IF NOT EXISTS livres (
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$uploadDir = 'uploads/books/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// ==========================================
// TRAITEMENT DES REQUÊTES (POST / GET)
// ==========================================
$error = '';

if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT fichier FROM livres WHERE id = ?");
    $stmt->execute([$id]);
    $livre = $stmt->fetch();
    
    if ($livre) {
        if (file_exists($livre['fichier'])) {
            unlink($livre['fichier']);
        }
        $stmtDel = $pdo->prepare("DELETE FROM livres WHERE id = ?");
        $stmtDel->execute([$id]);
        header("Location: index.php?msg=deleted");
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $titre = trim($_POST['titre'] ?? '');
    $auteur = trim($_POST['auteur'] ?? '');
    $isbn = trim($_POST['isbn'] ?? '');
    $cote = trim($_POST['cote'] ?? '');
    $editeur = trim($_POST['editeur'] ?? '');
    $annee = !empty($_POST['annee']) ? (int)$_POST['annee'] : null;
    $format = $_POST['format'] ?? 'PDF';
    $categorie = trim($_POST['categorie'] ?? 'Général');
    $pages = !empty($_POST['pages']) ? (int)$_POST['pages'] : null;
    $resume = trim($_POST['resume'] ?? '');

    $filePath = '';
    $fileUploaded = false;

    if (isset($_FILES['fichier']) && $_FILES['fichier']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['fichier']['tmp_name'];
        $fileName = $_FILES['fichier']['name'];
        
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExtensions = ['pdf', 'epub', 'mobi'];

        if (in_array($fileExtension, $allowedExtensions)) {
            $newFileName = md5(time() . $fileName) . '.' . $fileExtension;
            $dest_path = $uploadDir . $newFileName;

            if (move_uploaded_file($fileTmpPath, $dest_path)) {
                $filePath = $dest_path;
                $fileUploaded = true;
            } else {
                $error = "Erreur lors du déplacement du fichier téléchargé.";
            }
        } else {
            $error = "Format de fichier non autorisé. Formats acceptés : PDF, EPUB, MOBI.";
        }
    }

    if (empty($error)) {
        if ($id > 0) {
            if (!$fileUploaded) {
                $stmt = $pdo->prepare("SELECT fichier FROM livres WHERE id = ?");
                $stmt->execute([$id]);
                $oldData = $stmt->fetch();
                $filePath = $oldData['fichier'];
            } else {
                $stmt = $pdo->prepare("SELECT fichier FROM livres WHERE id = ?");
                $stmt->execute([$id]);
                $oldData = $stmt->fetch();
                if ($oldData && file_exists($oldData['fichier'])) {
                    unlink($oldData['fichier']);
                }
            }

            $sql = "UPDATE livres SET titre=?, auteur=?, isbn=?, cote=?, editeur=?, annee=?, format=?, categorie=?, pages=?, resume=?, fichier=? WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$titre, $auteur, $isbn, $cote, $editeur, $annee, $format, $categorie, $pages, $resume, $filePath, $id]);
            header("Location: index.php?msg=updated");
            exit;
        } else {
            if ($fileUploaded) {
                $sql = "INSERT INTO livres (titre, auteur, isbn, cote, editeur, annee, format, categorie, pages, resume, fichier) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$titre, $auteur, $isbn, $cote, $editeur, $annee, $format, $categorie, $pages, $resume, $filePath]);
                header("Location: index.php?msg=added");
                exit;
            } else {
                $error = "Veuillez joindre un fichier numérique valide pour un nouveau livre.";
            }
        }
    }
}

// ==========================================
// RÉCUPÉRATION DES DONNÉES & FILTRES
// ==========================================
$search = $_GET['search'] ?? '';
$filterFormat = $_GET['format'] ?? '';
$filterCat = $_GET['categorie'] ?? '';

$whereClauses = ["1=1"];
$params = [];

if (!empty($search)) {
    $whereClauses[] = "(titre LIKE ? OR auteur LIKE ? OR isbn LIKE ? OR cote LIKE ?)";
    $searchTerm = "%$search%";
    array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
}

if (!empty($filterFormat)) {
    $whereClauses[] = "format = ?";
    $params[] = $filterFormat;
}

if (!empty($filterCat)) {
    $whereClauses[] = "categorie = ?";
    $params[] = $filterCat;
}

$whereSql = implode(" AND ", $whereClauses);

$countQuery = "SELECT COUNT(*) FROM livres WHERE $whereSql";
$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();

$perPage = 15;
$totalPages = max(1, ceil($totalRecords / $perPage));
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$query = "SELECT * FROM livres WHERE $whereSql ORDER BY id DESC LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$livres = $stmt->fetchAll();

$totalLivres = $pdo->query("SELECT COUNT(*) FROM livres")->fetchColumn();
$totalPdf = $pdo->query("SELECT COUNT(*) FROM livres WHERE format='PDF'")->fetchColumn();
$totalEpub = $pdo->query("SELECT COUNT(*) FROM livres WHERE format='EPUB'")->fetchColumn();
$totalMobi = $pdo->query("SELECT COUNT(*) FROM livres WHERE format='MOBI'")->fetchColumn();

$categories = $pdo->query("SELECT DISTINCT categorie FROM livres ORDER BY categorie")->fetchAll(PDO::FETCH_COLUMN);

$editLivre = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmtEdit = $pdo->prepare("SELECT * FROM livres WHERE id = ?");
    $stmtEdit->execute([$editId]);
    $editLivre = $stmtEdit->fetch();
}
?>
<!DOCTYPE html>
<html lang="fr" class="h-screen overflow-hidden">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bibliothèque Numérique - BiblioTech</title>
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            corePlugins: { preflight: true }
        }
    </script>
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        #sidebar {
            transition: margin-left 0.3s ease-in-out, width 0.3s ease-in-out, opacity 0.3s ease-in-out;
        }
        #sidebar.collapsed {
            margin-left: -24rem;
            opacity: 0;
            pointer-events: none;
        }
        #mobiContainer img {
            max-width: 100%;
            height: auto;
            margin: 1rem auto;
            display: block;
        }
        #epubViewer iframe {
            width: 100% !important;
            height: 100% !important;
            border: none;
        }
    </style>
</head>
<body class="bg-gray-50 h-screen flex flex-col overflow-hidden text-gray-800">

    <!-- En-tête / Header -->
    <header class="bg-white border-b border-gray-200 h-16 flex items-center justify-between px-6 shrink-0 z-20">
        <div class="flex items-center space-x-4">
            <button id="sidebarToggle" class="text-gray-500 hover:text-indigo-600 focus:outline-none transition-colors" title="Ouvrir / Fermer le panneau">
                <i class="fa-solid fa-bars text-xl"></i>
            </button>
            <div class="flex items-center space-x-2">
                <div class="bg-indigo-600 text-white p-2 rounded-lg shadow-sm">
                    <i class="fa-solid fa-book-open"></i>
                </div>
                <h1 class="font-bold text-lg text-gray-900 tracking-tight">BiblioTech <span class="text-xs font-normal text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded-full border border-indigo-100">MVP</span></h1>
            </div>
        </div>

        <div class="hidden md:flex items-center space-x-6 text-sm">
            <div class="flex items-center space-x-2 bg-gray-50 px-3 py-1.5 rounded-lg border border-gray-100">
                <span class="text-gray-500">Total :</span>
                <span class="font-semibold text-gray-800"><?= $totalLivres ?></span>
            </div>
            <div class="flex items-center space-x-2 bg-red-50 px-3 py-1.5 rounded-lg border border-red-100">
                <span class="text-red-600">PDF :</span>
                <span class="font-semibold text-red-700"><?= $totalPdf ?></span>
            </div>
            <div class="flex items-center space-x-2 bg-emerald-50 px-3 py-1.5 rounded-lg border border-emerald-100">
                <span class="text-emerald-600">EPUB :</span>
                <span class="font-semibold text-emerald-700"><?= $totalEpub ?></span>
            </div>
            <div class="flex items-center space-x-2 bg-blue-50 px-3 py-1.5 rounded-lg border border-blue-100">
                <span class="text-blue-600">MOBI :</span>
                <span class="font-semibold text-blue-700"><?= $totalMobi ?></span>
            </div>
        </div>

        <div class="flex items-center space-x-3">
            <button onclick="openNewBookPanel()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition shadow-sm flex items-center space-x-2">
                <i class="fa-solid fa-plus"></i>
                <span>Ajouter un livre</span>
            </button>
        </div>
    </header>

    <!-- Corps Principal -->
    <div class="flex flex-1 overflow-hidden relative">

        <!-- Panneau Latéral Rétractable -->
        <aside id="sidebar" class="w-96 bg-white border-r border-gray-200 flex flex-col shrink-0 z-10 shadow-lg md:shadow-none <?= $editLivre ? '' : 'collapsed' ?>">
            <div class="p-4 border-b border-gray-200 flex items-center justify-between bg-gray-50">
                <h2 class="font-semibold text-gray-800 flex items-center space-x-2">
                    <i class="fa-solid <?= $editLivre ? 'fa-pen-to-square' : 'fa-circle-plus' ?> text-indigo-600"></i>
                    <span><?= $editLivre ? 'Modifier l\'ouvrage' : 'Nouvel ouvrage' ?></span>
                </h2>
                <div class="flex items-center space-x-2">
                    <?php if($editLivre): ?>
                        <a href="index.php" class="text-xs text-gray-500 hover:text-gray-700 underline">Annuler</a>
                    <?php endif; ?>
                    <button onclick="toggleSidebar()" class="text-gray-400 hover:text-gray-600 p-1">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>

            <!-- Formulaire -->
            <div class="flex-1 overflow-y-auto p-4 space-y-4">
                <?php if (!empty($error)): ?>
                    <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded-lg text-xs">
                        <?= $error ?>
                    </div>
                <?php endif; ?>

                <form action="index.php" method="POST" enctype="multipart/form-data" class="space-y-3">
                    <input type="hidden" name="id" value="<?= $editLivre['id'] ?? '' ?>">

                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="block text-xs font-medium text-gray-700">ISBN</label>
                            <button type="button" onclick="fetchBookByISBN()" id="isbnBtn" class="text-[11px] text-indigo-600 hover:text-indigo-800 font-medium flex items-center space-x-1 focus:outline-none">
                                <i class="fa-solid fa-wand-magic-sparkles"></i>
                                <span>Remplir via ISBN</span>
                            </button>
                        </div>
                        <input type="text" id="isbnInput" name="isbn" value="<?= htmlspecialchars($editLivre['isbn'] ?? '') ?>" placeholder="ex: 9782070619177" class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-1 focus:ring-indigo-500 focus:outline-none">
                        <span id="isbnStatus" class="text-[10px] text-gray-400 mt-0.5 block"></span>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Titre *</label>
                        <input type="text" id="titreInput" name="titre" required value="<?= htmlspecialchars($editLivre['titre'] ?? '') ?>" class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-1 focus:ring-indigo-500 focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Auteur(s) *</label>
                        <input type="text" id="auteurInput" name="auteur" required value="<?= htmlspecialchars($editLivre['auteur'] ?? '') ?>" class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-1 focus:ring-indigo-500 focus:outline-none">
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Format *</label>
                            <select name="format" class="w-full text-sm border border-gray-300 rounded-lg px-2 py-2 bg-white focus:ring-1 focus:ring-indigo-500 focus:outline-none">
                                <option value="PDF" <?= (isset($editLivre) && $editLivre['format'] == 'PDF') ? 'selected' : '' ?>>PDF</option>
                                <option value="EPUB" <?= (isset($editLivre) && $editLivre['format'] == 'EPUB') ? 'selected' : '' ?>>EPUB</option>
                                <option value="MOBI" <?= (isset($editLivre) && $editLivre['format'] == 'MOBI') ? 'selected' : '' ?>>MOBI</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Catégorie *</label>
                            <input type="text" name="categorie" required value="<?= htmlspecialchars($editLivre['categorie'] ?? 'Roman') ?>" class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-1 focus:ring-indigo-500 focus:outline-none">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Cote Bibliothèque</label>
                            <input type="text" name="cote" value="<?= htmlspecialchars($editLivre['cote'] ?? '') ?>" placeholder="ex: 843.9 HUGO" class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-1 focus:ring-indigo-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Éditeur</label>
                            <input type="text" id="editeurInput" name="editeur" value="<?= htmlspecialchars($editLivre['editeur'] ?? '') ?>" class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-1 focus:ring-indigo-500 focus:outline-none">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Année</label>
                            <input type="number" id="anneeInput" name="annee" value="<?= htmlspecialchars($editLivre['annee'] ?? '') ?>" class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-1 focus:ring-indigo-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Nb Pages</label>
                            <input type="number" id="pagesInput" name="pages" value="<?= htmlspecialchars($editLivre['pages'] ?? '') ?>" class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-1 focus:ring-indigo-500 focus:outline-none">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Résumé / Description</label>
                        <textarea id="resumeInput" name="resume" rows="2" class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-1 focus:ring-indigo-500 focus:outline-none"><?= htmlspecialchars($editLivre['resume'] ?? '') ?></textarea>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Fichier Numérique (PDF, EPUB, MOBI) <?= $editLivre ? '(Laisser vide pour conserver)' : '*' ?></label>
                        <input type="file" name="fichier" accept=".pdf,.epub,.mobi" <?= $editLivre ? '' : 'required' ?> class="w-full text-xs text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                    </div>

                    <div class="pt-2">
                        <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 rounded-lg text-sm transition shadow-sm">
                            <?= $editLivre ? 'Mettre à jour l\'ouvrage' : 'Enregistrer dans la bibliothèque' ?>
                        </button>
                    </div>
                </form>
            </div>
        </aside>

        <!-- Contenu Principal -->
        <main class="flex-1 flex flex-col overflow-hidden bg-gray-50">
            
            <!-- Barre de filtres -->
            <div class="bg-white border-b border-gray-200 p-4 shrink-0 space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider">
                        Catalogue des ouvrages (<?= $totalRecords ?> résultat<?= $totalRecords > 1 ? 's' : '' ?>)
                    </div>

                    <form action="index.php" method="GET" class="flex items-center space-x-2">
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-400">
                                <i class="fa-solid fa-search text-xs"></i>
                            </span>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Rechercher titre, auteur, ISBN, cote..." class="pl-8 pr-4 py-1.5 text-xs bg-gray-50 border border-gray-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-indigo-500 w-56 md:w-72">
                        </div>

                        <select name="format" onchange="this.form.submit()" class="text-xs bg-gray-50 border border-gray-300 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                            <option value="">Tous formats</option>
                            <option value="PDF" <?= $filterFormat=='PDF'?'selected':'' ?>>PDF</option>
                            <option value="EPUB" <?= $filterFormat=='EPUB'?'selected':'' ?>>EPUB</option>
                            <option value="MOBI" <?= $filterFormat=='MOBI'?'selected':'' ?>>MOBI</option>
                        </select>

                        <select name="categorie" onchange="this.form.submit()" class="text-xs bg-gray-50 border border-gray-300 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                            <option value="">Toutes catégories</option>
                            <?php foreach($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>" <?= $filterCat==$cat?'selected':'' ?>><?= htmlspecialchars($cat) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <?php if(!empty($search) || !empty($filterFormat) || !empty($filterCat)): ?>
                            <a href="index.php" class="text-xs text-red-500 hover:text-red-700 p-1" title="Réinitialiser les filtres"><i class="fa-solid fa-xmark"></i></a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Tableau & Pagination -->
            <div class="flex-1 overflow-y-auto p-4 flex flex-col justify-between">
                <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden mb-4">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="bg-gray-50 text-gray-500 border-b border-gray-200 font-semibold uppercase tracking-wider">
                                <th class="p-3">Titre / Auteur</th>
                                <th class="p-3">Catégorie</th>
                                <th class="p-3">Cote</th>
                                <th class="p-3">ISBN</th>
                                <th class="p-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-gray-700">
                            <?php if(empty($livres)): ?>
                                <tr>
                                    <td colspan="5" class="p-6 text-center text-gray-400">Aucun ouvrage trouvé dans la bibliothèque.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach($livres as $l): ?>
                                    <tr class="hover:bg-gray-50/80 transition-colors">
                                        <td class="p-3 max-w-xs">
                                            <div class="flex items-center space-x-2">
                                                <?php 
                                                    $badgeColor = 'bg-gray-100 text-gray-800 border-gray-200';
                                                    if($l['format'] == 'PDF') $badgeColor = 'bg-red-50 text-red-700 border-red-200';
                                                    elseif($l['format'] == 'EPUB') $badgeColor = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                                                    elseif($l['format'] == 'MOBI') $badgeColor = 'bg-blue-50 text-blue-700 border-blue-200';
                                                ?>
                                                <span class="px-1.5 py-0.5 rounded border text-[9px] font-bold uppercase shrink-0 <?= $badgeColor ?>">
                                                    <?= htmlspecialchars($l['format']) ?>
                                                </span>
                                                <div class="font-semibold text-gray-900 truncate" title="<?= htmlspecialchars($l['titre']) ?>"><?= htmlspecialchars($l['titre']) ?></div>
                                            </div>
                                            <div class="text-gray-500 truncate mt-0.5 pl-7" title="<?= htmlspecialchars($l['auteur']) ?>"><?= htmlspecialchars($l['auteur']) ?> <?php if($l['annee']) echo "({$l['annee']})"; ?></div>
                                        </td>
                                        <td class="p-3">
                                            <span class="bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full text-[11px]"><?= htmlspecialchars($l['categorie']) ?></span>
                                        </td>
                                        <td class="p-3 font-mono font-medium text-indigo-700">
                                            <?= htmlspecialchars($l['cote'] ?: '-') ?>
                                        </td>
                                        <td class="p-3 text-gray-500 font-mono">
                                            <?= htmlspecialchars($l['isbn'] ?: '-') ?>
                                        </td>
                                        <td class="p-3 text-right space-x-2 whitespace-nowrap">
                                            <button onclick="openPreview('<?= htmlspecialchars($l['fichier']) ?>', '<?= htmlspecialchars(addslashes($l['titre'])) ?>', '<?= $l['format'] ?>')" class="text-gray-500 hover:text-indigo-600 transition" title="Prévisualiser / Lire">
                                                <i class="fa-solid fa-eye text-sm"></i>
                                            </button>
                                            <a href="<?= htmlspecialchars($l['fichier']) ?>" download class="text-gray-500 hover:text-emerald-600 transition" title="Télécharger">
                                                <i class="fa-solid fa-download text-sm"></i>
                                            </a>
                                            <a href="index.php?edit=<?= $l['id'] ?>" class="text-gray-500 hover:text-blue-600 transition" title="Modifier">
                                                <i class="fa-solid fa-pen-to-square text-sm"></i>
                                            </a>
                                            <a href="index.php?action=delete&id=<?= $l['id'] ?>" onclick="return confirm('Êtes-vous sûr de vouloir supprimer cet ouvrage ?');" class="text-gray-500 hover:text-red-600 transition" title="Supprimer">
                                                <i class="fa-solid fa-trash text-sm"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="flex items-center justify-between bg-white px-4 py-3 border border-gray-200 rounded-xl shadow-sm text-xs shrink-0">
                        <div class="text-gray-500">
                            Page <span class="font-semibold text-gray-800"><?= $page ?></span> sur <span class="font-semibold text-gray-800"><?= $totalPages ?></span> (<?= $totalRecords ?> livres)
                        </div>
                        <div class="flex items-center space-x-1">
                            <?php 
                                $queryString = $_GET;
                                unset($queryString['page']);
                                $queryParameters = http_build_query($queryString);
                                $paramPrefix = !empty($queryParameters) ? '&' . $queryParameters : '';
                            ?>

                            <?php if ($page > 1): ?>
                                <a href="index.php?page=<?= $page - 1 ?><?= $paramPrefix ?>" class="px-3 py-1.5 bg-gray-50 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition">
                                    <i class="fa-solid fa-chevron-left mr-1"></i> Précédent
                                </a>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <a href="index.php?page=<?= $i ?><?= $paramPrefix ?>" class="px-3 py-1.5 rounded-lg border transition <?= $i === $page ? 'bg-indigo-600 border-indigo-600 text-white font-semibold' : 'bg-gray-50 border-gray-300 text-gray-700 hover:bg-gray-100' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="index.php?page=<?= $page + 1 ?><?= $paramPrefix ?>" class="px-3 py-1.5 bg-gray-50 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition">
                                    Suivant <i class="fa-solid fa-chevron-right ml-1"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Modal de Prévisualisation -->
    <div id="previewModal" class="fixed inset-0 z-50 hidden bg-gray-900/75 backdrop-blur-sm flex items-center justify-center p-4 sm:p-6">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-6xl h-[90vh] flex flex-col overflow-hidden relative">
            
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between bg-gray-50 shrink-0">
                <div class="flex items-center space-x-3 overflow-hidden">
                    <i class="fa-solid fa-book-reader text-indigo-600 text-lg shrink-0"></i>
                    <h3 id="modalTitle" class="text-base font-semibold text-gray-900 truncate">Prévisualisation Document</h3>
                </div>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 hover:bg-gray-200 p-2 rounded-lg transition-colors">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <div id="modalBody" class="flex-1 relative bg-gray-100 overflow-hidden">
                <!-- Direct Reader Container -->
            </div>

        </div>
    </div>

    <!-- EPUB & MOBI Reader Libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/epubjs@0.3.93/dist/epub.min.js"></script>

    <script type="module">
        import { toMarkdown } from 'https://cdn.jsdelivr.net/npm/@mdgate/mobi@0.6.25/+esm';
        window.mobiToMarkdown = toMarkdown;
    </script>

    <script>
        // Sidebar Management
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');

        function toggleSidebar() {
            sidebar.classList.toggle('collapsed');
        }

        function openNewBookPanel() {
            if (sidebar.classList.contains('collapsed')) {
                sidebar.classList.remove('collapsed');
            }
        }

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', toggleSidebar);
        }

        // ISBN Auto-Fetch
        async function fetchBookByISBN() {
            const isbnInput = document.getElementById('isbnInput');
            const statusSpan = document.getElementById('isbnStatus');
            const btn = document.getElementById('isbnBtn');
            const isbn = isbnInput.value.trim();

            if (!isbn) {
                statusSpan.textContent = 'Veuillez saisir un ISBN.';
                statusSpan.className = 'text-[10px] text-red-500 mt-0.5 block';
                return;
            }

            statusSpan.textContent = 'Recherche en cours...';
            statusSpan.className = 'text-[10px] text-indigo-600 mt-0.5 block';
            btn.disabled = true;

            try {
                const response = await fetch(`index.php?ajax_isbn=${encodeURIComponent(isbn)}`);
                const result = await response.json();

                if (result.success) {
                    const data = result.data;
                    if (data.titre) document.getElementById('titreInput').value = data.titre;
                    if (data.auteur) document.getElementById('auteurInput').value = data.auteur;
                    if (data.editeur) document.getElementById('editeurInput').value = data.editeur;
                    if (data.annee) document.getElementById('anneeInput').value = data.annee;
                    if (data.pages) document.getElementById('pagesInput').value = data.pages;
                    if (data.resume) document.getElementById('resumeInput').value = data.resume;

                    statusSpan.textContent = 'Champs remplis avec succès !';
                    statusSpan.className = 'text-[10px] text-emerald-600 mt-0.5 block';
                } else {
                    statusSpan.textContent = result.error || 'Livre introuvable.';
                    statusSpan.className = 'text-[10px] text-red-500 mt-0.5 block';
                }
            } catch (err) {
                console.error(err);
                statusSpan.textContent = 'Erreur lors de la récupération.';
                statusSpan.className = 'text-[10px] text-red-500 mt-0.5 block';
            } finally {
                btn.disabled = false;
            }
        }

        // Unified Preview Engine (PDF, EPUB, MOBI)
        let currentRendition = null;

        async function openPreview(filePath, title, format) {
            const modal = document.getElementById('previewModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalBody = document.getElementById('modalBody');

            modalTitle.innerText = `${title} (${format})`;
            modalBody.innerHTML = '';
            modal.classList.remove('hidden');

            const fmt = format.toUpperCase();

            if (fmt === 'PDF') {
                modalBody.innerHTML = `<iframe src="${filePath}" class="w-full h-full border-none"></iframe>`;
            } 
            else if (fmt === 'EPUB') {
                modalBody.innerHTML = `
                    <div id="epubViewer" class="w-full h-full overflow-hidden bg-white"></div>
                    <button id="prevBtn" class="absolute left-4 top-1/2 -translate-y-1/2 bg-black/50 text-white p-3 rounded-full hover:bg-black/70 z-10 transition-colors">
                        <i class="fa-solid fa-chevron-left text-lg"></i>
                    </button>
                    <button id="nextBtn" class="absolute right-4 top-1/2 -translate-y-1/2 bg-black/50 text-white p-3 rounded-full hover:bg-black/70 z-10 transition-colors">
                        <i class="fa-solid fa-chevron-right text-lg"></i>
                    </button>
                `;

                try {
                    const book = ePub(filePath);
                    currentRendition = book.renderTo("epubViewer", {
                        width: "100%",
                        height: "100%",
                        spread: "always"
                    });
                    await currentRendition.display();

                    document.getElementById('prevBtn').onclick = () => currentRendition.prev();
                    document.getElementById('nextBtn').onclick = () => currentRendition.next();
                } catch (e) {
                    console.error("EPUB Parse Error:", e);
                    showError(modalBody, fmt, filePath);
                }
            } 
            else if (fmt === 'MOBI') {
                modalBody.innerHTML = `
                    <div id="mobiContainer" class="w-full h-full overflow-y-auto p-8 bg-white text-gray-900 font-sans leading-relaxed max-w-4xl mx-auto shadow-sm">
                        <div class="flex items-center justify-center h-full text-gray-400 space-x-3">
                            <i class="fa-solid fa-circle-notch fa-spin text-2xl text-indigo-600"></i>
                            <span class="text-sm font-medium text-gray-600">Décodage du fichier MOBI en cours...</span>
                        </div>
                    </div>`;
                
                try {
                    const response = await fetch(filePath);
                    if (!response.ok) throw new Error('Erreur HTTP ' + response.status);
                    
                    const buffer = await response.arrayBuffer();
                    const bytes = new Uint8Array(buffer);
                    const mobiContainer = document.getElementById('mobiContainer');

                    // Primary Method: ESM @mdgate/mobi binary parser
                    if (typeof window.mobiToMarkdown === 'function') {
                        try {
                            const markdown = await window.mobiToMarkdown(bytes);
                            if (markdown && markdown.trim().length > 0) {
                                const html = markdown
                                    .replace(/^### (.*$)/gim, '<h3 class="text-lg font-bold my-2">$1</h3>')
                                    .replace(/^## (.*$)/gim, '<h2 class="text-xl font-bold my-3">$1</h2>')
                                    .replace(/^# (.*$)/gim, '<h1 class="text-2xl font-bold my-4">$1</h1>')
                                    .replace(/\n\n/g, '</p><p class="my-3">');
                                
                                mobiContainer.innerHTML = `<article class="prose max-w-none"><p class="my-3">${html}</p></article>`;
                                return;
                            }
                        } catch (mobiErr) {
                            console.warn("Échec du parseur mdgate, tentative via extraction PalmDOC...", mobiErr);
                        }
                    }

                    // Fallback Method: Native PalmDOC LZ77 Binary Decompressor
                    const extractedText = decompressPalmDoc(bytes);
                    if (extractedText && extractedText.trim().length > 0) {
                        const cleanHtml = sanitizeMobiContent(extractedText);
                        mobiContainer.innerHTML = `<div class="mobi-content prose max-w-none">${cleanHtml}</div>`;
                    } else {
                        throw new Error("Impossible de décompresser le contenu du fichier MOBI.");
                    }
                } catch (e) {
                    console.error("MOBI Extraction Error:", e);
                    showError(modalBody, fmt, filePath);
                }
            } 
            else {
                modalBody.innerHTML = `
                    <div class="flex flex-col items-center justify-center h-full text-gray-500">
                        <i class="fa-solid fa-file-circle-exclamation text-4xl mb-2 text-amber-500"></i>
                        <p class="text-sm">Format non supporté pour la prévisualisation directe.</p>
                    </div>`;
            }
        }

        // Binary Decompressor for PalmDOC / MOBI LZ77 streams
        function decompressPalmDoc(bytes) {
            try {
                const view = new DataView(bytes.buffer);
                const numRecords = view.getUint16(76);
                if (numRecords === 0) return '';

                let textResult = '';
                const decoder = new TextDecoder('latin1');

                for (let i = 1; i <= Math.min(numRecords, 200); i++) {
                    const offset = view.getUint32(78 + (i - 1) * 8);
                    const nextOffset = (i < numRecords) ? view.getUint32(78 + i * 8) : bytes.length;
                    
                    if (offset >= bytes.length) break;

                    const recordBytes = bytes.subarray(offset, Math.min(nextOffset, bytes.length));
                    let pos = 0;
                    let decompressed = [];

                    while (pos < recordBytes.length) {
                        const byte = recordBytes[pos++];
                        if (byte === 0) {
                            decompressed.push(0);
                        } else if (byte >= 1 && byte <= 8) {
                            for (let j = 0; j < byte && pos < recordBytes.length; j++) {
                                decompressed.push(recordBytes[pos++]);
                            }
                        } else if (byte <= 0x7F) {
                            decompressed.push(byte);
                        } else if (byte >= 0xC0) {
                            decompressed.push(32);
                            decompressed.push(byte ^ 0x80);
                        } else if (byte >= 0x80 && byte <= 0xBF) {
                            if (pos >= recordBytes.length) break;
                            const next = recordBytes[pos++];
                            const distance = (((byte << 8) | next) >> 3) & 0x07FF;
                            const length = (next & 0x07) + 3;
                            for (let l = 0; l < length; l++) {
                                const backIdx = decompressed.length - distance;
                                if (backIdx >= 0) decompressed.push(decompressed[backIdx]);
                            }
                        }
                    }
                    textResult += decoder.decode(new Uint8Array(decompressed));
                }
                return textResult;
            } catch (err) {
                console.error("PalmDOC Decompress error:", err);
                return '';
            }
        }

        function sanitizeMobiContent(rawText) {
            const htmlMatch = rawText.match(/<html[\s\S]*?>([\s\S]*?)<\/html>/i) || 
                              rawText.match(/<body[\s\S]*?>([\s\S]*?)<\/body>/i);
            
            let content = htmlMatch ? htmlMatch[1] : rawText;
            content = content.replace(/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/g, '');
            
            if (!/<[a-z][\s\S]*>/i.test(content)) {
                content = content.replace(/\n\n/g, '</p><p class="my-2">');
                content = `<p>${content}</p>`;
            }
            return content;
        }

        function showError(container, format, filePath) {
            container.innerHTML = `
                <div class="flex flex-col items-center justify-center h-full bg-white p-8 text-center">
                    <div class="w-16 h-16 bg-red-100 text-red-500 rounded-full flex items-center justify-center mb-4">
                        <i class="fa-solid fa-triangle-exclamation text-2xl"></i>
                    </div>
                    <h4 class="text-lg font-semibold text-gray-900 mb-1">Impossible de charger le fichier ${format}</h4>
                    <p class="text-sm text-gray-500 max-w-md mb-6">Ce fichier MOBI utilise peut-être un chiffrement DRM (Kindle) ou un dictionnaire HUFF/CDIC propriétaire non pris en compte.</p>
                    <a href="${filePath}" download class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm font-medium transition-colors">
                        <i class="fa-solid fa-download mr-2"></i> Télécharger le fichier
                    </a>
                </div>`;
        }

        function closeModal() {
            const modal = document.getElementById('previewModal');
            const modalBody = document.getElementById('modalBody');
            
            modal.classList.add('hidden');
            modalBody.innerHTML = '';
            
            if (currentRendition) {
                currentRendition.destroy();
                currentRendition = null;
            }
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeModal();
        });
    </script>
</body>
</html>