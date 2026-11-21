<?php

namespace App\Game\Core\Config;

use App\Game\Core\Language;
use App\Game\Core\Singleton;

/**
 * Config Field Schema Definition
 * Centralized definition of all config fields for easier maintenance
 */
class ConfigFieldSchema
{
    public const CONFIG_TYPE_BASIC = 'basic';
    public const CONFIG_TYPE_UNI = 'uni';

    // Field types
    public const TYPE_NONE = 'none';
    public const TYPE_TEXT = 'text';
    public const TYPE_TEXTAREA = 'textarea';
    public const TYPE_CHECKBOX = 'checkbox';
    public const TYPE_NUMBER = 'number';
    public const TYPE_SELECT = 'select';
    public const TYPE_FLOAT = 'float';
    public const TYPE_SLIDER = 'slider';

    /** @var array<mixed> */
    private array $schema = [];
    /** @var array<mixed> */
    private array $fields = [];

    /**
     * @param Singleton $state game state: its language labels the fields (an English fallback is
     *                         loaded into it when none is set yet, as before)
     */
    public function __construct(
        string $config_type,
        private Singleton $state,
        private \App\Game\Core\ConfigRegistry $configs,
    ) {
        if ($config_type === self::CONFIG_TYPE_BASIC) {
            $this->schema = $this->getBasicSchema();
        } elseif ($config_type === self::CONFIG_TYPE_UNI) {
            $this->schema = $this->getUniSchema();
        } else {
            $this->schema = [];
        }

        foreach ($this->schema as $category => $categoryFields) {
            $this->fields = array_merge($this->fields, $categoryFields);
        }
    }

    /**
     * Basic config fields with metadata
     *
     * Each entry in the returned array represents a category having multiple
     * configuration keys and their options.
     * The category keys and config keys are used for translation keys in the
     * format: se_{category_key} and se_{category_key}_info for tooltips.
     * Example structure:
     *
     * ```
     *   'config_key' => [                      // Configuration key
     *       'type'      => self::TYPE_TEXT,    // Field type: none, text, textarea, checkbox, number, select, float, slider
     *       'default'   => '',                 // Default value, if not set
     *       'options'   => [...],              // For select: selection options
     *       'maxlength' => 255,                // For text/number: maximum length
     *       'size'      => 60,                 // For text: size of the input field
     *       'transform' => 'string',           // Transformation function: int, float, string, bool, int_multiply_2500 (example for special transformations)
     *       'validate'  => 'http_url',         // For text: empty or an absolute http(s) URL, anything else is stored as empty
     *       'sanitize'  => true,               // For text: whether the value should be sanitized before saving
     *       'min'       => 0,                  // For slider/number: minimum value
     *       'max'       => 100,                // For slider/number: maximum value
     *       'label'     => '...',              // For slider: label for displaying the slider value
     *       'unit'      => '...',              // Optional unit that is displayed in the template
     *       'tooltip'   => '...',              // Optional tooltip text for the field
     *   ]
     * ```
     *
     * @return array<mixed>
     */
    private function getBasicSchema(): array
    {
        $LNG = $this->state->LNG;
        if (is_null($LNG)) {
            $LNG = new Language($this->configs, 'en'); // Fallback to avoid errors if language is not loaded
            $LNG->includeData(['L18N', 'INGAME', 'ADMIN', 'PUBLIC', 'CUSTOM']);
            $this->state->LNG = $LNG;
        }
        $LNG->includeData(['ADMIN']);

        return array_merge(
            (new Schema\BasicServerParametersFields())->fields($LNG),
            (new Schema\BasicCommunityLinksFields())->fields($LNG),
            (new Schema\BasicDiscordHooksFields())->fields($LNG),
            (new Schema\BasicSmtpFields())->fields($LNG),
            (new Schema\BasicSocialFields())->fields($LNG),
            (new Schema\BasicUserSettingsFields())->fields($LNG),
            (new Schema\BasicMessagesFields())->fields($LNG),
            (new Schema\BasicProofOfWorkFields())->fields($LNG),
            (new Schema\BasicDisclaimerFields())->fields($LNG),
            (new Schema\BasicLegalFields())->fields($LNG),
            (new Schema\BasicStatisticsFields())->fields($LNG),
        );
    }

