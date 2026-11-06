<?php
/**
 * Réglages du plugin (une seule option, tableau).
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect;

use LabyrintheConnect\Vendor\Labyrinthe\Connect\PhoneNumber;
use LabyrintheConnect\Vendor\Labyrinthe\Connect\Service\OtpService;

defined( 'ABSPATH' ) || exit;

/**
 * Lecture, valeurs par défaut et assainissement des réglages.
 *
 * Les secrets (clé API, secret de webhook) sont chiffrés en base (Crypto) et ne sont
 * jamais renvoyés au navigateur : le formulaire envoie une nouvelle valeur dans
 * api_key_new / webhook_secret_new, laissée vide pour conserver l'actuelle.
 * Ils peuvent aussi être définis dans wp-config.php (LABYRINTHE_CONNECT_API_KEY,
 * LABYRINTHE_CONNECT_WEBHOOK_SECRET), ce qui est prioritaire.
 */
final class Settings {

	public const OPTION = 'labyrinthe_connect_settings';

	/** Format d'une clé API : lsk_live_xx_… ou lsk_test_xx_… */
	public const API_KEY_PATTERN = '/^lsk_(live|test)_[A-Za-z0-9_]{8,200}$/';

	public const TAB_GENERAL     = 'general';
	public const TAB_WOOCOMMERCE = 'woocommerce';
	public const TAB_OTP         = 'otp';

	public const MAX_TEMPLATE_LENGTH = 1600;

	/** Statuts de commande activés par défaut. */
	private const DEFAULT_ENABLED_STATUSES = array( 'processing', 'completed' );

	/**
	 * Valeurs par défaut (sans traduction : les modèles vides prennent le modèle par défaut).
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'api_key'              => '',
			'webhook_secret'       => '',
			'sender'               => '',
			'country_code'         => PhoneNumber::DEFAULT_COUNTRY_CODE,
			'strip_accents'        => false,
			'log_retention_days'   => 90,
			'wc_statuses'          => array(),
			'wc_admin_enabled'     => false,
			'wc_admin_phones'      => '',
			'wc_admin_template'    => '',
			'otp_login_enabled'    => false,
			'otp_login_form'       => true,
			'otp_allow_admins'     => false,
			'otp_register_enabled' => false,
			'otp_checkout_enabled' => false,
			'otp_template'         => '',
			'otp_ip_send_limit'    => 5,
			'otp_web_otp'          => true,
			'otp_web_otp_domain'   => '',
		);
	}

	/**
	 * Tous les réglages, complétés par les valeurs par défaut.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Un réglage.
	 *
	 * @param string $key Nom du réglage.
	 * @return mixed
	 */
	public function get( string $key ) {
		$all = $this->all();

		return $all[ $key ] ?? null;
	}

	/**
	 * Clé API en clair ('' si absente). Jamais affichée ni journalisée.
	 */
	public function api_key(): string {
		if ( defined( 'LABYRINTHE_CONNECT_API_KEY' ) && is_string( LABYRINTHE_CONNECT_API_KEY ) && '' !== LABYRINTHE_CONNECT_API_KEY ) {
			return trim( LABYRINTHE_CONNECT_API_KEY );
		}

		return Crypto::decrypt( (string) $this->get( 'api_key' ) );
	}

	/**
	 * D'où vient la clé : 'constant' (wp-config.php), 'option' (réglages) ou 'none'.
	 */
	public function api_key_source(): string {
		if ( defined( 'LABYRINTHE_CONNECT_API_KEY' ) && is_string( LABYRINTHE_CONNECT_API_KEY ) && '' !== LABYRINTHE_CONNECT_API_KEY ) {
			return 'constant';
		}

		return '' !== $this->api_key() ? 'option' : 'none';
	}

	/**
	 * Vrai quand une clé est enregistrée mais ne se déchiffre plus (salts modifiés).
	 */
	public function api_key_unreadable(): bool {
		$stored = (string) $this->get( 'api_key' );

		return 'constant' !== $this->api_key_source() && '' !== $stored && '' === Crypto::decrypt( $stored );
	}

	/**
	 * Vrai si une clé API utilisable est configurée.
	 */
	public function has_api_key(): bool {
		return '' !== $this->api_key();
	}

