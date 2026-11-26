<?php

namespace Easy_MCP_AI\Tools\Wordfence;

use Easy_MCP_AI\Text_Redactor;
use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Get_Live_Traffic extends Base_Tool {

    use Wordfence_Tool;

    const MAX_LIMIT = 100;

    const MAX_PARAM_VALUE = 100;

    const VIEWS = array(
        'all'                 => array(),
        'humans'              => array( array( 'jsRun', '=', 1 ) ),
        'crawlers'            => array( array( 'jsRun', '=', 0 ) ),
        'registered_users'    => array( array( 'userID', '!=', 0 ) ),
        'google_crawlers'     => array( array( 'isGoogle', '=', 1 ) ),
        'not_found'           => array( array( 'statusCode', '=', 404 ) ),
        'logins'              => array( array( 'action', 'contains', 'login' ), array( 'action', 'contains', 'logout' ) ),
        'failed_logins'       => array( array( 'action', 'match', 'loginFail%' ) ),
        'locked_out'          => array( array( 'action', '=', 'lockedOut' ) ),
        'blocked'             => array( array( 'action', 'contains', 'blocked' ) ),
        'blocked_by_firewall' => array( array( 'action', '=', 'blocked:waf' ) ),
    );

    public function get_name() {
        return 'wp_wordfence_get_live_traffic';
    }

    public function get_description() {
        return 'Reads Wordfence\'s Live Traffic log, newest first: visits, login attempts (successful and failed), lockouts and requests its firewall or blocking rules stopped, as its Live Traffic screen lists them. Optional: `view` ("all" (default), "humans", "crawlers", "registered_users", "google_crawlers", "not_found", "logins", "failed_logins", "locked_out", "blocked", "blocked_by_firewall"), `ip` (one IP address), `username` (the name a login attempt used), `since` and `until` (dates, e.g. 2026-11-20 or 2026-11-20T08:00:00Z), `limit` (1-100, default 20), `offset`. Returns { logging ("all" or "security_only": Wordfence\'s Traffic logging mode; with "security_only" ordinary visits are not logged), view, hits: [{ id, time, ip, location: { country_code, country, region, city } | null, url, referer, user_agent, status_code, visitor ("human" or "bot"), google_crawler, user: { id, display_name } | null, login_username, action, action_description, ip_blocked, firewall, truncated }], offset, limit, next_offset }. Each text is cut at 2,000 characters; `truncated` (only when one was) lists the fields cut. `action` is Wordfence\'s: "loginOK", "loginFailValidUsername", "loginFailInvalidUsername", "logout", "lockedOut", "blocked:waf", "blocked:wordfence" and others; "" for an ordinary request. `firewall` (only on a request the firewall matched) names the rules, their category and the request value they matched; a value that can be a credential (a cookie, a header, a whole request body, a field named like a password, token or key) is masked, and so are secrets in URLs, user agents and login names. A page may hold fewer than `limit` requests, even none, and still have a `next_offset`: Wordfence drops some after reading a page. Wordfence\'s own reader may ask Wordfence\'s servers where an IP is, and whether a claimed Googlebot is real, as its Live Traffic screen does; this tool adds no lookup of its own. Requires Wordfence active and the account Wordfence lets see Live Traffic (manage_options; manage_network on a network).';
    }

    public function get_annotations() {
        return array(
            'title'           => $this->get_title(),
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'openWorldHint'   => true,
        );
    }

    public function get_covers(): string {
        return 'Wordfence activity log, failed login attempts, firewall blocks, lockouts, security traffic log';
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'view'     => array(
                    'type'        => 'string',
                    'enum'        => array_keys( self::VIEWS ),
                    'description' => 'Which requests: Wordfence\'s Live Traffic views. "logins" includes logouts; "failed_logins" only failed attempts; "blocked" every block, "blocked_by_firewall" only the firewall\'s. Default "all".',
                ),
                'ip'       => array(
                    'type'        => 'string',
                    'description' => 'Only requests from this IP address (IPv4 or IPv6).',
                ),
                'username' => array(
                    'type'        => 'string',
                    'description' => 'Only login attempts that used this username, exactly.',
                ),
                'since'    => array(
                    'type'        => 'string',
                    'description' => 'Only requests after this date or date-time, e.g. 2026-11-20 or 2026-11-20T08:00:00Z.',
                ),
                'until'    => array(
                    'type'        => 'string',
                    'description' => 'Only requests before this date or date-time.',
                ),
                'limit'    => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LIMIT,
                    'default'     => 20,
                    'description' => 'Requests to return, 1-100.',
                ),
                'offset'   => array(
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'default'     => 0,
                    'description' => 'Requests to skip, for the next page: send the previous reply\'s next_offset.',
                ),
            ),
            'required'   => array(),
        );
    }

    public function execute( array $arguments ) {
        $view = isset( $arguments['view'] ) && '' !== $arguments['view'] ? $arguments['view'] : 'all';
        $this->refuse_unless_one_of( 'view', $view, array_keys( self::VIEWS ), $view, implode( ', ', array_keys( self::VIEWS ) ) );
        $ip       = self::ip_argument( $arguments );
        $username = self::filter_text( $arguments, 'username' );
        $since    = self::date_argument( $arguments, 'since' );
        $until    = self::date_argument( $arguments, 'until' );
        if ( null !== $since && null !== $until && $since >= $until ) {
            throw new \InvalidArgumentException( 'since must be before until.' );
        }
        $limit  = self::bounded_int( $arguments, 'limit', 20, 1, self::MAX_LIMIT );
        $offset = self::bounded_int( $arguments, 'offset', 0, 0, PHP_INT_MAX );

        self::require_wordfence(
            array(
                'wordfence'                          => array( 'getLog' ),
                'wfLiveTrafficQuery'                 => array( 'setFilters', 'setLimit', 'setOffset', 'setStartDate', 'setEndDate', 'execute', 'buildQuery' ),
                'wfLiveTrafficQueryFilterCollection' => array( 'addFilter' ),
                'wfLiveTrafficQueryFilter'           => array( 'toSQL' ),
                'wfConfig'                           => array( 'liveTrafficEnabled' ),
                'wfUtils'                            => array( 'inet_pton' ),
            )
        );

        $query   = new \wfLiveTrafficQuery( \wordfence::getLog() );
        $filters = new \wfLiveTrafficQueryFilterCollection();
        $wanted  = self::VIEWS[ $view ];
        if ( null !== $ip ) {
            $wanted[] = array( 'ip', '=', \wfUtils::inet_pton( $ip ) );
        }
        if ( '' !== $username ) {
            $wanted[] = array( 'username', '=', $username );
        }
        foreach ( $wanted as $filter ) {
            $filters->addFilter( new \wfLiveTrafficQueryFilter( $query, $filter[0], $filter[1], $filter[2] ) );
        }
        $query->setFilters( $filters );
        $query->setLimit( $limit );
        $query->setOffset( $offset );
        if ( null !== $since ) {
            $query->setStartDate( $since );
        }
        if ( null !== $until ) {
            $query->setEndDate( $until );
        }

        try {
            $rows = (array) $query->execute();
        } catch ( \Exception $e ) {
            throw new \RuntimeException( 'Wordfence could not read its Live Traffic log: ' . Text_Redactor::redact( $e->getMessage() ), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $hits = array_map( array( __CLASS__, 'hit' ), array_values( array_filter( $rows, 'is_array' ) ) );
        return array(
            'logging'     => \wfConfig::liveTrafficEnabled() ? 'all' : 'security_only',
            'view'        => $view,
            'hits'        => $hits,
            'offset'      => $offset,
            'limit'       => $limit,
            'next_offset' => self::page_size( $query ) >= $limit ? $offset + $limit : null,
        );
    }

    private static function page_size( $query ): int {
        global $wpdb;
        $delayed = false;
        $humans  = false;
        $sql     = $query->buildQuery( $delayed, $humans );
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM ({$sql}) AS wf_page" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Wordfence's own query, prepared by wfLiveTrafficQuery::buildQuery().
    }

    private static function filter_text( array $arguments, string $key ): string {
        $value = $arguments[ $key ] ?? null;
        if ( null === $value ) {
            return '';
        }
        if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
            throw new \InvalidArgumentException( sprintf( '%s must be text.', $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return trim( (string) $value );
    }

    private static function ip_argument( array $arguments ): ?string {
        $ip = self::filter_text( $arguments, 'ip' );
        if ( '' === $ip ) {
            return null;
        }
        if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            throw new \InvalidArgumentException( sprintf( 'ip must be one IPv4 or IPv6 address, not "%s".', $ip ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $ip;
    }

    private static function date_argument( array $arguments, string $key ): ?int {
        $text = self::filter_text( $arguments, $key );
        if ( '' === $text ) {
            return null;
        }
        $time = strtotime( $text );
        if ( false === $time ) {
            throw new \InvalidArgumentException( sprintf( '%1$s must be a date or date-time, e.g. 2026-11-20 or 2026-11-20T08:00:00Z, not "%2$s".', $key, $text ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $time;
    }

    private static function hit( array $row ): array {
        $loc  = isset( $row['loc'] ) && is_array( $row['loc'] ) ? $row['loc'] : null;
        $user = isset( $row['user'] ) && is_array( $row['user'] ) ? $row['user'] : null;
        $cut  = array();
        $hit  = array(
            'id'                 => (int) ( $row['id'] ?? 0 ),
            'time'               => self::iso_time( $row['ctime'] ?? null ),
            'ip'                 => (string) ( $row['IP'] ?? '' ),
            'location'           => null === $loc ? null : array(
                'country_code' => self::capped( (string) ( $loc['countryCode'] ?? '' ), 'location.country_code', $cut ),
                'country'      => self::capped( (string) ( $loc['countryName'] ?? '' ), 'location.country', $cut ),
                'region'       => self::capped( (string) ( $loc['region'] ?? '' ), 'location.region', $cut ),
                'city'         => self::capped( (string) ( $loc['city'] ?? '' ), 'location.city', $cut ),
            ),
            'url'                => self::capped( self::redact_url( $row['URL'] ?? '' ), 'url', $cut ),
            'referer'            => self::capped( self::redact_url( $row['referer'] ?? '' ), 'referer', $cut ),
            'user_agent'         => self::capped( self::redact_text( $row['UA'] ?? '' ), 'user_agent', $cut ),
            'status_code'        => (int) ( $row['statusCode'] ?? 0 ),
            'visitor'            => empty( $row['jsRun'] ) ? 'bot' : 'human',
            'google_crawler'     => ! empty( $row['isGoogle'] ),
            'user'               => null === $user ? null : array(
                'id'           => (int) ( $user['ID'] ?? 0 ),
                'display_name' => self::capped( self::redact_text( $user['display_name'] ?? '' ), 'user.display_name', $cut ),
            ),
            'login_username'     => self::capped( self::redact_text( $row['username'] ?? '' ), 'login_username', $cut ),
            'action'             => self::capped( (string) ( $row['action'] ?? '' ), 'action', $cut ),
            'action_description' => self::capped( self::redact_text( self::plain_text( $row['actionDescription'] ?? '' ) ), 'action_description', $cut ),
            'ip_blocked'         => ! empty( $row['blocked'] ) || ! empty( $row['rangeBlocked'] ),
        );
        $firewall = self::firewall( isset( $row['actionData'] ) && is_array( $row['actionData'] ) ? $row['actionData'] : array(), $cut );
        if ( null !== $firewall ) {
            $hit['firewall'] = $firewall;
        }
        if ( array() !== $cut ) {
            $hit['truncated'] = $cut;
        }
        return $hit;
    }

    private static function firewall( array $data, array &$cut ): ?array {
        if ( ! isset( $data['failedRules'] ) && ! isset( $data['paramKey'] ) ) {
            return null;
        }
        $rules = $data['failedRules'] ?? array();
        $key   = self::decoded( $data['paramKey'] ?? '' );
        $value = self::decoded( $data['paramValue'] ?? '' );
        if ( self::is_secret_source( $key ) ) {
            $value = '' === $value ? '' : Text_Redactor::MASK;
        } else {
            $value = self::redact_text( $value );
            $short = self::cut_to( $value, self::MAX_PARAM_VALUE );
            if ( $short !== $value ) {
                $value = $short . '...';
                $cut[] = 'firewall.matched_value';
            }
        }
        return array(
            'rules'         => self::capped_rules( self::rule_list( $rules ), $cut ),
            'category'      => self::capped( is_scalar( $data['category'] ?? null ) ? (string) $data['category'] : '', 'firewall.category', $cut ),
            'learning_mode' => ! empty( $data['learningMode'] ),
            'matched_in'    => self::capped( self::redact_text( $key ), 'firewall.matched_in', $cut ),
            'matched_value' => $value,
        );
    }

    private static function rule_list( $rules ): array {
        $parts = is_array( $rules ) ? $rules : explode( '|', is_scalar( $rules ) ? (string) $rules : '' );
        return array_values( array_filter( array_map( 'strval', array_filter( $parts, 'is_scalar' ) ), 'strlen' ) );
    }

    private static function capped_rules( array $rules, array &$cut ): array {
        $cut_here = array();
        foreach ( $rules as $i => $rule ) {
            $rules[ $i ] = self::capped( $rule, 'firewall.rules', $cut_here );
        }
        if ( array() !== $cut_here ) {
            $cut[] = 'firewall.rules';
        }
        return $rules;
    }

    private static function decoded( $value ): string {
        if ( ! is_string( $value ) || '' === $value ) {
            return '';
        }
        $plain = base64_decode( $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Wordfence's own storage format.
        return false === $plain ? $value : $plain;
    }

    private static function is_secret_source( string $key ): bool {
        if ( 1 !== preg_match( '/^([a-z0-9_]+\.[a-z0-9_]+)((?:\[[^\]]*\])*)/', \Easy_MCP_AI\Ascii::lower( $key ), $m ) ) {
            return '' !== $key;
        }
        $source = $m[1];
        if ( in_array( $source, array( 'request.cookies', 'request.cookie', 'request.headers', 'request.rawbody' ), true ) ) {
            return true;
        }
        if ( '' === $m[2] ) {
            return ! in_array( $source, array( 'request.path', 'request.uri' ), true );
        }
        return self::is_secret_parameter( $m[2] );
    }
}
