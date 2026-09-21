# BiblioTech - Digital Library Manager (MVP)

BiblioTech is a lightweight, intuitive monolithic web application built with PHP and Tailwind CSS to manage, organize, and read digital books in PDF, EPUB, and MOBI formats.

---

## 🌟 Key Features

- **Automatic ISBN Data Fetching (Internal API & AJAX)**:
  - Dedicated PHP endpoint (`?ajax_isbn=...`) querying **Google Books API** (primary) with an automatic fallback to **Open Library API**.
  - Dynamically fetches and populates book details: title, author(s), publisher, publication year, page count, and summary.
  - Bypass browser CORS restrictions and network limitations seamlessly via server-side requests.
- **Complete Book Management (CRUD)**:
  - Add, edit, delete, and download digital documents.
  - Secure file upload handling: extension validation (`.pdf`, `.epub`, `.mobi`) and unique MD5 hashing for file storage.
  - Automatic physical file cleanup upon book deletion or file replacement.
- **Security & Upload Protection**:
  - Direct execution protection on storage folders using Apache access controls (`.htaccess`).
  - Restricts access in sensitive storage locations to safe document formats while blocking web execution scripts (PHP, CGI, etc.).
- **Integrated Multi-Format Reader & Viewer**:
  - In-browser modal viewer interface for direct previewing and reading of stored digital books.
- **Modern & Responsive UI (Tailwind CSS & FontAwesome)**:
  - Retractable/collapsible sidebar for adding and editing entries.
  - Real-time header dashboard displaying catalog statistics (total book count and format breakdown for PDF, EPUB, MOBI).
- **Search Engine, Filtering & Pagination**:
  - Global search combining title, author, ISBN, and library shelf mark (cote).
  - Combined filters by format (`PDF`, `EPUB`, `MOBI`) and category.
  - Dynamic pagination set to 15 records per page.

---

## 🛠️ Tech Stack

- **Backend**: PHP 7.4+ (PDO MySQL, Stream Context, Internal JSON API)
- **Database**: MySQL / MariaDB (auto-creates the `livres` table upon initial run)
- **Frontend**: HTML5, JavaScript ES6 (Fetch API, DOM manipulation), Tailwind CSS (CDN), FontAwesome 6
- **Storage & Security**: Local filesystem (`uploads/books/`) with `.htaccess` execution control and directory initialization

---

## 📁 Directory Structure

```text
.
├── index.php           # Monolithic Application (AJAX API, PHP Controller, Frontend UI)
├── README.md           # Project Documentation
└── uploads/
    └── books/          # Secure directory for uploaded book files
        └── .htaccess   # Apache access control policy preventing PHP execution