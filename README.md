# BiblioTech - Full-Stack Digital Library Application

BiblioTech is a monolithic, single-file PHP digital library management system for organizing, indexing, and viewing digital publications such as **PDF, EPUB, and MOBI** files.

## Key Features

* **Centralized Single-File Architecture (`index.php`)**

  * Complete routing, controller logic, database persistence, and presentation are contained in a single PHP file.

* **Auto-Migration Database Layer**

  * Automatically initializes the required database schema and indexes on first load using PDO.
  * Includes standard and full-text indexes for:

    * `idx_isbn`
    * `idx_cote`
    * `idx_format`
    * `idx_categorie`
    * `ft_livres_search` — `FULLTEXT` index across `titre`, `auteur`, `resume`, and `contenu_texte`

* **Full-Text Content Indexing**

  * Automatically extracts text content from uploaded books for searchable catalog indexing.
  * Stores extracted content in the `contenu_texte` database field.
  * Uses MySQL/MariaDB `FULLTEXT` search capabilities for deep catalog queries across metadata and extracted book content.

* **ISBN Auto-Enrichment**

  * Asynchronous ISBN lookup using the **Google Books API**.
  * Automatic fallback to the **Open Library API**.

* **Integrated Multi-Format Reader Modal**

  * Native in-browser PDF viewing through an embedded object viewer.
  * Interactive client-side EPUB reading powered by **ePub.js**.
  * Supports EPUB pagination, chapter navigation, and font controls.
  * Direct download fallback interface for MOBI formats.

* **Security-First File Handling**

  * MIME-type validation using PHP `finfo` (`fileinfo` extension).
  * Anti-directory-traversal protection using `realpath()`.
  * Cryptographically secure filenames using `bin2hex(random_bytes(16))`.
  * XSS protection through an `htmlspecialchars` helper.

* **Responsive Interface**

  * Built with **Tailwind CSS** and **Font Awesome**.
  * Collapsible side drawer.
  * Structured catalog filters.
  * Full-text search input.
  * Responsive layout for desktop and mobile devices.

---

## Requirements

Before installing BiblioTech, make sure your environment meets the following requirements:

| Requirement         | Version / Details                                                          |
| ------------------- | -------------------------------------------------------------------------- |
| **PHP**             | 8.0 or higher                                                              |
| **PHP Extensions**  | `pdo_mysql`, `fileinfo`, `zip` recommended for EPUB parsing                |
| **Database**        | MariaDB 10.4+ or MySQL 8.0+ with InnoDB `FULLTEXT` support                 |
| **Web Server**      | Apache, Nginx, or PHP built-in development server                          |
| **Internet Access** | Required for Tailwind CSS, Font Awesome, ePub.js CDN, and ISBN API lookups |

---

## Database Configuration

Database credentials are defined in the centralized configuration block at the top of `index.php`.

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'bibliotheque_db');
define('DB_USER', 'root');
define('DB_PASS', 'root');
define('DB_CHARSET', 'utf8mb4');
```

Adjust these values according to your local environment.

### Database Schema

BiblioTech automatically creates the `livres` table if it does not already exist.

The resulting schema is equivalent to:

```sql
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
    contenu_texte LONGTEXT DEFAULT NULL,
    fichier VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_isbn (isbn),
    INDEX idx_cote (cote),
    INDEX idx_format (format),
    INDEX idx_categorie (categorie),

    FULLTEXT INDEX ft_livres_search (
        titre,
        auteur,
        resume,
        contenu_texte
    )
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
```

> **Note:** The `contenu_texte` field stores extracted textual content from supported uploaded documents and is included in the `FULLTEXT` index used by the catalog search functionality.

---

## Installation & Running Locally

### 1. Clone or Copy the Files

Clone the repository:

```bash
git clone https://github.com/YOUR_USERNAME/YOUR_REPOSITORY.git
cd YOUR_REPOSITORY
```

Or manually place `index.php` into your local web server root directory, such as:

* `htdocs` for XAMPP
* `www` for Laragon
* Your configured Apache/Nginx document root

---

### 2. Create the Database

Create a database matching the `DB_NAME` value configured in `index.php`.

For example:

```sql
CREATE DATABASE bibliotheque_db
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;
```

The application will automatically create the required tables, columns, and indexes on first execution.

---

### 3. Start the Server

You can use PHP's built-in development server:

```bash
php -S localhost:8000
```

Then open:

```text
http://localhost:8000
```

Alternatively, access the application through your local stack such as **XAMPP**, **Laragon**, or another Apache/Nginx environment.

---

### 4. Configure Directory Permissions

BiblioTech needs write access to the upload directory.

Make sure the following directory exists and is writable by the web server:

```text
uploads/books/
```

Uploaded PDF, EPUB, and MOBI files will be stored in this directory.

---

## Application Structure

```text
BiblioTech/
├── index.php              # Core application: configuration, logic, UI, and reader
└── uploads/
    └── books/             # Secure storage for uploaded eBook files
```

> **Note:** The application intentionally uses a single-file architecture. This keeps the MVP lightweight and easy to deploy, while concentrating the core application logic in `index.php`.

---

## Security

BiblioTech implements several safeguards for uploaded files and user-provided data:

* MIME-type verification through PHP `finfo`.
* Protection against directory traversal using `realpath()`.
* Randomized filenames generated with `random_bytes()`.
* HTML output escaping using `htmlspecialchars()`.
* Prepared statements for database operations through PDO.
* Indexed and full-text database fields for efficient catalog searches.

These measures are intended to reduce common risks associated with file uploads and user-generated content.

---

## External Services & Libraries

BiblioTech currently relies on the following external resources and libraries.

### Google Books API

Used for ISBN-based book metadata enrichment.

### Open Library API

Used as a fallback when Google Books does not return sufficient information.

### ePub.js

Used for client-side EPUB rendering and interactive reading inside the reader modal, including pagination and navigation.

### JSZip

Used as part of the EPUB processing/rendering stack required by the client-side reader.

### Tailwind CSS

Loaded through the Tailwind CSS CDN for the application interface.

### Font Awesome

Used for interface icons.

> **Note:** Internet access is required for the full feature set when these resources are loaded from their respective CDNs or APIs. Core database operations and local file management can continue to operate locally once the application is installed.

---

## Roadmap

* [x] **Step 1:** Core CRUD & File Upload Engine
* [x] **Step 2:** ISBN Lookup Integration (Google Books + Open Library APIs)
* [x] **Step 3:** UI Overhaul with Tailwind CSS & Responsive Layout
* [x] **Step 4:** Integrated In-Browser Viewer & Reader Modal
* [x] **Step 5:** Full-Text Content Indexing & Client-Side EPUB Reader Integration (`ePub.js`)

---

## Project Status

**Current status:** Feature Complete / Active Maintenance

BiblioTech has fulfilled its core single-file MVP feature set, including:

* Digital library catalog management
* Secure PDF, EPUB, and MOBI file handling
* ISBN-based metadata enrichment
* Full-text content extraction and indexing
* MySQL/MariaDB `FULLTEXT` search
* In-browser PDF viewing
* Client-side EPUB rendering with `ePub.js`
* Interactive EPUB navigation and reading controls
* Responsive catalog interface

The project is now in **active maintenance**, with future development focused on improvements, optimization, compatibility, security, and additional functionality rather than the completion of the original MVP roadmap.