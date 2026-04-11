-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1
-- Généré le : sam. 11 avr. 2026 à 02:21
-- Version du serveur : 10.4.32-MariaDB
-- Version de PHP : 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `pharmassist`
--

-- --------------------------------------------------------

--
-- Structure de la table `achats`
--

CREATE TABLE `achats` (
  `id_achat` int(11) NOT NULL,
  `date_achat` datetime DEFAULT current_timestamp(),
  `id_fournisseur` int(11) DEFAULT NULL,
  `montant_total` decimal(10,2) DEFAULT NULL,
  `montant_paye` decimal(10,2) DEFAULT 0.00,
  `statut_paiement` enum('non_paye','partiel','paye') DEFAULT 'non_paye',
  `mode_reglement` varchar(50) DEFAULT NULL,
  `date_echeance` date DEFAULT NULL,
  `id_utilisateur` int(11) DEFAULT NULL,
  `num_facture` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `ajustements`
--

CREATE TABLE `ajustements` (
  `id_ajustement` int(11) NOT NULL,
  `id_produit` int(11) DEFAULT NULL,
  `id_stock` int(11) DEFAULT NULL,
  `type_ajustement` enum('ajout','retrait') NOT NULL,
  `quantite_ajustee` int(11) NOT NULL,
  `motif` varchar(255) DEFAULT NULL,
  `date_ajustement` datetime DEFAULT current_timestamp(),
  `id_utilisateur` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `assurances`
--

CREATE TABLE `assurances` (
  `id_assurance` int(11) NOT NULL,
  `nom_assurance` varchar(100) NOT NULL,
  `taux_couverture` int(11) DEFAULT 80,
  `telephone` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `caisse`
--

CREATE TABLE `caisse` (
  `id_mouvement` int(11) NOT NULL,
  `date_mouvement` datetime DEFAULT current_timestamp(),
  `type_mouvement` enum('entree','sortie','ouverture','cloture') NOT NULL,
  `montant` decimal(10,2) NOT NULL,
  `motif` varchar(255) DEFAULT NULL,
  `id_vente` int(11) DEFAULT NULL,
  `id_utilisateur` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `catalogue_referentiel`
--

CREATE TABLE `catalogue_referentiel` (
  `id_ref` int(11) NOT NULL,
  `code_cip` varchar(20) DEFAULT NULL,
  `nom_produit` varchar(100) NOT NULL,
  `dosage` varchar(50) DEFAULT NULL,
  `prix_conseille` decimal(10,2) NOT NULL,
  `date_mise_a_jour` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `categories`
--

CREATE TABLE `categories` (
  `id_categorie` int(11) NOT NULL,
  `nom_categorie` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `charges`
--

CREATE TABLE `charges` (
  `id_charge` int(11) NOT NULL,
  `date_operation` date NOT NULL,
  `libelle_operation` varchar(255) NOT NULL,
  `montant` decimal(15,2) NOT NULL,
  `code_compte` varchar(10) DEFAULT NULL,
  `mode_paiement` enum('Espèces','Mobile Money','Chèque','Virement') DEFAULT 'Espèces',
  `commentaire` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `clients`
--

CREATE TABLE `clients` (
  `id_client` int(11) NOT NULL,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) DEFAULT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `adresse` text DEFAULT NULL,
  `date_creation` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `clients`
--

INSERT INTO `clients` (`id_client`, `nom`, `prenom`, `telephone`, `adresse`, `date_creation`) VALUES
(1, 'CLIENT DIVERS', 'CLIENT DIVERS', NULL, NULL, '2026-04-07 08:41:45');

-- --------------------------------------------------------

--
-- Structure de la table `clotures`
--

CREATE TABLE `clotures` (
  `id_cloture` int(11) NOT NULL,
  `date_cloture` datetime DEFAULT current_timestamp(),
  `total_especes` int(11) DEFAULT 0,
  `total_mobile_money` int(11) DEFAULT 0,
  `total_assurance` int(11) DEFAULT 0,
  `montant_final` int(11) DEFAULT 0,
  `id_utilisateur` int(11) DEFAULT NULL,
  `nb_ventes` int(11) DEFAULT 0,
  `total_achat` int(11) DEFAULT 0,
  `marge_brute` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `commandes`
--

CREATE TABLE `commandes` (
  `id_commande` int(11) NOT NULL,
  `id_fournisseur` int(11) DEFAULT NULL,
  `date_commande` datetime DEFAULT current_timestamp(),
  `statut` enum('en_attente','recue_partielle','terminee','annulee') DEFAULT NULL,
  `total_prevu` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `commandes_envoyees`
--

CREATE TABLE `commandes_envoyees` (
  `id_commande` int(11) NOT NULL,
  `id_fournisseur` int(11) DEFAULT NULL,
  `date_envoi` datetime DEFAULT current_timestamp(),
  `statut` enum('envoyé','reçu','annulé') DEFAULT 'envoyé',
  `montant_total` decimal(15,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `commande_lignes`
--

CREATE TABLE `commande_lignes` (
  `id_ligne` int(11) NOT NULL,
  `id_commande` int(11) DEFAULT NULL,
  `id_produit` int(11) DEFAULT NULL,
  `quantite_commandee` int(11) DEFAULT NULL,
  `quantite_recue` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `compte_charges`
--

CREATE TABLE `compte_charges` (
  `id` int(11) NOT NULL,
  `code_compte` varchar(10) DEFAULT NULL,
  `libelle` varchar(255) DEFAULT NULL,
  `parent` varchar(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `details_ventes`
--

CREATE TABLE `details_ventes` (
  `id_detail` int(11) NOT NULL,
  `id_vente` int(11) NOT NULL,
  `id_produit` int(11) NOT NULL,
  `quantite` int(11) NOT NULL,
  `prix_unitaire` decimal(10,2) NOT NULL,
  `type_unite` enum('boite','detail') DEFAULT 'boite'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `detail_achats`
--

CREATE TABLE `detail_achats` (
  `id_detail_achat` int(11) NOT NULL,
  `id_achat` int(11) DEFAULT NULL,
  `id_produit` int(11) DEFAULT NULL,
  `quantite_recue` int(11) DEFAULT NULL,
  `prix_achat_unitaire` decimal(10,2) DEFAULT NULL,
  `date_peremption` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `detail_ventes`
--

CREATE TABLE `detail_ventes` (
  `id_detail` int(11) NOT NULL,
  `id_vente` int(11) NOT NULL,
  `id_produit` int(11) NOT NULL,
  `quantite` int(11) NOT NULL,
  `prix_unitaire` decimal(10,2) NOT NULL,
  `type_unite` varchar(110) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `familles`
--

CREATE TABLE `familles` (
  `id_famille` int(11) NOT NULL,
  `nom_famille` varchar(100) NOT NULL,
  `code_famille` varchar(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `familles`
--

INSERT INTO `familles` (`id_famille`, `nom_famille`, `code_famille`) VALUES
(1, 'pharmacie', NULL),
(2, 'divers', NULL);

-- --------------------------------------------------------

--
-- Structure de la table `fournisseurs`
--

CREATE TABLE `fournisseurs` (
  `id_fournisseur` int(11) NOT NULL,
  `nom_fournisseur` varchar(100) NOT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `fournisseurs`
--

INSERT INTO `fournisseurs` (`id_fournisseur`, `nom_fournisseur`, `telephone`, `email`) VALUES
(1, 'valentin Ebini Mah', '652105979', 'ebinivale@gmail.com');

-- --------------------------------------------------------

--
-- Structure de la table `inventaires`
--

CREATE TABLE `inventaires` (
  `id_inventaire` int(11) NOT NULL,
  `date_debut` datetime DEFAULT current_timestamp(),
  `type_inventaire` enum('general','partiel') NOT NULL,
  `statut` enum('en_cours','valide','annule') DEFAULT 'en_cours',
  `id_utilisateur` int(11) DEFAULT NULL,
  `commentaire` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `inventaire_lignes`
--

CREATE TABLE `inventaire_lignes` (
  `id_ligne` int(11) NOT NULL,
  `id_inventaire` int(11) DEFAULT NULL,
  `id_produit` int(11) DEFAULT NULL,
  `id_stock` int(11) DEFAULT NULL,
  `stock_theorique` int(11) DEFAULT NULL,
  `stock_reel` int(11) DEFAULT 0,
  `ecart` int(11) GENERATED ALWAYS AS (`stock_reel` - `stock_theorique`) VIRTUAL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `logs_activites`
--

CREATE TABLE `logs_activites` (
  `id_log` int(11) NOT NULL,
  `utilisateur` varchar(100) DEFAULT NULL,
  `action_type` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `date_action` datetime DEFAULT current_timestamp(),
  `ip_adresse` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `mouvements_stock`
--

CREATE TABLE `mouvements_stock` (
  `id_mouvement` int(11) NOT NULL,
  `id_produit` int(11) DEFAULT NULL,
  `id_stock` int(11) DEFAULT NULL,
  `type_mouvement` enum('entree_achat','sortie_vente','retour_fournisseur','casse','perime','ajustement_inventaire','Initialisation du stock') DEFAULT NULL,
  `quantite` int(11) DEFAULT NULL,
  `date_mouvement` datetime DEFAULT current_timestamp(),
  `id_utilisateur` int(11) DEFAULT NULL,
  `commentaire` varchar(255) DEFAULT NULL,
  `motif` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `plan_comptable`
--

CREATE TABLE `plan_comptable` (
  `code_compte` varchar(10) NOT NULL,
  `libelle_compte` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `produits`
--

CREATE TABLE `produits` (
  `id_produit` int(11) NOT NULL,
  `nom_commercial` varchar(100) NOT NULL,
  `id_categorie` int(11) DEFAULT NULL,
  `id_sous_famille` int(11) DEFAULT NULL,
  `id_fournisseur` int(11) DEFAULT NULL,
  `prix_unitaire` decimal(10,2) DEFAULT NULL,
  `seuil_alerte` int(11) DEFAULT 10,
  `molecule` varchar(100) DEFAULT NULL,
  `unite_detail` varchar(50) DEFAULT 'Plaquette',
  `rapport_boite_detail` int(11) DEFAULT 1,
  `prix_unitaire_detail` decimal(10,2) DEFAULT NULL,
  `stock_max` int(11) DEFAULT 50,
  `id_fournisseur_pref` int(11) DEFAULT 1,
  `dosage` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `delai_peremption` int(11) DEFAULT 6,
  `est_divers` tinyint(1) DEFAULT 0,
  `emplacement` varchar(255) DEFAULT NULL,
  `est_detail` tinyint(1) DEFAULT 0,
  `coefficient_division` int(11) DEFAULT 1,
  `actif` tinyint(1) DEFAULT 1,
  `prix_achat` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `sessions_caisse`
--

CREATE TABLE `sessions_caisse` (
  `id_session` int(11) NOT NULL,
  `date_ouverture` datetime DEFAULT current_timestamp(),
  `date_cloture` datetime DEFAULT NULL,
  `fond_caisse_depart` decimal(10,2) NOT NULL,
  `montant_theorique` decimal(10,2) DEFAULT NULL,
  `montant_final_reel` decimal(10,2) DEFAULT NULL,
  `statut` enum('ouvert','ferme') DEFAULT 'ouvert',
  `id_utilisateur` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `sous_familles`
--

CREATE TABLE `sous_familles` (
  `id_sous_famille` int(11) NOT NULL,
  `id_famille` int(11) NOT NULL,
  `nom_sous_famille` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `sous_familles`
--

INSERT INTO `sous_familles` (`id_sous_famille`, `id_famille`, `nom_sous_famille`) VALUES
(1, 1, 'antalgique'),
(2, 1, 'divers');

-- --------------------------------------------------------

--
-- Structure de la table `stocks`
--

CREATE TABLE `stocks` (
  `id_stock` int(11) NOT NULL,
  `id_produit` int(11) NOT NULL,
  `numero_lot` varchar(50) NOT NULL,
  `prix_achat` decimal(10,2) DEFAULT 0.00,
  `date_peremption` date NOT NULL,
  `quantite_disponible` int(11) NOT NULL,
  `date_reception` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `utilisateurs`
--

CREATE TABLE `utilisateurs` (
  `id_user` int(11) NOT NULL,
  `nom_complet` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('Administrateur','Pharmacien','Vendeur') DEFAULT 'Pharmacien',
  `statut` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `utilisateurs`
--

INSERT INTO `utilisateurs` (`id_user`, `nom_complet`, `username`, `password`, `role`, `statut`) VALUES
(1, 'Administrateur', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrateur', 1),
(2, 'Pharmacien Chef', 'pharma', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Pharmacien', 1);

-- --------------------------------------------------------

--
-- Structure de la table `ventes`
--

CREATE TABLE `ventes` (
  `id_vente` int(11) NOT NULL,
  `date_vente` datetime DEFAULT current_timestamp(),
  `total` decimal(10,2) NOT NULL,
  `id_utilisateur` int(11) NOT NULL,
  `id_client` int(11) DEFAULT 1,
  `mode_paiement` varchar(50) DEFAULT 'Espèces',
  `id_assurance` int(11) DEFAULT NULL,
  `part_assurance` decimal(10,2) DEFAULT 0.00,
  `part_patient` decimal(10,2) DEFAULT 0.00,
  `remise_montant` decimal(10,2) DEFAULT 0.00,
  `id_transaction_mobile` varchar(100) DEFAULT NULL,
  `statut_paiement` enum('complet','credit','en_attente') DEFAULT 'complet',
  `remise` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `achats`
--
ALTER TABLE `achats`
  ADD PRIMARY KEY (`id_achat`);

--
-- Index pour la table `ajustements`
--
ALTER TABLE `ajustements`
  ADD PRIMARY KEY (`id_ajustement`),
  ADD KEY `id_produit` (`id_produit`),
  ADD KEY `id_stock` (`id_stock`);

--
-- Index pour la table `assurances`
--
ALTER TABLE `assurances`
  ADD PRIMARY KEY (`id_assurance`);

--
-- Index pour la table `caisse`
--
ALTER TABLE `caisse`
  ADD PRIMARY KEY (`id_mouvement`),
  ADD KEY `fk_caisse_vente` (`id_vente`);

--
-- Index pour la table `catalogue_referentiel`
--
ALTER TABLE `catalogue_referentiel`
  ADD PRIMARY KEY (`id_ref`),
  ADD KEY `idx_nom` (`nom_produit`);

--
-- Index pour la table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id_categorie`);

--
-- Index pour la table `charges`
--
ALTER TABLE `charges`
  ADD PRIMARY KEY (`id_charge`),
  ADD KEY `code_compte` (`code_compte`);

--
-- Index pour la table `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`id_client`);

--
-- Index pour la table `clotures`
--
ALTER TABLE `clotures`
  ADD PRIMARY KEY (`id_cloture`),
  ADD KEY `id_utilisateur` (`id_utilisateur`);

--
-- Index pour la table `commandes`
--
ALTER TABLE `commandes`
  ADD PRIMARY KEY (`id_commande`),
  ADD KEY `id_fournisseur` (`id_fournisseur`);

--
-- Index pour la table `commandes_envoyees`
--
ALTER TABLE `commandes_envoyees`
  ADD PRIMARY KEY (`id_commande`),
  ADD KEY `id_fournisseur` (`id_fournisseur`);

--
-- Index pour la table `commande_lignes`
--
ALTER TABLE `commande_lignes`
  ADD PRIMARY KEY (`id_ligne`),
  ADD KEY `id_commande` (`id_commande`);

--
-- Index pour la table `compte_charges`
--
ALTER TABLE `compte_charges`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `details_ventes`
--
ALTER TABLE `details_ventes`
  ADD PRIMARY KEY (`id_detail`),
  ADD KEY `id_vente` (`id_vente`),
  ADD KEY `id_produit` (`id_produit`);

--
-- Index pour la table `detail_achats`
--
ALTER TABLE `detail_achats`
  ADD PRIMARY KEY (`id_detail_achat`),
  ADD KEY `id_achat` (`id_achat`);

--
-- Index pour la table `detail_ventes`
--
ALTER TABLE `detail_ventes`
  ADD PRIMARY KEY (`id_detail`),
  ADD KEY `fk_vente` (`id_vente`),
  ADD KEY `fk_produit` (`id_produit`);

--
-- Index pour la table `familles`
--
ALTER TABLE `familles`
  ADD PRIMARY KEY (`id_famille`);

--
-- Index pour la table `fournisseurs`
--
ALTER TABLE `fournisseurs`
  ADD PRIMARY KEY (`id_fournisseur`);

--
-- Index pour la table `inventaires`
--
ALTER TABLE `inventaires`
  ADD PRIMARY KEY (`id_inventaire`);

--
-- Index pour la table `inventaire_lignes`
--
ALTER TABLE `inventaire_lignes`
  ADD PRIMARY KEY (`id_ligne`);

--
-- Index pour la table `logs_activites`
--
ALTER TABLE `logs_activites`
  ADD PRIMARY KEY (`id_log`);

--
-- Index pour la table `mouvements_stock`
--
ALTER TABLE `mouvements_stock`
  ADD PRIMARY KEY (`id_mouvement`),
  ADD KEY `id_produit` (`id_produit`),
  ADD KEY `id_utilisateur` (`id_utilisateur`);

--
-- Index pour la table `plan_comptable`
--
ALTER TABLE `plan_comptable`
  ADD PRIMARY KEY (`code_compte`);

--
-- Index pour la table `produits`
--
ALTER TABLE `produits`
  ADD PRIMARY KEY (`id_produit`),
  ADD KEY `id_categorie` (`id_categorie`),
  ADD KEY `id_fournisseur` (`id_fournisseur`),
  ADD KEY `id_sous_famille` (`id_sous_famille`);

--
-- Index pour la table `sessions_caisse`
--
ALTER TABLE `sessions_caisse`
  ADD PRIMARY KEY (`id_session`);

--
-- Index pour la table `sous_familles`
--
ALTER TABLE `sous_familles`
  ADD PRIMARY KEY (`id_sous_famille`),
  ADD KEY `id_famille` (`id_famille`);

--
-- Index pour la table `stocks`
--
ALTER TABLE `stocks`
  ADD PRIMARY KEY (`id_stock`),
  ADD KEY `id_produit` (`id_produit`);

--
-- Index pour la table `utilisateurs`
--
ALTER TABLE `utilisateurs`
  ADD PRIMARY KEY (`id_user`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Index pour la table `ventes`
--
ALTER TABLE `ventes`
  ADD PRIMARY KEY (`id_vente`),
  ADD KEY `fk_ventes_assurance` (`id_assurance`),
  ADD KEY `fk_vente_client` (`id_client`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `achats`
--
ALTER TABLE `achats`
  MODIFY `id_achat` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `ajustements`
--
ALTER TABLE `ajustements`
  MODIFY `id_ajustement` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `assurances`
--
ALTER TABLE `assurances`
  MODIFY `id_assurance` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `caisse`
--
ALTER TABLE `caisse`
  MODIFY `id_mouvement` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `catalogue_referentiel`
--
ALTER TABLE `catalogue_referentiel`
  MODIFY `id_ref` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `categories`
--
ALTER TABLE `categories`
  MODIFY `id_categorie` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `charges`
--
ALTER TABLE `charges`
  MODIFY `id_charge` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `clients`
--
ALTER TABLE `clients`
  MODIFY `id_client` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT pour la table `clotures`
--
ALTER TABLE `clotures`
  MODIFY `id_cloture` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `commandes`
--
ALTER TABLE `commandes`
  MODIFY `id_commande` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `commandes_envoyees`
--
ALTER TABLE `commandes_envoyees`
  MODIFY `id_commande` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `commande_lignes`
--
ALTER TABLE `commande_lignes`
  MODIFY `id_ligne` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `compte_charges`
--
ALTER TABLE `compte_charges`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `details_ventes`
--
ALTER TABLE `details_ventes`
  MODIFY `id_detail` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `detail_achats`
--
ALTER TABLE `detail_achats`
  MODIFY `id_detail_achat` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `detail_ventes`
--
ALTER TABLE `detail_ventes`
  MODIFY `id_detail` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `familles`
--
ALTER TABLE `familles`
  MODIFY `id_famille` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT pour la table `fournisseurs`
--
ALTER TABLE `fournisseurs`
  MODIFY `id_fournisseur` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT pour la table `inventaires`
--
ALTER TABLE `inventaires`
  MODIFY `id_inventaire` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `inventaire_lignes`
--
ALTER TABLE `inventaire_lignes`
  MODIFY `id_ligne` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `logs_activites`
--
ALTER TABLE `logs_activites`
  MODIFY `id_log` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `mouvements_stock`
--
ALTER TABLE `mouvements_stock`
  MODIFY `id_mouvement` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `produits`
--
ALTER TABLE `produits`
  MODIFY `id_produit` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `sessions_caisse`
--
ALTER TABLE `sessions_caisse`
  MODIFY `id_session` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `sous_familles`
--
ALTER TABLE `sous_familles`
  MODIFY `id_sous_famille` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT pour la table `stocks`
--
ALTER TABLE `stocks`
  MODIFY `id_stock` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `utilisateurs`
--
ALTER TABLE `utilisateurs`
  MODIFY `id_user` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT pour la table `ventes`
--
ALTER TABLE `ventes`
  MODIFY `id_vente` int(11) NOT NULL AUTO_INCREMENT;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `ajustements`
--
ALTER TABLE `ajustements`
  ADD CONSTRAINT `ajustements_ibfk_1` FOREIGN KEY (`id_produit`) REFERENCES `produits` (`id_produit`),
  ADD CONSTRAINT `ajustements_ibfk_2` FOREIGN KEY (`id_stock`) REFERENCES `stocks` (`id_stock`);

--
-- Contraintes pour la table `caisse`
--
ALTER TABLE `caisse`
  ADD CONSTRAINT `fk_caisse_vente` FOREIGN KEY (`id_vente`) REFERENCES `ventes` (`id_vente`) ON DELETE SET NULL;

--
-- Contraintes pour la table `charges`
--
ALTER TABLE `charges`
  ADD CONSTRAINT `charges_ibfk_1` FOREIGN KEY (`code_compte`) REFERENCES `plan_comptable` (`code_compte`);

--
-- Contraintes pour la table `clotures`
--
ALTER TABLE `clotures`
  ADD CONSTRAINT `clotures_ibfk_1` FOREIGN KEY (`id_utilisateur`) REFERENCES `utilisateurs` (`id_user`);

--
-- Contraintes pour la table `commandes`
--
ALTER TABLE `commandes`
  ADD CONSTRAINT `commandes_ibfk_1` FOREIGN KEY (`id_fournisseur`) REFERENCES `fournisseurs` (`id_fournisseur`);

--
-- Contraintes pour la table `commandes_envoyees`
--
ALTER TABLE `commandes_envoyees`
  ADD CONSTRAINT `commandes_envoyees_ibfk_1` FOREIGN KEY (`id_fournisseur`) REFERENCES `fournisseurs` (`id_fournisseur`);

--
-- Contraintes pour la table `commande_lignes`
--
ALTER TABLE `commande_lignes`
  ADD CONSTRAINT `fk_commande_parent` FOREIGN KEY (`id_commande`) REFERENCES `commandes` (`id_commande`) ON DELETE CASCADE;

--
-- Contraintes pour la table `details_ventes`
--
ALTER TABLE `details_ventes`
  ADD CONSTRAINT `details_ventes_ibfk_1` FOREIGN KEY (`id_vente`) REFERENCES `ventes` (`id_vente`),
  ADD CONSTRAINT `details_ventes_ibfk_2` FOREIGN KEY (`id_produit`) REFERENCES `produits` (`id_produit`);

--
-- Contraintes pour la table `detail_achats`
--
ALTER TABLE `detail_achats`
  ADD CONSTRAINT `detail_achats_ibfk_1` FOREIGN KEY (`id_achat`) REFERENCES `achats` (`id_achat`) ON DELETE CASCADE;

--
-- Contraintes pour la table `detail_ventes`
--
ALTER TABLE `detail_ventes`
  ADD CONSTRAINT `fk_produit` FOREIGN KEY (`id_produit`) REFERENCES `produits` (`id_produit`),
  ADD CONSTRAINT `fk_vente` FOREIGN KEY (`id_vente`) REFERENCES `ventes` (`id_vente`) ON DELETE CASCADE;

--
-- Contraintes pour la table `mouvements_stock`
--
ALTER TABLE `mouvements_stock`
  ADD CONSTRAINT `mouvements_stock_ibfk_1` FOREIGN KEY (`id_produit`) REFERENCES `produits` (`id_produit`),
  ADD CONSTRAINT `mouvements_stock_ibfk_2` FOREIGN KEY (`id_utilisateur`) REFERENCES `utilisateurs` (`id_user`);

--
-- Contraintes pour la table `produits`
--
ALTER TABLE `produits`
  ADD CONSTRAINT `produits_ibfk_1` FOREIGN KEY (`id_categorie`) REFERENCES `categories` (`id_categorie`),
  ADD CONSTRAINT `produits_ibfk_2` FOREIGN KEY (`id_fournisseur`) REFERENCES `fournisseurs` (`id_fournisseur`),
  ADD CONSTRAINT `produits_ibfk_3` FOREIGN KEY (`id_sous_famille`) REFERENCES `sous_familles` (`id_sous_famille`);

--
-- Contraintes pour la table `sous_familles`
--
ALTER TABLE `sous_familles`
  ADD CONSTRAINT `sous_familles_ibfk_1` FOREIGN KEY (`id_famille`) REFERENCES `familles` (`id_famille`);

--
-- Contraintes pour la table `stocks`
--
ALTER TABLE `stocks`
  ADD CONSTRAINT `stocks_ibfk_1` FOREIGN KEY (`id_produit`) REFERENCES `produits` (`id_produit`);

--
-- Contraintes pour la table `ventes`
--
ALTER TABLE `ventes`
  ADD CONSTRAINT `fk_vente_client` FOREIGN KEY (`id_client`) REFERENCES `clients` (`id_client`),
  ADD CONSTRAINT `fk_ventes_assurance` FOREIGN KEY (`id_assurance`) REFERENCES `assurances` (`id_assurance`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
