<?php

namespace App\Controller\Login;

use App\Game\Core\UniverseRules;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;

/** Public universe settings (/rules/universe): every rule of the universe in the open, no login needed. */
class UniverseRulesController extends AbstractLoginController
{
    private UniverseRules $universeRules;

    #[Required]
    public function setUniverseRules(UniverseRules $universeRules): void
    {
        $this->universeRules = $universeRules;
    }

    /**
     * The settings of the current universe grouped as tables (UniverseRules).
     */
    #[GameRoute('index', 'rules/universe', 'universeRules')]
    public function show(): Response
    {
        return $this->render('login/page.universeRules.default.html.twig', [
            'universeRules' => $this->universeRules->groups(),
        ]);
    }
}