    /**
     * All config fields with metadata (universe-specific)
     *
     * Each entry in the returned array represents a category having multiple
     * configuration keys and their options.
     * The category keys and config keys are used for translation keys in the
     * format: se_{category_key} and se_{category_key}_info for tooltips.
     * Example structure:
     *
     * ```
     *   'config_key' => [                      // Configuration key
     *       'type'      => self::TYPE_TEXT,    // Field type: text, textarea, checkbox, number, select, float, slider
     *       'default'   => '',                 // Default value, if not set
     *       'options'   => [...],              // For select: selection options
     *       'maxlength' => 255,                // For text/number: maximum length
     *       'size'      => 60,                 // For text: size of the input field
     *       'transform' => 'string',           // Transformation function: int, float, string, bool, int_multiply_2500 (example for special transformations)
     *       'sanitize'  => true,               // For text: whether the value should be sanitized before saving
     *       'min'       => 0,                  // For slider/number: minimum value
     *       'max'       => 100,                // For slider/number: maximum value
     *       'label'     => '...',              // For slider: label for displaying the slider value
     *       'unit'      => '...',              // Optional unit that is displayed in the template
     *       'tooltip'   => '...',              // Optional tooltip text for the field
     *   ]
     * ```
     *
     * @return array<mixed>
     */
    private function getUniSchema(): array
    {
        $LNG = $this->state->LNG;
        if (is_null($LNG)) {
            $LNG = new Language($this->configs, 'en'); // Fallback to avoid errors if language is not loaded
            $LNG->includeData(['L18N', 'INGAME', 'ADMIN', 'PUBLIC', 'CUSTOM']);
            $this->state->LNG = $LNG;
        }
        // the expedition and build fields use element names ($LNG['tech']), which console runs have not loaded yet
        $LNG->includeData(['ADMIN', 'TECH']);

        return array_merge(
            (new Schema\UniServerParametersFields())->fields($LNG),
            (new Schema\UniBuildlistFields())->fields($LNG),
            (new Schema\UniGalaxyParametersFields())->fields($LNG),
            (new Schema\UniColonisationConfigFields())->fields($LNG),
            (new Schema\UniPlanetParametersFields())->fields($LNG),
            (new Schema\UniBuildingsFields())->fields($LNG),
            (new Schema\UniExpeditionParametersFields())->fields($LNG),
            (new Schema\UniAttacksFields())->fields($LNG),
            (new Schema\UniPiratebaseFields())->fields($LNG),
            (new Schema\UniTraderFields())->fields($LNG),
            (new Schema\UniNewsFields())->fields($LNG),
            (new Schema\UniRefFields())->fields($LNG),
            (new Schema\UniBotsFields())->fields($LNG),
        );
    }

    /**
     * Returns all categories with their respective fields and metadata
     *
     * @return array<mixed>
     */
    public function getSchema(): array
    {
        return $this->schema;
    }

    /**
     * The fields of one category (empty for an unknown category)
     *
     * @return array<string, array<mixed>>
     */
    public function getCategoryFields(string $category): array
    {
        return $this->schema[$category] ?? [];
    }

    /**
     * A copy of the schema without the given categories (the page of the basic settings leaves out the categories that
     * have a page of their own, so saving it cannot overwrite them)
     *
     * @param list<string> $categories
     */
    public function withoutCategories(array $categories): static
    {
        $copy = clone $this;
        $copy->schema = array_diff_key($this->schema, array_flip($categories));
        $copy->fields = [];
        foreach ($copy->schema as $categoryFields) {
            $copy->fields = array_merge($copy->fields, $categoryFields);
        }

        return $copy;
    }

    /**
     * Returns all fields (without categories)
     *
     * @return array<mixed>
     */
    public function getAllFields(): array
    {
        return $this->fields;
    }

    /**
     * Returns a specific field
     *
     * @return array<mixed>
     */
    public function getField(string $fieldName): ?array
    {
        return $this->fields[$fieldName] ?? null;
    }
}
