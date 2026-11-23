<?php

namespace App\Game\Core;

use App\Runtime\ProjectPaths;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Renders spy reports for the message list: spy report messages store a JSON blob built by MissionCaseSpy;
 * this class turns it into the message title and the HTML report (Twig template game/shared.mission.spyReport).
 */
class SpyReport
{
    use \App\Game\Core\GameUtilities;

    /** seconds a rendered report stays cached (also bounds how long a changed theme image or translation of an unchanged file can show stale) */
    private const CACHE_TTL = 43200;

    /** bump when the cached value layout or what the render depends on changes */
    private const CACHE_VERSION = 2;

    private ?string $renderStamp = null;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
        private readonly ?CacheInterface $cache = null,
    ) {}

    /**
     * Title and HTML of a report message in one go, from the render cache when there is one (the message list shows many
     * reports per page). Not a report in the JSON format: empty title and the text unchanged.
     *
     * @return array{title: string, html: string}
     */
    public function titleAndHtml(string $MessageText): array
    {
        if ($this->cache === null || !json_validate($MessageText)) {
            return ['title' => $this->getSpyReportTitle($MessageText), 'html' => $this->getSpyReport($MessageText)];
        }

        return $this->cache->get($this->cacheKey($MessageText), function (ItemInterface $item) use ($MessageText): array {
            $item->expiresAfter(self::CACHE_TTL);

            return ['title' => $this->getSpyReportTitle($MessageText), 'html' => $this->getSpyReport($MessageText)];
        });
    }

    /**
     * Cache key of the rendered report: everything the output depends on besides the report data itself, i.e. the language
     * (and the language files' build time), the templates' modification times, the viewer's theme, time zone and number
     * format, plus a hash of the stored report.
     */
    private function cacheKey(string $MessageText): string
    {
        $USER = $this->state->USER;
        $this->renderStamp ??= (string) max(
            (int) @filemtime(ProjectPaths::ROOT . 'templates/game/shared.mission.spyReport.html.twig'),
            (int) @filemtime(ProjectPaths::ROOT . 'templates/game/tooltip.element.html.twig'),
            (int) @filemtime(rtrim($this->state->runtime->cachePath, '/') . '/languages'),
        );

        return 'spyreport_' . sha1(implode("\0", [
            self::CACHE_VERSION,
            $this->renderStamp,
            $this->state->LNG->getLanguage(),
            $USER['dpath'] ?? '',
            $USER['css_dpath'] ?? '',
            $this->state->THEME?->getImageThemeName() ?? '',
            $USER['timezone'] ?? '',
            $USER['decimal_separator'] ?? '',
            // the raid tools show the age and the fuel from the current planet: refresh them every 15 minutes and per planet / drive tech
            intdiv($this->state->runtime->timestamp, 900),
            $this->state->PLANET['id'] ?? 0,
            ($USER['combustion_tech'] ?? 0) . '/' . ($USER['impulse_motor_tech'] ?? 0) . '/' . ($USER['hyperspace_motor_tech'] ?? 0) . '/' . ($USER['spio_anz'] ?? 1),
            md5($MessageText),
        ]));
    }

    /**
     * Title line of a report message (planet, owner, coordinates, spy time) from the JSON text; '' if the text is not valid JSON.
     */
    public function getSpyReportTitle(string $MessageText): string
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $LNG->includeData(['FLEET']);
        $reportData = json_decode($MessageText, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return '';
        }

        $spyTime = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $reportData['time'], $USER['timezone'], $LNG);
        return $LNG->format(
            'sys_mess_spy_report_details_' . $reportData['targetPlanet']['planet_type'],
            $reportData['targetPlanet']['name'],
            $reportData['targetUserName'],
            $reportData['targetPlanet']['galaxy'],
            $reportData['targetPlanet']['system'],
            $reportData['targetPlanet']['planet'],
            $spyTime,
        );
    }

    /**
     * HTML of a report from the stored JSON text, in the viewer's theme; the text is returned unchanged if it is not JSON (old-format reports).
     */
    public function getSpyReport(string $MessageText): string
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $LNG->includeData(['FLEET']);
        $reportData = json_decode($MessageText, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $MessageText;
        }

        $spyTime = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $reportData['time'], $USER['timezone'], $LNG);
        $title = $LNG->format(
            'sys_mess_spy_report_details_' . $reportData['targetPlanet']['planet_type'],
            $reportData['targetPlanet']['name'],
            $reportData['targetUserName'],
            $reportData['targetPlanet']['galaxy'],
            $reportData['targetPlanet']['system'],
            $reportData['targetPlanet']['planet'],
            $spyTime,
        );

        $THEME = new Theme();
        if (!empty($USER['dpath'])) {
            $THEME->setUserTheme($USER['dpath'], $USER['css_dpath'] ?? null);
        }

        // the report is HTML shown inside the message list, so it is compacted like the former output filter did
        $html = $this->gameView()->render('game/shared.mission.spyReport.html.twig', array_replace($reportData, [
            'title'        => $title,
            'dpath'        => $THEME->getTheme(),
            'themeCssPath' => $THEME->getThemeCssPath(),
            'tools'        => $this->tools($reportData),
        ]));

        return \App\View\HtmlWhitespace::trim($html);
    }

    /**
     * Raid planning numbers of the report (SpyReportTools) for the template; null when the report has no coordinates.
     *
     * @param array<string, mixed> $reportData
     * @return array<string, mixed>|null
     */
    private function tools(array $reportData): ?array
    {
        if (!isset($reportData['targetPlanet']['galaxy'], $reportData['time']) || empty($this->state->PLANET)) {
            return null;
        }

        return (new SpyReportTools($this->db, $this->configs, $this->state))->analyse($reportData);
    }
}
