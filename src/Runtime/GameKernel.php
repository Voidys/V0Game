<?php

namespace App\Runtime;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Twig\Environment;
use App\Game\Core\Language;
use App\Game\Core\Singleton;

/**
 * Bootstraps the game code for one request, as the former entry scripts did:
 * defines MODE and ROOT_PATH, presents the request as the original script
 * ($_SERVER SCRIPT_*), and runs GameBootstrap (config, DB, session, user, planet).
 */
final class GameKernel
{
    public function __construct(
        private readonly Environment $twig,
        private readonly GameBootstrap $bootstrap,
        private readonly \App\Game\Core\Database $db,
        private readonly \App\Game\Core\ConfigRegistry $configs,
        private readonly Singleton $state,
        private readonly \Symfony\Component\HttpFoundation\RequestStack $requests,
    ) {
        // game templates render with Symfony's Twig environment (App\View\GameView)
        $this->state->twig = $this->twig;
    }

    /** Project root with trailing slash (as in RuntimeContext::rootPath), for legacy code paths. */
    public function getRootPath(): string
    {
        return $this->state->runtime->rootPath;
    }

    /**
     * @param string $mode       LOGIN, INGAME, ADMIN, ACCOUNT, CRON, ...
     * @param string $scriptName the original entry script, e.g. "game"
     * @param list<string> $lean parts of the boot to skip (App\Routing\LeanBoot constants)
     */
    public function boot(string $mode, string $scriptName, array $lean = []): void
    {
        $this->state->lean = $lean;
        $root = $this->getRootPath();

        $this->useRequest($this->requests->getCurrentRequest() ?? Request::createFromGlobals(), $root, $scriptName);
        $this->state->runtime->start($mode, $this->state->requestServer, $this->state->requestInput);

        set_include_path($root);
        chdir($root);

        $this->state->time['start'] = microtime(true);
        $this->bootstrap->run();
        $this->state->time['end_common'] = microtime(true);
    }

    /**
     * Bootstrap for console commands: no session and no logged-in user, but the game data
     * (GameData) and the default language, like the web cron had.
     */
    public function bootCli(): void
    {
        $root = $this->getRootPath();
        // Present the run like a request to the site root, so the request URLs (runtime httpRoot/httpBase)
        // resolve as on the web.
        $this->useRequest(Request::createFromGlobals(), $root, 'index');
        $this->state->runtime->start('CLI', $this->state->requestServer);

        set_include_path($root);
        chdir($root);

        $this->bootstrap->run();
        // Registers the universes; in web requests the session setup does this.
        $this->configs->get(ROOT_UNI);
        (new \App\Game\Core\GameData($this->db, $this->configs, $this->state))->load();

        $LNG = new Language($this->configs);
        $this->state->LNG = $LNG;
        $LNG->includeData(['L18N', 'INGAME', 'TECH', 'PUBLIC', 'CUSTOM']);
        date_default_timezone_set($this->configs->get()->timezone);
    }

    /**
     * Hands the Symfony request to the game: its input lands in the game state (requestQuery,
     * requestPost, requestInput = POST over GET like $_REQUEST, requestCookies, requestServer),
     * headers/cookies/status the game sets are collected in responseHeaders/responseStatus and
     * applied to the Response by GameResponseFactory.
     */
    private function useRequest(Request $request, string $root, string $scriptName): void
    {
        $request->server->set('SCRIPT_FILENAME', $root . $scriptName);
        $request->server->set('SCRIPT_NAME', '/' . $scriptName);
        $request->server->set('PHP_SELF', '/' . $scriptName);
        // directory of the universe the page belongs to (`/`, `/uni5/`, with the base path of a sub-directory install)
        if ($request->attributes->has('uni')) {
            $request->server->set('GAME_HTTP_ROOT', $request->getBasePath() . '/' . $request->attributes->get('uni'));
        }

        $this->state->request = $request;
        $this->state->requestQuery = $request->query->all();
        $this->state->requestPost = $request->request->all();
        // path placeholders of the route ({id} in /news/{id}) are input like query and form fields, and win over them
        $pathInput = array_filter(
            (array) $request->attributes->get('_route_params', []),
            static fn(mixed $value, string|int $key): bool => is_scalar($value) && $key !== 'uni' && !str_starts_with((string) $key, '_'),
            ARRAY_FILTER_USE_BOTH,
        );
        $this->state->requestInput = array_replace($request->query->all(), $request->request->all(), $pathInput);
        $this->state->requestCookies = $request->cookies->all();
        $this->state->requestServer = $request->server->all();
        $this->state->responseHeaders = new ResponseHeaderBag();
        $this->state->responseStatus = null;
    }
}
