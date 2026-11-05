<?php
/**
 * Copie les dépendances d'exécution (SDK PHP, Guzzle, PSR) dans vendor-prefixed/
 * sous l'espace de noms LabyrintheConnect\Vendor\ (PHP-Scoper), puis écrit
 * vendor-prefixed/autoload.php. Aucune autre extension embarquant Guzzle ou les
 * interfaces PSR ne peut ainsi entrer en conflit avec le plugin.
 *
 * Reproductible : PHP-Scoper est figé (tools/php-scoper/composer.lock), les
 * paquets viennent de vendor/composer/installed.json (hors require-dev), et
 * l'autoloader généré est trié et sans date.
 *
 * Usage : php bin/prefix-dependencies.php   (lancé aussi par composer install/update)
 *
 * @package LabyrintheConnect
 */

// phpcs:disable -- script de build en ligne de commande, hors WordPress.

const LABYRINTHE_PREFIX = 'LabyrintheConnect\\Vendor\\';

// Inutiles en PHP >= 8.1 (exigence du plugin) : jamais embarqués.
const LABYRINTHE_SKIPPED_PACKAGES = array( 'symfony/polyfill-php80', 'symfony/polyfill-mbstring', 'symfony/polyfill-ctype' );

$root   = dirname( __DIR__ );
$tools  = $root . '/tools/php-scoper';
$target = $root . '/vendor-prefixed';

// PHP-Scoper demande PHP 8.2+ (le plugin, lui, tourne dès PHP 8.1 : en CI, vendor-prefixed/ est
// construit une fois en PHP 8.3 puis réutilisé par les tests en PHP 8.1).
if ( PHP_VERSION_ID < 80200 ) {
	$message = 'PHP-Scoper needs PHP 8.2+: vendor-prefixed/ was not rebuilt (run "composer prefix-dependencies" with PHP 8.2 or newer).';
	if ( is_file( $target . '/autoload.php' ) ) {
		fwrite( STDERR, "Warning: $message\n" );
		exit( 0 );
	}
	fwrite( STDERR, "$message\n" );
	exit( 1 );
}

$installed_file = $root . '/vendor/composer/installed.json';
if ( ! is_file( $installed_file ) ) {
	fwrite( STDERR, "vendor/ is missing: run composer install first.\n" );
	exit( 1 );
}

if ( ! is_file( $tools . '/vendor/humbug/php-scoper/bin/php-scoper' ) ) {
	passthru( 'composer --working-dir=' . escapeshellarg( $tools ) . ' install --no-interaction --no-progress --prefer-dist', $code );
	if ( 0 !== $code ) {
		exit( $code );
	}
}

$installed = json_decode( (string) file_get_contents( $installed_file ), true );
$dev       = $installed['dev-package-names'] ?? array();
$packages  = array();
foreach ( $installed['packages'] as $package ) {
	if ( ! in_array( $package['name'], $dev, true ) && ! in_array( $package['name'], LABYRINTHE_SKIPPED_PACKAGES, true ) ) {
		$packages[ $package['name'] ] = $package;
	}
}
ksort( $packages );

if ( ! isset( $packages['labyrinthe/connect-php'] ) ) {
	fwrite( STDERR, "labyrinthe/connect-php is not installed.\n" );
	exit( 1 );
}

// Point de départ propre : un fichier supprimé d'une dépendance ne doit pas survivre.
remove_tree( $target );

putenv( 'LABYRINTHE_SCOPER_PACKAGES=' . implode( ',', array_keys( $packages ) ) );
$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $tools . '/vendor/humbug/php-scoper/bin/php-scoper' )
	. ' add-prefix --config=' . escapeshellarg( $root . '/scoper.inc.php' ) . ' --force --no-interaction --quiet';
passthru( $command, $code );
if ( 0 !== $code ) {
	exit( $code );
}

