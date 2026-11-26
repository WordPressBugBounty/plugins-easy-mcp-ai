<?php

namespace Easy_MCP_AI\Loco;

use Easy_MCP_AI\Builders\Layout_Adapter;
use Easy_MCP_AI\MCP\Detailed_Runtime_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Loco_Catalogue {

    const TESTED_VERSIONS = array( '2.8.9' );

    const CLASSES = array( 'Loco_package_Plugin', 'Loco_package_Theme', 'Loco_package_Core', 'Loco_gettext_Data', 'Loco_gettext_Metadata', 'Loco_gettext_Compiler', 'Loco_fs_LocaleFile', 'Loco_fs_LocaleDirectory', 'Loco_fs_Locations', 'Loco_fs_Siblings' );

    public static function require_loco(): void {
        if ( ! function_exists( 'loco_plugin_version' ) ) {
            throw new \RuntimeException( 'Loco Translate is not active on this site.' );
        }
        $class = self::missing_class();
        if ( null !== $class ) {
            throw new \RuntimeException( sprintf( 'This version of Loco Translate (%1$s) has no %2$s class, which these tools use. Update Easy MCP AI, or edit the file in Loco Translate.', \loco_plugin_version(), $class ) );
        }
    }

    public static function missing_class(): ?string {
        foreach ( self::CLASSES as $class ) {
            if ( ! class_exists( $class ) ) {
                return $class;
            }
        }
        return null;
    }

    public static function writes_here(): bool {
        return Layout_Adapter::writes_on( (string) \loco_plugin_version(), self::TESTED_VERSIONS, array() );
    }

    public static function require_tested_version(): void {
        $version = (string) \loco_plugin_version();
        if ( ! self::writes_here() ) {
            throw new \RuntimeException( sprintf( 'Writing translations is tested with Loco Translate %1$s and its later patch releases, and this site runs %2$s, whose file format may differ. Nothing was changed. Edit the file in Loco Translate, or update Easy MCP AI.', implode( ', ', self::TESTED_VERSIONS ), $version ) );
        }
    }

    public static function files( string $type = '', string $search = '', string $locale = '' ): array {
        $rows = array();
        foreach ( self::bundles( $type ) as $bundle ) {
            foreach ( $bundle as $project ) {
                $domain = (string) $project->getDomain()->getName();
                if ( '' !== $search && false === stripos( $bundle->getName() . ' ' . $domain, $search ) ) {
                    continue;
                }
                foreach ( $project->findLocaleFiles( 'po' ) as $file ) {
                    $row = self::file_row( $bundle, $domain, $file );
                    if ( '' === $locale || $row['locale'] === $locale ) {
                        $rows[ $row['path'] ] = $row;
                    }
                }
            }
        }
        ksort( $rows );
        return array_values( $rows );
    }

    public static function locate( $path ): array {
        if ( ! is_string( $path ) || '' === $path || '.po' !== strtolower( substr( $path, -3 ) ) ) {
            throw new \InvalidArgumentException( 'path must be a .po file path relative to wp-content, as wp_loco_list_files returns it.' );
        }
        foreach ( self::bundles( '' ) as $bundle ) {
            foreach ( $bundle as $project ) {
                foreach ( $project->findLocaleFiles( 'po' ) as $file ) {
                    if ( self::relative( $file ) === ltrim( $path, '/' ) ) {
                        self::require_readable( $file );
                        return array( $file, $project );
                    }
                }
            }
        }
        throw new \InvalidArgumentException( sprintf( 'Loco Translate knows no PO file at %s. wp_loco_list_files lists the files it knows.', $path ) );
    }

    public static function entries( $file ): array {
        $out = array();
        foreach ( \Loco_gettext_Data::load( $file ) as $message ) {
            $plurals = array();
            $plural  = '';
            foreach ( (array) $message->plurals as $i => $form ) {
                $plurals[] = (string) $form['target'];
                $plural    = 0 === $i ? (string) $form['source'] : $plural;
            }
            $out[] = array(
                'source'              => (string) $message['source'],
                'context'             => (string) $message->context,
                'plural_source'       => $plural,
                'translation'         => (string) $message['target'],
                'plural_translations' => $plurals,
                'fuzzy'               => $message->isFuzzy(),
                'format'              => $message->getFormat(),
            );
        }
        return $out;
    }

    public static function write( $file, $project, array $changes ): array {
        $raw = \Loco_gettext_Data::load( $file )->getArrayCopy();
        foreach ( $raw as $i => $entry ) {
            if ( isset( $entry['parent'] ) || 0 === $i && '' === (string) $entry['source'] && empty( $entry['context'] ) ) {
                continue;
            }
            $key = self::key( (string) $entry['source'], (string) ( $entry['context'] ?? '' ) );
            if ( ! isset( $changes[ $key ] ) ) {
                continue;
            }
            $raw[ $i ]['target'] = $changes[ $key ]['translation'];
            $raw[ $i ]           = self::with_fuzzy( $raw[ $i ], $changes[ $key ]['fuzzy'] );
            $form                = 0;
            foreach ( $raw as $j => $child ) {
                if ( ! isset( $child['parent'] ) || $i !== $child['parent'] ) {
                    continue;
                }
                $raw[ $j ] = self::with_fuzzy( $child, $changes[ $key ]['fuzzy'] );
                if ( isset( $changes[ $key ]['plurals'][ $form ] ) ) {
                    $raw[ $j ]['target'] = $changes[ $key ]['plurals'][ $form ];
                }
                ++$form;
            }
        }
        $data     = new \Loco_gettext_Data( $raw );
        $compiler = new \Loco_gettext_Compiler( $file );
        add_filter( 'loco_allow_remote', '__return_false', PHP_INT_MAX );
        self::warnings();
        try {
            $written = $compiler->writeAll( $data, $project );
        } catch ( \Exception $e ) {
            throw Detailed_Runtime_Error::fitted( sprintf( 'Loco Translate could not write the translation files: %s.%s Make the language files writable by the web server, or edit them in Loco Translate.', $e->getMessage(), self::reported( self::warnings() ) ) );
        } finally {
            remove_filter( 'loco_allow_remote', '__return_false', PHP_INT_MAX );
        }
        $warnings = self::warnings();
        $paths    = array();
        foreach ( $written as $done ) {
            $paths[] = self::relative( $done );
        }
        $mo = self::relative( $file->cloneExtension( 'mo' ) );
        if ( ! in_array( $mo, $paths, true ) ) {
            throw Detailed_Runtime_Error::fitted( sprintf( 'The PO file was saved, but Loco Translate could not write %s, so WordPress still shows the old translations.%s Make that file writable, then send the same call again: it recompiles.', $mo, self::reported( $warnings ) ) );
        }
        return array(
            'files'    => $paths,
            'warnings' => $warnings,
        );
    }

    public static function run( callable $call ) {
        try {
            return $call();
        } finally {
            self::warnings();
        }
    }

    private static function reported( array $warnings ): string {
        if ( array() === $warnings ) {
            return '';
        }
        $sentences = array();
        foreach ( $warnings as $warning ) {
            $sentences[] = rtrim( $warning, '.' ) . '.';
        }
        return ' Loco Translate reported: ' . implode( ' ', $sentences );
    }

    private static function warnings(): array {
        if ( ! class_exists( 'Loco_error_AdminNotices' ) || ! class_exists( 'Loco_error_Exception' ) ) {
            return array();
        }
        $out = array();
        foreach ( (array) \Loco_error_AdminNotices::destroy() as $notice ) {
            if ( is_object( $notice ) && method_exists( $notice, 'getLevel' ) && $notice->getLevel() <= \Loco_error_Exception::LEVEL_WARNING ) {
                $out[] = (string) $notice->getMessage();
            }
        }
        return $out;
    }

    public static function is_compiled( $file ): bool {
        $mo = $file->cloneExtension( 'mo' )->getPath();
        return is_file( $mo ) && (string) file_get_contents( $mo ) === \Loco_gettext_Data::load( $file )->msgfmt(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file Loco just located.
    }

    public static function require_locale( $file ): void {
        if ( ! $file->getLocale()->isValid() ) {
            throw new \InvalidArgumentException( 'Loco Translate reads no locale from this file\'s name, so it saves the file as a template, with no MO for WordPress. Nothing was changed. Translate a file named for its locale (-de_DE.po).' );
        }
    }

    public static function require_writable( $file ): void {
        $siblings = new \Loco_fs_Siblings( $file );
        foreach ( array( $file, $siblings->getBinary(), $siblings->getCache() ) as $target ) {
            if ( null !== $target && ( $target->exists() ? ! $target->writable() : ! $target->creatable() ) ) {
                throw new \RuntimeException( sprintf( 'WordPress cannot write %s itself, and Loco Translate would ask for FTP credentials, which these tools cannot give. Nothing was changed. Make the language files writable by the web server, or edit them in Loco Translate.', self::relative( $target ) ) );
            }
        }
    }

    private static function require_readable( $file ): void {
        try {
            \Loco_gettext_Data::check( $file );
        } catch ( \Exception $e ) {
            throw new \InvalidArgumentException( $e->getMessage() . ' (Loco Translate › Settings). Nothing was read.' );
        }
    }

    private static function with_fuzzy( array $entry, bool $fuzzy ): array {
        if ( $fuzzy ) {
            $entry['flag'] = 4;
        } else {
            unset( $entry['flag'] );
        }
        return $entry;
    }

    public static function key( string $source, string $context ): string {
        if ( '' === $context ) {
            return $source;
        }
        return $context . "\4" . ( '' === $source ? '(' . $context . ')' : $source );
    }

    private static function bundles( string $type ): array {
        $bundles = array();
        if ( '' === $type || 'plugin' === $type ) {
            $bundles = array_merge( $bundles, \Loco_package_Plugin::getAll() );
        }
        if ( '' === $type || 'theme' === $type ) {
            $bundles = array_merge( $bundles, \Loco_package_Theme::getAll() );
        }
        if ( '' === $type || 'core' === $type ) {
            $bundles[] = \Loco_package_Core::create();
        }
        return $bundles;
    }

    private static function file_row( $bundle, string $domain, $file ): array {
        $meta  = \Loco_gettext_Metadata::load( $file );
        $total = (int) $meta->getTotal();
        $fuzzy = (int) $meta->countFuzzy();
        $empty = (int) $meta->countUntranslated();
        $place = ( new \Loco_fs_LocaleDirectory( $file->dirname() ) )->getTypeId();
        return array(
            'path'         => self::relative( $file ),
            'bundle'       => (string) $bundle->getId(),
            'bundle_name'  => (string) $bundle->getName(),
            'type'         => (string) $bundle->getType(),
            'domain'       => $domain,
            'locale'       => (string) $file->getLocale(),
            'location'     => self::LOCATIONS[ $place ] ?? 'other',
            'readable'     => \Loco_fs_Locations::permittedRead( $file->getPath() ),
            'writable'     => \Loco_fs_Locations::permittedWrite( $file->getPath() ) && is_writable( $file->getPath() ),
            'total'        => $total,
            'translated'   => max( 0, $total - $empty - $fuzzy ),
            'untranslated' => $empty,
            'fuzzy'        => $fuzzy,
        );
    }

    const LOCATIONS = array(
        'custom' => 'custom',
        'wplang' => 'system',
        'theme'  => 'author',
        'plugin' => 'author',
        'other'  => 'other',
    );

    private static function relative( $file ): string {
        return ltrim( (string) $file->getRelativePath( \loco_constant( 'WP_CONTENT_DIR' ) ), '/' );
    }
}
