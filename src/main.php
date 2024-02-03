<?php
use function CatPaw\Core\anyError;
use function CatPaw\Core\env;
use CatPaw\Core\Unsafe;
use CatPaw\Web\Server;

function main():Unsafe {
    return anyError(function() {
        $server = Server::create(api:env('api'))->try($error)
        or yield $error;

        $server->start()->await()->try($error)
        or yield $error;
    });
}
