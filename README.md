# BiblioTech - Full-Stack Digital Library Application (PHP / MariaDB)

BiblioTech is a monolithic, single-file PHP digital library management system for organizing, indexing, and viewing digital publications (PDF, EPUB, MOBI).

---

## Key Features

- **Centralized Single-File Architecture (`index.php`)**: Complete routing, controller logic, database persistence, and presentation in one file.
- **Auto-Migration Database Layer**: Automatically initializes table schemas and indexes (`idx_isbn`, `idx_cote`, `idx_format`, `idx_categorie`) on first load via PDO.
- **ISBN Auto-Enrichment**: Asynchronous lookup using Google Books API with automatic fallback to Open Library API.
- **Integrated Reader Modal**: Direct in-browser viewing for PDF documents and a download fallback interface for EPUB and MOBI formats.
- **Security-First File Handling**:
  - MIME-type validation via PHP `finfo` (`fileinfo` extension).
  - Anti-directory traversal path verification using `realpath()`.
  - Cryptographically secure random filenames (`bin2hex(random_bytes(16))`).
  - XSS sanitization (`htmlspecialchars` helper).
- **Responsive Interface**: Built with Tailwind CSS and FontAwesome, featuring a collapsible side drawer and structured catalog filters.

---

## Requirements

- **PHP**: 8.0 or higher (with `pdo_mysql` and `fileinfo` extensions enabled)
- **Database**: MariaDB 10.4+ or MySQL 8.0+
- **Web Server**: Apache / Nginx or built-in PHP development server
- **Internet Access**: Required for Tailwind CSS CDN, FontAwesome, and ISBN API lookups.

---

## Database Configuration

Update the credentials in the centralized configuration block at the top of `index.php`:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'bibliotheque_db');
define('DB_USER', 'root');
define('DB_PASS', 'root');
define('DB_CHARSET', 'utf8mb4');

The application automatically creates the livres table if it does not exist:

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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_isbn (isbn),
    INDEX idx_cote (cote),
    INDEX idx_format (format),
    INDEX idx_categorie (categorie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

Installation & Running Locally

1. Clone or copy the files:
Place index.php into your local web server root directory (e.g., htdocs, www, or Laragon public folder).

2. Create the database:
Create a database matching your DB_NAME setting (e.g., bibliotheque_db):

CREATE DATABASE bibliotheque_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

3. Start the server:
Using PHP's built-in server:

php -S localhost:8000

Or access it through your local stack (XAMPP, Laragon, etc.).

4. Directory permissions:
Ensure the script has write permissions to create the target uploads folder (uploads/books/).

Application Structure

├── index.php           # Core application file (Configuration, Logic, UI, Reader Modal)
└── uploads/
    └── books/          # Directory where uploaded eBook files are safely stored


Roadmap

x] Step 1: Core CRUD & File Upload Engine

[x] Step 2: ISBN Lookup Integration (Google Books + Open Library APIs)

[x] Step 3: UI Overhaul with Tailwind CSS & Responsive Layout

[x] Step 4: Integrated In-Browser Viewer & Reader Modal

[ ] Step 5: Full-Text Content Indexing & Client-Side EPUB Reader Integration (ePub.js)

