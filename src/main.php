<?php
use App\Superstyle;
use function CatPaw\Core\anyError;
use function CatPaw\Core\asFileName;
use CatPaw\Core\File;
use CatPaw\Core\Unsafe;

function main():Unsafe {
    return anyError(function() {
        try {
            $file_name = asFileName(__DIR__, './scss/app.scss');

            $app = File::open($file_name)->try($error)
            or yield $error;
    
            $content = $app->readAll()->await()->try($error)
            or yield $error;

            $result = Superstyle::render($file_name, $content);
            print_r($result);
        } catch(Throwable $error) {
            yield $error;
        }
    });
}