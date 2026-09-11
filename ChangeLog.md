# ChangeLog

## 3.3.1 — Non publiée

- CardDAV exclut les contacts dont le tiers est inaccessible : affectation commerciale ou extension d’accès native, permissions, confidentialité et partages d’entité cumulés. Les contacts sans tiers restent soumis à leurs propres règles. Le contrôle couvre les listes, les lectures directes ou multiples et les modifications/suppressions.
- L’indicateur de changement du carnet Contacts suit son périmètre visible, y compris après retrait d’une affectation commerciale ou changement de catégorie. Une erreur de lecture du carnet est signalée sans présenter un carnet vide.
- Nouveau réglage CardDAV par entité, désactivé par défaut, pour synchroniser le titre/civilité des contacts. Désactivé, il omet la civilité des vCards et conserve celle de Dolibarr lors des mises à jour ; le poste/fonction reste synchronisé. L’affichage facultatif du nom du tiers ne remplace plus la civilité.
- Aucun changement de schéma ni nettoyage des contacts. Après mise à jour, vérifier la nouvelle synchronisation et le retrait des anciens contacts hors périmètre sur les clients déployés. Les essais d’instance et de clients restent à exécuter ; voir `doc/correctifs-3.3.1.md`.

## 3.3 — Non publiée

- Réglages répartis dans cinq onglets natifs : Réglages, CardDAV, CalDAV, Compatibilité et À propos ; sauvegarde limitée à l’onglet et à l’entité, avec validation des références. L’aide de connexion et les informations et avertissements de compatibilité restent visibles dans des notifications natives persistantes.
- Catalogues anglais, français, allemand, espagnol et italien ; métadonnées affichées depuis le descripteur, identité CDav et crédits Befox conservés. Le bandeau de présentation de l’onglet À propos s’affiche sous ses deux colonnes.
- Connexion DAV stateless fixée sur l’entité de l’URL avant chargement ; authentification native et contrôle séparé de l’admission Multicompany, découverte des affectations transverses et droits réels des liens ICS. Les URL de découverte et de compte utilisateur sont identifiées séparément ; les URL complètes sont lisibles et copiables avec le bouton natif.
- Montage WebDAV administratif limité aux répertoires configurés des modules natifs, avec contrôles de droits et de chemins ; les répertoires d’autres entités et les liens symboliques ne sont plus parcourus.
- Mutations CardDAV et CalDAV réalisées par les objets natifs, avec transactions et conservation du propriétaire des objets partagés ; métadonnées URI/UID CardDAV séparées et installation idempotente. Décodage des liens ICS historiques et des notes vCard corrigé ; sérialisation iCalendar par Sabre avec accents, échappement et dates de fin exclusives.
- Génération des tâches depuis le trigger natif `PROJECT_VALIDATE`, dans la transaction de validation : services et documents accessibles, rôles contrôlés, numérotation native, absence de doublons et annulation complète en cas d’échec. Valider les projets depuis leur entité propriétaire.
- Socle conservé : Dolibarr 16.0 et PHP 8.0 ; cible de vérification étendue à Dolibarr 24, sous réserve des exigences PHP du core. Contrats des neuf versions majeures documentés, prérequis centralisés et tests reproductibles fournis.

Les validations sur instances Dolibarr et Multicompany, notamment en mode transverse, restent à exécuter. Les contrôles simulés sont identifiés comme tels.

L’historique antérieur reste disponible dans les commits du dépôt ; aucune date de publication passée n’est reconstituée.
