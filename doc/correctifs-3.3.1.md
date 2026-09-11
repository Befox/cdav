# Suivi des correctifs CDav 3.3.1

État au 2026-09-11, branche `fix/3.3.1-fix-contacts-permissions`. Correctifs implémentés localement ; validation sur instance et clients encore à réaliser avant livraison. Aucune release publiée.

## Restriction des contacts au portefeuille commercial

- [x] Exclure de CardDAV les contacts liés à un tiers auquel l’utilisateur n’a pas accès.
- [x] Utiliser le même périmètre pour la découverte du carnet et les lectures ; refuser les modifications et suppressions via les anciennes URI.
- [x] Modifier le ctag lorsque le périmètre visible change, même si le maximum des dates de modification reste identique.
- [ ] Valider la disparition des contacts déjà synchronisés sur les clients réels.

**Défaut corrigé :** le carnet Tiers appliquait le filtre commercial via `societe_commerciaux` lorsque `$user->hasRight('societe', 'client', 'voir')` était faux. Le carnet Contacts n’appliquait pas ce filtre et pouvait synchroniser un contact public avec les coordonnées d’un tiers hors du portefeuille de l’utilisateur.

**Comportement attendu :** cumuler le droit de lecture des contacts, l’accès au tiers rattaché, la confidentialité du contact et les règles d’entité/Multicompany. Le droit « Consulter les contacts » ne doit pas permettre de contourner la restriction aux tiers liés à l’utilisateur comme commercial. L’extension d’accès à tous les tiers lève uniquement la restriction commerciale ; elle ne remplace pas les autres contrôles.

Appliquer le même périmètre à la découverte du carnet, aux listes, aux lectures individuelles ou multiples et aux opérations de synchronisation. Vérifier également les écritures et suppressions CardDAV sur un contact devenu inaccessible. Traiter séparément les contacts sans tiers selon les règles natives applicables.

**Implémentation :** `class/CardDAVDolibarr.php`, notamment `getAddressBooksForUser()`, `_getSqlContacts()`, `getCards()`, `getCard()` et, par ces lectures, `getMultipleCards()`, `updateCard()` et `deleteCard()`. Les appels aux permissions restent directs, sans élévation administrateur. La requête partagée applique le périmètre avant restitution et agrégation. Le ctag est calculé sur les identifiants, les dates du contact et du tiers séparément, les catégories et les options de représentation ; il n’impose pas de lecture des photos lors de la découverte.

Un parent absent (`fk_soc IS NULL` ou ancienne valeur `0`) laisse le contact indépendant. Un parent renseigné mais introuvable ou hors périmètre exclut le contact, même avec extension commerciale. La restriction des fournisseurs purs sans `fournisseur.lire` reprend celle du carnet Tiers CDav. Les autres carnets conservent leurs règles propres ; cette correction ne prétend pas auditer tous les objets liés du module.

Le protocole conserve le fonctionnement existant : `getChangesForAddressBook()` renvoie `null` pour demander un nouveau parcours complet plutôt qu’un historique incrémental. La détection par ctag est corrigée ; la suppression des copies locales dépend du client et reste à vérifier.

**Vérifications avant livraison :**

- Utilisateur avec lecture des contacts, lecture des tiers et sans extension globale : contact d’un tiers lié comme commercial accessible ; contact d’un tiers non lié exclu, y compris par URI directe.
- Même utilisateur avec extension globale : contacts des autres tiers accessibles uniquement si les autres droits, la confidentialité et le périmètre d’entité l’autorisent.
- Absence du droit de lecture des contacts : accès refusé même avec l’extension globale sur les tiers.
- Contact privé d’un autre utilisateur, tiers d’une entité non autorisée et tiers partagé Multicompany : périmètres respectés.
- Retrait de l’affectation commerciale après une première synchronisation : contact devenu inaccessible retiré du périmètre exposé, sans lecture, modification ni suppression serveur autorisée par son ancienne URI.
- Contacts sans tiers : comportement natif vérifié, sans régression.

## Synchronisation facultative du titre/civilité

- [x] Ajouter `CDAV_CONTACT_SYNC_CIVILITY` dans les réglages CardDAV, avec interrupteur natif par entité et traductions dans les cinq langues du module.
- [x] Désactiver cette synchronisation par défaut, y compris avant réactivation du module si la constante est absente.
- [x] Omettre la civilité du préfixe du nom vCard lorsque désactivé et ignorer le préfixe reçu en création/modification. Ne pas effacer une civilité Dolibarr existante lors d’un aller-retour.
- [x] Conserver l’import/export de la civilité lorsque le réglage est activé, et conserver indépendamment le poste/fonction (`TITLE`).
- [ ] Vérifier le switch dans le navigateur et sa conservation après désactivation/réactivation sur deux entités.

