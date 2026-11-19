<?php
namespace Easy_MCP_AI\OAuth;

use Easy_MCP_AI\MCP\Server;
use Easy_MCP_AI\Tools\Tool_State;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Scope_Availability {

    const SOME = 'some';

    const NONE = 'none';

    const OFF = 'off';

    public static function for_current_user( array $categories, ?\Easy_MCP_AI\Tools\Tool_Registry $registry = null ): array {
        $out = array();
        foreach ( self::rows( $categories, $registry ) as $slug => $tools ) {
            $refused = array_filter( $tools, array( __CLASS__, 'is_refused' ) );
            if ( array() !== $refused ) {
                $out[ $slug ] = array(
                    'state' => count( $refused ) === count( $tools ) ? self::NONE : self::SOME,
                    'roles' => self::roles_granting_any( array_values( array_unique( array_column( $refused, 'cap' ) ) ) ),
                );
            }
        }
        return $out;
    }

    public static function for_user( int $user_id, ?array $roles, array $categories, ?\Easy_MCP_AI\Tools\Tool_Registry $registry = null, ?array $shared = null ): ?array {
        $shared = $shared ?? self::shared( $categories, $registry );
        if ( null === $shared || \Easy_MCP_AI\Auth\Role_Ceiling::is_limited( $user_id ) ) {
            return null;
        }
        $scopes = $shared['scopes'];
        $ask     = static function () use ( $scopes, $user_id ): array {
            $out = array();
            foreach ( $scopes as $scope => $tools ) {
                $refused = array();
                foreach ( $tools as $name => $tool ) {
                    if ( self::is_refused( $tool, $user_id ) ) {
                        $refused[ $name ] = $tool['cap'];
                    }
                }
                $out[ $scope ] = array(
                    'on'      => array_keys( $tools ),
                    'refused' => $refused,
                );
            }
            return $out;
        };
        $answers = null === $roles ? $ask() : \Easy_MCP_AI\Auth\Role_Ceiling::while_limited( $user_id, $roles, $ask );
        return array(
            'scopes' => $answers,
            'off'    => $shared['off'],
        );
    }

    public static function shared( array $categories, ?\Easy_MCP_AI\Tools\Tool_Registry $registry = null ): ?array {
        $registry = $registry ?? self::registry();
        $scopes   = $registry ? self::scope_tools( $categories, $registry ) : null;
        if ( null === $scopes ) {
            return null;
        }
        return array(
            'scopes' => $scopes,
            'off'    => self::switched_off_names( $registry ),
        );
    }

    public static function mark( array $category, array $held, array $answer ): ?array {
        if ( ! empty( $category['is_ability'] ) ) {
            return null;
        }
        $on      = array();
        $refused = array();
        $names   = array();
        foreach ( $held as $scope ) {
            if ( ! isset( $answer['scopes'][ $scope ] ) ) {
                continue;
            }
            $on      = array_merge( $on, $answer['scopes'][ $scope ]['on'] );
            $refused = $refused + $answer['scopes'][ $scope ]['refused'];
            $names   = array_merge( $names, Scope_Map::SCOPE_MAP[ $scope ] );
        }
        $on = array_unique( $on );
        if ( array() === $on ) {
            $inactive = ! empty( $category['plugin_required'] ) && ! Scope_Map::is_plugin_category_active( (string) $category['slug'] );
            return ! $inactive && array() !== array_intersect( $names, $answer['off'] )
                ? array( 'state' => self::OFF, 'roles' => array() )
                : null;
        }
        if ( array() === $refused ) {
            return null;
        }
        return array(
            'state' => count( $refused ) === count( $on ) ? self::NONE : self::SOME,
            'roles' => self::roles_granting_any( array_values( array_unique( $refused ) ) ),
        );
    }

    private static function switched_off_names( \Easy_MCP_AI\Tools\Tool_Registry $registry ): array {
        return array_merge(
            array_column( \Easy_MCP_AI\Tools\Switched_Off_Inventory::tools( $registry ), 'name' ),
            array_column( \Easy_MCP_AI\Tools\Switched_Off_Inventory::integration_tools(), 'name' )
        );
    }

    public static function refused_titles( array $categories, ?\Easy_MCP_AI\Tools\Tool_Registry $registry = null ): array {
        $titles = array();
        foreach ( self::rows( $categories, $registry ) as $tools ) {
            foreach ( array_filter( $tools, array( __CLASS__, 'is_refused' ) ) as $name => $tool ) {
                $titles[ $name ] = $tool['title'];
            }
        }
        $titles = array_values( $titles );
        sort( $titles, SORT_NATURAL | SORT_FLAG_CASE );
        return $titles;
    }

    public static function refused_for_roles( array $categories, array $roles, ?\Easy_MCP_AI\Tools\Tool_Registry $registry = null ): array {
        $scopes = self::scope_tools( $categories, $registry );
        if ( null === $scopes ) {
            return array();
        }
        $user_id = (int) \get_current_user_id();
        $groups  = \Easy_MCP_AI\Privileged\Privileged_Groups::enabled_groups();
        $out     = array();
        foreach ( $roles as $role ) {
            $out[ $role ] = \Easy_MCP_AI\Auth\Role_Ceiling::while_limited(
                $user_id,
                array( $role ),
                static function () use ( $scopes, $groups, $user_id ): array {
                    $passes  = array();
                    $refused = array();
                    foreach ( $scopes as $scope => $tools ) {
                        if ( ! self::any_usable( $tools, $passes ) ) {
                            $refused[] = $scope;
                        }
                    }
                    foreach ( $groups as $group ) {
                        if ( ! \Easy_MCP_AI\Privileged\Privileged_Groups::floor_passes( $group, $user_id ) ) {
                            $refused[] = \Easy_MCP_AI\Privileged\Privileged_Groups::scope_for_group( $group );
                        }
                    }
                    return $refused;
                }
            );
        }
        return $out;
    }

    private static function any_usable( array $tools, array &$passes ): bool {
        foreach ( $tools as $tool ) {
            if ( ! isset( $passes[ $tool['cap'] ] ) ) {
                $passes[ $tool['cap'] ] = ! self::is_refused( $tool );
            }
            if ( $passes[ $tool['cap'] ] ) {
                return true;
            }
        }
        return false;
    }

    private static function rows( array $categories, ?\Easy_MCP_AI\Tools\Tool_Registry $registry ): array {
        $scopes = (array) self::scope_tools( $categories, $registry );
        $rows   = array();
        foreach ( $categories as $category ) {
            $tools = array();
            foreach ( array( 'read_scope', 'write_scope' ) as $key ) {
                $tools += $scopes[ isset( $category[ $key ] ) ? (string) $category[ $key ] : '' ] ?? array();
            }
            if ( array() !== $tools ) {
                $rows[ (string) $category['slug'] ] = $tools;
            }
        }
        return $rows;
    }

    private static function scope_tools( array $categories, ?\Easy_MCP_AI\Tools\Tool_Registry $registry ): ?array {
        $registry = $registry ?? self::registry();
        if ( ! $registry || ! \Easy_MCP_AI\Config::tool_policy_valid() ) {
            return null;
        }
        $patterns = (array) \Easy_MCP_AI\Config::get( 'allowed_tool_patterns' );
        $scopes   = array();
        foreach ( $categories as $category ) {
            if ( ! empty( $category['is_ability'] ) ) {
                continue;
            }
            foreach ( array( 'read_scope', 'write_scope' ) as $key ) {
                $scope = isset( $category[ $key ] ) ? (string) $category[ $key ] : '';
                if ( ! isset( Scope_Map::SCOPE_MAP[ $scope ] ) ) {
                    continue;
                }
                $scopes[ $scope ] = array();
                foreach ( Scope_Map::SCOPE_MAP[ $scope ] as $name ) {
                    $tool = $registry->get_tool( $name );
                    if ( ! $tool || $tool instanceof \Easy_MCP_AI\Tools\Privileged_Tool || ! Tool_State::is_enabled( $tool ) || ! Server::matches_tool_patterns( $name, $patterns ) || null !== Server::required_tool_off( $registry, $tool ) ) {
                        continue;
                    }
                    $scopes[ $scope ][ $name ] = array(
                        'cap'   => (string) Server::effective_required_capability( $tool->get_category(), $tool->get_required_capability() ),
                        'title' => (string) $tool->get_title(),
                    );
                }
            }
        }
        return $scopes;
    }

    private static function is_refused( array $tool, ?int $user_id = null ): bool {
        if ( '' === $tool['cap'] ) {
            return false;
        }
        return null === $user_id ? ! \current_user_can( $tool['cap'] ) : ! \user_can( $user_id, $tool['cap'] );
    }

    private static function roles_granting_any( array $capabilities ): array {
        $granting = array();
        foreach ( $capabilities as $capability ) {
            $granting = array_merge( $granting, \Easy_MCP_AI\Auth\Connect_Policy::roles_granting( $capability ) );
        }
        $names = array();
        foreach ( \Easy_MCP_AI\Auth\Connect_Policy::role_choices() as $choice ) {
            if ( in_array( $choice['value'], $granting, true ) ) {
                $names[] = $choice['label'];
            }
        }
        return $names;
    }

    public static function registry(): ?\Easy_MCP_AI\Tools\Tool_Registry {
        if ( ! class_exists( '\\Easy_MCP_AI\\Plugin' ) || ! method_exists( '\\Easy_MCP_AI\\Plugin', 'instance' ) ) {
            return null;
        }
        return \Easy_MCP_AI\Plugin::instance()->tool_registry_on_demand();
    }
}
