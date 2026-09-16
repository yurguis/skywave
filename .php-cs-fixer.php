<?php

/**
 * House style: PSR-12, plus the few habits this codebase already follows — aligned
 * assignments and array arrows, short arrays, alphabetical imports, and a blank line
 * before the statements that end a block.
 */

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/tools', __DIR__ . '/public'])
    ->append([__DIR__ . '/analyze.php', __DIR__ . '/hdhomerun.php'])
    ->exclude('vendor');

return (new PhpCsFixer\Config())
    // Only for void_return, which infers a return type and so counts as risky.
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'                     => true,
        'array_syntax'               => ['syntax' => 'short'],
        'ordered_imports'            => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'          => true,
        'trailing_comma_in_multiline' => true,
        'void_return'                => true,
        'binary_operator_spaces'     => [
            'default'   => 'single_space',
            'operators' => ['=' => 'align_single_space_minimal', '=>' => 'align_single_space_minimal'],
        ],
        'unary_operator_spaces'      => true,
        'blank_line_before_statement' => [
            'statements' => ['break', 'continue', 'declare', 'return', 'throw', 'try'],
        ],
    ])
    ->setFinder($finder);
