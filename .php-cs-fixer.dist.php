<?php

declare(strict_types=1);

use ErickSkrauch\PhpCsFixer\Fixers;

putenv('PHP_CS_FIXER_IGNORE_ENV=1');

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . DIRECTORY_SEPARATOR . 'src',
        __DIR__ . DIRECTORY_SEPARATOR . 'tests',
        __DIR__ . DIRECTORY_SEPARATOR . 'migrations',
        __DIR__ . DIRECTORY_SEPARATOR . 'public',
        __DIR__ . DIRECTORY_SEPARATOR . 'config',
    ])
    ->exclude('var')
;

return (new PhpCsFixer\Config())
    ->registerCustomFixers(new Fixers())
    ->setRules([
        '@Symfony'                                   => true,
        'ErickSkrauch/align_multiline_parameters'    => true,
        'ErickSkrauch/blank_line_before_return'      => true,
        'ErickSkrauch/multiline_if_statement_braces' => true,
        'concat_space'                               => [
            'spacing' => 'one'
        ],
        'class_attributes_separation' => [
            'elements' => [
                'method' => 'one',
                'const'  => 'none'
            ],
        ],
        'cast_spaces'                 => false,
        'yoda_style'                  => false,
        'trailing_comma_in_multiline' => false,
        'braces_position'             => [
            'classes_opening_brace'   => 'next_line_unless_newline_at_signature_end',
            'functions_opening_brace' => 'next_line_unless_newline_at_signature_end'
        ],
        'global_namespace_import' => [
            'import_classes'   => true,
            'import_constants' => true,
            'import_functions' => true
        ],
        'binary_operator_spaces' => [
            'default' => 'align_single_space_minimal',
        ],
        'single_line_throw' => false,
    ])
    ->setFinder($finder)
;
