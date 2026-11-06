# Labyrinthe Connect pour WordPress et WooCommerce

Plugin WordPress officiel de [Labyrinthe Connect](https://labyrinthe-rdc.com) (slug `labyrinthe-connect`) : SMS de
commande WooCommerce, vérification du téléphone et connexion avec un code reçu par SMS. Il s'appuie sur le
[SDK PHP](../php) et sur la [spécification de l'API](../openapi/openapi.yaml) (1.3.0), via le SDK PHP 1.2.0.

*English version: [README.md](README.md). La documentation utilisateur pour wordpress.org est [`readme.txt`](readme.txt).*

| | |
|---|---|
| WordPress | 6.4 ou plus (testé jusqu'à 7.1) |
| PHP | **8.1 ou plus** — exigé par le SDK PHP (`labyrinthe/connect-php` demande `^8.1`). Sur un PHP plus ancien, le plugin affiche un avis et reste inactif. La plupart des hébergeurs mutualisés proposent PHP 8.1 à 8.3 dans leur panneau (souvent « Version PHP ») ; PHP 7.4 et 8.0 ne reçoivent plus de correctifs de sécurité. |
| WooCommerce | 8.0 ou plus, facultatif (testé jusqu'à 11.2), compatible HPOS et validation de commande en blocs |
| Licence | GPL-2.0-or-later (SDK et dépendances embarqués : MIT) |

## Démarrage en 5 minutes (propriétaire du site)

1. Installer `dist/labyrinthe-connect.zip` (*Extensions > Ajouter > Téléverser*), activer.
2. *Labyrinthe Connect > Réglages* : coller une clé `lsk_test_…`, **Tester la connexion**, **Envoyer un SMS de test**.
3. Onglet *WooCommerce* : activer les statuts qui envoient un SMS. Onglet *OTP* : connexion par SMS, vérification à
   l'inscription / à la commande.
4. Facultatif : déclarer l'URL du webhook (`/wp-json/labyrinthe/v1/webhook`) dans l'espace client et coller son secret.
5. Passer à la clé `lsk_live_…`.

## Fonctionnement

| Fonction | Comment |
|---|---|
| SMS par statut de commande | `woocommerce_order_status_changed` ne fait que **planifier** une action Action Scheduler (`labyrinthe_connect_order_sms`, unique par commande + statut). L'action rend le modèle et l'envoie avec le SDK : aucun appel réseau pendant le paiement. |
| Aucun doublon | Action unique, métadonnée `_labyrinthe_sms_sent` sur la commande, et `Idempotency-Key: wc-<site>-<commande>-<statut>` réutilisée à chaque nouvel essai (l'API la rejoue pendant 24 h). |
| Nouveaux essais | Erreur réseau, 5xx ou `TOO_MANY_REQUESTS` : le SDK relance une fois (sans risque, même clé), puis l'action est replanifiée (5, 10, 15 min ; 4 tentatives). Les erreurs métier (`INSUFFICIENT_CREDITS`, `RECIPIENT_NOT_SENDABLE`…) ne sont pas relancées. |
| Notes de commande | Une note par SMS : envoyé (numéro masqué, segments, encodage, identifiant) ou échec avec le code d'erreur et l'**identifiant de requête** (`X-Request-Id`, à communiquer au support) ; remise ou échec définitif (avec `error_code` / `error_message`) via les webhooks. Les SMS de commande portent `reference: order-<identifiant de commande>` : un accusé retrouve sa commande même si la ligne du journal a été purgée. |
| SMS au gérant | `woocommerce_checkout_order_processed` et `woocommerce_store_api_checkout_order_processed` → `labyrinthe_connect_admin_order_sms`. |
| Modèles | Variables `{order_number}`, `{order_id}`, `{order_status}`, `{order_total}` (`25.00 USD`, en ASCII), `{order_date}`, `{order_url}`, `{payment_method}`, `{customer_name}`, `{customer_first_name}`, `{customer_phone}`, `{shop_name}`, `{shop_url}`, `{tracking_number}`, `{tracking_url}`. Aperçu des caractères, de l'encodage et des segments compté par l'API (`POST /sms/estimate`, rien n'est envoyé), avec une estimation locale pendant la saisie ou sans clé API (tout caractère hors ASCII → UCS-2). Retrait des accents en option, fait par l'API (`strip_accents`) pour les SMS de commande, les SMS de test et les codes OTP ; les modèles français par défaut sont sans accents. |
| OTP | Actions `admin-ajax.php` `labyrinthe_otp_send` / `labyrinthe_otp_verify` (nonce). L'`otp_id` reste dans un transient côté serveur, lié à un cookie aléatoire httpOnly ; il n'atteint jamais le navigateur. Chaque envoi porte une `Idempotency-Key` stable (`wp-otp-<site>-<empreinte session + contexte + numéro>-<rang>`) : un double clic ou une relance après une coupure réseau rejoue le même envoi (même code, aucun second SMS) ; seul « Renvoyer le code » après un envoi réussi en crée un autre ; le SDK relance une fois (réseau, 5xx, 429). Le champ du code suit `code_length` / `code_type` (longueur, clavier numérique ou texte, `autocomplete="one-time-code"`), le compte à rebours suit `resend_after_seconds`, un code faux affiche `attempts_remaining`. **Web OTP** : `web_otp_domain` (domaine du site par défaut, modifiable ou désactivable dans les réglages ; ignoré pour `localhost` ou une adresse IP) ajoute `@domaine #code` au SMS, et Chrome sur Android remplit et vérifie le code. Limite par IP (réglage, 5 codes / heure par défaut) en plus des limites du backend. Le `test_code` n'est renvoyé qu'aux administrateurs, avec une clé `lsk_test_`. |
| Connexion par SMS | Formulaire de connexion WordPress, « Mon compte » WooCommerce, `[labyrinthe_otp_login redirect="/mon-compte/"]`, bloc `labyrinthe-connect/otp-login`. Le compte est retrouvé par la métadonnée `labyrinthe_phone` (remplie uniquement par une vérification OTP ou par un administrateur). Un numéro inconnu reçoit la même réponse sans aucun SMS (pas d'énumération, pas de crédit dépensé). Les administrateurs sont refusés sauf autorisation. |
| Vérification | Inscription (WordPress et WooCommerce) et commande (classique : `woocommerce_after_checkout_validation` ; blocs : `RouteException` dans `woocommerce_store_api_checkout_update_order_from_request`). |
| Webhooks | `POST /wp-json/labyrinthe/v1/webhook` : signature vérifiée par le SDK sur le corps brut (temps constant, tolérance de 5 min), dédoublonnage sur `id` (clé primaire, `INSERT IGNORE`), mise à jour du journal (un statut définitif n'est jamais remplacé par un statut intermédiaire ; `error_code` / `error_message` enregistrés), notes de commande, `otp.verified` / `otp.failed` → actions `labyrinthe_otp_verified` / `labyrinthe_otp_failed`. |
| Journal des SMS | Table `{prefix}labyrinthe_sms_log`, `WP_List_Table` filtrable (commande, identifiant de message, code d'erreur, identifiant de requête) ; numéros masqués, textes jamais enregistrés ; identifiant de requête de chaque appel (`wp-<uuid>`, envoyé en `X-Request-Id`, utile aussi pour une erreur réseau) ; purge quotidienne (réglage, 90 jours par défaut). |
| Résultat inconnu | `409 IDEMPOTENCY_KEY_UNRESOLVED` (`IdempotencyKeyUnresolvedException`) : la requête d'origine avec cette clé a échoué de façon inattendue côté API et un SMS a pu partir. Échec définitif, jamais relancé ni replanifié : note de commande et ligne du journal avec le code d'erreur et l'identifiant de requête, invitant à vérifier les messages dans l'espace Labyrinthe Connect avant tout envoi manuel. OTP : le visiteur voit un message neutre (« demandez un nouveau code si vous ne l'avez pas reçu ») ; sa demande suivante part avec une nouvelle clé d'idempotence. |
| Maintenance | `503 FEATURE_DISABLED` / `OTP_SERVICE_DISABLED` (`FeatureDisabledException`) : la plateforme a coupé l'API, l'envoi de SMS ou l'OTP ; rien n'est parti. OTP : code d'échec `FEATURE_DISABLED` avec le message de l'administrateur (texte brut) ou un message traduit « momentanément indisponible », l'heure de retour si elle est connue, et `retry_after` pour le compte à rebours. SMS de commande : replanifié à l'heure de retour annoncée (`Retry-After`), sinon 5, 10, 15 min ; jamais relancé en boucle. `Send_Result::$retry_after` donne le délai. |
| Compte suspendu | `403 ACCOUNT_SUSPENDED` (`AccountSuspendedException`) : avis d'erreur clair dans l'administration avec l'identifiant de requête, jamais relancé ; effacé au premier appel réussi. Les visiteurs ne voient qu'un message neutre. |
| Numéros | Normalisés par le plugin (indicatif du pays de facturation pour un numéro local), puis envoyés en `+<numéro international>` (le `+` empêche le SDK d'ajouter 243 à un numéro étranger de 10 chiffres ou moins ; le SDK 1.1.1 le transmet à l'API avec son `+`). L'API conserve l'indicatif pour les SMS comme pour les OTP (`+2481234567` → `2481234567` ; `BACKEND-TODO.md` n° 34 corrigé côté API). |

Les hooks pour les développeurs et les constantes de `wp-config.php` (`LABYRINTHE_CONNECT_API_KEY`,
`LABYRINTHE_CONNECT_WEBHOOK_SECRET`, `LABYRINTHE_CONNECT_BASE_URL`) sont listés dans le [README anglais](README.md#hooks-for-developers).

## Choix techniques

- **SDK préfixé** : les dépendances d'exécution (`labyrinthe/connect-php`, Guzzle, interfaces PSR) sont copiées par
  PHP-Scoper dans `vendor-prefixed/` sous `LabyrintheConnect\Vendor\`, avec un autoloader PSR-4 généré : aucune autre
  extension embarquant une autre version de Guzzle ou des interfaces PSR ne peut entrer en conflit. PHP-Scoper est figé
  par `tools/php-scoper/composer.lock` ; `bin/prefix-dependencies.php` reconstruit le dossier de zéro et vérifie le
  résultat. (Strauss 0.31 a d'abord été essayé : il ne fonctionne pas sous Windows.)
- **HTTP par WordPress** : le SDK reçoit un client PSR-18 au-dessus de `wp_remote_request()` (proxy, certificats et
  `pre_http_request` respectés ; les tests s'en servent comme serveur simulé).
- **Secrets** : clé API et secret de webhook chiffrés avec libsodium (`secretbox`, clé dérivée de `wp_salt()`), jamais
  réaffichés (seulement `lsk_test_…ABCD`), jamais envoyés au navigateur ni journalisés.
- **Aucun appel à l'API au chargement d'une page publique** : seules les actions AJAX, les actions planifiées et la page
  de réglages appellent l'API.
- **Désinstallation** (`uninstall.php`) : options, tables, transients, métadonnées des comptes et des commandes (articles
  et tables HPOS), actions planifiées, sur chaque site d'un réseau multisite.

## Développement

```bash
composer install                 # outils + vendor-prefixed/ (PHP-Scoper lancé automatiquement)
php bin/install-wp-tests.php     # WordPress + WooCommerce dans .wp-tests/, base labyrinthe_wp_tests
composer lint                    # PHPCS (WordPress Coding Standards + PHPCompatibilityWP 8.1+)
composer analyse                 # PHPStan niveau 8
composer test                    # PHPUnit + suite de tests WordPress (HPOS ; LABYRINTHE_TEST_HPOS=no pour les articles)
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 composer test:integration
composer i18n                    # .pot, .po, .mo, .l10n.php, .json
composer build                   # dist/labyrinthe-connect.zip + dist/labyrinthe-connect/
```

## Publication

Tag `wordpress-vX.Y.Z` (voir [`CONTRIBUTING.md`](../CONTRIBUTING.md)) : le workflow `wordpress-release.yml` vérifie les
versions, lance les contrôles, construit le zip, crée la release GitHub et déploie `dist/labyrinthe-connect/` sur le SVN
de wordpress.org avec 10up/action-wordpress-plugin-deploy (secrets `SVN_USERNAME` / `SVN_PASSWORD`).
