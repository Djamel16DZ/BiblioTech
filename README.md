# BiblioTech - Gestionnaire de Bibliothèque Numérique (MVP)

BiblioTech est une application web légère et intuitive conçue en PHP et Tailwind CSS pour gérer, organiser et consulter une bibliothèque numérique (PDF, EPUB, MOBI).

---

## 🌟 Fonctionnalités Principales

- **Recherche Automatique via ISBN (100% PHP/Server-Side)** :
  - Interroge automatiquement **Google Books API** et **Open Library API** directement depuis le serveur PHP.
  - Remplit automatiquement le titre, l'auteur, l'éditeur, l'année de publication, le nombre de pages et le résumé.
  - Contourne l'ensemble des blocages CORS et restrictions réseau du navigateur client.
- **Gestion Complète des Ouvrages (CRUD)** :
  - Ajout, modification, suppression et téléchargement de documents numériques.
  - Stockage sécurisé des fichiers joints (`PDF`, `EPUB`, `MOBI`).
- **Visionneuse Intégrée Multi-format** :
  - **PDF** : Intégration native dans le navigateur.
  - **EPUB** : Liseuse interactive intégrée alimentée par `ePUB.js` (navigation page par page).
  - **MOBI** : Détection et lien direct de téléchargement.
- **Interface Moderne & Ergonomique** :
  - Panneau latéral rétractable/collapsible.
  - Statistiques en temps réel (nombre total de livres, répartition par format).
  - Mode plein écran pour la lecture d'ouvrages.
- **Moteur de Recherche & Filtres** :
  - Recherche globale par titre, auteur, ISBN ou cote de classement.
  - Filtrage combiné par format (`PDF`, `EPUB`, `MOBI`) et par catégorie.
  - Pagination dynamique.

---

## 🛠️ Stack Technique

- **Backend** : PHP 7.4+ (PDO MySQL, Stream Context / cURL)
- **Base de Données** : MySQL / MariaDB (auto-création de la table à l'initialisation)
- **Frontend** : HTML5, JavaScript (ES6+), Tailwind CSS (CDN), FontAwesome 6
- **Bibliothèques JS externes** :
  - [ePUB.js](https://github.com/futurepress/epub.js/) (Lecture native EPUB)
  - JSZip (Prérequis pour ePUB.js)

---

## 📁 Structure du Projet

```text
.
├── index.php           # Application monolithique (Backend API + Frontend UI)
├── README.md           # Documentation du projet
└── uploads/
    └── books/          # Dossier de stockage automatique des fichiers importés
```

---

## 🚀 Installation & Configuration

### 1. Prérequis
- Un serveur web local ou distant (XAMPP, WampServer, MAMP, Docker, ou CLI PHP).
- PHP version **7.4** ou supérieure.
- Un serveur de base de données **MySQL / MariaDB**.

### 2. Configuration de la base de données
Par défaut, le fichier `index.php` pointe vers une configuration locale standard. Vous pouvez modifier les identifiants au début du fichier `index.php` si nécessaire :

```php
$host = 'localhost';
$db   = 'bibliotheque_db';
$user = 'root';
$pass = '';
```

> **Note** : La base de données `bibliotheque_db` doit être créée au préalable (ex: via `CREATE DATABASE bibliotheque_db;`). La table `livres` sera quant à elle créée automatiquement lors du premier chargement de la page.

### 3. Démarrage rapide (PHP Built-in Server)

```bash
# Se placer dans le répertoire du projet
cd bibliotech

# Démarrer le serveur de développement PHP
php -S localhost:8000
```

Ouvrez votre navigateur à l'adresse : `http://localhost:8000`

---

## 📖 Utilisation

1. **Ajouter un ouvrage via ISBN** :
   - Entrez un code ISBN à 10 ou 13 chiffres (ex: `9782070619177`).
   - Cliquez sur **"Remplir via ISBN"**.
   - Le serveur récupère automatiquement les détails du livre.
   - Joignez le fichier numérique correspondant (`.pdf`, `.epub`, ou `.mobi`) et validez.

2. **Lire / Prévisualiser un livre** :
   - Cliquez sur l'icône **Œil** ($\mathcal{O}$) dans la liste des ouvrages.
   - Les fichiers PDF et EPUB s'ouvriront directement dans la liseuse modale.

---

## 📄 Licence

Projet open-source distribué sous la licence [MIT](LICENSE).