<?php
use App\Superstyle;
use function CatPaw\Core\asFileName;

use CatPaw\Core\File;
use CatPaw\Web\Accepts;
use CatPaw\Web\Attributes\Produces;
use function CatPaw\Web\failure;
use const CatPaw\Web\INTERNAL_SERVER_ERROR;

use const CatPaw\Web\OK;
use function CatPaw\Web\success;
use const CatPaw\Web\TEXT_HTML;
use const CatPaw\Web\TEXT_PLAIN;

$fileName = asFileName(__DIR__, '../scss/app.scss');

// $app = File::open($fileName)->try($error)
// or yield $error;

// $content = $app->readAll()->await()->try($error)
// or yield $error;

// $result = Superstyle::render($fileName, $content)->try($error)
// or yield $error;

return
#[Produces(OK, TEXT_HTML, 'On success.', 'string')]
#[Produces(INTERNAL_SERVER_ERROR, TEXT_HTML, 'On success.', 'string')]
static function(Accepts $accepts) use ($fileName) {
    $app = File::open($fileName)->try($error);
    if ($error) {
        return failure("Couldn't open file $fileName.")->as(TEXT_HTML);
    }

    $content = $app->readAll()->await()->try($error);
    if ($error) {
        return failure("Couldn't read file $fileName.")->as(TEXT_HTML);
    }

    $result = Superstyle::render($fileName, $content)->try($error);
    if ($error) {
        return failure("Couldn't compile file $fileName. $error")->as(TEXT_HTML);
    }

    return match (true) {
        $accepts->html() => success(<<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Document</title>
                <style>
                    {$result->css}
                </style>
            </head>
            <body>
                {$result->html}
            </body>
            </html>
            HTML)->as(TEXT_HTML),
        default => failure("Unsupported content-type $accepts.")->as(TEXT_PLAIN)
    };
};
