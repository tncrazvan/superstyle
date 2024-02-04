<?php
namespace App\Services;

use App\CompiledResult;
use App\Superstyle;
use CatPaw\Core\Attributes\Service;
use function CatPaw\Core\error;
use CatPaw\Core\File;
use function CatPaw\Core\ok;
use CatPaw\Core\Unsafe;
use CatPaw\Web\Accepts;
use function CatPaw\Web\failure;
use CatPaw\Web\Interfaces\ResponseModifier;
use function CatPaw\Web\success;
use const CatPaw\Web\TEXT_HTML;

#[Service]
class SuperstyleService {
    /** @var array<string,string> */
    private array $sources = [];
    /** @var array<string,CompiledResult> */
    private array $compiled = [];
    /** @var array<string,CompiledResult> */
    private array $invalidating = [];

    public function findCompiledResult(string $sessionId, string $fileName):false|CompiledResult {
        $result = false;

        if (isset($this->compiled[$sessionId][$fileName])) {
            return $this->compiled[$sessionId][$fileName] ?? false;
        }

        return $result;
    }

    public function invalidate(string $sessionId, string $fileName):bool {
        if (!$compiled = $this->findCompiledResult($sessionId, $fileName)) {
            return false;
        }

        $this->invalidating[$sessionId][$fileName] = $compiled;

        return true;
    }

    /**
     *
     * @param  string                 $sessionId
     * @return Unsafe<CompiledResult>
     */
    private function compile(string $sessionId, string $fileName) {
        if (isset($this->invalidating[$sessionId][$fileName])) {
            $compiled = $this->compiled[$sessionId][$fileName];

            $compiled = Superstyle::renderState($compiled->fileName, $compiled->source, $compiled->state)->try($error);
            if ($error) {
                return error("Couldn't compile file $compiled->fileName.\n$error");
            }

            $this->compiled[$sessionId][$fileName] = $compiled;

            return ok($compiled);
        }

        if (isset($this->compiled[$sessionId][$fileName])) {
            return ok($this->compiled[$sessionId][$fileName]);
        }

        $source = File::open($fileName)->try($error);
        if ($error) {
            return error("Couldn't open file $fileName.\n$error");
        }

        $source = $source->readAll()->await()->try($error);
        if ($error) {
            return error("Couldn't read file $fileName.\n$error");
        }

        $this->sources[$sessionId] = $source;

        $compiled = Superstyle::compileAndRender($fileName, $source)->try($error);
        if ($error) {
            return error("Couldn't compile file $fileName.\n$error");
        }

        if (!isset($this->compiled[$sessionId])) {
            $this->compiled[$sessionId] = [];
        }

        $this->compiled[$sessionId][$fileName] = $compiled;

        return ok($compiled);
    }

    public function render(Accepts $accepts, string $sessionId, string $fileName):ResponseModifier {
        $result = $this->compile($sessionId, $fileName)->try($error);
        if ($error) {
            return failure($error);
        }

        return match (true) {
            $accepts->match('/\*\/\*/'),
            $accepts->html() => success(<<<HTML
                <!DOCTYPE html>
                <html lang="en">
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <title>Document</title>
                    <script src="https://unpkg.com/htmx.org@1.9.10"></script>
                    <style>
                        {$result->css}
                    </style>
                </head>
                <body>
                    {$result->html}
                </body>
                </html>
                HTML)->as(TEXT_HTML),
            default => failure("Unsupported content-type $accepts.")->as(TEXT_HTML)
        };
    }
}
