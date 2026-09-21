-- =====================================================
-- Création de la base de données (si non existante)
-- =====================================================
CREATE DATABASE IF NOT EXISTS bibliotheque_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bibliotheque_db;

-- =====================================================
-- Structure de la table `livres`
-- =====================================================
DROP TABLE IF EXISTS livres;

CREATE TABLE livres (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titre VARCHAR(255) NOT NULL,
    auteur VARCHAR(255) NOT NULL,
    isbn VARCHAR(50) DEFAULT NULL,
    cote VARCHAR(50) DEFAULT NULL, -- Cote bibliothèque (ex: 843.9 HUGO)
    editeur VARCHAR(100) DEFAULT NULL,
    annee INT DEFAULT NULL,
    format VARCHAR(10) NOT NULL, -- PDF, EPUB, MOBI
    categorie VARCHAR(100) NOT NULL,
    pages INT DEFAULT NULL,
    resume TEXT DEFAULT NULL,
    fichier VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Exemples de données initiales (Optionnel)
-- =====================================================
INSERT INTO livres (titre, auteur, isbn, cote, editeur, annee, format, categorie, pages, resume, fichier) VALUES
('Les Misérables', 'Victor Hugo', '978-2070409228', '843.9 HUG', 'Gallimard', 1862, 'EPUB', 'Roman', 1488, 'Fresque épique et sociale décrivant la vie des misérables.', 'uploads/books/exemple_les_miserables.epub'),
('Clean Code', 'Robert C. Martin', '978-0132350884', '005.1 MAR', 'Prentice Hall', 2008, 'PDF', 'Technique', 464, 'Manuel de l''art de l''écriture de code propre et maintenable.', 'uploads/books/exemple_clean_code.pdf'),
('Dune', 'Frank Herbert', '978-2266175517', '813.54 HER', 'Pocket', 1965, 'MOBI', 'Science-Fiction', 416, 'Sur la planète désertique Arrakis...', 'uploads/books/exemple_dune.mobi');
