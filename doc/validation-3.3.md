# Validation de CDav 3.3

État au 2026-09-09. La branche de développement est `feat/cdav-3.3` ; aucune release n’est créée par ces contrôles.

## Contrôles exécutés

Environnement local : PHP 8.4.22, Python 3.12, sources Dolibarr disponibles en lecture seule. Les bibliothèques Sabre sont celles des tags 16.0.0 et 24.0.0, sans modification du core.

```sh
python scripts/check_core_contracts.py --core ../dolibarr --report doc/core-contracts.md
python scripts/run_checks.py --core-htdocs .test-cache/16.0.0/htdocs --core-htdocs .test-cache/24.0.0/htdocs
```

Les chemins `--core-htdocs` peuvent désigner les sources complètes correspondantes. Le runner utilise PHP présent dans le PATH ; `--php` permet de choisir un autre exécutable. Les fixtures sont créées exclusivement sous `.test-cache/` dans le module.

| Contrôle | Résultat et nature de la preuve |
|---|---|
| Contrats Dolibarr 16 à 24 | 171 contrôles de sources, signatures et commits consignés dans [core-contracts.md](core-contracts.md) |
| Syntaxe PHP | 38 fichiers passent le lint sous PHP 8.4.22 |
| Langues | Parité de 130 clés dans EN/FR/DE/ES/IT ; paramètres de traduction vérifiés |
| Réglages | 14 scénarios avec base simulée : onglets indépendants, valeurs 0/vides, références forgées, mauvais type ou entité |
| Routes | 12 scénarios : URL historique, entité explicite, chemins malformés et paramètre d’entité concurrent |
| Génération de tâches | 12 scénarios avec objets/base simulés : ordre des tâches, absence de doublons, transaction, droits, propriétaire, durées et heures vides/0 |
| Répertoires documentaires | 8 scénarios avec configuration/helper simulés : propriétaire distinct, configuration absente, retour vide ou `error-...`, aucun repli |
| Admission transverse | 6 scénarios simulés : compte central, entité cible, mot de passe, compte ambigu, résultat natif négatif/false/null ; aucune instance Multicompany réelle |
| Signatures Sabre | Chargement des backends et des nœuds fichiers avec les bibliothèques réelles v16 et v24 |
| Accès WebDAV | 9 scénarios par bibliothèque Sabre : lecture, écritures interdites, traversée, administrateur sans droit, refus avant suppression partielle et suppression native du répertoire vide ; utilisateur simulé |
| Mutations DAV | 15 scénarios par bibliothèque Sabre, objets/base simulés : création, échec transactionnel, objet partagé, mauvais propriétaire, UID, droits et verrou |
| Formats | 14 scénarios par bibliothèque Sabre : tiers sans nom, durée d’intervention par défaut, notes vCard, absence de N, vCard 4, accents, injection de composant, UID, stabilité des octets, dates de fin exclusives, changement d’heure et tâche sans date |
| CSRF | 6 scénarios par version : bloc natif de `main.inc.php` exécuté avec une session simulée ; POST/GET valides, token absent ou expiré, y compris avec option globale à 0 |

L’ancien Sabre fourni par Dolibarr 16 émet sous PHP 8.4 un avertissement `continue targeting switch` dans `Component/VCard.php`. Il reste visible dans les résultats. Aucun fichier core n’a été corrigé et cet avertissement n’est pas attribué au module.

PHPStan n’a pas été exécuté : aucun binaire ni configuration PHPStan propre au module n’est disponible. Aucun baseline ni règle d’ignorance n’a été ajouté.

## Exécution GitHub Actions