	/**
	 * Mode test : clé lsk_test_… (rien n'est envoyé, aucun crédit consommé).
	 */
	public function is_test_mode(): bool {
		return 0 === strpos( $this->api_key(), 'lsk_test_' );
	}

	/**
	 * Indice affichable de la clé : « lsk_test_ab_…wxyz » (jamais la clé entière).
	 */
	public function api_key_hint(): string {
		$key = $this->api_key();
		if ( '' === $key ) {
			return '';
		}
		$prefix = preg_match( '/^(lsk_(?:live|test)_)/', $key, $matches ) ? $matches[1] : '';

		return $prefix . '…' . substr( $key, -4 );
	}

	/**
	 * Secret de webhook en clair ('' si absent).
	 */
	public function webhook_secret(): string {
		if ( defined( 'LABYRINTHE_CONNECT_WEBHOOK_SECRET' ) && is_string( LABYRINTHE_CONNECT_WEBHOOK_SECRET ) && '' !== LABYRINTHE_CONNECT_WEBHOOK_SECRET ) {
			return trim( LABYRINTHE_CONNECT_WEBHOOK_SECRET );
		}

		return Crypto::decrypt( (string) $this->get( 'webhook_secret' ) );
	}

	/**
	 * Vrai si le secret de webhook vient de wp-config.php.
	 */
	public function webhook_secret_from_constant(): bool {
		return defined( 'LABYRINTHE_CONNECT_WEBHOOK_SECRET' ) && is_string( LABYRINTHE_CONNECT_WEBHOOK_SECRET ) && '' !== LABYRINTHE_CONNECT_WEBHOOK_SECRET;
	}

	/**
	 * Sender ID choisi, ou null pour l'expéditeur par défaut de la plateforme.
	 */
	public function sender(): ?string {
		$sender = (string) $this->get( 'sender' );

		return '' === $sender ? null : $sender;
	}

	/**
	 * Indicatif par défaut des numéros au format local (243 pour la RDC).
	 */
	public function country_code(): string {
		$code = (string) $this->get( 'country_code' );

		return 1 === preg_match( '/^[1-9]\d{0,2}$/', $code ) ? $code : PhoneNumber::DEFAULT_COUNTRY_CODE;
	}

	/**
	 * Retirer les accents des SMS (reste en GSM-7, coût souvent divisé par deux).
	 */
	public function strip_accents(): bool {
		return (bool) $this->get( 'strip_accents' );
	}

	/**
	 * Durée de conservation du journal, en jours (0 : illimitée).
	 */
	public function log_retention_days(): int {
		return max( 0, (int) $this->get( 'log_retention_days' ) );
	}

	/**
	 * Réglage SMS d'un statut de commande.
	 *
	 * @param string $status Statut sans préfixe « wc- ».
	 * @return array{enabled: bool, template: string}
	 */
	public function order_status( string $status ): array {
		$statuses = (array) $this->get( 'wc_statuses' );
		$setting  = isset( $statuses[ $status ] ) && is_array( $statuses[ $status ] ) ? $statuses[ $status ] : array();
		$template = isset( $setting['template'] ) ? (string) $setting['template'] : '';

		return array(
			'enabled'  => isset( $setting['enabled'] ) ? (bool) $setting['enabled'] : in_array( $status, self::DEFAULT_ENABLED_STATUSES, true ),
			'template' => '' !== trim( $template ) ? $template : Templates::order_status( $status ),
		);
	}

	/**
	 * Modèle de la notification « nouvelle commande » envoyée à l'administrateur.
	 */
	public function admin_template(): string {
		$template = (string) $this->get( 'wc_admin_template' );

		return '' !== trim( $template ) ? $template : Templates::admin_new_order();
	}

	/**
	 * Numéros de l'administrateur (normalisés).
	 *
	 * @return list<string>
	 */
	public function admin_phones(): array {
		$phones = preg_split( '/[\s,;]+/', (string) $this->get( 'wc_admin_phones' ) );

		return array_values( array_filter( false === $phones ? array() : $phones ) );
	}

	/**
	 * Modèle OTP personnalisé, ou null pour celui des paramètres OTP du compte.
	 */
	public function otp_template(): ?string {
		$template = trim( (string) $this->get( 'otp_template' ) );

		return '' === $template ? null : $template;
	}

