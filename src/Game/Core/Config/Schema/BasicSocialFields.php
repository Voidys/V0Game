<?php

namespace App\Game\Core\Config\Schema;

use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\Language;

/**
 * Field definitions of one admin config category (split out of ConfigFieldSchema, which merges them in order).
 */
final class BasicSocialFields
{
    /** providers of the social login, in display order (App\Security\Social\SocialProvider has their endpoints) */
    public const PROVIDERS = ['discord', 'facebook', 'github', 'google'];

    /**
     * Basic server settings, category `social` (OAuth2 social login). Per provider: `social_<p>_enabled` (off by default),
     * `social_<p>_client_id` and `social_<p>_client_secret` (secret: never rendered, an empty input keeps the stored value).
     * The category has its own admin page (ConfigSocialController); the basic settings page leaves it out.
     *
     * @return array<string, array<string, array<string, mixed>>> the category with its field definitions
     */
    public function fields(Language $LNG): array
    {
        $fields = [];
        foreach (self::PROVIDERS as $provider) {
            $fields['social_' . $provider . '_enabled'] = [
                'type' => ConfigFieldSchema::TYPE_CHECKBOX,
                'transform' => 'bool',
                'default' => false,
            ];
            $fields['social_' . $provider . '_client_id'] = [
                'type' => ConfigFieldSchema::TYPE_TEXT,
                'maxlength' => 255,
                'size' => 60,
                'transform' => 'string',
                'sanitize' => true,
                'default' => '',
            ];
            $fields['social_' . $provider . '_client_secret'] = [
                'type' => ConfigFieldSchema::TYPE_TEXT,
                'secret' => true,
                'maxlength' => 255,
                'size' => 60,
                'transform' => 'string',
                'sanitize' => true,
                'default' => '',
            ];
        }

        return ['social' => $fields];
    }
}
