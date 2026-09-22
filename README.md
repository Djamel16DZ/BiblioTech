BiblioTech — Full-Stack Digital Library Application

BiblioTech is a monolithic, single-file PHP digital library management system for organizing, indexing, and viewing digital publications such as PDF, EPUB, and MOBI files.

Key Features
Centralized Single-File Architecture (index.php)

Complete routing, controller logic, database persistence, and presentation are contained in a single PHP file.

Auto-Migration Database Layer

Automatically initializes the required database schema and indexes on first load using PDO.

Includes standard and full-text indexes for:

idx_isbn

idx_cote

idx_format

idx_categorie

ft_search — FULLTEXT index across titre, auteur, resume, and fulltext_content.

Full-Text Content Indexing & Memory Safety

Automatically extracts text content from uploaded PDF books for searchable catalog indexing.

Enforces a 15 MB file size limit during text extraction to prevent memory_limit exhaustion on large technical manuals.

Stores extracted content in the fulltext_content database field.

Employs MySQL/MariaDB FULLTEXT search capabilities using IN BOOLEAN MODE, with hardened LIKE fallback queries across metadata and extracted text.

Hardened PDO Prepared Statements

Explicitly uses unique named parameters (:l1, :l2, :l3, etc.) across multi-field search conditions to prevent:

SQLSTATE[HY093]: Invalid parameter number


This ensures compatibility when native prepared statements are enabled:

PDO::ATTR_EMULATE_PREPARES => false

Resilient ISBN Auto-Enrichment

Performs asynchronous ISBN lookups using the Google Books API.

Automatically falls back to the Open Library API when necessary.

Uses a hybrid HTTP transport mechanism (fetchUrl) with cURL fallback to function even when allow_url_fopen is disabled in the PHP configuration.

Integrated Multi-Format Reader Modal

Native in-browser PDF viewing through an embedded iframe viewer.

Interactive client-side EPUB reading powered by ePub.js and JSZip.

Direct download fallback interface for MOBI formats.

Hardened Security & Robust Upload Handling

Strict dual-layer validation checks both file extensions (.pdf, .epub, .mobi) and MIME types via PHP finfo / fileinfo.

Cryptographically secure filename generation using:

bin2hex(random_bytes(16))


XSS protection through output escaping with htmlspecialchars().

Contextual upload error reporting for server limits such as upload_max_filesize and post_max_size.

Responsive Interface

Built with Tailwind CSS and Font Awesome.

Collapsible side drawer for book submissions.

Dynamic catalog filters for Category and Format.

Search bar with multi-term token parsing.

Requirements
Requirement	Version / Details
PHP	8.0 or higher
PHP Extensions	pdo_mysql, fileinfo, zip (for EPUB rendering), curl (recommended)
Database	MariaDB 10.4+ or MySQL 8.0+ with InnoDB FULLTEXT support
Web Server	Apache, Nginx, Laragon, XAMPP, or PHP built-in development server
Internet Access	Required for Tailwind CSS, Font Awesome, ePub.js CDN, and ISBN API lookups
Database Configuration

Database credentials are defined in the centralized configuration block at the top of index.php:

define('DB_HOST', 'localhost');
define('DB_NAME', 'bibliotheque_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');


Note: Adjust these values according to your local environment setup.

Database Schema

BiblioTech automatically creates the livres table if it does not already exist.

The resulting schema is equivalent to:

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
    INDEX idx_categorie (categorie),

    FULLTEXT INDEX ft_search (
        titre,
        auteur,
        resume,
        fulltext_content
    )
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


Note: The fulltext_content field stores extracted textual content from supported uploaded documents and is included in the FULLTEXT index (ft_search) used by the catalog search functionality.

Installation & Running Locally
1. Clone or Copy the Files

Clone the repository:

git clone https://github.com/YOUR_USERNAME/YOUR_REPOSITORY.git
cd YOUR_REPOSITORY


Or manually place index.php into your local web server root directory:

htdocs for XAMPP

www for Laragon

Your configured Apache/Nginx document root

2. Create the Database

Create a database matching the DB_NAME value configured in index.php:

CREATE DATABASE bibliotheque_db
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;


The application will automatically create the required tables, columns, and indexes on first execution.

3. Start the Server

You can use PHP's built-in development server:

php -S localhost:8000


Then open:

http://localhost:8000


Alternatively, access the application through your local web server stack, such as Laragon or XAMPP.

4. Configure Directory Permissions

BiblioTech needs write access to the upload directory.

Make sure the following directory exists and is writable by the web server:

uploads/books/

Application Structure
BiblioTech/
├── index.php              # Core application: configuration, auto-migrations,
│                          # search logic, UI, and reader
└── uploads/
    └── books/             # Secure storage for uploaded eBook files

Security & Reliability Hardening
PDO Strict Compatibility

Resolved SQLSTATE[HY093] by ensuring unique named parameter placeholders exist across all conditional query blocks when prepared statement emulation is disabled.

Strict Dual Verification

Enforces extension and strict MIME-type checking using finfo:

application/pdf

application/epub+zip

application/x-mobipocket-ebook

Memory Protection

Caps PDF text stream extraction at 15 MB to prevent PHP runtime memory-limit crashes such as:

Fatal error: Allowed memory size exhausted

HTTP Fallback Transport

Features a custom fetchUrl() helper that leverages cURL when allow_url_fopen is restricted by the production php.ini configuration.

Randomized Filenames

Generates cryptographically secure filenames using:

bin2hex(random_bytes(16))


This helps prevent directory traversal and file overwrite collisions.

XSS & SQL Injection Prevention

Output escaping with htmlspecialchars().

Prepared SQL statements using PDO.

External Services & Libraries
Google Books API

Used for ISBN-based book metadata enrichment.

Open Library API

Used as a fallback when Google Books does not return sufficient information.

ePub.js & JSZip

Used for client-side EPUB parsing and dynamic in-browser rendering.

Tailwind CSS

Loaded through the Tailwind CSS CDN for application styling.

Font Awesome

Used for interface iconography.

Roadmap

 Step 1: Core CRUD & File Upload Engine

 Step 2: ISBN Lookup Integration (Google Books + Open Library APIs)

 Step 3: UI Overhaul with Tailwind CSS & Responsive Layout

 Step 4: Integrated In-Browser Viewer & Reader Modal

 Step 5: Full-Text Content Indexing & Client-Side EPUB Reader Integration (ePub.js)

 Hardening: Security audit, FULLTEXT query fixes, PDO parameter fix (HY093), MIME verification, memory safety caps, and cURL API fallbacks.

Project Status

Current Status: Feature Complete / Production-Hardened MVP

BiblioTech is in active maintenance.

All core feature deliverables, auto-migration handlers, search query edge cases, and in-browser reading integrations are fully implemented and verified.