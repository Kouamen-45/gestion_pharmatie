achats(
id_achat
date_achat
id_fournisseur
montant_total
montant_paye
statut_paiement
mode_reglement
date_echeance
id_utilisateur
num_facture
)

ajustements(
id_ajustement   
id_produit  
id_stock    
type_ajustement 
quantite_ajustee    
motif   
date_ajustement 
id_utilisateur
)

assurances(
id_assurance 
nom_assurance   
taux_couverture 
telephone   
)

caisse(
id_mouvement
date_mouvement
type_mouvement
montant
motif
id_vente
id_utilisateu
)

charges(
id_charge
date_operation
libelle_operation
montant
code_compte
mode_paiement
commentaire
created_at
)

clients(
id_client
nom
prenom
telephone
adresse
date_creation
)

clotures(
id_cloture 
date_cloture    
total_especes   
total_mobile_money  
total_assurance 
montant_final   
id_utilisateur  
nb_ventes   
total_achat marge_brute 
)

commandes(
id_commande   
id_fournisseur  
date_commande   
statut  
total_prevu
)

commandes_envoyees(
id_commande  
id_fournisseur  
date_envoi  
statut  
montant_total
)

commande_lignes(
id_ligne    
id_commande 
id_produit  
quantite_commandee  
quantite_recue
)

compte_charges(
id   
code_compte 
libelle 
parent  
)

details_ventes(
id_detail    
id_vente    
id_produit  
quantite    
prix_unitaire   
type_unite
)

detail_achats(
id_detail_achat
id_achat
id_produit
quantite_recue
prix_achat_unitaire
date_peremption
)

detail_ventes(
id_detail
id_vente
id_produit
quantite
prix_unitaire
type_unite
)

familles(
id_famille
nom_famille
code_famille
)

fournisseurs(
id_fournisseur
nom_fournisseur
telephone
email
)

inventaires(
id_inventaire
date_debut
type_inventaire
statut
id_utilisateur
commentaire
)

inventaire_lignes(
id_ligne
id_inventaire
id_produit
id_stock
stock_theorique
stock_reel
ecart
)

logs_activites(
id_log   
utilisateur 
action_type 
description 
date_action 
ip_adresse
)

mouvements_stock(
id_mouvement
id_produit
id_stock
type_mouvement
quantite
date_mouvement
id_utilisateur
commentaire
motif
)

plan_comptable(
code_compte  
libelle_compte
)

produits(
id_produit
nom_commercial
id_categorie
id_sous_famille
id_fournisseur
prix_unitaire
seuil_alerte
molecule
unite_detail
rapport_boite_detail
prix_unitaire_detail
stock_max
id_fournisseur_pref
dosage
description
delai_peremption
est_divers
emplacement
est_detail
coefficient_division
actif
prix_acha
)

sessions_caisse(
id_session
date_ouverture
date_cloture
fond_caisse_depart
montant_theorique
montant_final_reel
statut
id_utilisateur
)

sous_familles(
id_sous_famille
id_famille
nom_sous_famille
)

stocks(
id_stock
id_produit
numero_lot
prix_achat
date_peremption
quantite_disponible
date_reception
)

utilisateurs(
id_user
nom_complet
username
password
role
statut
)

ventes(
id_vente
date_vente
total
id_utilisateur
id_client
mode_paiement
id_assurance
part_assurance
part_patient
remise_montant
id_transaction_mobile
statut_paiement
remise
)

