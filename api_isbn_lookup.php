<?php
header('Content-Type: application/json; charset=utf-8');

// Désactiver l'affichage direct des erreurs pour éviter de corrompre le JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

$rawIsbn = $_GET['isbn'] ?? '';

// Nettoyage de l'ISBN (retrait des tirets et espaces)
$isbn = preg_replace('/[^0-9X]/i', '', $rawIsbn);

if (empty($isbn)) {
    echo json_encode([
        'success' => false,
        'message' => 'Veuillez fournir un numéro ISBN valide.'
    ]);
    exit;
}

/**
 * Fonction pour effectuer une requête HTTP CURL sécurisée avec User-Agent
 */
function makeCurlRequest($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_USERAGENT => 'CatalogingApp/1.0 (Contact: admin@local.dev)',
        CURLOPT_SSL_VERIFYPEER => false // À activer en production si certificats OK
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $httpCode, 'body' => $response];
}

/**
 * Fonction pour générer une cote indicative (Ex: DEY-2024-9780)
 */
function generateCote($author, $year, $isbn) {
    $authorCode = 'VAR';
    if (!empty($author)) {
        $cleanAuthor = preg_replace('/[^a-zA-Z]/', '', $author);
        $authorCode = strtoupper(substr($cleanAuthor, 0, 3));
    }
    
    $yearCode = !empty($year) ? preg_replace('/[^0-9]/', '', $year) : date('Y');
    if (strlen($yearCode) > 4) $yearCode = substr($yearCode, 0, 4);
    
    $isbnSuffix = substr($isbn, -4);
    
    return sprintf('%s-%s-%s', $authorCode, $yearCode, $isbnSuffix);
}

// =========================================================================
// TENTATIVE 1 : Open Library API
// =========================================================================
$openLibraryUrl = "https://openlibrary.org/api/books?bibkeys=ISBN:{$isbn}&format=json&jscmd=data";
$olResponse = makeCurlRequest($openLibraryUrl);

if ($olResponse['code'] === 200 && !empty($olResponse['body'])) {
    $olData = json_decode($olResponse['body'], true);
    $bookKey = "ISBN:{$isbn}";

    if (isset($olData[$bookKey])) {
        $book = $olData[$bookKey];

        // Extrait les auteurs
        $authors = [];
        if (!empty($book['authors'])) {
            foreach ($book['authors'] as $a) {
                $authors[] = $a['name'];
            }
        }

        // Extrait les éditeurs
        $publishers = [];
        if (!empty($book['publishers'])) {
            foreach ($book['publishers'] as $p) {
                $publishers[] = $p['name'];
            }
        }

        $authorStr = implode(', ', $authors);
        $pubDate = $book['publish_date'] ?? '';

        // Récupération de l'image de couverture
        $coverUrl = '';
        if (isset($book['cover']['large'])) {
            $coverUrl = $book['cover']['large'];
        } elseif (isset($book['cover']['medium'])) {
            $coverUrl = $book['cover']['medium'];
        }

        echo json_encode([
            'success' => true,
            'source' => 'Open Library',
            'data' => [
                'title' => $book['title'] ?? '',
                'authors' => $authorStr,
                'publisher' => implode(', ', $publishers),
                'published_date' => $pubDate,
                'cover_url' => $coverUrl,
                'cote' => generateCote($authorStr, $pubDate, $isbn)
            ]
        ]);
        exit;
    }
}

// =========================================================================
// TENTATIVE 2 (FALLBACK) : Google Books API
// Réalisée en cas de 404 ou absence de données sur Open Library
// =========================================================================
$googleUrl = "https://www.googleapis.com/books/v1/volumes?q=isbn:{$isbn}";
$gbResponse = makeCurlRequest($googleUrl);

if ($gbResponse['code'] === 200 && !empty($gbResponse['body'])) {
    $gbData = json_decode($gbResponse['body'], true);

    if (isset($gbData['items'][0]['volumeInfo'])) {
        $info = $gbData['items'][0]['volumeInfo'];

        $authorStr = isset($info['authors']) ? implode(', ', $info['authors']) : '';
        $pubDate = $info['publishedDate'] ?? '';

        // Gestion de l'image (Remplacement HTTP par HTTPS si nécessaire)
        $coverUrl = '';
        if (isset($info['imageLinks']['thumbnail'])) {
            $coverUrl = str_replace('http://', 'https://', $info['imageLinks']['thumbnail']);
        }

        echo json_encode([
            'success' => true,
            'source' => 'Google Books (Fallback)',
            'data' => [
                'title' => $info['title'] ?? '',
                'authors' => $authorStr,
                'publisher' => $info['publisher'] ?? '',
                'published_date' => $pubDate,
                'cover_url' => $coverUrl,
                'cote' => generateCote($authorStr, $pubDate, $isbn)
            ]
        ]);
        exit;
    }
}

// =========================================================================
// AUCUN RÉSULTAT
// =========================================================================
echo json_encode([
    'success' => false,
    'message' => "Livre introuvable pour l'ISBN {$isbn} (Open Library & Google Books)."
]);