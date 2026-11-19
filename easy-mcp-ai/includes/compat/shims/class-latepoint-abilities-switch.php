<?php

namespace Easy_MCP_AI\Compat\Shims;

use Easy_MCP_AI\Compat\Compat_Shim;
use Easy_MCP_AI\Compat\Latepoint_Abilities;
use Easy_MCP_AI\Tools\Dynamic_Tool;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Latepoint_Abilities_Switch extends Compat_Shim {

    private $settings;

    private $auth;

    public function __construct( string $settings = Latepoint_Abilities::SETTINGS_CLASS, string $auth = Latepoint_Abilities::AUTH_CLASS ) {
        $this->settings = $settings;
        $this->auth     = $auth;
    }

    public function id(): string {
        return 'latepoint-abilities-switch';
    }

    public function applies_to( $tool ): bool {
        return $tool instanceof Dynamic_Tool && 0 === strpos( (string) $tool->get_ability(), Latepoint_Abilities::PREFIX );
    }

    public function is_needed(): bool {
        if ( (int) get_current_user_id() <= 0 || ! Latepoint_Abilities::settled() || ! Latepoint_Abilities::switches_available( $this->settings ) ) {
            return false;
        }
        return ! Latepoint_Abilities::switches_read_on( $this->settings );
    }

    public function enter( array $arguments = array(), $tool = null ): \stdClass {
        $kind     = $tool instanceof Dynamic_Tool ? Latepoint_Abilities::kind_of( (string) $tool->get_ability() ) : null;
        $switches = Latepoint_Abilities::switches_for( $kind );
        $state    = (object) array( 'prior' => Latepoint_Abilities::set_switches( $switches, $this->settings ) );
        if ( class_exists( $this->auth, false ) && is_callable( array( $this->auth, 'reset_current_user' ) ) ) {
            call_user_func( array( $this->auth, 'reset_current_user' ) );
        }
        return $state;
    }

    public function leave( $state = null ): void {
        if ( ! $state instanceof \stdClass || ! Latepoint_Abilities::switches_available( $this->settings ) ) {
            return;
        }
        Latepoint_Abilities::restore_switches( $state->prior, $this->settings );
    }
}
