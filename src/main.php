<?php
use function CatPaw\Core\anyError;
use function CatPaw\Core\asFileName;

use CatPaw\Core\File;
use CatPaw\Core\Unsafe;
use ScssPhp\ScssPhp\Block;
use ScssPhp\ScssPhp\Block\CallableBlock;
use ScssPhp\ScssPhp\Node\Number;
use ScssPhp\ScssPhp\Parser;

function compile_interpolate(&$state, $child) {
    $result = '';
    foreach ($child as $chunk) {
        if (is_array($chunk)) {
            $type = $chunk[0];
            $result .= match ($type) {
                'interpolate' => $state[$chunk[1][1]],
            };
            continue;
        }
        $result .= $chunk;
    }
    return $result;
}


function compile_expression(&$state, $child) {
    $result      = '';
    $expressions = array_slice($child, 1, count($child) - 4);

    $primitive           = false;
    $primitive_arguments = [];

    foreach ($expressions as $child) {
        $type = is_array($child)?$child[0]:$child;
        if ($found = match ($type) {
            '+' => function($left, $right) {
                if (is_string($left) || is_string($right)) {
                    return $left.$right;
                }
                return $left + $right;
            },
            '-' => function($left, $right) {
                return $left - $right;
            },
            default => false,
        }) {
            $primitive = $found;
            continue;
        }

        $compiled = compile_value($state, $child);

        if ($primitive) {
            $primitive_arguments[] = $compiled;
            if (2 === count($primitive_arguments)) {
                $result .= $primitive(...$primitive_arguments);
            }
            continue;
        }

        $result .= $compiled;
    }

    return $result;
}

function compile_string(&$state, $child) {
    return $child[2] ?? '';
}

function compile_function_call(&$state, $child) {
    $arguments = [];
    /** @var CallableBlock $function */
    $function = $state[$child[1]]['function'];
    foreach ($child[2] as $index => $argument) {
        $value            = compile_value($state, $argument[1]);
        $name             = $function->args[$index][0];
        $arguments[$name] = $value;
    }
    $compiled = $state[$child[1]]['compiled'];
    return $compiled($arguments);
}

function compile_keyword(&$state, $child) {
    return match ($child[1]) {
        'EOL'   => "\n",
        default => $child[1] ?? '',
    };
}

function compile_number(&$state, Number $child) {
    return (string)$child->getDimension();
}

function compile_value(&$state, $child) {
    $right_type = $child[0] ?? '';
    $value      = match ($right_type) {
        'keyword' => compile_keyword($state, $child),
        'number'  => compile_number($state, $child),
        'string'  => compile_string($state, $child),
        'fncall'  => compile_function_call($state, $child),
        'exp'     => compile_expression($state, $child),
    };

    if ('string' === $right_type) {
        if (is_array($value) && 1 === count($value)) {
            $value = $value[0] ?? '';
        } else {
            $value = compile_interpolate($state, $value);
        }
    }

    return $value;
}

function compile_assign(&$state, $child) {
    $left_type = $child[1][0]                  ?? '';
    $left_name = $child[1][1]?:$child[1][2][0] ?? '';

    $right_type  = $child[2][0] ?? '';
    $right_value = compile_value($state, $child[2]);

    $state[$left_name] = $right_value;

    return '';
}


function compile_return(&$state, $child) {
    return compile_value($state, $child[1]);
}

function compile_debug(&$state, $child) {
    echo compile_value($state, $child[1]).PHP_EOL;
    return '';
}

function compile_operation(&$state, $child) {
    $operation = $child[0];
    return match ($operation) {
        'debug'  => compile_debug($state, $child),
        'assign' => compile_assign($state, $child),
        'return' => compile_return($state, $child),
    };
}

function compile_function(&$state, CallableBlock $function) {
    $name       = $function->name;
    $stateLocal = [...$state];

    $compiled = function(array $state = []) use (
        $function,
        &$stateLocal,
    ) {
        $stateLocal = [
            ...$stateLocal,
            ...$state,
        ];
        $result = '';
        foreach ($function->children as $child) {
            $result .= compile_operation($stateLocal, $child);
        }

        return $result;
    };
    $state[$name] = [
        'function' => $function,
        'compiled' => $compiled,
    ];
}

function compile_block(&$state, Block $block) {
    $name       = $block->selectors[0][0][0];
    $stateLocal = [...$state];

    foreach ($block->children as $child) {
        $type = $child[0];
        match ($type) {
            'assign'   => compile_assign($stateLocal, $child),
            'block'    => compile_block($stateLocal, $child[1]),
            'function' => compile_function($stateLocal, $child[1]),
        };
    }

    $state[$name] = $stateLocal;

    return '';
}

/**
 * @param  array $state
 * @return void
 */
function compile(array $blocks, array &$state = []) {
    foreach ($blocks as $block) {
        [$type, $child] = $block;
        match ($type) {
            'function' => compile_function($state, $child),
            'block'    => compile_block($state, $child),
        };
    }
}

function main():Unsafe {
    return anyError(function() {
        try {
            $file_name = asFileName(__DIR__, './scss/app.scss');
            $parser    = new Parser($file_name);

            $app = File::open(asFileName(__DIR__, './scss/app.scss'))->try($error)
            or yield $error;
    
            $content = $app->readAll()->await()->try($error)
            or yield $error;

            $block = $parser->parse($content);

            $state = [];
            compile($block->children, $state);
            echo "Done.".PHP_EOL;
        } catch(Throwable $error) {
            yield $error;
        }
    });
}