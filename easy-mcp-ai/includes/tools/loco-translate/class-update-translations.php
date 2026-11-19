<?php

namespace Easy_MCP_AI\Tools\Loco_Translate;

use Easy_MCP_AI\Loco\Loco_Catalogue;
use Easy_MCP_AI\Loco\String_Check;
use Easy_MCP_AI\MCP\Detailed_Runtime_Error;
use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Update_Translations extends Base_Tool {

    public function get_name() {
        return 'wp_loco_update_translations';
    }

    public function get_description() {
        return 'Translates strings in a Loco Translate PO file and recompiles its MO, JSON and PHP translation files, as saving in Loco\'s editor does (Loco keeps a backup of the PO as its settings say). Required: `path` (as wp_loco_list_files returns it; prefer a `custom` location file, which updates never replace) and `translations`: a list of { source, context, translation, plurals, fuzzy } where `source` and `context` are exactly as wp_loco_get_translations returned them, `plurals` the other plural forms in order, and `fuzzy` (default false) marks the translation as needing review. Every entry is checked first and nothing is written if one is refused: a file whose name holds no locale (Loco saves it as a template), a source the file does not hold, a translation whose printf placeholders go past the source\'s (a fatal error where it prints) or format a value as another kind (`%d` for `%s`), or HTML this account may not publish. Returns { path, updated, unchanged, changed, files_written, warnings }; `warnings` (only when there are any) carries what Loco reported while saving, such as a JavaScript (JSON) file it could not write; changed is false when every translation was already so (the files are then written only when the MO is missing or does not hold what the PO compiles to).';
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
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        );
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'path'         => array(
                    'type'        => 'string',
                    'description' => 'The PO file, relative to wp-content, as wp_loco_list_files returns it.',
                ),
                'translations' => array(
                    'type'        => 'array',
                    'description' => 'The strings to translate, each { source, context, translation, plurals, fuzzy }.',
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'source'      => array(
                                'type'        => 'string',
                                'description' => 'The source string (msgid), exactly as wp_loco_get_translations returned it.',
                            ),
                            'context'     => array(
                                'type'        => 'string',
                                'description' => 'Its context (msgctxt), "" or left out for none.',
                            ),
                            'translation' => array(
                                'type'        => 'string',
                                'description' => 'The translation ("" to clear it).',
                            ),
                            'plurals'     => array(
                                'type'        => 'array',
                                'items'       => array( 'type' => 'string' ),
                                'description' => 'For a plural string, the other forms in order (msgstr[1], msgstr[2], …).',
                            ),
                            'fuzzy'       => array(
                                'type'        => 'boolean',
                                'description' => 'Mark the translation as needing review. Default false, which clears the mark.',
                            ),
                        ),
                        'required'   => array( 'source', 'translation' ),
                    ),
                ),
            ),
            'required'   => array( 'path', 'translations' ),
        );
    }

    public function precheck( array $arguments ): void {
        Loco_Catalogue::run(
            function () use ( $arguments ): array {
                return $this->plan( $arguments );
            }
        );
    }

    public function execute( array $arguments ) {
        return Loco_Catalogue::run(
            function () use ( $arguments ): array {
                return $this->update( $arguments );
            }
        );
    }

    private function update( array $arguments ): array {
        list( $file, $project, $changes, $unchanged ) = $this->plan( $arguments );
        $written = array() === $changes && Loco_Catalogue::is_compiled( $file ) ? array( 'files' => array(), 'warnings' => array() ) : Loco_Catalogue::write( $file, $project, $changes );
        $result  = array(
            'path'          => (string) $arguments['path'],
            'updated'       => count( $changes ),
            'unchanged'     => $unchanged,
            'changed'       => array() !== $changes,
            'files_written' => $written['files'],
        );
        if ( array() !== $written['warnings'] ) {
            $result['warnings'] = $written['warnings'];
        }
        return $result;
    }

    private function plan( array $arguments ): array {
        $this->validate_required( $arguments, array( 'path', 'translations' ) );
        Loco_Catalogue::require_loco();
        Loco_Catalogue::require_tested_version();
        list( $file, $project ) = Loco_Catalogue::locate( $arguments['path'] );
        Loco_Catalogue::require_locale( $file );
        Loco_Catalogue::require_writable( $file );
        $list                   = $arguments['translations'];
        if ( is_string( $list ) ) {
            $list = json_decode( $list, true );
        }
        if ( ! is_array( $list ) || array() === $list ) {
            throw new \InvalidArgumentException( 'translations must be a non-empty list of { source, context, translation } objects. Nothing was changed.' );
        }
        $entries = array();
        foreach ( Loco_Catalogue::entries( $file ) as $entry ) {
            $entries[ Loco_Catalogue::key( $entry['source'], $entry['context'] ) ] = $entry;
        }
        $changes   = array();
        $seen      = array();
        $unchanged = 0;
        foreach ( array_values( $list ) as $n => $item ) {
            list( $key, $change ) = self::checked( $item, $n, $entries );
            if ( isset( $seen[ $key ] ) ) {
                throw new \InvalidArgumentException( sprintf( 'translations[%d] repeats a string sent earlier in the list. Nothing was changed.', $n ) );
            }
            $seen[ $key ] = true;
            $entry = $entries[ $key ];
            if ( $change['translation'] === $entry['translation'] && $change['fuzzy'] === $entry['fuzzy'] && array_slice( $entry['plural_translations'], 0, count( $change['plurals'] ) ) === $change['plurals'] ) {
                ++$unchanged;
                continue;
            }
            $changes[ $key ] = $change;
        }
        return array( $file, $project, $changes, $unchanged );
    }

    private static function checked( $item, int $n, array $entries ): array {
        if ( ! is_array( $item ) || ! isset( $item['source'] ) || ! is_string( $item['source'] ) || ! isset( $item['translation'] ) || ! is_string( $item['translation'] ) ) {
            throw new \InvalidArgumentException( sprintf( 'translations[%d] needs `source` and `translation`, both text. Nothing was changed.', $n ) );
        }
        $context = isset( $item['context'] ) && is_string( $item['context'] ) ? $item['context'] : '';
        $key     = Loco_Catalogue::key( $item['source'], $context );
        if ( ! isset( $entries[ $key ] ) ) {
            throw new \InvalidArgumentException( sprintf( 'translations[%1$d]: the file has no string "%2$s"%3$s. Send source and context exactly as wp_loco_get_translations returns them. Nothing was changed.', $n, $item['source'], '' === $context ? '' : ' with context "' . $context . '"' ) );
        }
        $entry   = $entries[ $key ];
        $plurals = isset( $item['plurals'] ) && is_array( $item['plurals'] ) ? array_values( array_map( 'strval', $item['plurals'] ) ) : array();
        if ( count( $plurals ) > count( $entry['plural_translations'] ) ) {
            throw new \InvalidArgumentException( sprintf( 'translations[%1$d]: "%2$s" has %3$d other plural form(s) in this file, and %4$d were sent. Nothing was changed.', $n, $item['source'], count( $entry['plural_translations'] ), count( $plurals ) ) );
        }
        $fuzzy = isset( $item['fuzzy'] ) && true === $item['fuzzy'];
        $sent  = array_merge( array( $item['translation'] ), $plurals );
        $kept  = array_slice( $entry['plural_translations'], count( $plurals ) );
        $check = new String_Check();
        foreach ( array_merge( $sent, $kept ) as $form => $text ) {
            $refusal = $check->refusal( $entry['source'], $text, (string) $entry['format'], $entry['plural_source'] );
            if ( null === $refusal ) {
                continue;
            }
            if ( $form < count( $sent ) ) {
                throw Detailed_Runtime_Error::for_validation( sprintf( 'translations[%1$d] is refused: %2$s Nothing was changed.', $n, $refusal ) );
            }
            throw Detailed_Runtime_Error::for_validation( sprintf( 'translations[%1$d] keeps the stored plural form msgstr[%2$d] "%3$s", which is refused: %4$s Send that form corrected in `plurals`. Nothing was changed.', $n, $form, $text, $refusal ) );
        }
        return array(
            $key,
            array(
                'translation' => $item['translation'],
                'plurals'     => $plurals,
                'fuzzy'       => $fuzzy,
            ),
        );
    }
}
