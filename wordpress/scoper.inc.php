<?php
/**
 * Configuration PHP-Scoper : préfixe les dépendances d'exécution (SDK PHP, Guzzle,
 * PSR) sous LabyrintheConnect\Vendor\ pour éviter tout conflit avec une autre
 * extension qui embarquerait d'autres versions de Guzzle ou des interfaces PSR.
 *
 * La liste des paquets est calculée par bin/prefix-dependencies.php à partir de
 * vendor/composer/installed.json (dépendances hors require-dev) et transmise par la
 * variable d'environnement LABYRINTHE_SCOPER_PACKAGES.
 *
 * @package LabyrintheConnect
 */

// phpcs:disable -- configuration d'outil de build, hors WordPress.

use Isolated\Symfony\Component\Finder\Finder;

$packages = array_filter( explode( ',', (string) getenv( 'LABYRINTHE_SCOPER_PACKAGES' ) ) );
if ( array() === $packages ) {
	throw new RuntimeException( 'LABYRINTHE_SCOPER_PACKAGES is empty: run bin/prefix-dependencies.php.' );
}

$dirs = array_map( static fn( string $name ): string => __DIR__ . '/vendor/' . $name, $packages );

return array(
	'prefix'                  => 'LabyrintheConnect\\Vendor',
	'output-dir'              => __DIR__ . '/vendor-prefixed',
	'finders'                 => array(
		Finder::create()
			->files()
			->ignoreVCS( true )
			->in( $dirs )
			->name( array( '*.php', 'LICENSE', 'LICENSE.md', 'LICENSE.txt', 'composer.json' ) )
			->notPath( '#(^|/)(tests?|docs?|examples?|\.github)(/|$)#i' )
			->notName( '#^(phpunit|phpstan|psalm|\.php-cs-fixer|rector).*#i' ),
	),
	'exclude-namespaces'      => array(),
	'exclude-classes'         => array(),
	'exclude-functions'       => array(),
	'exclude-constants'       => array(),
	'expose-global-constants' => false,
	'expose-global-classes'   => false,
	'expose-global-functions' => false,
);
