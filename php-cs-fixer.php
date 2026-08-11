<?php

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12'                         => true,
        '@PHP80Migration'                => true,

        // Imports
        'ordered_imports'                => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'              => true,
        'global_namespace_import'        => ['import_classes' => false],

        // Arrays
        'array_syntax'                   => ['syntax' => 'short'],
        'trailing_comma_in_multiline'    => ['elements' => ['arrays', 'parameters']],

        // Strings
        'single_quote'                   => true,
        'explicit_string_variable'       => true,

        // Funciones y métodos
        'no_empty_statement'             => true,
        'return_type_declaration'        => ['space_before' => 'none'],
        'method_chaining_indentation'    => true,
        'nullable_type_declaration_for_default_null_value' => true,

        // PHPDoc
        'phpdoc_align'                   => ['align' => 'left'],
        'phpdoc_order'                   => true,
        'phpdoc_scalar'                  => true,
        'phpdoc_trim'                    => true,
        'no_superfluous_phpdoc_tags'     => ['remove_inheritdoc' => false],

        // Espacios y formato
        'no_extra_blank_lines'           => true,
        'blank_line_before_statement'    => ['statements' => ['return', 'throw', 'try']],
        'cast_spaces'                    => ['space' => 'single'],
        'concat_space'                   => ['spacing' => 'one'],
    ])
    ->setFinder($finder)
    ->setUsingCache(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
