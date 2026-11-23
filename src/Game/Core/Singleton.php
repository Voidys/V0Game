<?php

namespace App\Game\Core;

/**
 * The game state of a request or console run, a shared container service: the named values ($state->USER,
 * PLANET, LNG, elementCatalog, request data, session, ...) are declared properties, plus the read-only
 * RuntimeContext ($state->runtime: time, mode, paths, URLs). Values are written in place
 * (`$this->state->USER['key'] = ...`); no local reference aliases.
 *
 * Nullable properties are null until the bootstrap (GameBootstrap / GameKernel) sets them; read them with
 * `??` / isset(). The row properties are untyped natively (the database returns false for a missing row).
 */
class Singleton
{
    /** @var UserRow|array{} the logged-in player's users row (+ settings); for visitors only a partial guest row (lang, timezone, ...) */
    public $USER = [];

    /** @var PlanetRow|array{} the current planet's row */
    public $PLANET = [];

    /** @var array<string, mixed> the account row (account/login areas) */
    public $ACCOUNT = [];

    /** @var \App\Twig\LanguageView|\App\Game\Core\Language|null the active language */
    public $LNG = null;

    /** the player's theme */
    public ?Theme $THEME = null;

    /** typed game data (units, costs, production, ...), set by GameData::load() */
    public ?\App\Game\Model\ElementCatalog $elementCatalog = null;

    /** @var array<string, mixed> */
    public array $requestQuery = [];

    /** @var array<string, mixed> */
    public array $requestPost = [];

    /** @var array<string, mixed> query and post merged (what RequestInput::typed() reads) */
    public array $requestInput = [];

    /** @var array<string, mixed> */
    public array $requestCookies = [];

    /** @var array<string, mixed> */
    public array $requestServer = [];

    public ?\Symfony\Component\HttpFoundation\Request $request = null;

    public ?Session $session = null;

    public ?\App\Security\GameAuth $auth = null;

    public ?\Twig\Environment $twig = null;

    public ?\Symfony\Component\HttpFoundation\ResponseHeaderBag $responseHeaders = null;

    public ?int $responseStatus = null;

    /** @var array<string, float> start, end_common, db: timings in seconds */
    public array $time = [];

    /** @var \Closure(bool): void|null loads what a lean boot skipped (argument: with planet and planet list); null when nothing was skipped */
    public ?\Closure $completeLean = null;

    /** @var list<string> parts of the game boot the action skips (App\Routing\LeanBoot constants) */
    public array $lean = [];

    /** @var array<int|string, string>|null module constant value => name */
    public ?array $modules = null;

    /** @var list<array<string, mixed>>|null recorded queries (debug mode) */
    public ?array $queryLog = null;

    /** @var int|null the current universe id, null until resolved */
    public $universeCurrent = null;

    /** @var int|null the emulated universe id (admin) */
    public $universeEmulated = null;

    /** @var list<int>|null */
    public ?array $universeAvailable = null;

    /** shared container service: one game state per request / console run */
    public function __construct(
        /** request time, mode, paths and URLs of this request (the former runtime constants) */
        public readonly \App\Runtime\RuntimeContext $runtime,
    ) {}
}