// Autoloader : PSR-4 (espaces de noms préfixés) + fichiers à inclure (fonctions).
$psr4  = array();
$files = array();
foreach ( $packages as $name => $package ) {
	foreach ( $package['autoload']['psr-4'] ?? array() as $namespace => $paths ) {
		foreach ( (array) $paths as $path ) {
			$psr4[ LABYRINTHE_PREFIX . $namespace ][] = rtrim( $name . '/' . trim( $path, '/' ), '/' );
		}
	}
	foreach ( $package['autoload']['files'] ?? array() as $file ) {
		$files[] = $name . '/' . $file;
	}
	if ( isset( $package['autoload']['classmap'] ) ) {
		fwrite( STDERR, "$name uses a classmap autoload, which is not supported here.\n" );
		exit( 1 );
	}
}
// Les espaces de noms les plus longs d'abord (GuzzleHttp\Psr7\ avant GuzzleHttp\).
uksort( $psr4, static fn( string $a, string $b ): int => ( strlen( $b ) <=> strlen( $a ) ) ?: strcmp( $a, $b ) );

$autoload  = "<?php\n";
$autoload .= "/**\n * Autoloader des dépendances préfixées (généré par bin/prefix-dependencies.php, ne pas modifier).\n *\n * @package LabyrintheConnect\n */\n\n";
$autoload .= "// phpcs:ignoreFile\n\n";
$autoload .= "if ( defined( 'LABYRINTHE_CONNECT_VENDOR_AUTOLOADED' ) ) {\n\treturn;\n}\ndefine( 'LABYRINTHE_CONNECT_VENDOR_AUTOLOADED', true );\n\n";
$autoload .= "spl_autoload_register(\n\tstatic function ( \$class ) {\n\t\tstatic \$map = " . export( $psr4 ) . ";\n";
$autoload .= "\t\tif ( 0 !== strpos( \$class, " . var_export( LABYRINTHE_PREFIX, true ) . " ) ) {\n\t\t\treturn;\n\t\t}\n";
$autoload .= "\t\tforeach ( \$map as \$prefix => \$dirs ) {\n\t\t\tif ( 0 !== strpos( \$class, \$prefix ) ) {\n\t\t\t\tcontinue;\n\t\t\t}\n";
$autoload .= "\t\t\t\$relative = str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n";
$autoload .= "\t\t\tforeach ( \$dirs as \$dir ) {\n\t\t\t\t\$file = __DIR__ . '/' . \$dir . '/' . \$relative;\n\t\t\t\tif ( is_file( \$file ) ) {\n\t\t\t\t\trequire \$file;\n\t\t\t\t\treturn;\n\t\t\t\t}\n\t\t\t}\n\t\t}\n\t}\n);\n\n";
foreach ( $files as $file ) {
	$autoload .= "require_once __DIR__ . '/" . $file . "';\n";
}
file_put_contents( $target . '/autoload.php', $autoload );

// Vérifications : le SDK est bien préfixé et chargeable.
$client = (string) file_get_contents( $target . '/labyrinthe/connect-php/src/Client.php' );
if ( false === strpos( $client, 'namespace LabyrintheConnect\\Vendor\\Labyrinthe\\Connect;' ) ) {
	fwrite( STDERR, "The SDK was not prefixed (namespace LabyrintheConnect\\Vendor\\Labyrinthe\\Connect expected).\n" );
	exit( 1 );
}
$probe = $target . '/.probe.php';
file_put_contents(
	$probe,
	"<?php require __DIR__ . '/autoload.php';\n"
	. "\$c = new LabyrintheConnect\\Vendor\\Labyrinthe\\Connect\\Client('lsk_test_check');\n"
	. "echo \$c->isTestMode() && class_exists('LabyrintheConnect\\Vendor\\GuzzleHttp\\Psr7\\HttpFactory') ? 'ok' : 'ko';\n"
);
$result = trim( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $probe ) ) );
unlink( $probe );
if ( 'ok' !== $result ) {
	fwrite( STDERR, "The prefixed SDK cannot be loaded from vendor-prefixed/autoload.php: $result\n" );
	exit( 1 );
}

printf( "vendor-prefixed/ is ready (%d packages: %s).\n", count( $packages ), implode( ', ', array_keys( $packages ) ) );

/**
 * var_export lisible et stable.
 *
 * @param array<string, list<string>> $map Carte PSR-4.
 */
function export( array $map ): string {
	$out = "array(\n";
	foreach ( $map as $namespace => $dirs ) {
		$out .= "\t\t\t" . var_export( $namespace, true ) . ' => array( ' . implode( ', ', array_map( static fn( $d ) => var_export( $d, true ), $dirs ) ) . " ),\n";
	}
	return $out . "\t\t)";
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
