<?php

namespace Easy_MCP_AI\Tools\Wordfence;

use Easy_MCP_AI\Tools\Base_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Get_Scan_Issues extends Base_Tool {

    use Wordfence_Tool;

    const STATUSES = array( 'new', 'ignored' );

    const MAX_LIMIT = 100;

    const SEVERITIES = array(
        100 => 'critical',
        75  => 'high',
        50  => 'medium',
        25  => 'low',
    );

    const STATUS_NAMES = array(
        'new'     => 'new',
        'ignoreP' => 'ignored_always',
        'ignoreC' => 'ignored_until_changed',
    );

    public function get_name() {
        return 'wp_wordfence_get_scan_issues';
    }

    public function get_description() {
        return 'Reads the security issues Wordfence\'s last scan found (malware, changed core or plugin files, vulnerable or abandoned plugins, weak settings, known-bad URLs), as its Scan screen lists them, with the scan\'s state. Optional: `status` ("new", the default: issues still open; "ignored": issues an administrator chose to ignore), `limit` (1-100, default 20), `offset`. Returns { scan: { last_scan_at, result ("completed", "failed" or "never"), error, running, failure, truncated }, counts: { new, ignored_always, ignored_until_changed }, status, issues: [{ id, type, status, severity ("critical", "high", "medium", "low" or "none"), severity_level, found_at, updated_at, summary, details, data, truncated }], offset, limit, next_offset }. Each text is cut at 2,000 characters; `truncated` (only when one was) lists the fields cut. `data` is what Wordfence stored for the issue (for a file: its path and the plugin or theme it belongs to), with secrets masked. Wordfence runs scans and changes issues itself; this tool only reads. Requires Wordfence active and the account Wordfence lets see its scan (manage_options; manage_network on a network).';
    }

    public function get_covers(): string {
        return 'security scan results, malware scan, vulnerabilities, Wordfence scan status';
    }

    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'status' => array(
                    'type'        => 'string',
                    'enum'        => self::STATUSES,
                    'description' => '"new" (default): open issues. "ignored": issues an administrator chose to ignore, always or until the file changes.',
                ),
                'limit'  => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LIMIT,
                    'default'     => 20,
                    'description' => 'Issues to return, 1-100. Wordfence orders them by severity, then type, newest first.',
                ),
                'offset' => array(
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'default'     => 0,
                    'description' => 'Issues to skip, for the next page: send the previous reply\'s next_offset.',
                ),
            ),
            'required'   => array(),
        );
    }

    public function execute( array $arguments ) {
        $status = isset( $arguments['status'] ) ? $arguments['status'] : 'new';
        $this->refuse_unless_one_of( 'status', $status, self::STATUSES, $status, '"new" or "ignored"' );
        $limit  = self::bounded_int( $arguments, 'limit', 20, 1, self::MAX_LIMIT );
        $offset = self::bounded_int( $arguments, 'offset', 0, 0, PHP_INT_MAX );

        self::require_wordfence(
            array(
                'wfIssues'  => array( 'shared', 'getIssues', 'getIssueCounts', 'hasScanFailed' ),
                'wfScanner' => array( 'shared', 'lastScanTime', 'isRunning' ),
                'wfConfig'  => array( 'get' ),
            )
        );

        $issues = \wfIssues::shared();
        $all    = self::in_stable_order( self::every_issue( $issues, $status ) );
        $list   = array_slice( $all, $offset, $limit );
        $counts = (array) $issues->getIssueCounts();

        $reply = array(
            'scan'   => self::scan_state(),
            'counts' => array(
                'new'                   => (int) ( $counts['new'] ?? 0 ),
                'ignored_always'        => (int) ( $counts['ignoreP'] ?? 0 ),
                'ignored_until_changed' => (int) ( $counts['ignoreC'] ?? 0 ),
            ),
            'status' => $status,
            'issues' => array_map( array( __CLASS__, 'issue' ), $list ),
            'offset' => $offset,
            'limit'  => $limit,
        );
        $reply['next_offset'] = $offset + count( $list ) < count( $all ) ? $offset + count( $list ) : null;
        return $reply;
    }

    private static function every_issue( $issues, string $status ): array {
        $found = 'new' === $status
            ? $issues->getIssues( 0, PHP_INT_MAX, 0, 0 )
            : $issues->getIssues( 0, 0, 0, PHP_INT_MAX );
        $list  = $found[ 'new' === $status ? 'new' : 'ignored' ] ?? array();
        return array_values( array_filter( (array) $list, 'is_array' ) );
    }

    private static function in_stable_order( array $list ): array {
        $key = static function ( array $issue ): array {
            return array( -(int) ( $issue['severity'] ?? 0 ), (int) ( $issue['sortTag'] ?? 0 ), strtolower( (string) ( $issue['type'] ?? '' ) ), -(int) ( $issue['time'] ?? 0 ), (int) ( $issue['id'] ?? 0 ) );
        };
        usort(
            $list,
            static function ( array $a, array $b ) use ( $key ): int {
                return $key( $a ) <=> $key( $b );
            }
        );
        return $list;
    }

    private static function scan_state(): array {
        $completed = \wfConfig::get( 'lastScanCompleted' );
        $failure   = \wfIssues::hasScanFailed();
        if ( 'ok' === $completed ) {
            $result = 'completed';
        } elseif ( empty( $completed ) ) {
            $result = 'never';
        } else {
            $result = 'failed';
        }
        $cut   = array();
        $state = array(
            'last_scan_at' => self::iso_time( \wfScanner::shared()->lastScanTime() ),
            'result'       => $result,
            'error'        => 'failed' === $result ? self::capped( self::redact_text( self::plain_text( $completed ) ), 'error', $cut ) : null,
            'running'      => (bool) \wfScanner::shared()->isRunning(),
            'failure'      => is_string( $failure ) && '' !== $failure ? $failure : null,
        );
        if ( array() !== $cut ) {
            $state['truncated'] = $cut;
        }
        return $state;
    }

    private static function issue( array $row ): array {
        $level = (int) ( $row['severity'] ?? 0 );
        $data  = isset( $row['data'] ) && is_array( $row['data'] ) ? $row['data'] : array();
        unset( $data['realFileToken'] );
        $status = (string) ( $row['status'] ?? '' );
        $cut    = array();
        $issue  = array(
            'id'             => (int) ( $row['id'] ?? 0 ),
            'type'           => (string) ( $row['type'] ?? '' ),
            'status'         => self::STATUS_NAMES[ $status ] ?? $status,
            'severity'       => self::severity_name( $level ),
            'severity_level' => $level,
            'found_at'       => self::iso_time( $row['time'] ?? null ),
            'updated_at'     => self::iso_time( $row['lastUpdated'] ?? null ),
            'summary'        => self::capped( self::redact_text( self::plain_text( $row['shortMsg'] ?? '' ) ), 'summary', $cut ),
            'details'        => self::capped( self::redact_text( self::plain_text( $row['longMsg'] ?? '' ) ), 'details', $cut ),
            'data'           => self::capped_data( self::redact_data( $data ), 'data', $cut ),
        );
        if ( array() !== $cut ) {
            $issue['truncated'] = $cut;
        }
        return $issue;
    }

    private static function severity_name( int $level ): string {
        foreach ( self::SEVERITIES as $floor => $name ) {
            if ( $level >= $floor ) {
                return $name;
            }
        }
        return 'none';
    }
}
