<?php
namespace App;

use ScssPhp\ScssPhp\Block;
use ScssPhp\ScssPhp\Block\CallableBlock;
use ScssPhp\ScssPhp\Node\Number;
use ScssPhp\ScssPhp\Parser;


class Superstyle {
    private static function compile_interpolate(&$state, $child, false|callable $update = false) {
        $result = '';
        foreach ($child as $chunk) {
            if (is_array($chunk)) {
                $type = $chunk[0];
                if ('interpolate' === $type) {
                    if (isset($chunk[1][2][0])) {
                        $result .= $chunk[1][2][0];
                    } else {
                        $result .= $state[$chunk[1][1]]['value'];
                        if ($update) {
                            $state[$chunk[1][1]]['subscribers'][] = $update;
                        }
                    }
                }
                continue;
            }
            $result .= $chunk;
        }
        return $result;
    }


    private static function compile_expression(&$state, $child) {
        $stack       = [];
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

            $compiled = self::compile_value($state, $child);

            if ($primitive) {
                $primitive_arguments[] = $compiled;
                if (2 === count($primitive_arguments)) {
                    $stack[] = $primitive(...$primitive_arguments);
                }
                continue;
            }

            $stack[] = $compiled;
        }

        if (count($stack) === 1 && is_float($stack[0])) {
            return $stack[0];
        }

        return join($stack);
    }

    private static function compile_string(&$state, $child) {
        return $child[2] ?? '';
    }

    private static function compile_function_call(&$state, $child) {
        $arguments = [];
        /** @var CallableBlock $private static function */
        $function = $state[$child[1]]['function'];
        foreach ($child[2] as $index => $argument) {
            if ('null' === $argument[0]) {
                break;
            }
            $value            = self::compile_value($state, $argument[1]);
            $name             = $function->args[$index][0];
            $arguments[$name] = [
                'type'  => 'parameter',
                'value' => $value,
            ];
        }
        $compiled = $state[$child[1]]['compiled'];
        return $compiled($arguments);
    }

    private static function compile_keyword(&$state, $child) {
        return match ($child[1]) {
            'EOL'   => "\n",
            default => $child[1] ?? '',
        };
    }

    private static function compile_number(&$state, Number $child) {
        return (float)$child->getDimension();
    }

    private static function compile_variable(&$state, $child) {
        return $state[$child[1]]['value'];
    }

    private static function &compile_value(&$state, $child, false|callable $update = false) {
        $right_type = $child[0] ?? '';
        $value      = match ($right_type) {
            'keyword' => self::compile_keyword($state, $child),
            'number'  => self::compile_number($state, $child),
            'string'  => self::compile_string($state, $child),
            'fncall'  => self::compile_function_call($state, $child),
            'exp'     => self::compile_expression($state, $child),
            'var'     => self::compile_variable($state, $child),
        };

        if ('string' === $right_type) {
            if (is_array($value) && 1 === count($value)) {
                $value = $value[0] ?? '';
            } else {
                $value = self::compile_interpolate($state, $value, $update);
            }
        }

        return $value;
    }

    private static function compile_assign(&$state, $child) {
        $left_type = $child[1][0] ?? '';
        $left_name = $child[1][1] ?? '';

        if (!$left_name) {
            return '';
        }

        $subscribers = [];
        if (isset($state[$left_name])) {
            $subscribers = $state[$left_name]['subscribers'] ?? [];
        } 

        $set = function(&$state) use (&$left_name, &$child, &$subscribers) {
            $state[$left_name] = [
                'type'        => 'variable',
                'value'       => self::compile_value($state, $child[2]),
                'subscribers' => $subscribers,
            ];
        };

        self::compile_value($state, $child[2], $set);

        $set($state);
        

        foreach ($subscribers as $subscriber) {
            $subscriber($state);
        }


        return '';
    }


    private static function compile_return(&$state, $child) {
        return self::compile_value($state, $child[1]);
    }

    private static function compile_debug(&$state, $child) {
        echo self::compile_value($state, $child[1]).PHP_EOL;
        return '';
    }

    private static function compile_operation(&$state, $child) {
        $operation = $child[0];
        return match ($operation) {
            'debug'  => self::compile_debug($state, $child),
            'assign' => self::compile_assign($state, $child),
            'return' => self::compile_return($state, $child),
        };
    }

    private static function compile_function(&$state, CallableBlock $function) {
        $name = $function->name;
        
        if (isset($state[$name])) {
            $stateLocal = $state[$name];
        } else {
            $stateLocal = [...$state];
        }

        $compiled = function(array $arguments = []) use (
            $function,
            &$state,
            &$stateLocal,
        ) {
            $stateLocal = [
                ...$stateLocal,
                ...$arguments,
            ];
            $stack = [];
            foreach ($function->children as $child) {
                $stack[] = self::compile_operation($stateLocal, $child);
            }


            foreach ($stateLocal as $key => $valueLocal) {
                if (!isset($state[$key]) || $state[$key] !== $valueLocal) {
                    $state[$key] = $valueLocal;
                }
            }

            if (count($stack) === 1 && is_float($stack[0])) {
                return $stack[0];
            }

            return $stack;
        };
        $state[$name] = [
            'type'     => 'function',
            'function' => $function,
            'compiled' => $compiled,
        ];
    }

    private static function compile_block(&$state, Block $block) {
        $name = $block->selectors[0][0][0];
        if (is_array($name)) {
            return '';
        }
        
        if (isset($state[$name])) {
            $stateLocal = $state[$name];
        } else {
            $stateLocal = [...$state];
        }
        
        foreach ($block->children as $child) {
            $type = $child[0];
            match ($type) {
                'assign'   => self::compile_assign($stateLocal, $child),
                'block'    => self::compile_block($stateLocal, $child[1]),
                'function' => self::compile_function($stateLocal, $child[1]),
            };
        }

        $state[$name] = [
            'type'  => 'block',
            'value' => &$stateLocal,
        ];

        return '';
    }

    /**
     * @param  array $state
     * @return void
     */
    private static function compile(array &$state = [], array $blocks) {
        foreach ($blocks as $block) {
            [$type, $child] = $block;
            match ($type) {
                'function' => self::compile_function($state, $child),
                'block'    => self::compile_block($state, $child),
            };
        }
    }

    private static function toHtml(&$state, string $component = '') {
        $result = '';
        foreach ($state as $key => $value) {
            if ('function' === $value['type']) {
                continue;
            }

            if ('block' === $value['type']) {
                $result .= self::toHtml($value['value'], $key);
                continue;
            }

            if ('content' !== $key) {
                continue;
            }

            $result .= $value['value'];
        }
        
        if ($component) {
            return <<<HTML
                <{$component}>{$result}</{$component}>
                HTML;
        }

        return $result;
    }


    public static function render(string $file_name, string $content, array $state = []) {
        $parser = new Parser($file_name);
        $block  = $parser->parse($content);
        self::compile($state, $block->children);

        $state['App']['value']['button']['value']['click']['compiled']();
        $state['App']['value']['button']['value']['click']['compiled']();
        $state['App']['value']['button']['value']['click']['compiled']();

        $cssCompiler = new \ScssPhp\ScssPhp\Compiler();
        $cssCompiler->compileString($content);

        return [
            'html' => self::toHtml($state),
            'css'  => $cssCompiler->compileString($content)->getCss(),
        ];
    }
}