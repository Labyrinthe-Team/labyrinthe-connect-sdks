<?php
/**
 * Traductions : régénère languages/labyrinthe-connect.pot depuis le code (PHP, JS,
 * block.json, en-tête du plugin), met à jour les .po, puis compile les .mo, les
 * .l10n.php (WordPress 6.5+) et les .json des scripts (bloc de connexion).
 *
 * Usage : php bin/i18n.php            (WP-CLI i18n, installé dans tools/wp-cli)
 *         php bin/i18n.php --check    (échoue si une chaîne française manque)
 *
 * @package LabyrintheConnect
 */

// phpcs:disable -- script en ligne de commande, hors WordPress.

$root  = dirname( __DIR__ );
$tools = $root . '/tools/wp-cli';
$check = in_array( '--check', $argv, true );

if ( ! is_file( $tools . '/vendor/wp-cli/wp-cli/php/boot-fs.php' ) ) {
	passthru( 'composer --working-dir=' . escapeshellarg( $tools ) . ' install --no-interaction --no-progress --prefer-dist', $code );
	if ( 0 !== $code ) {
		exit( $code );
	}
}

$boot      = $tools . '/vendor/wp-cli/wp-cli/php/boot-fs.php';
$languages = $root . '/languages';

// Arguments passés sans shell (proc_open avec un tableau) : le JSON des en-têtes reste intact sous Windows.
$commands = array(
	array(
		'i18n',
		'make-pot',
		$root,
		$languages . '/labyrinthe-connect.pot',
		'--slug=labyrinthe-connect',
		'--domain=labyrinthe-connect',
		'--exclude=vendor,vendor-prefixed,tools,tests,.wp-tests,dist,bin,build',
		'--headers={"Report-Msgid-Bugs-To":"https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/issues"}',
		'--file-comment=Copyright (C) 2026 Labyrinthe Connect. This file is distributed under the GPL-2.0-or-later license.',
	),
	array( 'i18n', 'update-po', $languages . '/labyrinthe-connect.pot', $languages ),
	array( 'i18n', 'make-mo', $languages ),
	array( 'i18n', 'make-php', $languages ),
	array( 'i18n', 'make-json', $languages, '--pretty-print' ),
);

foreach ( $commands as $command ) {
	$process = proc_open( array_merge( array( PHP_BINARY, $boot ), $command, array( '--quiet' ) ), array( STDIN, STDOUT, STDERR ), $pipes, $root );
	$code    = is_resource( $process ) ? proc_close( $process ) : 1;
	if ( 0 !== $code ) {
		exit( $code );
	}
}

// Les .json ne servent qu'aux scripts ; WP-CLI nomme les fichiers d'après le chemin du script.
$untranslated = array();
foreach ( glob( $languages . '/*.po' ) ?: array() as $po ) {
	$content = (string) file_get_contents( $po );
	preg_match_all( '/^msgid "(.+)"\n(?:msgid_plural ".*"\n)?msgstr(?:\[0\])? ""$/m', $content, $matches );
	foreach ( $matches[1] as $msgid ) {
		$untranslated[] = basename( $po ) . ': ' . $msgid;
	}
}

if ( array() !== $untranslated ) {
	fwrite( STDERR, "Untranslated strings:\n  " . implode( "\n  ", $untranslated ) . "\n" );
	if ( $check ) {
		exit( 1 );
	}
}

echo "languages/ is up to date.\n";
