# Suivi de CDav 3.3.2

État au 2026-10-06, branche `fix/3.3.2-contact-thirdparty-details`, base `feat/cdav-3.3` au commit `1cdcf973f01f2df350cbcaca176cf6a9a02f4100`. Évolution implémentée ; aucune release ni aucun déploiement effectué.

## Comportement

Le réglage **Ajouter les coordonnées du tiers aux contacts synchronisés** (`CDAV_CONTACT_SYNC_THIRDPARTY_DETAILS`) est un interrupteur natif dans l’onglet CardDAV. Il est désactivé par défaut et enregistré dans l’entité de consultation.

- Désactivé ou absent : le contact exporte ses propres adresse, téléphones, fax et email. Aucune adresse professionnelle, aucun fax, email ou site web du tiers n’est ajouté ; un téléphone vide reste vide.
- Activé : le comportement précédent est rétabli. Les coordonnées du tiers complètent la vCard ; le téléphone du contact reste prioritaire.
- Le nom de l’organisation, la fonction, la civilité selon son réglage et les carnets Tiers et Adhérents restent indépendants de cette option.
- Les lectures directes, multiples et les listes utilisent le même sérialiseur. Le changement de réglage modifie le ctag du carnet Contacts et les ETags des contacts concernés.
- Aucune écriture ou suppression de coordonnées dans les objets Contact ou Societe. Les droits, restrictions commerciales, confidentialité et partages existants sont conservés.

## Intégration native et périmètre

Le schéma des réglages existant utilise `FormSetup`, `ajax_constantonoff`, `dolibarr_set_const` et le contrôle CSRF natif. La nouvelle constante utilise `current` et `deleteonunactive=0`. La lecture utilise directement `getDolGlobalInt`, sans helper supplémentaire. L’onglet À propos lit la version 3.3.2 du descripteur.

Lecture du core Dolibarr 24.0.0 (`769c7db907099643558e77d7002c109cfda919e5`) : `DolibarrModules::insert_const()` ajoute uniquement les constantes absentes et `delete_const()` supprime uniquement celles marquées pour suppression à la désactivation. Il s’agit d’une preuve de sources, pas d’un essai d’activation sur instance. [Source immuable](https://github.com/Dolibarr/dolibarr/blob/769c7db907099643558e77d7002c109cfda919e5/htdocs/core/modules/DolibarrModules.class.php).

L’identifiant historique 562387 et l’éditeur Befox sont conservés ; aucun nouvel ID attribué. Aucun nouveau hook, trigger, événement Agenda, notification, document, modèle de numérotation, tâche cron, table ou migration. `modulebuilder.txt` est présent. Aucun fichier core modifié.

## Vérifications exécutées

- Reproduction avant patch : le scénario « Default never adds third-party details: Company street » échoue sur l’ancien sérialiseur, puis réussit après correction avec les mêmes données.
- PHP CLI 8.4.22 : lint des 39 fichiers PHP ; parité des 136 clés et placeholders dans les cinq langues ; 20 contrôles de réglages et les contrôles existants de routage, génération, documents et admission.
- CardDAV : 103 contrôles sans filtre de catégorie, 102 avec filtre, pour chaque bibliothèque Sabre ci-dessous. Données, droits, entités et persistance ERP simulés ; SQL évalué par SQLite, parser et backend Sabre réels. Les scénarios couvrent défaut absent, zéro explicite, activation, priorité du téléphone propre, coordonnées propres conservées, champs vides, aller-retour vCard, lecture directe/liste/multiget, ctag/ETag et indépendance des entités pour un contact partagé.
- Suite existante Sabre, formats, fichiers, persistance et CSRF exécutée avec les sources 16 et 20 à 24. Un avertissement historique de Sabre v16 sur `continue` reste visible ; aucun échec du module.
- PHPStan non exécuté : aucun exécutable ni configuration PHPStan présent dans le module. Aucun ignore, baseline ou dépendance ajouté.

| Sources Dolibarr / Sabre | Révision | PHP local | Résultat CardDAV |
|---|---|---|---|
| 16.0.0, compatibilité historique | `ad673b3207506de03ee8e745399e3a19505900d2` | 8.4.22 | 103 + 102 contrôles simulés réussis |
| 20.0.0 | `697bf01970740a3339cd99cf055b4428fc5e051c` | 8.4.22 | 103 + 102 contrôles simulés réussis |
| 21.0.0 | `fd970b582a4d8c5779a2958a4e9f4fce225cf085` | 8.4.22 | 103 + 102 contrôles simulés réussis |
| 22.0.0 | `49b9a6d19f3deb6d410c0e9b3310e95be4ea7710` | 8.4.22 | 103 + 102 contrôles simulés réussis |
| 23.0.0 | `57a1f05d490a7a80944a8232e9c613e6556d2704` | 8.4.22 | 103 + 102 contrôles simulés réussis |
| 24.0.0 | `769c7db907099643558e77d7002c109cfda919e5` | 8.4.22 | 103 + 102 contrôles simulés réussis |
| 25 | Tag 25.0.0 absent de la consultation distante du 2026-10-06 | Non exécuté | Non vérifié ; reste dans la cible |

Ces résultats ne prouvent pas le fonctionnement d’instances complètes Dolibarr ni Multicompany. Le socle historique déclaré Dolibarr 16/PHP 8.0 est conservé ; l’exécution locale PHP 8.0 n’est pas disponible. Les résultats CI doivent être consultés sur le SHA final de la PR, sans assimiler une exécution en attente à une réussite.

Commande reproductible :

```text
python scripts/run_checks.py --php-arg=-d --php-arg=extension=pdo_sqlite --core-htdocs .test-cache/20.0.0/htdocs --core-htdocs .test-cache/21.0.0/htdocs --core-htdocs .test-cache/22.0.0/htdocs --core-htdocs .test-cache/23.0.0/htdocs --core-htdocs .test-cache/24.0.0/htdocs
```

## Validation manuelle et limites

Navigateur et déploiement non réalisés : aucun serveur modifié. Après déploiement, vérifier l’interrupteur dans CardDAV, les valeurs par entité et leur conservation après désactivation/réactivation. Tester un compte en lecture seule et un compte autorisé à modifier les contacts, puis un contact partagé et un tiers inaccessible.

Synchroniser des clients réels après mise à jour et après chaque bascule. Vérifier le retrait des anciennes coordonnées ajoutées par l’export ; une actualisation complète du carnet peut être nécessaire si le client conserve les anciennes valeurs. Aucun nettoyage automatique des coordonnées déjà enregistrées en ERP ou conservées manuellement dans un client : leur origine ne peut pas être déduite avec certitude. Une vCard entrante reste une modification explicite du contact soumise aux contrôles existants.