Le « titre » désigne ici le champ natif Dolibarr `UserTitle` / `civility_code`, et non le poste. La déclaration de constante utilise `current` et `deleteonunactive=0`. Le mécanisme natif d’installation ajoute uniquement les constantes absentes ; aucune migration de données ni valeur existante n’est écrasée. L’onglet À propos lit la version `3.3.1` depuis le descripteur.

## Preuves de sources

Lecture locale le 2026-09-11, sans modification du core ni essai d’instance :

- La restriction native des contacts combine `societe_commerciaux`, l’utilisateur courant et l’absence de tiers. Ce motif est présent dans `htdocs/core/lib/security.lib.php` sur les neuf tags 16.0.0 à 24.0.0, aux commits immuables de `core-contracts.md` ; exemples [v16](https://github.com/Dolibarr/dolibarr/blob/ad673b3207506de03ee8e745399e3a19505900d2/htdocs/core/lib/security.lib.php#L764), [v20](https://github.com/Dolibarr/dolibarr/blob/697bf01970740a3339cd99cf055b4428fc5e051c/htdocs/core/lib/security.lib.php#L1003), [v24](https://github.com/Dolibarr/dolibarr/blob/769c7db907099643558e77d7002c109cfda919e5/htdocs/core/lib/security.lib.php#L886). CDav reprend ce filtrage dans sa requête de collection, complété par ses contrôles de confidentialité et d’entités ; aucun wrapper de permission ni requête d’accès par ligne n’est ajouté.
- Le titre est relié à `select_civility()` dans la fiche native des contacts sur les tags 16, 20 et 24 examinés : [exemple v20](https://github.com/Dolibarr/dolibarr/blob/697bf01970740a3339cd99cf055b4428fc5e051c/htdocs/contact/card.php#L716).
- La création des constantes absentes et leur conservation suivent `DolibarrModules::insert_const()` et `remove_const()`, lus sur les tags 16.0.0 et 24.0.0. Cela constitue une preuve de lecture, pas un test d’activation du module.

## Contrôles exécutés et limites

Commande reproductible sous Windows avec l’extension SQLite installée mais non activée globalement :

```text
python scripts/run_checks.py --php-arg=-d --php-arg=extension=pdo_sqlite --core-htdocs .test-cache/16.0.0/htdocs --core-htdocs .test-cache/24.0.0/htdocs
```

Les tests de `test/carddav.php` exécutent les méthodes réelles du backend et les parseurs Sabre, évaluent le SQL dans SQLite en adaptant seulement la syntaxe MySQL `GROUP_CONCAT` et simulent les permissions, le partage d’entités et les méthodes de persistance de Contact. Ils couvrent le périmètre commercial, l’extension globale, les contacts privés, les parents absents/inaccessibles, les fournisseurs, le partage, les URI mappées, le multiget, le retrait d’affectation, les erreurs SQL, les ctags, les droits d’écriture/suppression et les civilités vCard 3/4.

L’environnement d’exécution local est PHP 8.4.22 ; les bibliothèques Sabre proviennent des tags Dolibarr 16.0.0 et 24.0.0. Le socle Dolibarr 16/PHP 8.0 du module existant est conservé, sans revendiquer un essai local sous PHP 8.0. Le workflow inclut désormais les branches `fix/**` et SQLite comme dépendance de test uniquement.

Résultat : syntaxe des 39 fichiers PHP et parité des 134 clés dans cinq langues validées ; 17 contrôles de réglages, 12 de routes, 12 de génération, 8 documentaires et 6 d’admission réussis. Pour chacune des deux bibliothèques Sabre : 9 contrôles fichiers, 15 de persistance simulée, 14 de formats, 68 CardDAV sans filtre de catégorie, 67 CardDAV avec filtre et 6 CSRF réussis. Les contrôles PHP 8.0 et le workflow distant ne sont pas présentés comme exécutés localement.

PHPStan non exécuté : aucun exécutable ni configuration disponible dans le module. Aucun test réel MySQL/MariaDB, installation/réactivation Dolibarr, Multicompany, navigateur ou client CardDAV n’a été exécuté. Les tests ne certifient donc ni une installation complète ni l’effacement des copies locales déjà téléchargées. Aucune instance, configuration globale ou donnée métier réelle n’a été modifiée. Le warning historique de Sabre v16 sur `continue` reste visible et ne provient pas du module.
