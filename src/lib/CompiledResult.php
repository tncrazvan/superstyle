<?php
namespace App;

class CompiledResult {
    /**
     *
     * @param  string $fileName
     * @param  string $source
     * @param  string $html
     * @param  string $css
     * @param  array  $state
     * @return void
     */
    public function __construct(
        public string $fileName,
        public string $source,
        public string $html,
        public string $css,
        public array $state,
    ) {
    }
}
