<?php
namespace App;

readonly class CompiledResult {
    public function __construct(
        public string $html,
        public string $css,
    ) {
    }
}
