<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/config', __DIR__.'/lang']);

$config = (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced'],
        'phpdoc_to_comment' => false,
        'php_unit_method_casing' => ['case' => 'snake_case'],
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
        'yoda_style' => false,
        'static_lambda' => false, // Manager::extend() binds its closure
        'concat_space' => ['spacing' => 'none'],
        'global_namespace_import' => ['import_classes' => false],
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays', 'match', 'parameters']],
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/.php-cs-fixer.cache');

// Local runs on a PHP newer than the fixer officially supports (CI lints on 8.3).
if (method_exists($config, 'setUnsupportedPhpVersionAllowed')) {
    $config->setUnsupportedPhpVersionAllowed(true);
}

return $config;
