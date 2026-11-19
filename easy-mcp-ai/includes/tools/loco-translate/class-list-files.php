<?php

namespace Easy_MCP_AI\Tools\Loco_Translate;

use Easy_MCP_AI\Loco\Loco_Catalogue;
use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class List_Files extends Base_Tool {

    public function get_name() {
        return 'wp_loco_list_files';
    }

    public function get_description() {
        return 'Lists the translation files (PO) Loco Translate knows for this site\'s plugins, themes and WordPress core, with how many strings each has translated, untranslated and fuzzy. Optional: `type` (plugin, theme or core), `search` (text in the plugin or theme name or the text domain), `locale` (e.g. de_DE). Each file\'s `location` is where Loco keeps it: `custom` (languages/loco, kept through updates; edit these), `system` (languages/plugins or languages/themes, replaced by WordPress language pack updates) or `author` (inside the plugin or theme, replaced by its updates). Read a file with wp_loco_get_translations and change it with wp_loco_update_translations, passing its `path`. `readable: false` marks a file Loco\'s own settings keep from being read; the other tools refuse it. Returns { files: [{ path, bundle, bundle_name, type, domain, locale, location, readable, writable, total, translated, untranslated, fuzzy }] }.';
    }

    public function get_category() {
        return 'loco-translate';
    }

    public function get_required_capability() {
        return 'loco_admin';
    }

    public function get_annotations() {
        return array(
            'title'           => $this->get_title(),
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'openWorldHint'   => false,
        );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'type'   => array(
                    'type'        => 'string',
                    'enum'        => array( 'plugin', 'theme', 'core' ),
                    'description' => 'Only files of plugins, themes or WordPress core.',
                ),
                'search' => array(
                    'type'        => 'string',
                    'description' => 'Only files whose plugin or theme name, or text domain, contains this text.',
                ),
                'locale' => array(
                    'type'        => 'string',
                    'description' => 'Only files of this locale, e.g. de_DE.',
                ),
            ),
        );
    }

    public function execute( array $arguments ) {
        return Loco_Catalogue::run(
            static function () use ( $arguments ): array {
                Loco_Catalogue::require_loco();
                return array(
                    'files' => Loco_Catalogue::files(
                        isset( $arguments['type'] ) ? (string) $arguments['type'] : '',
                        isset( $arguments['search'] ) ? sanitize_text_field( (string) $arguments['search'] ) : '',
                        isset( $arguments['locale'] ) ? sanitize_text_field( (string) $arguments['locale'] ) : ''
                    ),
                );
            }
        );
    }
}
