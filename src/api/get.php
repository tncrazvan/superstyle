<?php
use App\Services\SuperstyleService;
use CatPaw\Web\Accepts;
use CatPaw\Web\Attributes\Produces;
use CatPaw\Web\Attributes\SessionId;
use const CatPaw\Web\INTERNAL_SERVER_ERROR;
use const CatPaw\Web\OK;
use const CatPaw\Web\TEXT_HTML;

return
#[Produces(OK, TEXT_HTML, 'On success.', 'string')]
#[Produces(INTERNAL_SERVER_ERROR, TEXT_HTML, 'On success.', 'string')]
static function(
    SuperstyleService $service,
    Accepts $accepts,
    #[SessionId]
    string $sessionId,
) {
    return $service->render($accepts, $sessionId);
};
