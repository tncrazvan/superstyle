<?php
use function CatPaw\Core\anyError;
use function CatPaw\Core\env;
use CatPaw\Core\Unsafe;
use const CatPaw\Web\APPLICATION_JSON;
use CatPaw\Web\Server;
use CatPaw\Web\Services\OpenApiService;
use function CatPaw\Web\success;

function main():Unsafe {
    return anyError(function() {
        $server = Server::create(api:env('api'))->try($error)
        or yield $error;

        $server->router->get("/openapi", fn (OpenApiService $oa) => success($oa->getData())->as(APPLICATION_JSON));

        $server->start()->await()->try($error)
        or yield $error;
    });
}
