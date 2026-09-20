# 📚 BiblioTech MVP - Application Web de Bibliothèque Numérique

**BiblioTech** est une application web minimale viable (MVP) conçue sous une architecture **Single-File PHP**, dédiée à la gestion, au catalogage, à la prévisualisation et au téléchargement d'ouvrages numériques (**PDF, EPUB, MOBI**).

L'application propose une interface moderne, ultra-fluide et adaptative grâce à **Tailwind CSS**, respectant un design de type dashboard avec un layout fixe sans défilement global de la page.

---

## 🚀 Fonctionnalités Principales

* **Gestion complète (CRUD) :** Ajout, modification et suppression d'ouvrages numériques avec nettoyage automatique des fichiers associés sur le serveur.
* **Champs ciblés :** Gestion précise du Titre, Auteur(s), Format (PDF, EPUB, MOBI), Catégorie, **Cote de bibliothèque**, **ISBN**, Éditeur, Année, Nombre de pages, Résumé et Fichier numérique.
* **Panneau latéral rétractable :** Formulaire de saisie/édition accessible via un bouton de bascule (toggle) doté de transitions fluides.
* **Recherche et Filtres multicritères :** Recherche instantanée par mots-clés (titre, auteur, ISBN, cote) et filtres dynamiques par format et catégorie.
* **Pagination intégrée :** Navigation fluide par blocs de 15 ouvrages avec conservation des filtres actifs.
* **Lecteur & Prévisualisation intégrés (Modale) :** 
  * 📄 **Lecteur PDF natif** intégré par iframe.
  * 📖 **Lecteur EPUB interactif en ligne** (propulsé par *Epub.js*) avec navigation par pages/chapitres.
  * 📱 Panneau de secours avec accès direct au téléchargement pour les formats **MOBI**.
  * Option de passage en mode plein écran.
* **Indicateurs clés (KPIs) :** Affichage en en-tête des volumes totaux et par format (PDF, EPUB, MOBI).

---

## 🛠️ Stack Technique

* **Backend :** PHP (orienté objet via PDO, gestion sécurisée des uploads de fichiers).
* **Base de données :** MySQL / MariaDB.
* **Frontend & Style :** HTML5, Tailwind CSS (via CDN), FontAwesome (icônes).
* **Bibliothèques JS :** Epub.js & JSZip (pour la lecture EPUB côté client).
* **Architecture :** *Single-File PHP* (`index.php`).

---

## ⚙️ Prérequis & Installation

1. **Environnement local :** Assurez-vous d'avoir un serveur local actif (ex: **XAMPP**, **Laragon** ou un serveur PHP/MySQL intégré).
2. **Dépôt du projet :** Placez le fichier unique `index.php` dans un dossier dédié sur votre serveur (ex: `C:/laragon/www/bibliotheque/`).
3. **Base de données :** 
   * Créez une base de données nommée `bibliotheque_db` (ou modifiez les paramètres `$host`, `$db`, `$user`, `$pass` tout en haut du fichier `index.php`).
   * L'application crée automatiquement la table `livres` et le dossier d'upload `uploads/books/` lors de sa première exécution.

---

## 📂 Structure du Projet

```text
bibliotheque/
│
├── index.php             # Fichier unique contenant toute l'application (PHP, HTML, Tailwind, JS)
└── uploads/
    └── books/            # Dossier de stockage centralisé des fichiers PDF, EPUB et MOBI