<?php
namespace App;

use Closure;
use ScssPhp\ScssPhp\Block\CallableBlock;

readonly class ClosestFunction {
    public function __construct(
        public Closure $compiled,
        public CallableBlock $block,
    ) {
    }
}