Le [run 34344876859](https://github.com/mapiolca/cdav/actions/runs/34344876859), exécuté le 2026-09-09 sur le commit `a818c26f9acedd831dd0cae36ef64c8e8d36a3ed`, a terminé avec succès dans les deux environnements :

- Dolibarr 16.0.0 / PHP 8.0 ;
- Dolibarr 24.0.0 / PHP 8.4.

Chaque job exécute le lint, la parité des cinq langues et la suite ciblée ci-dessus avec les bibliothèques Sabre et le bloc CSRF du tag correspondant. Les services ERP, objets métier, droits et sessions restent simulés. Cette exécution vérifie notamment la syntaxe et le comportement testé sous PHP 8.0 ; elle ne constitue pas une installation Dolibarr ou une validation Multicompany.

## Validations réelles encore nécessaires

Les scénarios suivants sont préparés mais **non exécutés** : aucune instance ERP avec base et aucun module Multicompany utilisable ne sont disponibles. Le navigateur n’a donc pas validé le nouveau code servi. Les contrôles de sources ou les simulations ci-dessus ne remplacent pas cette recette.

1. Installer sur Dolibarr 16/PHP 8.0 puis Dolibarr 24 avec PHP compatible ; activer, désactiver et réactiver deux fois. Vérifier constantes absentes puis valeurs 0 et vides, extrafields existants et nouveaux, schéma `cdav_card`, migration `sourceuid`, droits et absence de doublons. Refaire avec un préfixe de tables long.
2. Ouvrir les cinq onglets dans les cinq langues, sur bureau et téléphone. Vérifier navigation, retour aux modules, aides, Select2, services et catégories. Sauvegarder chaque onglet sans modifier les autres. Rejouer les erreurs et contrôler la conservation des saisies. Tester les switches avec Ajax activé puis désactivé et une tentative inter-entité.
3. Tester l’accès direct aux cinq pages avec administrateur, utilisateur standard et utilisateur externe. Soumettre sans token, avec token expiré, identifiants falsifiés et modules dépendants désactivés. Vérifier l’absence d’écriture et la cohérence avec l’onglet Compatibilité.
4. Configurer deux entités A/B avec partage natif activé puis désactivé, séparément pour agenda, projets, interventions, contacts, tiers, adhérents, produits et catégories. Utiliser une connexion DAV par entité. Tester un utilisateur transverse autorisé dans A mais refusé dans B, ses affectations aux groupes, la découverte des utilisateurs/calendriers et le rechargement des droits dans la cible.
5. Avec un client DAV, exécuter PROPFIND, REPORT, GET, PUT, MOVE/COPY et DELETE sur événements, tâches, interventions et carnets. Vérifier les UID identiques dans A/B, les profils de lecture seule, les administrateurs sans droit fonctionnel et les modifications concurrentes. DELETE conserve les comportements historiques : archivage des fiches CardDAV et retrait de l’affectation au calendrier.
6. Tester les objets partagés consultés depuis B et appartenant à A : conservation du propriétaire, répertoire A utilisé pour la photo, refus si A est mal configurée. WebDAV : téléchargement, upload, extension/MIME incohérents, image avec EXIF/GPS et orientation, traversée, lien symbolique et descendant inaccessible. Vérifier les index ECM et les erreurs disque.
7. Valider un projet lié à commandes/propositions : tâches initiales/finales, durée native ou extrafield, rôle et services autorisés, validation répétée et déclencheur aval en échec. Vérifier le rollback de la validation et des tâches. Valider un projet partagé depuis son entité propriétaire.
8. Vérifier HTTPS, transmission Authorization (y compris mot de passe contenant `:`), PATH_INFO, anciennes URL sans entité et refus de `loginfunction` avant le bootstrap. Tester DAVx⁵, abonnements ICS complets/sans titres, ancien token, token invalide, fuseaux horaires et récurrences. L’expansion reste bornée à 1 000 occurrences.

## Périmètre

CDav expose des objets natifs : aucun objet partageable CDav concurrent, aucune nouvelle numérotation métier, aucune notification spécifique, aucun PDF/ODT ni cron n’est ajouté. Les tests des modèles PDF, des calculs financiers, des consentements publics et des imports ERP ne sont donc pas applicables à cette évolution. Les effets des triggers natifs Agenda/Notifications lors des mutations restent à vérifier sur l’instance.

La modernisation complète du protocole des liens ICS et de la gestion des récurrences reste hors périmètre. Les liens ICS historiques sont conservés ; leur clé doit rester confidentielle. L’onglet Compatibilité indique des capacités détectées, jamais une certification du serveur ou de Multicompany.