	/**
	 * Domaine Web OTP (remplissage automatique du code par le navigateur), ou null si l'option
	 * est désactivée ou si le domaine n'est pas un nom d'hôte valide (localhost, adresse IP…).
	 *
	 * Par défaut : le domaine du site (home_url), qui doit être celui de la page où le code est saisi.
	 */
	public function web_otp_domain(): ?string {
		if ( ! $this->get( 'otp_web_otp' ) ) {
			return null;
		}
		$domain = (string) $this->get( 'otp_web_otp_domain' );
		if ( '' === $domain ) {
			$domain = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}

		/**
		 * Domaine Web OTP ajouté aux SMS de code (dernière ligne « @domaine #code »).
		 *
		 * @param string $domain Nom d'hôte (exemple.com) ; une chaîne vide désactive le Web OTP.
		 */
		$domain = strtolower( trim( (string) apply_filters( 'labyrinthe_connect_web_otp_domain', $domain ) ) );

		return self::is_valid_web_otp_domain( $domain ) ? $domain : null;
	}

	/**
	 * Vrai pour un nom d'hôte accepté par l'API (au moins deux libellés, sans schéma, port ni chemin).
	 *
	 * @param string $domain Nom d'hôte.
	 */
	public static function is_valid_web_otp_domain( string $domain ): bool {
		return '' !== $domain && strlen( $domain ) <= OtpService::MAX_WEB_OTP_DOMAIN_LENGTH && 1 === preg_match( '/' . OtpService::WEB_OTP_DOMAIN_PATTERN . '/', $domain );
	}

