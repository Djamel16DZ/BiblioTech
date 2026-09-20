<?php
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

// Création de la table 'livres' si elle n'existe pas
$pdo->exec("CREATE TABLE IF NOT EXISTS livres (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titre VARCHAR(255) NOT NULL,
    auteur VARCHAR(255) NOT NULL,
    isbn VARCHAR(50) DEFAULT NULL,
    cote VARCHAR(50) DEFAULT NULL,
    editeur VARCHAR(100) DEFAULT NULL,
    annee INT DEFAULT NULL,
    format VARCHAR(10) NOT NULL, -- PDF, EPUB, MOBI
    categorie VARCHAR(100) NOT NULL,
    pages INT DEFAULT NULL,
    resume TEXT DEFAULT NULL,
    fichier VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Création du dossier d'upload s'il n'existe pas
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
// RÉCUPÉRATION DES DONNÉES, FILTRAGE & PAGINATION
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

// Calcul du nombre total d'éléments
$countQuery = "SELECT COUNT(*) FROM livres WHERE $whereSql";
$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();

// Paramètres de pagination (15 par page)
$perPage = 15;
$totalPages = max(1, ceil($totalRecords / $perPage));
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// Requête paginée sécurisée
$query = "SELECT * FROM livres WHERE $whereSql ORDER BY id DESC LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$livres = $stmt->fetchAll();

// KPIs globaux
$totalLivres = $pdo->query("SELECT COUNT(*) FROM livres")->fetchColumn();
$totalPdf = $pdo->query("SELECT COUNT(*) FROM livres WHERE format='PDF'")->fetchColumn();
$totalEpub = $pdo->query("SELECT COUNT(*) FROM livres WHERE format='EPUB'")->fetchColumn();
$totalMobi = $pdo->query("SELECT COUNT(*) FROM livres WHERE format='MOBI'")->fetchColumn();

// Catégories
$categories = $pdo->query("SELECT DISTINCT categorie FROM livres ORDER BY categorie")->fetchAll(PDO::FETCH_COLUMN);

// Données pour édition éventuelle
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
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- EPUB.js CDN pour lecture intégrée -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.5/jszip.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/epubjs/dist/epub.min.js"></script>
    <style>
        #sidebar {
            transition: margin-left 0.3s ease-in-out, width 0.3s ease-in-out, opacity 0.3s ease-in-out;
        }
        #sidebar.collapsed {
            margin-left: -24rem;
            opacity: 0;
            pointer-events: none;
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

        <!-- KPIs en-tête -->
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
        <aside id="sidebar" class="w-96 bg-white border-r border-gray-200 flex flex-col shrink-0 z-10 shadow-lg md:shadow-none">
            <div class="p-4 border-b border-gray-200 flex items-center justify-between bg-gray-50">
                <h2 class="font-semibold text-gray-800 flex items-center space-x-2">
                    <i class="fa-solid <?= $editLivre ? 'fa-pen-to-square' : 'fa-circle-plus' ?> text-indigo-600"></i>
                    <span><?= $editLivre ? 'Modifier l\'ouvrage' : 'Nouvel ouvrage' ?></span>
                </h2>
                <?php if($editLivre): ?>
                    <a href="index.php" class="text-xs text-gray-500 hover:text-gray-700 underline">Annuler l'édition</a>
                <?php endif; ?>
            </div>

            <!-- Formulaire scrollable -->
            <div class="flex-1 overflow-y-auto p-4 space-y-4">
                <?php if (!empty($error)): ?>
                    <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded-lg text-xs">
                        <?= $error ?>
                    </div>
                <?php endif; ?>

                <form action="index.php" method="POST" enctype="multipart/form-data" class="space-y-3">
                    <input type="hidden" name="id" value="<?= $editLivre['id'] ?? '' ?>">

                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Titre *</label>
                        <input type="text" name="titre" required value="<?= htmlspecialchars($editLivre['titre'] ?? '') ?>" class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-1 focus:ring-indigo-500 focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Auteur(s) *</label>
                        <input type="text" name="auteur" required value="<?= htmlspecialchars($editLivre['auteur'] ?? '') ?>" class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-1 focus:ring-indigo-500 focus:outline-none">
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
                            <label class="block text-xs font-medium text-gray-700 mb-1">ISBN</label>
                            <input type="text" name="isbn" value="<?= htmlspecialchars($editLivre['isbn'] ?? '') ?>" class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-1 focus:ring-indigo-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Cote Bibliothèque</label>
                            <input type="text" name="cote" value="<?= htmlspecialchars($editLivre['cote'] ?? '') ?>" placeholder="ex: 843.9 HUGO" class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-1 focus:ring-indigo-500 focus:outline-none">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Éditeur</label>
                            <input type="text" name="editeur" value="<?= htmlspecialchars($editLivre['editeur'] ?? '') ?>" class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-1 focus:ring-indigo-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Année</label>
                            <input type="number" name="annee" value="<?= htmlspecialchars($editLivre['annee'] ?? '') ?>" class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-1 focus:ring-indigo-500 focus:outline-none">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Nombre de pages</label>
                        <input type="number" name="pages" value="<?= htmlspecialchars($editLivre['pages'] ?? '') ?>" class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-1 focus:ring-indigo-500 focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Résumé / Description</label>
                        <textarea name="resume" rows="2" class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:ring-1 focus:ring-indigo-500 focus:outline-none"><?= htmlspecialchars($editLivre['resume'] ?? '') ?></textarea>
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

                        <!-- Filtre Format -->
                        <select name="format" onchange="this.form.submit()" class="text-xs bg-gray-50 border border-gray-300 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                            <option value="">Tous formats</option>
                            <option value="PDF" <?= $filterFormat=='PDF'?'selected':'' ?>>PDF</option>
                            <option value="EPUB" <?= $filterFormat=='EPUB'?'selected':'' ?>>EPUB</option>
                            <option value="MOBI" <?= $filterFormat=='MOBI'?'selected':'' ?>>MOBI</option>
                        </select>

                        <!-- Filtre Catégorie -->
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

                <!-- Barre de Pagination -->
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
                            <?php else: ?>
                                <span class="px-3 py-1.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-300 cursor-not-allowed">
                                    <i class="fa-solid fa-chevron-left mr-1"></i> Précédent
                                </span>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <?php if ($i == 1 || $i == $totalPages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                                    <a href="index.php?page=<?= $i ?><?= $paramPrefix ?>" class="px-3 py-1.5 rounded-lg border transition <?= $i == $page ? 'bg-indigo-600 text-white border-indigo-600 font-semibold' : 'bg-gray-50 border-gray-300 text-gray-700 hover:bg-gray-100' ?>">
                                        <?= $i ?>
                                    </a>
                                <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                                    <span class="px-2 text-gray-400">...</span>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="index.php?page=<?= $page + 1 ?><?= $paramPrefix ?>" class="px-3 py-1.5 bg-gray-50 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition">
                                    Suivant <i class="fa-solid fa-chevron-right ml-1"></i>
                                </a>
                            <?php else: ?>
                                <span class="px-3 py-1.5 bg-gray-50 border border-gray-200 rounded-lg text-gray-300 cursor-not-allowed">
                                    Suivant <i class="fa-solid fa-chevron-right ml-1"></i>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Modale de Prévisualisation / Lecture (PDF & EPUB Intégré) -->
    <div id="previewModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-4xl h-[85vh] flex flex-col overflow-hidden">
            <div class="p-4 border-b border-gray-200 flex items-center justify-between bg-gray-50 shrink-0">
                <div class="flex items-center space-x-4">
                    <h3 id="modalTitle" class="font-semibold text-gray-800 text-sm">Lecture de l'ouvrage</h3>
                    <!-- Contrôles spécifiques EPUB (Page précédente / suivante) -->
                    <div id="epubControls" class="hidden items-center space-x-1">
                        <button onclick="prevEpubPage()" class="px-2 py-1 bg-white border border-gray-300 rounded text-xs hover:bg-gray-100 text-gray-700 shadow-sm" title="Page précédente"><i class="fa-solid fa-chevron-left"></i> Précédent</button>
                        <button onclick="nextEpubPage()" class="px-2 py-1 bg-white border border-gray-300 rounded text-xs hover:bg-gray-100 text-gray-700 shadow-sm" title="Page suivante">Suivant <i class="fa-solid fa-chevron-right"></i></button>
                    </div>
                </div>
                <div class="flex items-center space-x-2">
                    <button onclick="toggleModalFullscreen()" class="text-gray-400 hover:text-gray-700 text-xs px-2 py-1 rounded bg-gray-200/50" title="Plein écran"><i class="fa-solid fa-expand"></i></button>
                    <button onclick="closePreview()" class="text-gray-400 hover:text-red-600 font-bold px-2 py-1"><i class="fa-solid fa-xmark text-lg"></i></button>
                </div>
            </div>
            
            <!-- Conteneurs de visionneuse -->
            <div class="flex-1 bg-gray-100 relative flex items-center justify-center overflow-hidden" id="modalBodyContainer">
                <!-- Lecteur PDF -->
                <iframe id="previewIframe" src="" class="w-full h-full border-0 hidden"></iframe>
                
                <!-- Lecteur EPUB Intégré (Epub.js) -->
                <div id="epubViewerContainer" class="w-full h-full hidden flex items-center justify-center bg-white p-4">
                    <div id="viewer" class="w-full h-full max-w-2xl"></div>
                </div>

                <!-- Fallback pour MOBI ou formats non pris en charge en ligne -->
                <div id="epubFallback" class="hidden absolute inset-0 flex flex-col items-center justify-center p-6 text-center bg-white">
                    <div class="text-indigo-600 text-4xl mb-3"><i class="fa-solid fa-book"></i></div>
                    <h4 class="font-semibold text-gray-800 mb-1">Format MOBI</h4>
                    <p class="text-xs text-gray-500 mb-4 max-w-sm">La lecture en ligne directe est disponible pour les PDF et EPUB. Pour ce format MOBI, veuillez télécharger le fichier.</p>
                    <a id="fallbackDownloadBtn" href="#" download class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-xs font-medium shadow-sm hover:bg-indigo-700">Télécharger le livre</a>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');

        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
        });

        function openNewBookPanel() {
            if (sidebar.classList.contains('collapsed')) {
                sidebar.classList.remove('collapsed');
            }
            if (window.location.search.includes('edit=')) {
                window.location.href = 'index.php';
            }
        }

        // Gestion de la Modale et des Lecteurs
        const previewModal = document.getElementById('previewModal');
        const previewIframe = document.getElementById('previewIframe');
        const epubViewerContainer = document.getElementById('epubViewerContainer');
        const epubFallback = document.getElementById('epubFallback');
        const epubControls = document.getElementById('epubControls');
        const modalTitle = document.getElementById('modalTitle');
        const fallbackDownloadBtn = document.getElementById('fallbackDownloadBtn');

        let currentBook = null;
        let currentRendition = null;

        function openPreview(filePath, title, format) {
            modalTitle.textContent = "Lecture : " + title;
            previewModal.classList.remove('hidden');
            
            // Réinitialiser les affichages
            previewIframe.classList.add('hidden');
            epubViewerContainer.classList.add('hidden');
            epubFallback.classList.add('hidden');
            epubControls.classList.add('hidden');

            const fmt = format.toUpperCase();

            if (fmt === 'PDF') {
                previewIframe.classList.remove('hidden');
                previewIframe.src = filePath;
            } else if (fmt === 'EPUB') {
                epubViewerContainer.classList.remove('hidden');
                epubControls.classList.remove('hidden');
                
                // Nettoyer l'instance précédente si elle existe
                document.getElementById('viewer').innerHTML = '';

                // Charger et rendre l'EPUB via Epub.js
                currentBook = ePub(filePath);
                currentRendition = currentBook.renderTo("viewer", {
                    width: "100%",
                    height: "100%",
                    spread: "auto"
                });
                currentRendition.display();
            } else {
                // Fallback (MOBI, etc.)
                epubFallback.classList.remove('hidden');
                fallbackDownloadBtn.href = filePath;
            }
        }

        function prevEpubPage() {
            if (currentRendition) {
                currentRendition.prev();
            }
        }

        function nextEpubPage() {
            if (currentRendition) {
                currentRendition.next();
            }
        }

        function closePreview() {
            previewModal.classList.add('hidden');
            previewIframe.src = '';
            document.getElementById('viewer').innerHTML = '';
            currentBook = null;
            currentRendition = null;
        }

        function toggleModalFullscreen() {
            const modalContent = previewModal.querySelector('div');
            modalContent.classList.toggle('max-w-4xl');
            modalContent.classList.toggle('w-full');
            modalContent.classList.toggle('h-[85vh]');
            modalContent.classList.toggle('h-screen');
            modalContent.classList.toggle('w-screen');
            modalContent.classList.toggle('rounded-xl');
        }

        previewModal.addEventListener('click', (e) => {
            if (e.target === previewModal) {
                closePreview();
            }
        });
    </script>
</body>
</html>