# Labyrinthe Connect pour WordPress et WooCommerce

![Version](https://img.shields.io/badge/version-1.2.1-008374)
[![CI](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/wordpress.yml/badge.svg)](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/wordpress.yml)
[![Licence : GPL v2 ou ultérieure](https://img.shields.io/badge/licence-GPL--2.0--or--later-blue.svg)](LICENSE)
![WordPress](https://img.shields.io/badge/wordpress-6.4%2B-21759b)
![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.1-777bb4)

Plugin WordPress officiel de [Labyrinthe Connect](https://connect.labyrinthe-rdc.com), la plateforme SMS et OTP de la RD
Congo et de l'Afrique centrale (slug `labyrinthe-connect`). Sans écrire de code : SMS de commande WooCommerce à chaque
statut, SMS au gérant pour chaque nouvelle commande, vérification du téléphone par SMS à l'inscription et à la
commande, et connexion avec un code reçu par SMS (formulaire de connexion, shortcode, bloc). Les SMS partent en
arrière-plan et jamais deux fois, chaque SMS laisse une note de commande, et une clé `lsk_test_` permet de tout tester
sans rien envoyer ni payer. Plugin 1.2.1, qui embarque le [SDK PHP](../php) 1.2.1 (spécification de l'API 1.3.1).

*English version: [README.md](README.md). La documentation utilisateur pour wordpress.org est
[`readme.txt`](readme.txt).*

[Prérequis](#prérequis) · [Installation](#installation) · [Démarrage rapide](#démarrage-rapide) ·
[Réglages](#réglages) · [SMS de commande WooCommerce](#sms-de-commande-woocommerce) ·
[OTP](#otp--vérification-du-téléphone-et-connexion) · [Webhooks](#webhooks) ·
[Journal, mode test et sécurité](#journal-des-sms-mode-test-et-sécurité) · [Erreurs et maintenance](#erreurs-et-maintenance) ·
[Relances et idempotence](#relances-et-idempotence) · [Hooks pour les développeurs](#hooks-et-filtres-pour-les-développeurs) ·
[Dépannage](#dépannage) · [Développement](#développement)

## Prérequis

| | |
|---|---|
| WordPress | 6.4 ou plus (testé jusqu'à 7.1) |
| PHP | **8.1 ou plus** (exigé par le SDK PHP). Sur un PHP plus ancien, le plugin affiche un avis et reste inactif. |
| WooCommerce | 8.0 ou plus, **facultatif** (testé jusqu'à 11.2), compatible HPOS et validation de commande en blocs. Sans lui, l'inscription et la connexion par OTP fonctionnent toujours. |
| Compte | un compte Labyrinthe Connect et une clé API (espace client > *Développeurs > Clés API*) |
| Licence | GPL-2.0-or-later (SDK et dépendances embarqués : MIT) |

## Installation

| Depuis | Comment |
|---|---|
| Fichier zip | Téléchargez `labyrinthe-connect.zip` dans les [versions GitHub](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/releases) (tags `wordpress-vX.Y.Z`), puis *Extensions > Ajouter > Téléverser une extension*, et activez. |
| WP-CLI | `wp plugin install labyrinthe-connect.zip --activate` |
| wordpress.org | *Extensions > Ajouter*, rechercher « Labyrinthe Connect » (le plugin est prêt mais pas encore publié). |
| Sources | `composer install && composer build` dans ce dossier produit `dist/labyrinthe-connect.zip` (voir [Développement](#développement)). Une copie du dossier sans `composer install` n'a pas `vendor-prefixed/` et affiche un avis. |

## Démarrage rapide

1. Activez le plugin, ouvrez *Labyrinthe Connect > Réglages*.
2. Collez une clé `lsk_test_…`, enregistrez, cliquez sur **Tester la connexion** puis sur **Envoyer un SMS de test**
   (rien n'est envoyé, rien n'est facturé).
3. Onglet *WooCommerce* : activez les statuts qui envoient un SMS. Onglet *OTP* : connexion par SMS, vérification à
   l'inscription ou à la commande.
4. Facultatif, pour les accusés de remise : ajoutez l'URL du webhook affichée dans les réglages à l'espace client
   (*Développeurs > Webhooks*) et collez son secret.
5. Remplacez la clé de test par votre clé `lsk_live_…`.

## Réglages

*Labyrinthe Connect > Réglages* (capacité `manage_options`), trois onglets. Tout est enregistré dans l'option
`labyrinthe_connect_settings`.

**Général**

| Réglage | Défaut | |
|---|---|---|
| Clé API | — | chiffrée en base, jamais réaffichée (seulement `lsk_test_…ABCD`) ; champ vide = garder la clé actuelle |
| Expéditeur | celui de la plateforme | un Sender ID approuvé du compte (**Actualiser** recharge la liste) |
| Indicatif par défaut | `243` | pour les numéros saisis au format local ; les commandes WooCommerce utilisent d'abord le pays de facturation |
| Accents | désactivé | « Retirer les accents et les guillemets typographiques avant l'envoi » : fait par l'API (`strip_accents`) pour les SMS de commande, les SMS de test et les codes OTP |
| URL du webhook | — | `https://votre-site/wp-json/labyrinthe/v1/webhook`, à copier dans l'espace client |
| Secret du webhook | — | chiffré, sert à vérifier chaque livraison |
| Conservation du journal | `90` jours | `0` conserve sans limite ; les lignes plus anciennes sont purgées chaque jour |
| Outils | — | **Tester la connexion** (mode et solde) et **Envoyer un SMS de test** |

**WooCommerce**

| Réglage | Défaut | |
|---|---|---|
| SMS au client quand le statut de la commande change | *En cours* et *Terminée* activés | un interrupteur et un modèle par statut (statuts personnalisés compris) |
| SMS au gérant pour chaque nouvelle commande | désactivé | un ou plusieurs numéros (séparés par des virgules) et un modèle |
| Variables disponibles | — | liste des `{variables}` ci-dessous, avec l'aperçu en direct des caractères, de l'encodage et des segments |

**OTP (vérification et connexion)**

| Réglage | Défaut | |
|---|---|---|
| Connexion par SMS | désactivé | autoriser la connexion avec un code ; l'ajouter aux formulaires de connexion WordPress et WooCommerce (activé) ; autoriser aussi les administrateurs (désactivé, déconseillé) |
| Inscription | désactivé | exiger un numéro vérifié par SMS pour créer un compte (WordPress et WooCommerce) |
| Commande | désactivé | exiger que le téléphone de facturation soit vérifié par SMS pour commander (validation classique et en blocs) |
| Message | modèle OTP du compte | facultatif, doit contenir `{code}` ; `{app_name}` et `{minutes}` sont disponibles |
| Remplissage automatique du code | activé | Web OTP : ajoute `@domaine #code` au SMS pour que Chrome sur Android remplisse le code |
| Domaine | domaine du site | celui de la page où le code est saisi ; ignoré pour `localhost` ou une adresse IP |
| Limite par visiteur | `5` | codes par heure et par adresse IP, en plus des limites de Labyrinthe Connect |

Longueur du code, validité et nombre d'essais viennent des paramètres OTP de l'espace client. Les utilisateurs ont
un champ *Numéro pour la connexion par SMS* dans leur profil (modifiable par les administrateurs).

**Constantes de `wp-config.php`** (prioritaires sur les réglages, jamais enregistrées en base) :

```php
define( 'LABYRINTHE_CONNECT_API_KEY', 'lsk_test_…' );
define( 'LABYRINTHE_CONNECT_WEBHOOK_SECRET', '…' );
define( 'LABYRINTHE_CONNECT_BASE_URL', 'http://localhost:8000' );   // autre environnement (par défaut : production)
```

## SMS de commande WooCommerce

- **Le paiement n'est jamais ralenti** : `woocommerce_order_status_changed` ne fait que planifier une action Action
  Scheduler (`labyrinthe_connect_order_sms`, groupe `labyrinthe-connect`, unique par commande et statut) ; l'API est
  appelée quelques secondes plus tard. Une nouvelle commande planifie `labyrinthe_connect_admin_order_sms` pour le
  gérant (`woocommerce_checkout_order_processed` et son équivalent Store API).
- **Destinataire** : le téléphone de facturation (sinon celui de livraison), normalisé avec le pays de facturation.
  Une commande sans numéro reçoit une note et aucun SMS.
- **Notes de commande** : une par SMS : envoyé (numéro masqué, segments, encodage, identifiant du message), échec (code
  d'erreur et **identifiant de requête** pour le support), puis remis ou échec définitif quand le webhook est
  configuré. Les SMS de commande portent la référence `order-<identifiant de commande>`.
- **Variables** : `{order_number}`, `{order_id}`, `{order_status}`, `{order_total}` (`25.00 USD`, en ASCII),
  `{order_date}`, `{order_url}`, `{payment_method}`, `{customer_name}`, `{customer_first_name}`, `{customer_phone}`,
  `{shop_name}`, `{shop_url}`, `{tracking_number}`, `{tracking_url}` (extensions de suivi d'expédition). Ajoutez les
  vôtres avec le filtre `labyrinthe_connect_order_sms_variables`.
- **Coût** : un accent, un guillemet typographique ou un emoji fait passer le SMS en Unicode (70 caractères au lieu de
  160, souvent le double du prix). L'aperçu sous chaque modèle est compté par l'API (`POST /sms/estimate`, rien n'est
  envoyé) ; activez *Accents* pour rester en GSM-7. Les modèles français par défaut sont sans accents exprès.

## OTP : vérification du téléphone et connexion

Les codes sont générés, envoyés et vérifiés par Labyrinthe Connect (un SMS de code coûte comme un SMS ; la
vérification est gratuite).

| Où | Ce que fait le visiteur |
|---|---|
| Inscription (WordPress et WooCommerce) | saisit un numéro, reçoit un code, le saisit ; le compte ne peut pas être créé sans cela. Le numéro vérifié est enregistré dans la métadonnée utilisateur `labyrinthe_phone`. |
| Commande (classique et blocs) | vérifie le téléphone de facturation avant de valider la commande. |
| Connexion | « Se connecter avec un code reçu par SMS » sur `wp-login.php` et *Mon compte* de WooCommerce, le shortcode `[labyrinthe_otp_login redirect="/mon-compte/" title="…"]` ou le bloc **Connexion par SMS (Labyrinthe Connect)** (`labyrinthe-connect/otp-login`, attributs `redirect` et `title`). |

- Le compte est retrouvé par sa métadonnée `labyrinthe_phone`, remplie uniquement par une vérification OTP ou par un
  administrateur. Un numéro inconnu reçoit la même réponse qu'un numéro connu, sans aucun SMS (pas d'énumération des
  comptes, pas de crédit dépensé). Un numéro partagé par plusieurs comptes ne permet pas de se connecter ; les
  administrateurs sont refusés sauf autorisation.
- L'`otp_id` n'atteint jamais le navigateur : il reste dans un transient côté serveur, lié à un cookie httpOnly
  aléatoire (`labyrinthe_otp`, 1 heure).
- Le champ du code suit l'API : `code_length`, clavier numérique ou texte (`code_type`),
  `autocomplete="one-time-code"` ; le compte à rebours de renvoi suit `resend_after_seconds` ; un code faux affiche les
  essais restants.
- **Web OTP** (Chrome sur Android) remplit et vérifie le code automatiquement quand *Remplissage automatique du code*
  est activé.
- **Limites** : *Limite par visiteur* (par IP, 5 codes / heure par défaut), plus les limites de la plateforme (délai
  de renvoi et plafond horaire par numéro et par compte). Derrière un proxy ou un CDN, voir le filtre
  `labyrinthe_connect_client_ip`.
- **Mode test** : avec une clé `lsk_test_`, aucun SMS ne part et les administrateurs voient le code à l'écran.

## Webhooks

Copiez l'*URL du webhook* (`/wp-json/labyrinthe/v1/webhook`) dans l'espace client (*Développeurs > Webhooks*) et
collez le secret dans les réglages. Chaque livraison est vérifiée par le SDK sur le corps brut (HMAC-SHA256 en temps
constant, horodatage signé de moins de 5 minutes), dédoublonnée sur son `id` (les livraisons sont retentées jusqu'à
5 fois), puis :

| Événement | Effet | Action WordPress |
|---|---|---|
| tous | — | `labyrinthe_webhook_received( $envelope )` |
| `message.status_changed` | journal des SMS mis à jour (un statut définitif n'est jamais remplacé), note de commande à la remise ou à l'échec définitif | `labyrinthe_message_status_changed( $envelope, $log_row )` |
| `otp.verified` / `otp.failed` | — (jamais le code) | `labyrinthe_otp_verified( $envelope )`, `labyrinthe_otp_failed( $envelope )` |
| `campaign.completed` | — | `labyrinthe_campaign_completed( $envelope )` |
| `balance.low` | avis d'administration « solde de crédits SMS bas » (7 jours, masquable) | `labyrinthe_balance_low( $envelope )` |
| `test.ping` | « Dernier événement de test reçu » affiché dans les réglages | `labyrinthe_webhook_test_ping( $envelope )` |

`$envelope` est le `{ id, event, data, timestamp }` décodé. Réponses : `200` (traité ou doublon), `400` (signature ou
contenu invalide), `503` tant qu'aucun secret n'est configuré (Labyrinthe Connect réessaie plus tard).

## Journal des SMS, mode test et sécurité

- *Labyrinthe Connect > Journal des SMS* : chaque SMS et chaque envoi d'OTP du site, filtrable par type et par statut,
  recherche par commande, identifiant de message, code d'erreur ou identifiant de requête. Les numéros sont stockés
  **masqués**, les textes ne sont **jamais** stockés. Purge quotidienne après la durée de conservation.
- **Mode test** (clé `lsk_test_`) : l'API simule tout, rien n'est envoyé, rien n'est facturé ; les notes de commande et
  le journal ressemblent à la production et indiquent « mode test ».
- **Secrets** : la clé API et le secret du webhook sont chiffrés avec libsodium (clé dérivée des salts de
  `wp-config.php`), jamais envoyés au navigateur, jamais journalisés. Le SDK ne journalise jamais les codes ni les
  numéros complets.
- **Aucun appel à l'API au chargement d'une page publique** : seuls les actions AJAX, les actions planifiées et la
  page de réglages appellent l'API.
- **Confidentialité** : un texte proposé pour la politique de confidentialité est ajouté dans *Réglages >
  Confidentialité*. Le service externe est décrit dans [`readme.txt`](readme.txt) (*External services*).
- **Désinstallation** : options, tables, transients, métadonnées utilisateur et de commande et actions planifiées sont
  supprimés, sur chaque site d'un réseau.

## Erreurs et maintenance

| Situation | Ce qui se passe |
|---|---|
| Numéro invalide, destinataire refusé (`INVALID_PHONE`, `RECIPIENT_NOT_SENDABLE`) | note de commande / message clair au visiteur ; pas de relance |
| `INSUFFICIENT_CREDITS` | note de commande avec l'erreur ; pas de relance : achetez des crédits dans l'espace client |
| `UNAUTHENTICATED` | « La clé API a été refusée (invalide ou révoquée) » dans les outils des réglages |
| `ACCOUNT_SUSPENDED` | avis d'erreur dans l'administration avec l'identifiant de requête, effacé au prochain appel réussi ; les visiteurs voient un message neutre |
| Erreur réseau, 5xx, `TOO_MANY_REQUESTS` | SMS de commande : relancé (voir plus bas) ; OTP : « veuillez réessayer » |
| `OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED` | compte à rebours / « trop de codes demandés » |
| `IDEMPOTENCY_KEY_UNRESOLVED` | le résultat de la première tentative est inconnu (un SMS a pu partir) : échec définitif, jamais relancé ; la note de commande invite à vérifier d'abord les messages dans l'espace Labyrinthe Connect. OTP : message neutre « demandez un nouveau code si vous ne l'avez pas reçu ». |
| **Maintenance** `503 FEATURE_DISABLED` / `OTP_SERVICE_DISABLED` | rien n'est parti, aucun crédit consommé. Les visiteurs voient le message de l'administrateur (ou « momentanément indisponible », traduit) avec l'heure de retour prévue quand elle est connue. Les SMS de commande sont replanifiés à l'heure de retour annoncée (`Retry-After`), sinon après 5, 10 puis 15 minutes ; jamais relancés en boucle. |

## Relances et idempotence

- **Jamais deux fois le même SMS** : chaque SMS de commande porte `Idempotency-Key: wc-<site>-<commande>-<statut>`,
  réutilisée à chaque tentative (l'API la rejoue pendant 24 h), l'action planifiée est unique, et la commande garde
  la trace des SMS envoyés (métadonnée `_labyrinthe_sms_sent`).
- SMS de commande : le SDK relance une fois quand c'est sans risque (erreur réseau, 5xx, 429), puis l'action est
  replanifiée après 5, 10 et 15 minutes (4 tentatives au total). Les erreurs métier ne sont pas relancées.
- OTP : chaque envoi porte une clé stable construite à partir de la session, du contexte, du numéro et du rang de
  l'envoi : un double clic ou une relance réseau rejoue le même envoi (même code, pas de second SMS) ; seul
  « Envoyer un nouveau code » après un envoi réussi en crée un autre. Les vérifications ne sont jamais relancées après
  une erreur réseau.
- Chaque appel à l'API porte un `X-Request-Id` (`wp-<uuid>`), écrit dans les notes de commande en échec et dans le
  journal des SMS : communiquez-le au support (connect@labyrinthe-rdc.com).

## Hooks et filtres pour les développeurs

**Actions**

| Action | Arguments | Quand |
|---|---|---|
| `labyrinthe_connect_sms_sent` | `array $result, array $meta` | après chaque tentative de SMS de commande ou de test ; `$result` : `success`, `recipient` (masqué), `message_id`, `status`, `segments`, `encoding`, `error_code`, `error_message`, `retryable`, `test_mode`, `replayed`, `log_id`, `request_id`, `retry_after` ; `$meta` : `context` (`order_status`, `admin_new_order`, `test`), `order_id`… |
| `labyrinthe_webhook_received` | `array $envelope` | chaque webhook vérifié et nouveau |
| `labyrinthe_message_status_changed` | `array $envelope, ?array $log_row` | `message.status_changed` (`data` : `message_id`, `status`, `reference`, `error_code`, `error_message`…) |
| `labyrinthe_otp_verified`, `labyrinthe_otp_failed` | `array $envelope` | `otp.verified` / `otp.failed` (`data` : `otp_id`, `phone`, `status`, `reason`, `verified_at`) |
| `labyrinthe_campaign_completed`, `labyrinthe_balance_low`, `labyrinthe_webhook_test_ping` | `array $envelope` | les webhooks correspondants |

**Filtres**

| Filtre | Arguments | Usage |
|---|---|---|
| `labyrinthe_connect_send_order_sms` | `bool $send, WC_Order $order, string $status` | ne pas envoyer certains SMS de commande |
| `labyrinthe_connect_order_sms_variables` | `array $vars, WC_Order $order, string $status` | ajouter ou modifier des `{variables}` |
| `labyrinthe_connect_order_sms_body` | `string $body, WC_Order $order, string $status` | texte final d'un SMS de commande au client |
| `labyrinthe_connect_sms_body` | `string $body, array $meta` | texte final de tout SMS envoyé par le plugin (hors OTP) |
| `labyrinthe_connect_order_total` | `string $total, WC_Order $order` | `{order_total}` (`25.00 USD`) |
| `labyrinthe_connect_tracking_url` | `string $url, WC_Order $order` | `{tracking_url}` |
| `labyrinthe_connect_otp_login_user` | `?WP_User $user, string $phone` | retrouver vous-même le compte d'un numéro |
| `labyrinthe_connect_otp_login_allowed` | `bool $allowed, WP_User $user` | autoriser ou refuser la connexion par SMS d'un compte |
| `labyrinthe_connect_client_ip` | `string $ip` | IP du visiteur pour la limite par IP (seul `REMOTE_ADDR` est fiable par défaut) |
| `labyrinthe_connect_web_otp_domain` | `string $domain` | domaine Web OTP ; `''` désactive le Web OTP |
| `labyrinthe_connect_webhook_tolerance` | `int $seconds` | tolérance de l'horodatage signé (300) |
| `labyrinthe_connect_delivery_order_notes` | `bool $enabled, array $row` | notes de commande sur les accusés de remise |
| `labyrinthe_connect_base_url` | `?string $url` | URL de l'API (production par défaut ; aussi `LABYRINTHE_CONNECT_BASE_URL`) |
| `labyrinthe_connect_http_client` | client PSR-18 | remplacer le client HTTP (par défaut : API HTTP de WordPress, donc proxys et `pre_http_request` s'appliquent) |

```php
// Pas de SMS « en attente » pour les commandes payées à la livraison.
add_filter( 'labyrinthe_connect_send_order_sms', function ( bool $send, WC_Order $order, string $status ): bool {
	return ( 'on-hold' === $status && 'cod' === $order->get_payment_method() ) ? false : $send;
}, 10, 3 );
```

Une extension « must-use » complète (variable personnalisée, alerte sur échec définitif, courriel de solde bas) est
dans [`examples/labyrinthe-connect-customizations.php`](examples/labyrinthe-connect-customizations.php).

Le SDK est embarqué sous le préfixe `LabyrintheConnect\Vendor\` et n'est pas destiné aux autres plugins. Pour appeler
l'API depuis votre code, ajoutez [`labyrinthe/connect-php`](../php) à votre plugin (aucun conflit grâce au préfixe),
ou appelez directement l'API REST (un SMS : `POST https://api.connect.labyrinthe-rdc.com/api/public/v1/sms` avec
`Authorization: Bearer lsk_test_…` et `{"recipient": "+243812345678", "body": "…"}` ; voir la
[spécification OpenAPI](../openapi/openapi.yaml)).

## Dépannage

| Symptôme | Solution |
|---|---|
| « Labyrinthe Connect nécessite PHP 8.1 ou plus récent » | demandez à votre hébergeur de passer à PHP 8.1, 8.2 ou 8.3 (panneau de contrôle, « Version PHP »). |
| « Labyrinthe Connect est incomplet (le dossier vendor-prefixed/ manque) » | installez le plugin depuis son zip, ou lancez `composer install` dans son dossier. |
| « La clé API enregistrée ne peut plus être lue » | les salts de `wp-config.php` ont changé : saisissez de nouveau la clé (ou utilisez `LABYRINTHE_CONNECT_API_KEY`). |
| **Tester la connexion** : « La clé API a été refusée » | clé erronée, révoquée ou tronquée : créez-en une nouvelle dans l'espace client. |
| « L’API Labyrinthe Connect est injoignable » | HTTPS sortant bloqué par l'hébergeur ou un pare-feu, `LABYRINTHE_CONNECT_BASE_URL` erronée, ou extension qui filtre `pre_http_request`. |
| Aucun SMS de commande | le statut est-il activé dans l'onglet *WooCommerce* ? La commande a-t-elle un numéro (voir ses notes) ? Consultez *WooCommerce > État > Actions planifiées*, groupe `labyrinthe-connect` : les actions restent en attente quand WP-Cron ne tourne pas (mettez en place un vrai cron). |
| Un SMS coûte 2 crédits | un accent ou un caractère spécial l'a fait passer en Unicode : activez *Accents* et regardez l'aperçu. |
| Les statuts de remise ne se mettent jamais à jour | l'URL du webhook manque dans l'espace client, ou le secret diffère (`400` signature invalide) ; une extension de sécurité ou un proxy ne doit ni bloquer ni réécrire `POST /wp-json/labyrinthe/v1/webhook`. |
| Le formulaire OTP indique « Votre session a expiré » | la page vient d'un cache de pages : excluez du cache les pages de connexion, d'inscription, de commande et de compte. |
| Tous les visiteurs atteignent la limite par IP | le site est derrière un proxy ou un CDN : renvoyez la vraie IP du client avec `labyrinthe_connect_client_ip` (uniquement depuis un en-tête de confiance). |
| « Ce compte ne peut pas se connecter par SMS » | le compte n'a pas de numéro vérifié, le numéro est partagé, ou c'est un administrateur (voir *Connexion par SMS*). |
| Les visiteurs voient « momentanément indisponible (maintenance) » | Labyrinthe Connect a coupé le service pour un temps ; les SMS de commande repartiront automatiquement. |

Les libellés exacts dépendent de la langue du site. Communiquez l'**identifiant de requête** de la note de commande ou
du journal des SMS au support : connect@labyrinthe-rdc.com.

## Développement

```bash
composer install                 # outils de dev + vendor-prefixed/ (PHP-Scoper s'exécute automatiquement)
php bin/install-wp-tests.php     # WordPress + WooCommerce dans .wp-tests/, base labyrinthe_wp_tests
                                 # (--wp=6.4.5 --wc=8.5.2 --db-user=root --db-pass= --db-host=127.0.0.1)
composer check                   # PHPCS (WordPress Coding Standards + PHPCompatibilityWP 8.1+), PHPStan niveau 8, tests
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 composer test:integration
composer i18n                    # languages/*.pot, .po, .mo, .l10n.php et .json (WP-CLI dans tools/wp-cli)
composer build                   # dist/labyrinthe-connect.zip + dist/labyrinthe-connect/
php bin/smoke-test.php           # vérifie le plugin construit
```

La construction demande PHP 8.2+ (l'exécution, 8.1+). Elle utilise une liste blanche (`labyrinthe-connect.php`,
`uninstall.php`, `readme.txt`, `LICENSE`, `includes/`, `assets/`, `blocks/`, `languages/`, `vendor-prefixed/`), des
horodatages fixes, et vérifie que la version de l'en-tête, `LABYRINTHE_CONNECT_VERSION`, le `Stable tag` du readme et
`block.json` concordent.

**Choix techniques.** Les dépendances d'exécution (`labyrinthe/connect-php`, Guzzle, interfaces PSR) sont copiées par
PHP-Scoper dans `vendor-prefixed/` sous `LabyrintheConnect\Vendor\` : un autre plugin qui embarque un autre Guzzle ne
peut pas entrer en conflit. Le HTTP passe par `wp_remote_request()` (adaptateur PSR-18 dans `includes/http`). L'OTP
utilise les actions `admin-ajax.php` `labyrinthe_otp_send` / `labyrinthe_otp_verify` (protégées par nonce) et du
JavaScript natif (sans étape de build).

```
labyrinthe-connect.php    en-tête du plugin, contrôle de PHP, autoloaders, hooks
uninstall.php             supprime toutes les données
includes/                 admin/ (réglages, journal, champ de profil), http/, otp/, webhook/, woocommerce/
assets/  blocks/          scripts et styles du widget et de l'administration, bloc otp-login
languages/                .pot, traductions fr_FR
examples/                 exemples de hooks (non livrés dans le zip)
bin/  tools/  tests/      scripts de build et de test, outils figés, suite PHPUnit
```

## Mise à jour, publication, contribution

- Changements : [CHANGELOG.md](CHANGELOG.md) (développeurs, en anglais) et le changelog de [`readme.txt`](readme.txt)
  (propriétaires de sites). Les mises à jour ne demandent aucun changement de réglage.
- Publication : tag `wordpress-vX.Y.Z` (voir [CONTRIBUTING.md](../CONTRIBUTING.md)) ; le workflow construit le zip,
  crée une version GitHub et déploie sur le SVN de wordpress.org.
- Tickets et pull requests : [Labyrinthe-Team/labyrinthe-connect-sdks](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks).

## Licence

GPL-2.0-or-later — voir [LICENSE](LICENSE). SDK et dépendances embarqués : MIT. Failles de sécurité : écrivez à
connect@labyrinthe-rdc.com plutôt que d'ouvrir un ticket public.