	/**
	 * Assainit les réglages (callback de register_setting, aussi appelé par update_option).
	 *
	 * Depuis le formulaire, seul l'onglet envoyé (_tab) est modifié ; une case absente vaut
	 * « non ». Appelé avec un tableau complet (code, mise à jour), chaque clé connue est
	 * assainie et les autres sont conservées.
	 *
	 * @param mixed $input Valeurs reçues.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		$current = $this->all();
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$tab = isset( $input['_tab'] ) ? sanitize_key( (string) $input['_tab'] ) : '';
		if ( '' !== $tab ) {
			$input = $this->with_unchecked_boxes( $tab, $input );
		}

		$output = $current;

		// Secrets : nouvelle valeur, suppression, ou valeur déjà chiffrée (appel par le code).
		$output['api_key']        = $this->sanitize_secret( $input, 'api_key', $current['api_key'], true );
		$output['webhook_secret'] = $this->sanitize_secret( $input, 'webhook_secret', $current['webhook_secret'], false );

		if ( array_key_exists( 'sender', $input ) ) {
			$sender           = sanitize_text_field( (string) $input['sender'] );
			$output['sender'] = mb_substr( $sender, 0, 11 );
		}

		if ( array_key_exists( 'country_code', $input ) ) {
			$code = preg_replace( '/\D/', '', (string) $input['country_code'] );
			if ( 1 === preg_match( '/^[1-9]\d{0,2}$/', (string) $code ) ) {
				$output['country_code'] = (string) $code;
			} else {
				self::error( 'country_code', __( 'The default country code must be 1 to 3 digits, for example 243 for the DR Congo.', 'labyrinthe-connect' ) );
			}
		}

		foreach ( array( 'strip_accents', 'wc_admin_enabled', 'otp_login_enabled', 'otp_login_form', 'otp_allow_admins', 'otp_register_enabled', 'otp_checkout_enabled', 'otp_web_otp' ) as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				$output[ $flag ] = self::to_bool( $input[ $flag ] );
			}
		}

		if ( array_key_exists( 'log_retention_days', $input ) ) {
			$output['log_retention_days'] = min( 3650, absint( $input['log_retention_days'] ) );
		}

		if ( array_key_exists( 'otp_web_otp_domain', $input ) ) {
			$domain = self::sanitize_domain( $input['otp_web_otp_domain'] );
			if ( '' === $domain || self::is_valid_web_otp_domain( $domain ) ) {
				$output['otp_web_otp_domain'] = $domain;
			} else {
				self::error( 'otp_web_otp_domain', __( 'The Web OTP domain must be a host name such as shop.example.com (no https://, port or path). The previous domain was kept.', 'labyrinthe-connect' ) );
			}
		}

		if ( array_key_exists( 'otp_ip_send_limit', $input ) ) {
			$output['otp_ip_send_limit'] = max( 1, min( 100, absint( $input['otp_ip_send_limit'] ) ) );
		}

		if ( array_key_exists( 'wc_statuses', $input ) && is_array( $input['wc_statuses'] ) ) {
			$output['wc_statuses'] = $this->sanitize_statuses( $input['wc_statuses'], (array) $current['wc_statuses'] );
		}

		if ( array_key_exists( 'wc_admin_template', $input ) ) {
			$output['wc_admin_template'] = self::sanitize_template( $input['wc_admin_template'] );
		}

		if ( array_key_exists( 'wc_admin_phones', $input ) ) {
			$output['wc_admin_phones'] = $this->sanitize_phone_list( (string) $input['wc_admin_phones'], $output['country_code'] );
		}

		if ( array_key_exists( 'otp_template', $input ) ) {
			$template = self::sanitize_template( $input['otp_template'] );
			if ( '' !== $template && false === strpos( $template, '{code}' ) ) {
				self::error( 'otp_template', __( 'The OTP message must contain {code}. The previous message was kept.', 'labyrinthe-connect' ) );
			} else {
				$output['otp_template'] = $template;
			}
		}

		// Jamais enregistrés : champs techniques du formulaire.
		unset( $output['_tab'], $output['api_key_new'], $output['api_key_clear'], $output['webhook_secret_new'], $output['webhook_secret_clear'] );

		return array_intersect_key( $output, self::defaults() );
	}

	/**
	 * Les cases décochées ne sont pas envoyées par le navigateur : on les met à « non ».
	 *
	 * @param string               $tab   Onglet envoyé.
	 * @param array<string, mixed> $input Valeurs reçues.
	 * @return array<string, mixed>
	 */
	private function with_unchecked_boxes( string $tab, array $input ): array {
		$boxes = array(
			self::TAB_GENERAL     => array( 'strip_accents' ),
			self::TAB_WOOCOMMERCE => array( 'wc_admin_enabled' ),
			self::TAB_OTP         => array( 'otp_login_enabled', 'otp_login_form', 'otp_allow_admins', 'otp_register_enabled', 'otp_checkout_enabled', 'otp_web_otp' ),
		);
		foreach ( $boxes[ $tab ] ?? array() as $box ) {
			if ( ! isset( $input[ $box ] ) ) {
				$input[ $box ] = false;
			}
		}

		return $input;
	}

	/**
	 * Assainit un secret (clé API ou secret de webhook) et le chiffre.
	 *
	 * @param array<string, mixed> $input    Valeurs reçues.
	 * @param string               $name     api_key ou webhook_secret.
	 * @param mixed                $current  Valeur chiffrée actuelle.
	 * @param bool                 $is_api_key Vrai pour la clé API (format vérifié).
	 */
	private function sanitize_secret( array $input, string $name, $current, bool $is_api_key ): string {
		$current = is_string( $current ) ? $current : '';

		if ( ! empty( $input[ $name . '_clear' ] ) ) {
			return '';
		}

		$new = isset( $input[ $name . '_new' ] ) ? trim( (string) $input[ $name . '_new' ] ) : '';
		if ( '' !== $new ) {
			if ( $is_api_key && 1 !== preg_match( self::API_KEY_PATTERN, $new ) ) {
				self::error( 'api_key', __( 'This API key is not valid: it must start with lsk_live_ or lsk_test_. The previous key was kept.', 'labyrinthe-connect' ) );

				return $current;
			}
			if ( ! $is_api_key && ( strlen( $new ) < 16 || strlen( $new ) > 255 || 1 === preg_match( '/\s/', $new ) ) ) {
				self::error( 'webhook_secret', __( 'The webhook secret must be 16 to 255 characters without spaces. The previous secret was kept.', 'labyrinthe-connect' ) );

				return $current;
			}
			if ( $is_api_key ) {
				// Les Sender IDs dépendent du compte : la liste en cache n'est plus valable.
				delete_transient( Sender_Ids::CACHE_KEY );
			}

			return Crypto::encrypt( $new );
		}

		// Appel par le code avec une valeur déjà chiffrée.
		if ( isset( $input[ $name ] ) && is_string( $input[ $name ] ) && ( '' === $input[ $name ] || Crypto::is_encrypted( $input[ $name ] ) ) ) {
			return $input[ $name ];
		}

		return $current;
	}

