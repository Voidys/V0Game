<?php

namespace App\Controller\Admin;

use App\Game\Core\Config\ConfigFieldProcessor;
use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Log;
use App\Routing\GameRoute;
use App\Security\Social\SocialProvider;
use App\Security\Social\SocialSettings;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Settings of the social login (category `social` of the basic settings, own page): per provider on/off, client id and client
 * secret, with the callback URL to register at the provider. The secret is never sent back to the browser (the field shows
 * a hint; an empty input keeps the stored value).
 */
class ConfigSocialController extends AbstractAdminController
{
    private SocialSettings $socialSettings;

    /** Container (setter) injection of the social login settings. */
    #[Required]
    public function setSocialSettings(SocialSettings $socialSettings): void
    {
        $this->socialSettings = $socialSettings;
    }

    /**
     * Saves the posted fields of the category (all of them, like the basic settings page does), writes an admin log
     * entry (type 3, secrets masked) and shows the page again.
     */
    #[GameRoute('admin', 'admin/config/social', 'configSocial', 'save', ['POST'])]
    public function save(): Response
    {
        $config = $this->configs->get($this->universe()->getEmulated());
        $schema = $this->socialSchema();

        $before = ConfigFieldProcessor::createSnapshot($config, $schema);
        $after = ConfigFieldProcessor::processPostData($schema->getAllFields(), $this->input(), $config);
        foreach ($after as $key => $value) {
            $config->$key = $value;
        }
        $config->saveGlobalKeys();

        $LOG = new Log($this->db, $this->configs, $this->state, 3);
        $LOG->target = 0;
        $LOG->old = ConfigFieldProcessor::maskSecrets($before, $schema);
        $LOG->new = ConfigFieldProcessor::maskSecrets($after, $schema);
        $LOG->save();

        return $this->show();
    }

    /**
     * The settings form: a card per provider with the callback URL (read only, the exact address to register), where to
     * create the app and the scopes the game asks for. A provider that is switched on without client id or secret is flagged.
     */
    #[GameRoute('admin', 'admin/config/social', 'configSocial')]
    public function show(): Response
    {
        $config = $this->configs->get($this->universe()->getEmulated());
        $values = ConfigFieldProcessor::prepareTemplateData($config, $this->socialSchema());

        $providers = [];
        $siteUrlMissing = false;
        foreach (SocialProvider::catalog() as $provider) {
            $callback = $this->canonicalUrl->siteUrl($this->state->runtime, 'login/' . $provider->id . '/callback');
            $siteUrlMissing = $siteUrlMissing || $callback === null;
            $providers[] = [
                'id' => $provider->id,
                'label' => $provider->label,
                'icon' => $provider->icon,
                'scope' => $provider->scope,
                'consoleUrl' => $provider->consoleUrl,
                'callbackUrl' => $callback,
                'incomplete' => !empty($values['social_' . $provider->id . '_enabled']) && !$this->socialSettings->isUsable($provider->id),
            ];
        }

        return $this->render('admin/ConfigSocial.html.twig', [
            'configValues' => $values,
            'providers' => $providers,
            'siteUrlMissing' => $siteUrlMissing,
        ]);
    }

    /** The schema reduced to the category of this page (what the form shows and saves). */
    private function socialSchema(): ConfigFieldSchema
    {
        $schema = new ConfigFieldSchema(ConfigFieldSchema::CONFIG_TYPE_BASIC, $this->state, $this->configs);

        return $schema->withoutCategories(array_values(array_diff(array_keys($schema->getSchema()), ['social'])));
    }
}
