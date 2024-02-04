<?php
namespace App;

use function CatPaw\Core\error;
use function CatPaw\Core\ok;
use CatPaw\Core\Unsafe;
use ScssPhp\ScssPhp\Block;
use ScssPhp\ScssPhp\Block\CallableBlock;
use ScssPhp\ScssPhp\Compiler;
use ScssPhp\ScssPhp\Node\Number;
use ScssPhp\ScssPhp\Parser;
use Throwable;

class Superstyle {
    private static function compile_interpolate(&$state, $child) {
        $result = '';
        foreach ($child as $chunk) {
            if (is_array($chunk)) {
                $type = $chunk[0];
                if ('interpolate' === $type) {
                    if (isset($chunk[1][2][0])) {
                        $result .= $chunk[1][2][0];
                    } else {
                        $result .= $state[$chunk[1][1]];
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

        $primitive          = false;
        $primitiveArguments = [];

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
                $primitiveArguments[] = $compiled;
                if (2 === count($primitiveArguments)) {
                    $stack[] = $primitive(...$primitiveArguments);
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

    private static function findClosestFunctionByName(&$state, string $name):false|ClosestFunction {
        foreach ($state['__functions'] as $nameLocal => $block) {
            if ($name === $nameLocal) {
                return new ClosestFunction(
                    compiled: $state[$nameLocal],
                    block: $block,
                );
            }
        }
        if (isset($state['__parent']) && $state['__parent']) {
            return self::findClosestFunctionByName($state['__parent'], $name);
        }

        return false;
    }

    private static function findClosesVariable(&$state, string $name):false|ClosestFunction {
        foreach ($state['__functions'] as $nameLocal => $block) {
            if ($name === $nameLocal) {
                return new ClosestFunction(
                    compiled: $state[$nameLocal],
                    block: $block,
                );
            }
        }
        if (isset($state['__parent'])) {
            return self::findClosestFunctionByName($state['__parent'], $name);
        }

        return false;
    }

    private static function compile_function_call(&$state, $child) {
        $arguments = [];

        if (!$function = self::findClosestFunctionByName($state, $child[1])) {
            return '';
        }


        foreach ($child[2] as $index => $argument) {
            if ('null' === $argument[0]) {
                break;
            }
            $value            = self::compile_value($state, $argument[1]);
            $name             = $function->block->args[$index][0];
            $arguments[$name] = $value;
        }
        return ($function->compiled)($arguments);
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
        return $state[$child[1]];
    }

    private static function &compile_value(&$state, $child) {
        $rightType = $child[0] ?? '';
        $value     = match ($rightType) {
            'keyword' => self::compile_keyword($state, $child),
            'number'  => self::compile_number($state, $child),
            'string'  => self::compile_string($state, $child),
            'fncall'  => self::compile_function_call($state, $child),
            'exp'     => self::compile_expression($state, $child),
            'var'     => self::compile_variable($state, $child),
        };

        if ('string' === $rightType) {
            if (is_array($value) && 1 === count($value)) {
                $value = $value[0] ?? '';
            } else {
                $value = self::compile_interpolate($state, $value);
            }
        }

        return $value;
    }

    private static function compile_assign(&$state, $child) {
        // $leftType = $child[1][0] ?? '';
        $leftName = $child[1][1] ?? '';

        if (!$leftName) {
            return '';
        }

        $state['__types'][$leftName] = 'variable';

        $state[$leftName] = self::compile_value($state, $child[2]);

        return '';
    }


    private static function compile_return(&$state, $child) {
        return self::compile_value($state, $child[1]);
    }

    private static function compile_debug(&$state, $child) {
        echo self::compile_value($state, $child[1]).PHP_EOL;
        return '';
    }

    private static function compile_operation(&$state, &$parameters, $child) {
        $operation = $child[0];
        $composite = [
            ...$state,
            ...$parameters,
        ];
        $result = match ($operation) {
            'debug'  => self::compile_debug($composite, $child),
            'assign' => self::compile_assign($composite, $child),
            'return' => self::compile_return($composite, $child),
        };

        foreach ($state as $key => $value) {
            if (!isset($composite[$key])) {
                continue;
            }
            $state[$key] = &$composite[$key];
        }
        return $result;
    }

    private static function compile_function(&$state, &$stateOfParent, CallableBlock $function) {
        $stateOfParent['__functions'][$function->name] = $function;
        $stateOfParent['__types'][$function->name]     = 'function';

        $compiled = function(array $parameters = []) use (&$stateOfParent, $function) {
            $stack = [];
            foreach ($function->children as $child) {
                $stack[] = self::compile_operation($stateOfParent, $parameters, $child);
            }

            if (count($stack) === 1 && is_float($stack[0])) {
                return $stack[0];
            }

            return join($stack);
        };

        $state = $compiled;
    }

    /**
     *
     * @param  mixed        $state
     * @param  Block        $block
     * @return Unsafe<void>
     */
    private static function compile_block(&$state, Block $block):Unsafe {
        $state['__functions'] = [];

        return self::compile($state, $block->children);
    }

    /**
     * @param  array        $state
     * @return Unsafe<void>
     */
    private static function compile(array &$state, array $children):Unsafe {
        if (!isset($state['__types'])) {
            $state['__types'] = [];
        }
        if (!isset($state['__types'])) {
            $state['__name'] = false;
        }
        if (!isset($state['__types'])) {
            $state['__parent'] = false;
        }
        if (!isset($state['__types'])) {
            $state['__functions'] = [];
        }
        if (!isset($state['__types'])) {
            $state['__selectors'] = [];
        }
        foreach ($children as $child) {
            [$type, $block] = $child;

            if ($block instanceof CallableBlock) {
                $name                        = $block->name;
                $state['__types'][$name]     = 'function';
                $state['__functions'][$name] = $block;
                $state['__selectors'][$name] = $block->selectors;
                $state[$name]                = [
                    '__name'   => $name,
                    '__parent' => $state,
                ];
                self::compile_function($state[$name], $state, $block);
            } else if ($block instanceof Block) {
                $name = self::name($block->selectors ?? []);
                if (!$name) {
                    continue;
                }
                $state['__types'][$name]     = 'block';
                $state['__blocks'][$name]    = $block;
                $state['__selectors'][$name] = $block->selectors;
                $state[$name]                = [
                    '__name'   => $name,
                    '__parent' => $state,
                ];
                self::compile_block($state[$name], $block)->try($error);
                if ($error) {
                    return error($error);
                }
            } else {
                $type = $child[0];
                match ($type) {
                    'assign' => self::compile_assign($state, $child),
                };
            }
        }

        return ok();
    }


    /**
     *
     * @param  array  $selectors
     * @return string
     */
    private static function name(array $selectors):string {
        $result             = '';
        $addingToAttributes = false;
        $nextIsId           = false;

        foreach ($selectors[0] ?? [] as $group) {
            foreach ($group as $selector) {
                if (is_array($selector)) {
                    return '';
                }
                if ($nextIsId) {
                    return "#$selector";
                }
                if ('#' === $selector) {
                    $nextIsId = true;
                    continue;
                }
                if ('[' === $selector) {
                    $addingToAttributes = true;
                    continue;
                } else if (']' === $selector) {
                    $addingToAttributes = false;
                    continue;
                }

                if ($addingToAttributes) {
                    $attributeName  = addslashes($selector[2][0]);
                    $attributeValue = addslashes((string)self::compile_value($state, $selector[2][1]));
                    $attributes[]   = "$attributeName\"$attributeValue\"";
                    continue;
                }

                $result .= $selector;
            }
        }
        return $result;
    }
    /**
     *
     * @param  array                      $selectors
     * @return Unsafe<ExtractedSelectors>
     */
    private static function selectors(array $selectors):Unsafe {
        $tag        = '';
        $id         = '';
        $classes    = [];
        $attributes = [];

        $addingToAttributes = false;
        $settingTag         = true;
        $settingId          = false;
        $addingToClass      = false;

        $firstGroup = true;

        foreach ($selectors[0] as $index => $group) {
            $firstGroup = 0 === $index;
            foreach ($group as $selector) {
                if ('.' === $selector) {
                    $addingToAttributes = false;
                    $settingTag         = false;
                    $settingId          = false;
                    $addingToClass      = true;
                    if (!$firstGroup) {
                        return error("Component classes must be set only within the first group of selectors.");
                    }
                    continue;
                } else if ('#' === $selector) {
                    if ($id) {
                        return error("You cannot set the id of a component more than once.");
                    }
                    $addingToAttributes = false;
                    $settingTag         = false;
                    $settingId          = true;
                    $addingToClass      = false;
                    continue;
                } else if ('[' === $selector) {
                    $addingToAttributes = true;
                    $settingTag         = false;
                    $settingId          = false;
                    $addingToClass      = false;
                    continue;
                } else if (']' === $selector) {
                    $addingToAttributes = false;
                    continue;
                }

                if ($settingTag) {
                    $tag = addslashes($selector);
                } if ($settingId) {
                    $id = 'id="'.addslashes($selector).'"';
                } else if ($addingToClass) {
                    $classes[] = addslashes($selector);
                } else if ($addingToAttributes) {
                    $attributeName  = addslashes($selector[2][0]);
                    $attributeValue = addslashes((string)self::compile_value($state, $selector[2][1]));
                    $attributes[]   = "$attributeName\"$attributeValue\"";
                }
            }
        }

        if ($classes) {
            $stringifiedClasses = 'class="'.join(' ', $classes).'"';
        } else {
            $stringifiedClasses = '';
        }

        if ($attributes) {
            $stringifiedAttributes = join(' ', $attributes);
        } else {
            $stringifiedAttributes = '';
        }

        if (!$tag) {
            $tag = 'div';
        }
        return ok(new ExtractedSelectors(
            id: $id,
            tag: $tag,
            classes: $stringifiedClasses,
            attributes: $stringifiedAttributes,
        ));
    }

    /**
     *
     * @param  mixed          $state
     * @param  array          $selectors
     * @return Unsafe<string>
     */
    public static function toHtml(&$state, ExtractedSelectors $extractedSelectors):Unsafe {
        $result      = $state['content'] ?? '';
        $isPrimitive = true;
        foreach ($state as $key => $stateLocal) {
            if (!isset($state['__types'][$key])) {
                continue;
            }
            if ('block' !== $state['__types'][$key]) {
                continue;
            }

            $block = $state['__blocks'][$key];

            /** @var Block $block */


            $extractedSelectors = self::selectors($block->selectors)->try($error);

            $content = self::toHtml($stateLocal, $extractedSelectors)->try($error);

            if ($error) {
                return error($error);
            }

            $isPrimitive = false;

            $result .= $content;
        }

        if ($isPrimitive) {
            return ok($result);
        }

        $tag        = $extractedSelectors->tag;
        $id         = $extractedSelectors->id;
        $classes    = $extractedSelectors->classes;
        $attributes = $extractedSelectors->attributes;

        return ok(<<<HTML
            <{$tag} $id $classes $attributes>{$result}</{$tag}>
            HTML);
    }


    /**
     * @param  string                 $fileName
     * @param  string                 $source
     * @param  array                  $state
     * @return Unsafe<CompiledResult>
     */
    public static function compileAndRender(string $fileName, string $source, array $state = []):Unsafe {
        try {
            $parser = new Parser($fileName);
            $block  = $parser->parse($source);
            self::compile($state, $block->children)->try($error);
            if ($error) {
                return error($error);
            }
            return self::renderState($fileName, $source, $state);
        } catch(Throwable $error) {
            return error($error);
        }
    }

    /**
     *
     * @param  string                 $fileName
     * @param  string                 $source
     * @param  array                  $state
     * @return Unsafe<CompiledResult>
     */
    public static function renderState(string $fileName, string $source, array &$state) {
        $html = self::toHtml($state, new ExtractedSelectors('id="main"'))->try($error);
        if ($error) {
            return error($error);
        }

        $cssCompiler = new Compiler();
        $css         = $cssCompiler->compileString($source)->getCss();

        return ok(new CompiledResult(
            fileName: $fileName,
            source: $source,
            html: $html,
            css: $css,
            state: $state,
        ));
    }
}