	/**
	 * Assainit les réglages par statut de commande.
	 *
	 * @param array<mixed> $statuses Valeurs reçues.
	 * @param array<mixed> $current  Valeurs actuelles.
	 * @return array<string, array{enabled: bool, template: string}>
	 */
	private function sanitize_statuses( array $statuses, array $current ): array {
		$output = array();
		foreach ( $current as $slug => $setting ) {
			if ( is_array( $setting ) ) {
				$output[ sanitize_key( (string) $slug ) ] = array(
					'enabled'  => ! empty( $setting['enabled'] ),
					'template' => self::sanitize_template( $setting['template'] ?? '' ),
				);
			}
		}
		foreach ( $statuses as $slug => $setting ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug || ! is_array( $setting ) ) {
				continue;
			}
			$output[ $slug ] = array(
				'enabled'  => self::to_bool( $setting['enabled'] ?? false ),
				'template' => self::sanitize_template( $setting['template'] ?? '' ),
			);
		}
		ksort( $output );

		return $output;
	}

	/**
	 * Assainit une liste de numéros (séparés par des virgules ou des retours à la ligne).
	 *
	 * @param string $raw          Liste saisie.
	 * @param mixed  $country_code Indicatif par défaut.
	 */
	private function sanitize_phone_list( string $raw, $country_code ): string {
		$country_code = is_string( $country_code ) && 1 === preg_match( '/^[1-9]\d{0,2}$/', $country_code ) ? $country_code : PhoneNumber::DEFAULT_COUNTRY_CODE;
		$phones       = array();
		$invalid      = array();
		$parts        = preg_split( '/[,;\r\n]+/', sanitize_textarea_field( $raw ) );
		foreach ( false === $parts ? array() : $parts as $phone ) {
			$phone = trim( $phone );
			if ( '' === $phone ) {
				continue;
			}
			$normalized = PhoneNumber::normalize( $phone, $country_code );
			if ( null === $normalized ) {
				$invalid[] = $phone;
			} else {
				$phones[] = '+' . $normalized;
			}
		}
		if ( array() !== $invalid ) {
			self::error(
				'wc_admin_phones',
				sprintf(
					/* translators: %s: list of invalid phone numbers. */
					__( 'Invalid phone number(s) ignored: %s.', 'labyrinthe-connect' ),
					implode( ', ', array_map( 'sanitize_text_field', $invalid ) )
				)
			);
		}

		return implode( ', ', array_unique( $phones ) );
	}

	/**
	 * Booléen d'une case à cocher ou d'une valeur enregistrée ('1', 'on', true, 'yes'…).
	 *
	 * @param mixed $value Valeur reçue.
	 */
	private static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return is_scalar( $value ) && true === filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
	}

	/**
	 * Nom d'hôte saisi : en minuscules, sans schéma, chemin ni port (https://Shop.example.com/ → shop.example.com).
	 *
	 * @param mixed $value Valeur saisie.
	 */
	private static function sanitize_domain( $value ): string {
		$value = strtolower( trim( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ) ) );
		if ( '' === $value ) {
			return '';
		}
		$host = wp_parse_url( false === strpos( $value, '//' ) ? '//' . $value : $value, PHP_URL_HOST );

		return is_string( $host ) ? rtrim( $host, '.' ) : $value;
	}

	/**
	 * Assainit un modèle de SMS (texte brut, 1600 caractères au plus).
	 *
	 * @param mixed $template Modèle saisi.
	 */
	public static function sanitize_template( $template ): string {
		$template = sanitize_textarea_field( is_scalar( $template ) ? (string) $template : '' );

		return mb_substr( $template, 0, self::MAX_TEMPLATE_LENGTH );
	}

	/**
	 * Signale une erreur de saisie dans la page de réglages.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 */
	private static function error( string $code, string $message ): void {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION, 'labyrinthe_connect_' . $code, $message );
		}
	}
}
