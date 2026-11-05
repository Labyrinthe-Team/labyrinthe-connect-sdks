<?php
/**
 * Construit dist/labyrinthe-connect.zip (installable depuis Extensions > Ajouter) et
 * dist/labyrinthe-connect/ (même contenu, pour le déploiement SVN sur wordpress.org).
 *
 * - liste blanche de fichiers : aucun fichier de développement (tests, outils, Composer…) ;
 * - dépendances préfixées reconstruites (bin/prefix-dependencies.php) ;
 * - archive reproductible : fichiers triés, date fixe (SOURCE_DATE_EPOCH ou date de version) ;
 * - vérifications : versions cohérentes, syntaxe PHP de chaque fichier, fichiers interdits.
 *
 * Usage : php bin/build-zip.php [--skip-prefix]
 *
 * @package LabyrintheConnect
 */

// phpcs:disable -- script de build en ligne de commande, hors WordPress.

const LABYRINTHE_SLUG = 'labyrinthe-connect';

$root  = dirname( __DIR__ );
$dist  = $root . '/dist';
$build = $dist . '/' . LABYRINTHE_SLUG;
$zip   = $dist . '/' . LABYRINTHE_SLUG . '.zip';

// Contenu distribué (liste blanche).
$include = array(
	'labyrinthe-connect.php',
	'uninstall.php',
	'readme.txt',
	'LICENSE',
	'includes',
	'assets',
	'blocks',
	'languages',
	'vendor-prefixed',
);

// Jamais distribués, même s'ils se trouvent dans un dossier inclus.
$forbidden = '#(^|/)(\.git|\.github|\.DS_Store|Thumbs\.db|node_modules|tests?|tools|bin|\.wp-tests|vendor)(/|$)|(^|/)(composer\.(json|lock)|phpunit[^/]*|phpcs[^/]*|phpstan[^/]*|scoper\.inc\.php|\.distignore|\.gitignore|\.editorconfig)$#i';

// 1. Versions cohérentes : en-tête, constante, readme.txt (Stable tag).
$main   = (string) file_get_contents( $root . '/labyrinthe-connect.php' );
$readme = (string) file_get_contents( $root . '/readme.txt' );
preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $main, $header );
preg_match( "/define\( 'LABYRINTHE_CONNECT_VERSION', '([^']+)' \)/", $main, $constant );
preg_match( '/^Stable tag:\s*(\S+)/m', $readme, $stable );
$version = $header[1] ?? '';
if ( '' === $version || ( $constant[1] ?? '' ) !== $version || ( $stable[1] ?? '' ) !== $version ) {
	fail( sprintf( 'Version mismatch: header %s, constant %s, readme.txt Stable tag %s.', $version, $constant[1] ?? '?', $stable[1] ?? '?' ) );
}
$block = json_decode( (string) file_get_contents( $root . '/blocks/otp-login/block.json' ), true );
if ( ( $block['version'] ?? '' ) !== $version ) {
	fail( 'blocks/otp-login/block.json version must be ' . $version );
}

// 2. Dépendances préfixées et traductions compilées.
if ( ! in_array( '--skip-prefix', $argv, true ) ) {
	passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $root . '/bin/prefix-dependencies.php' ), $code );
	if ( 0 !== $code ) {
		exit( $code );
	}
}
foreach ( array( 'vendor-prefixed/autoload.php', 'languages/labyrinthe-connect.pot', 'languages/labyrinthe-connect-fr_FR.mo' ) as $required ) {
	if ( ! is_file( $root . '/' . $required ) ) {
		fail( "$required is missing (composer install, composer i18n)." );
	}
}

// 3. Liste des fichiers.
$files = array();
foreach ( $include as $entry ) {
	$path = $root . '/' . $entry;
	if ( is_file( $path ) ) {
		$files[] = $entry;
		continue;
	}
	if ( ! is_dir( $path ) ) {
		fail( "$entry is missing." );
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		$relative = $entry . '/' . str_replace( '\\', '/', substr( $file->getPathname(), strlen( $path ) + 1 ) );
		if ( 1 === preg_match( $forbidden, substr( $relative, strlen( $entry ) + 1 ) ) ) {
			continue;
		}
		$files[] = $relative;
	}
}
sort( $files, SORT_STRING );

// 4. Copie dans dist/labyrinthe-connect/ et archive.
remove_tree( $build );
if ( ! is_dir( $dist ) ) {
	mkdir( $dist, 0777, true );
}
if ( is_file( $zip ) ) {
	unlink( $zip );
}
$mtime   = (int) ( getenv( 'SOURCE_DATE_EPOCH' ) ?: strtotime( '2026-11-06 00:00:00 UTC' ) );
$archive = new ZipArchive();
if ( true !== $archive->open( $zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fail( "Cannot create $zip" );
}
$archive->addEmptyDir( LABYRINTHE_SLUG );
$archive->setMtimeName( LABYRINTHE_SLUG . "/", $mtime );
foreach ( $files as $relative ) {
	$target = $build . '/' . $relative;
	if ( ! is_dir( dirname( $target ) ) ) {
		mkdir( dirname( $target ), 0777, true );
	}
	copy( $root . '/' . $relative, $target );
	$name = LABYRINTHE_SLUG . '/' . $relative;
	$archive->addFile( $root . '/' . $relative, $name );
	$archive->setMtimeName( $name, $mtime );
	$archive->setExternalAttributesName( $name, ZipArchive::OPSYS_UNIX, 0644 << 16 );
}
$archive->close();

// 5. Vérifications du contenu.
$check = new ZipArchive();
$check->open( $zip );
$names = array();
for ( $i = 0; $i < $check->numFiles; $i++ ) {
	$names[] = (string) $check->getNameIndex( $i );
}
$check->close();
foreach ( array( 'labyrinthe-connect.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'vendor-prefixed/autoload.php', 'vendor-prefixed/labyrinthe/connect-php/src/Client.php', 'languages/labyrinthe-connect-fr_FR.mo', 'blocks/otp-login/block.json' ) as $expected ) {
	if ( ! in_array( LABYRINTHE_SLUG . '/' . $expected, $names, true ) ) {
		fail( "The zip does not contain $expected." );
	}
}
foreach ( $names as $name ) {
	$inside = substr( $name, strlen( LABYRINTHE_SLUG ) + 1 );
	if ( '' !== $inside && 1 === preg_match( $forbidden, $inside ) ) {
		fail( "Development file in the zip: $name" );
	}
	if ( 0 !== strpos( $name, LABYRINTHE_SLUG . '/' ) && LABYRINTHE_SLUG . '/' !== $name ) {
		fail( "File outside the plugin folder: $name" );
	}
}

// Syntaxe PHP de chaque fichier distribué (avec la version de PHP du build).
$php_files = array_filter( $files, static fn( string $f ): bool => str_ends_with( $f, '.php' ) );
foreach ( $php_files as $relative ) {
	exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $build . '/' . $relative ) . ' 2>&1', $output, $code );
	if ( 0 !== $code ) {
		fail( "PHP syntax error in $relative: " . implode( "\n", $output ) );
	}
}

printf(
	"dist/%s.zip %s: %d files (%d PHP), %.1f KB, sha256 %s\n",
	LABYRINTHE_SLUG,
	$version,
	count( $files ),
	count( $php_files ),
	filesize( $zip ) / 1024,
	hash_file( 'sha256', $zip )
);

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
 * Arrête le build avec un message d'erreur.
 *
 * @param string $message Message.
 */
function fail( string $message ): never {
	fwrite( STDERR, "Build failed: $message\n" );
	exit( 1 );
}
