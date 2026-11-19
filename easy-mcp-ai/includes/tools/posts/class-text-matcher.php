<?php
namespace Easy_MCP_AI\Tools\Posts;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Text_Matcher {

    const MAX_SUBJECT_BYTES = 1048576;

    const MAX_REGEX_SUBJECT_BYTES = 20480;

    const MAX_PATTERN_LENGTH = 500;

    const MAX_SHOWN_MATCH = 500;

    const PCRE_BACKTRACK_LIMIT = 100000;

    public static function window( string $subject, int $offset, int $length, int $context ): string {
        $start = max( 0, $offset - $context );
        $end   = min( strlen( $subject ), $offset + min( $length, self::MAX_SHOWN_MATCH ) + $context );
        while ( $start < $offset && 0x80 === ( ord( $subject[ $start ] ) & 0xC0 ) ) {
            ++$start;
        }
        while ( $end < strlen( $subject ) && $end > $offset && 0x80 === ( ord( $subject[ $end ] ) & 0xC0 ) ) {
            --$end;
        }
        return substr( $subject, $start, $end - $start );
    }

    public static function check_length( string $search ): void {
        if ( strlen( $search ) > self::MAX_PATTERN_LENGTH ) {
            throw new \InvalidArgumentException(
                sprintf( 'Search pattern is too long (max %d chars).', self::MAX_PATTERN_LENGTH ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            );
        }
    }

    public static function regex_allowed(): bool {
        return (bool) apply_filters( 'easy_mcp_ai_replace_in_post_allow_regex', true );
    }

    public static function pattern( string $search, string $modifiers = '' ): string {
        if ( preg_match( '/\([^)]*[+*?][^)]*\)[+*?]/', $search ) ) {
            throw new \InvalidArgumentException( 'Search pattern contains nested quantifiers which can cause catastrophic backtracking. Simplify the pattern or use literal mode.' );
        }

        $pattern = '/' . str_replace( '/', '\\/', $search ) . '/u' . $modifiers;

        $compile_check = @preg_match( $pattern, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( false === $compile_check ) {
            throw new \InvalidArgumentException( 'Invalid regex pattern. Provide the pattern body only — do not include delimiters or trailing modifiers (e.g. send `foo` not `/foo/i`; use inline modifiers like `(?i)foo`).' );
        }
        return $pattern;
    }

    public static function lower_backtrack_limit() {
        return @ini_set( 'pcre.backtrack_limit', (string) self::PCRE_BACKTRACK_LIMIT ); // phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- Scoped lowering of PCRE backtrack limit for ReDoS mitigation; the caller restores it.
    }

    public static function check_regex_bound( string $search, bool $bounded ): void {
        if ( ! $bounded && strlen( $search ) > (int) ( self::MAX_PATTERN_LENGTH / 2 ) ) {
            throw new \RuntimeException( 'Cannot lower pcre.backtrack_limit on this host; regex patterns longer than 250 chars are not permitted here for ReDoS safety.' );
        }
    }
}
