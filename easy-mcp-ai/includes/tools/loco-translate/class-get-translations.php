<?php

namespace Easy_MCP_AI\Tools\Loco_Translate;

use Easy_MCP_AI\Loco\Loco_Catalogue;
use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Get_Translations extends Base_Tool {

    const PER_PAGE = 50;
    const MAX_PER_PAGE = 200;

    public function get_name() {
        return 'wp_loco_get_translations';
    }

    public function get_description() {
        return 'Reads the strings of one Loco Translate PO file. Required: `path` (as wp_loco_list_files returns it). Optional: `filter` (all, untranslated, fuzzy or translated; default all), `search` (text in the source or a translation, plural forms included), `page` and `per_page` (default 50, at most 200). Each entry has `source`, `context` (msgctxt, "" for none), `plural_source`, `translation`, `plural_translations`, `fuzzy` and `format` ("php" when the string is a PHP format string: keep its placeholders). Send `source` and `context` back unchanged to wp_loco_update_translations. Returns { path, locale, total, entries, page, per_page, total_pages }.';
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
                'path'     => array(
                    'type'        => 'string',
                    'description' => 'The PO file, relative to wp-content, as wp_loco_list_files returns it.',
                ),
                'filter'   => array(
                    'type'        => 'string',
                    'enum'        => array( 'all', 'untranslated', 'fuzzy', 'translated' ),
                    'description' => 'Which strings: all (default), untranslated, fuzzy (marked as needing review) or translated.',
                ),
                'search'   => array(
                    'type'        => 'string',
                    'description' => 'Only strings whose source or translation, plural forms included, contains this text (case-insensitive).',
                ),
                'page'     => array(
                    'type'        => 'integer',
                    'description' => 'Page number, from 1.',
                ),
                'per_page' => array(
                    'type'        => 'integer',
                    'description' => 'Strings per page, 1 to 200 (default 50).',
                ),
            ),
            'required'   => array( 'path' ),
        );
    }

    public function execute( array $arguments ) {
        return Loco_Catalogue::run(
            function () use ( $arguments ): array {
                return $this->read( $arguments );
            }
        );
    }

    private function read( array $arguments ): array {
        $this->validate_required( $arguments, array( 'path' ) );
        Loco_Catalogue::require_loco();
        list( $file ) = Loco_Catalogue::locate( $arguments['path'] );
        $filter       = isset( $arguments['filter'] ) ? (string) $arguments['filter'] : 'all';
        $search       = isset( $arguments['search'] ) ? (string) $arguments['search'] : '';
        $entries      = array_values(
            array_filter(
                Loco_Catalogue::entries( $file ),
                static function ( array $entry ) use ( $filter, $search ): bool {
                    return self::matches( $entry, $filter, $search );
                }
            )
        );
        $per_page = isset( $arguments['per_page'] ) ? min( self::MAX_PER_PAGE, max( 1, absint( $arguments['per_page'] ) ) ) : self::PER_PAGE;
        $page     = isset( $arguments['page'] ) ? max( 1, absint( $arguments['page'] ) ) : 1;
        return array(
            'path'        => (string) $arguments['path'],
            'locale'      => (string) $file->getLocale(),
            'total'       => count( $entries ),
            'entries'     => array_slice( $entries, ( $page - 1 ) * $per_page, $per_page ),
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil( count( $entries ) / $per_page ),
        );
    }

    private static function matches( array $entry, string $filter, string $search ): bool {
        $empty = '' === $entry['translation'];
        if ( ( 'untranslated' === $filter && ( ! $empty || $entry['fuzzy'] ) )
            || ( 'fuzzy' === $filter && ! $entry['fuzzy'] )
            || ( 'translated' === $filter && ( $empty || $entry['fuzzy'] ) ) ) {
            return false;
        }
        $texts = array_merge( array( $entry['source'], $entry['plural_source'], $entry['translation'] ), $entry['plural_translations'] );
        return '' === $search || false !== stripos( implode( "\n", $texts ), $search );
    }
}
