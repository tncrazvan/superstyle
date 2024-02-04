<?php
use App\Services\SuperstyleService;
use CatPaw\Web\Accepts;
use CatPaw\Web\Attributes\Produces;
use CatPaw\Web\Attributes\SessionId;

use function CatPaw\Web\failure;

use const CatPaw\Web\INTERNAL_SERVER_ERROR;
use const CatPaw\Web\OK;
use const CatPaw\Web\TEXT_HTML;

return
#[Produces(
    status:INTERNAL_SERVER_ERROR,
    contentType:TEXT_HTML,
    description:'When no application is found in the session.',
    className:'string'
)]
#[Produces(
    status:OK,
    contentType:TEXT_HTML,
    description:'Increase the counter.',
    className:'string'
)]
function(
    Accepts $accepts,
    SuperstyleService $service,
    #[SessionId]
    string $sessionId
) {
    if (!$compiled = $service->findStateBySessionId($sessionId)) {
        return failure("Application not initialized.")->as(TEXT_HTML);
    }

    $increaseCounter = $compiled->state['#app']['#click']['increaseCounter'];

    $increaseCounter();

    if (!$service->invalidate($sessionId)) {
        return failure("Could not invalidate application.")->as(TEXT_HTML);
    }

    return $service->render($accepts, $sessionId);
};
