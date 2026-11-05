<?php
/**
 * Prépare la suite de tests : télécharge WordPress et WooCommerce dans .wp-tests/
 * (ignoré par git), crée la base de test et écrit .wp-tests/wp-tests-config.php.
 *
 * Usage :
 *   php bin/install-wp-tests.php [--wp=latest|6.4.5] [--wc=latest|8.5.2]
 *        [--db-name=labyrinthe_wp_tests] [--db-user=root] [--db-pass=] [--db-host=127.0.0.1]
 *
 * Variables d'environnement équivalentes : WP_VERSION, WC_VERSION, DB_NAME, DB_USER,
 * DB_PASSWORD, DB_HOST. La base indiquée est créée si besoin ; aucune autre n'est touchée.
 *
 * @package LabyrintheConnect
 */

// phpcs:disable -- outil en ligne de commande, hors WordPress.

$options = getopt( '', array( 'wp::', 'wc::', 'db-name::', 'db-user::', 'db-pass::', 'db-host::' ) );
$option  = static function ( string $name, string $env, string $default ) use ( $options ): string {
	if ( isset( $options[ $name ] ) && is_string( $options[ $name ] ) ) {
		return $options[ $name ];
	}
	$value = getenv( $env );

	return false !== $value ? $value : $default;
};

$wp_version = $option( 'wp', 'WP_VERSION', 'latest' );
$wc_version = $option( 'wc', 'WC_VERSION', 'latest' );
$db_name    = $option( 'db-name', 'DB_NAME', 'labyrinthe_wp_tests' );
$db_user    = $option( 'db-user', 'DB_USER', 'root' );
$db_pass    = $option( 'db-pass', 'DB_PASSWORD', '' );
$db_host    = $option( 'db-host', 'DB_HOST', '127.0.0.1' );

if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $db_name ) ) {
	fail( "Invalid database name: $db_name" );
}

$root = dirname( __DIR__ ) . '/.wp-tests';
if ( ! is_dir( $root ) && ! mkdir( $root, 0777, true ) ) {
	fail( "Cannot create $root" );
}

// WordPress.
$wp_url = 'latest' === $wp_version ? 'https://wordpress.org/latest.zip' : "https://downloads.wordpress.org/release/wordpress-$wp_version.zip";
install_zip( $wp_url, "$root/downloads/wordpress-$wp_version", "$root/wordpress", 'wordpress', $wp_version );

// WooCommerce (extension officielle, depuis wordpress.org).
$wc_url = 'latest' === $wc_version ? 'https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip' : "https://downloads.wordpress.org/plugin/woocommerce.$wc_version.zip";
install_zip( $wc_url, "$root/downloads/woocommerce-$wc_version", "$root/plugins/woocommerce", 'woocommerce', $wc_version );

// Base de test dédiée.
mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
try {
	[ $host, $port ] = array_pad( explode( ':', $db_host, 2 ), 2, null );
	$mysqli          = new mysqli( $host, $db_user, $db_pass, '', null !== $port ? (int) $port : 3306 );
	$mysqli->query( "CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );
	$mysqli->close();
} catch ( mysqli_sql_exception $e ) {
	fail( 'MySQL: ' . $e->getMessage() );
}

$config = "<?php\n// Généré par bin/install-wp-tests.php.\n"
	. "define( 'ABSPATH', " . var_export( "$root/wordpress/", true ) . " );\n"
	. "define( 'WP_DEFAULT_THEME', 'default' );\n"
	. "define( 'WP_DEBUG', true );\n"
	. "define( 'DB_NAME', " . var_export( $db_name, true ) . " );\n"
	. "define( 'DB_USER', " . var_export( $db_user, true ) . " );\n"
	. "define( 'DB_PASSWORD', " . var_export( $db_pass, true ) . " );\n"
	. "define( 'DB_HOST', " . var_export( $db_host, true ) . " );\n"
	. "define( 'DB_CHARSET', 'utf8mb4' );\n"
	. "define( 'DB_COLLATE', '' );\n"
	. "\$table_prefix = 'wptests_';\n"
	. "define( 'WP_TESTS_DOMAIN', 'example.org' );\n"
	. "define( 'WP_TESTS_EMAIL', 'admin@example.org' );\n"
	. "define( 'WP_TESTS_TITLE', 'Labyrinthe Connect tests' );\n"
	. "define( 'WP_PHP_BINARY', 'php' );\n"
	. "define( 'WPLANG', '' );\n";
file_put_contents( "$root/wp-tests-config.php", $config );

echo "WordPress ($wp_version) and WooCommerce ($wc_version) are in .wp-tests/, database `$db_name` is ready.\n";

/**
 * Télécharge et extrait une archive dans $target (sauf si la même version y est déjà).
 *
 * @param string $url      Archive.
 * @param string $download Dossier de téléchargement (nouveau, vide).
 * @param string $target   Destination finale.
 * @param string $top      Dossier racine de l'archive.
 * @param string $version  Version demandée.
 */
function install_zip( string $url, string $download, string $target, string $top, string $version ): void {
	$marker = "$target/.labyrinthe-version";
	if ( 'latest' !== $version && is_file( $marker ) && trim( (string) file_get_contents( $marker ) ) === $version ) {
		return;
	}

	remove_tree( $download );
	mkdir( $download, 0777, true );
	$zip_file = "$download/archive.zip";

	$context = stream_context_create( array( 'http' => array( 'follow_location' => 1, 'timeout' => 300, 'user_agent' => 'labyrinthe-connect-tests' ) ) );
	$data    = @file_get_contents( $url, false, $context );
	if ( false === $data || strlen( $data ) < 1000 ) {
		fail( "Download failed: $url" );
	}
	file_put_contents( $zip_file, $data );

	$zip = new ZipArchive();
	if ( true !== $zip->open( $zip_file ) ) {
		fail( "Invalid archive: $url" );
	}
	$zip->extractTo( "$download/extracted" );
	$zip->close();

	if ( ! is_dir( "$download/extracted/$top" ) ) {
		fail( "Unexpected archive layout: $url" );
	}
	remove_tree( $target );
	if ( ! is_dir( dirname( $target ) ) ) {
		mkdir( dirname( $target ), 0777, true );
	}
	rename( "$download/extracted/$top", $target );
	file_put_contents( $marker, $version );
	remove_tree( $download );
}

/**
 * Supprime un dossier récursivement.
 *
 * @param string $dir Dossier.
 */
function remove_tree( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $dir );
}

/**
 * Arrête le script avec un message d'erreur.
 *
 * @param string $message Message.
 */
function fail( string $message ): never {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}
