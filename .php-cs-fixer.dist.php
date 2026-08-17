<?php

declare(strict_types=1);

// The two scripts outside src/ and tests/ are appended by name rather than by
// directory: they are the only PHP in tools/ and docker/, and PHP nobody styles is
// PHP that drifts.
$finder = new PhpCsFixer\Finder()
    ->in([__DIR__.'/src', __DIR__.'/tests'])
    ->append([
        __FILE__,
        __DIR__.'/public/index.php',
        __DIR__.'/tools/serve-router.php',
        __DIR__.'/docker/needs-seeding.php',
    ])
;

return new PhpCsFixer\Config()
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP84Migration' => true,
        'declare_strict_types' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => true,
            'import_functions' => true,
        ],
        'ordered_class_elements' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true],
        'phpdoc_to_comment' => false,
    ])
    ->setFinder($finder)
    ->setCacheFile('var/.php-cs-fixer.cache')
;
