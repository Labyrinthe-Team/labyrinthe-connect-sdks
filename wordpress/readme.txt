=== Labyrinthe Connect ===
Contributors: labyrinthe
Tags: sms, otp, woocommerce, notifications, congo
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Order SMS notifications, phone verification and login with a code received by SMS, for shops, schools and NGOs in the DR Congo and Central Africa.

== Description ==

**Français** — Labyrinthe Connect envoie des SMS et des codes de vérification (OTP) depuis votre site WordPress, avec le service [Labyrinthe Connect](https://labyrinthe-rdc.com) (RDC et Afrique centrale, paiement en USD ou CDF). Aucune ligne de code à écrire : collez votre clé API, choisissez vos messages, c'est prêt.

* **SMS de commande WooCommerce** : un SMS au client à chaque changement de statut (en attente, en cours, terminée, annulée, remboursée…), avec un modèle par statut et des variables ({order_number}, {customer_name}, {order_total}, {order_status}, {shop_name}, {tracking_url}…).
* **Notification au gérant** : un SMS pour chaque nouvelle commande, sur un ou plusieurs numéros.
* **Jamais de blocage du paiement** : les SMS partent en arrière-plan (Action Scheduler). Jamais de doublon : chaque SMS est identifié par la commande et le statut.
* **Note de commande** pour chaque SMS (envoyé, ou échec avec le code d'erreur), et accusé de remise par webhook.
* **Coût maîtrisé** : aperçu du nombre de SMS et de l'encodage de chaque modèle. Un seul accent fait passer le SMS en Unicode (70 caractères au lieu de 160, coût souvent doublé) : l'option « Retirer les accents » l'évite.
* **Vérification du téléphone** à l'inscription (WordPress et WooCommerce) et à la commande (validation classique et en blocs).
* **Connexion par SMS** : formulaire de connexion WordPress, « Mon compte » WooCommerce, shortcode `[labyrinthe_otp_login]` et bloc Gutenberg.
* **Journal des SMS** (numéros masqués, purge automatique) et **mode test** : avec une clé `lsk_test_`, rien n'est envoyé et rien n'est facturé.
* Interface en français (et en anglais), compatible HPOS.

**English** — Labyrinthe Connect sends SMS and one-time codes (OTP) from your WordPress site with the [Labyrinthe Connect](https://labyrinthe-rdc.com) service (DR Congo and Central Africa). No code to write: paste your API key, choose your messages, done.

* **WooCommerce order SMS**: an SMS to the customer on every status change, with one editable template per status and variables.
* **Shop manager notification** for every new order.
* **Never blocks checkout**: SMS are sent in the background (Action Scheduler); never sent twice (idempotency key per order and status).
* **Order notes** for every SMS (sent, or failed with the error code) and delivery reports by webhook.
* **Cost control**: live preview of the number of SMS segments and of the encoding; optional removal of accents (an accent switches the SMS to Unicode, often doubling the cost).
* **Phone verification** at registration and at checkout (classic and block checkout).
* **Login by SMS**: WordPress login form, WooCommerce "My account", `[labyrinthe_otp_login]` shortcode and a block.
* **SMS log** with masked numbers and automatic purge; **test mode** with `lsk_test_` keys (nothing sent, nothing billed).
* HPOS compatible. WooCommerce is optional: without it, OTP registration and login still work.

= Requirements =

* WordPress 6.4 or newer, **PHP 8.1 or newer** (required by the Labyrinthe Connect PHP SDK; most hosts offer it in their control panel, often under "PHP version").
* WooCommerce 8.0 or newer for the order features (optional).
* A Labyrinthe Connect account and an API key (client area > Developers > API keys).

== External services ==

This plugin connects to the **Labyrinthe Connect API** (`https://api.labyrinthe-rdc.com`), operated by Labyrinthe Connect, to send SMS and one-time codes. It is required for the plugin to work.

* **When**: only when an SMS is sent (order status change, new order, test SMS from the settings), when a visitor asks for or types a verification code, and on the plugin settings page (balance, approved Sender IDs). Public pages never call the API on load.
* **What is sent**: your API key, the recipient phone number, the message text (order SMS) or the verification request (OTP: phone number, optional message template and language), and a reference (order number).
* **What is received**: delivery statuses, by the webhook `/wp-json/labyrinthe/v1/webhook` if you configure it (signed with your webhook secret).

Terms of service: https://labyrinthe-rdc.com/conditions — Privacy policy: https://labyrinthe-rdc.com/confidentialite

== Installation ==

1. Install the plugin from *Plugins > Add New* (search "Labyrinthe Connect"), or upload `labyrinthe-connect.zip`, then activate it.
2. Open *Labyrinthe Connect > Settings*, paste your API key (start with a `lsk_test_` key: nothing is sent), click **Test the connection**, then **Send a test SMS**.
3. Choose the Sender ID shown on phones (approved in your client area).
4. *WooCommerce* tab: enable the statuses that send an SMS and adapt the messages.
5. *OTP* tab: enable login by SMS, phone verification at registration or at checkout.
6. Optional, for delivery reports: copy the webhook URL into your client area (Developers > Webhooks) and paste the webhook secret in the settings.
7. Replace the test key with your `lsk_live_` key when everything works.

== Frequently Asked Questions ==

= Why does an SMS cost 2 credits? / Pourquoi un SMS coûte-t-il 2 crédits ? =

An SMS holds 160 characters in GSM-7. A single accent (é, à, ç), a typographic quote (’ « ») or an emoji switches it to Unicode: 70 characters only, so the same text often needs twice as many SMS. The settings show the count under each template; enable "Remove accents" to stay in GSM-7. The default French templates have no accents on purpose.

Un SMS contient 160 caractères en GSM-7. Un seul accent le fait passer en Unicode (70 caractères) : activez « Retirer les accents ».

= Does the plugin slow down checkout? =

No. When an order changes status, the plugin only schedules a background action (Action Scheduler, included in WooCommerce). The API is called a few seconds later, outside the visitor's request.

= Can a customer receive the same SMS twice? =

No. Each SMS carries an idempotency key built from the site, the order and the status: if WooCommerce fires the same hook twice, or if a send is retried after a network error, the API sends it only once. The order also remembers which SMS were sent.

= What is test mode? =

With an API key starting with `lsk_test_`, the API simulates everything: no SMS is sent and no credit is used, but the responses (and the order notes) look like production. In test mode, administrators also see the OTP code on screen to test login and verification.

= Where is the API key stored? =

Encrypted in the database with the security keys of your `wp-config.php`, and never shown again in clear. You can also define it in `wp-config.php`: `define( 'LABYRINTHE_CONNECT_API_KEY', 'lsk_live_…' );` (and `LABYRINTHE_CONNECT_WEBHOOK_SECRET`).

= How does login by SMS find the account? =

With the phone number verified by SMS at registration or at checkout, or set by an administrator in the user profile ("Phone number for SMS login"). A number shared by several accounts cannot be used. Administrators cannot log in by SMS unless you allow it.

= My hosting uses PHP 7.4. =

The plugin needs PHP 8.1 or newer and stays inactive (with a notice) on older versions. PHP 7.4 and 8.0 no longer receive security updates: ask your host to switch to PHP 8.1, 8.2 or 8.3.

= Does it work with page caching? =

Yes for order SMS. For OTP forms, exclude the login, registration and checkout pages from the page cache (most cache plugins do it by default), as for any form with a security token.

== Screenshots ==

1. Settings: API key (encrypted), test mode, Sender ID, test connection and test SMS.
2. WooCommerce tab: one SMS template per order status, with the segment and encoding preview.
3. OTP tab: login by SMS, phone verification at registration and checkout.
4. Login with a code received by SMS on the WordPress login page.
5. Order notes written for every SMS.
6. SMS log with masked numbers and delivery statuses.

== Changelog ==

= 1.0.0 =
* First release: WooCommerce order SMS (per status, shop manager notification, Action Scheduler, idempotency, order notes), OTP phone verification (registration, classic and block checkout), login by SMS (login form, shortcode, block), signed webhook endpoint with deduplication, SMS log with automatic purge, encrypted API key, French translation.

== Upgrade Notice ==

= 1.0.0 =
First release.
