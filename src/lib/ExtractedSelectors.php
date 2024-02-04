<?php
namespace App;

readonly class ExtractedSelectors {
    public function __construct(
        public string $id,
        public string $tag = '',
        public string $classes = '',
        public string $attributes = '',
    ) {
    }
}
