<?php
namespace Easy_MCP_AI\Tools\Cloud;

use Easy_MCP_AI\Cloud\Block_Checks;
use Easy_MCP_AI\Cloud\Cloud_Refs;
use Easy_MCP_AI\MCP\Detailed_Tool_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Blocks_Publish extends Local_Block_Tool {

    const STATUSES     = array( 'draft', 'publish', 'pending', 'private' );
    const RESTORE_HINT = "To undo it, restore that revision with wp_restore_revision or from the WordPress editor's revision history.";

    protected function writes_content(): bool {
        return true;
    }

    public function get_name() {
        return 'blocks_publish';
    }

    public function get_title() {
        return 'Publish blocks';
    }

    public function get_description() {
        return implode(
            "\n",
            array(
                'Publish a ref from blocks_execute on this site: create a page, post or WooCommerce product with it, or, with post_id, replace an existing one\'s whole content.',
                'Default: a draft page. homepage: true (pages, status "publish") also makes it the site\'s homepage.',
                'To change only some sections of an existing page, use blocks_edit.',
                'With post_id it overwrites the current content (the answer names the newest revision to restore; a product keeps none and its old description can\'t be read by the tools, so it needs confirm: true), so the tool is marked destructive.',
                "The WordPress user you're connected as needs the unfiltered_html capability (administrators have it; editors and multisite admins don't) or WordPress strips markup such as forms, SVG and style from the page. After saving a page or post, the tool reads the saved content back and warns when the site removed such elements or saved fewer top-level blocks (a plugin that allows them, or a user with unfiltered_html, loses none). blocks_publish of a product runs the description through WooCommerce's filter for every user, so a ref with svg, form, input, style or similar tags is refused there; blocks_edit on a product keeps them for a user with unfiltered_html and warns when the saved description lost them.",
            )
        );
    }

    public function get_input_schema() {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'ref'      => self::ref_property(),
                'title'    => array(
                    'type'        => 'string',
                    'minLength'   => 1,
                    'maxLength'   => 200,
                    'description' => 'The title (a product: its name). Required without post_id.',
                ),
                'type'     => self::type_property(),
                'status'   => array(
                    'type'        => 'string',
                    'enum'        => self::STATUSES,
                    'description' => 'Default draft when creating; with post_id, unchanged unless given.',
                ),
                'post_id'  => self::post_id_property( "Replace this page's, post's or product's whole content instead of creating one." ),
                'slug'     => array(
                    'type'        => 'string',
                    'minLength'   => 1,
                    'maxLength'   => 200,
                    'description' => 'Pages and posts only.',
                ),
                'parent'   => array(
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'description' => 'Pages only: the parent page id (0: top level).',
                ),
                'homepage' => array(
                    'type'        => 'boolean',
                    'description' => 'Pages only, with status "publish": make the page the site\'s homepage.',
                ),
                'confirm'  => array(
                    'type'        => 'boolean',
                    'description' => "Products only, with post_id: required, since a product keeps no revisions and the tools can't read its old description.",
                ),
            ),
            'required'             => array( 'ref' ),
        );
    }

    public function describe( array $arguments ) {
        try {
            $sentence = $this->publish_sentence( $arguments );
        } catch ( \Throwable $e ) {
            $sentence = null;
        }
        return null === $sentence ? self::generic_description( $this, $arguments ) : $sentence;
    }

    public function approval_details( array $arguments ) {
        $stored = isset( $arguments['ref'] ) && is_string( $arguments['ref'] ) ? Cloud_Refs::get( $arguments['ref'] ) : null;
        if ( null === $stored ) {
            return null;
        }
        $details = array( array( 'label' => 'content', 'after' => $stored['markup'] ) );
        try {
            $generic = null === $this->publish_sentence( $arguments );
        } catch ( \Throwable $e ) {
            $generic = true;
        }
        return $generic ? array_merge( $details, self::generic_details( $this, $arguments ) ) : $details;
    }

    private function publish_sentence( array $arguments ): ?string {
        $stored = isset( $arguments['ref'] ) && is_string( $arguments['ref'] ) ? Cloud_Refs::get( $arguments['ref'] ) : null;
        if ( null === $stored ) {
            return null;
        }
        list( $title, $type, $status, $post_id, $slug, $parent, $homepage ) = self::target_arguments( $arguments );
        $count = $stored['summary']['blockCount'];
        $blocks   = sprintf( '%d %s', $count, 1 === $count ? 'block' : 'blocks' );
        $clauses  = array();
        if ( null === $post_id ) {
            if ( null === $title ) {
                return null;
            }
            $sentence = sprintf( 'Create %1$s "%2$s" as %3$s from %4$s', $type, $title, $status ?? 'draft', $blocks );
        } else {
            $target = $this->target_line( $type, $post_id );
            if ( null === $target ) {
                return null;
            }
            $sentence = sprintf( 'Replace the content of %1$s with %2$s', $target, $blocks );
            if ( null !== $status ) {
                $clauses[] = 'set its status to ' . $status;
            }
            if ( null !== $title ) {
                $clauses[] = sprintf( 'set its %1$s to "%2$s"', 'product' === $type ? 'name' : 'title', $title );
            }
        }
        if ( null !== $slug ) {
            $clauses[] = sprintf( 'set its slug to "%s"', $slug );
        }
        if ( null !== $parent ) {
            $clauses[] = 'set its parent to ' . $parent;
        }
        if ( true === $homepage ) {
            $clauses[] = 'make it the homepage';
        }
        return array() === $clauses ? $sentence : $sentence . ', ' . self::join_clauses( $clauses );
    }

    private static function target_arguments( array $arguments ): array {
        $title = self::string_arg( $arguments, 'title', 1, 200 );
        $type  = self::enum_arg( $arguments, 'type', self::POST_KINDS ) ?? 'page';
        return array(
            $title,
            $type,
            self::enum_arg( $arguments, 'status', self::STATUSES ),
            self::int_arg( $arguments, 'post_id', 1 ),
            self::string_arg( $arguments, 'slug', 1, 200 ),
            self::int_arg( $arguments, 'parent', 0 ),
            self::bool_arg( $arguments, 'homepage' ),
        );
    }

    private function checked_arguments( array $arguments ): array {
        $ref_id = self::string_arg( $arguments, 'ref', 1, self::MAX_REF_CHARS );
        list( $title, $type, $status, $post_id, $slug, $parent, $homepage ) = self::target_arguments( $arguments );
        $confirm = self::bool_arg( $arguments, 'confirm' );
        if ( null === $ref_id ) {
            throw new Detailed_Tool_Error( 'Missing required parameters: ref' );
        }

        if ( null === $post_id && null === $title ) {
            throw self::refusal( sprintf( "title is required to create a %1\$s. Give a title, or post_id to replace an existing %1\$s's content.", $type ) );
        }
        if ( 'page' !== $type && ( null !== $parent || null !== $homepage ) ) {
            throw self::refusal( 'parent and homepage apply to pages only.' );
        }
        if ( 'product' === $type && null !== $slug ) {
            throw self::refusal( 'slug applies to pages and posts only.' );
        }
        if ( true === $homepage && 'publish' !== $status ) {
            throw self::refusal( Block_Checks::HOMEPAGE_NEEDS_PUBLISH );
        }
        if ( 'product' === $type && null !== $post_id && true !== $confirm ) {
            throw self::refusal( $this->confirm_text( 'replacing its description' ) );
        }
        $stored = Cloud_Refs::get( $ref_id );
        if ( null === $stored ) {
            throw self::refusal( Block_Checks::UNKNOWN_REF );
        }
        if ( '' === trim( $stored['markup'] ) ) {
            throw self::refusal( Block_Checks::NOTHING_BUILT );
        }
        $markup_bytes = strlen( $stored['markup'] );
        $stripped     = 'product' === $type ? Block_Checks::kses_stripped_tags( $stored['markup'] ) : array();
        if ( array() !== $stripped ) {
            $tags = implode( ', ', array_map( static function ( string $tag ): string {
                return '<' . $tag . '>';
            }, $stripped ) );
            throw self::refusal( sprintf( "This markup has %s, which blocks_publish saves into a product through WooCommerce's filter (wp_kses_post), and that removes them for every user. Use it on a page or post, add it to the product with blocks_edit, or build it without those elements.", $tags ) );
        }
        return array(
            'title'        => $title,
            'type'         => $type,
            'status'       => $status,
            'post_id'      => $post_id,
            'slug'         => $slug,
            'parent'       => $parent,
            'homepage'     => $homepage,
            'stored'       => $stored,
            'markup_bytes' => $markup_bytes,
        );
    }

    public function precheck( array $arguments ): void {
        $checked = $this->checked_arguments( $arguments );
        $this->refuse_unavailable(
            self::write_tool( $checked['type'], $checked['post_id'] ),
            sprintf( '%1$s a %2$s', null === $checked['post_id'] ? 'creates' : 'updates', $checked['type'] )
        );
    }

    protected function perform( array $arguments ) {
        $checked      = $this->checked_arguments( $arguments );
        $title        = $checked['title'];
        $type         = $checked['type'];
        $status       = $checked['status'];
        $post_id      = $checked['post_id'];
        $slug         = $checked['slug'];
        $parent       = $checked['parent'];
        $homepage     = $checked['homepage'];
        $stored       = $checked['stored'];
        $markup_bytes = $checked['markup_bytes'];

        $warnings = array();
        if ( 'product' === $type ) {
            $warnings[] = "WooCommerce filters a product's description, which can alter block attributes, and it can't be read back here: check the product in the editor.";
        }
        if ( $markup_bytes > Block_Checks::RECOMMENDED_REQUEST_BYTES ) {
            $warnings[] = $this->size_warning( $markup_bytes );
        }

        $this->check_block_plugins( $stored['blockTypes'], $warnings );

        $previous_homepage = null;
        if ( true === $homepage ) {
            $current = $this->ask_quietly( 'wp_get_site_settings', array() );
            if ( null !== $current ) {
                $previous_homepage = array(
                    'showOnFront' => isset( $current['show_on_front'] ) && is_string( $current['show_on_front'] ) ? $current['show_on_front'] : null,
                    'pageOnFront' => isset( $current['page_on_front'] ) && is_int( $current['page_on_front'] ) ? $current['page_on_front'] : null,
                );
            } else {
                $warnings[] = "Couldn't read the site's current homepage setting (it needs site settings read), so the previous homepage isn't recorded.";
            }
        }
        $previous_read = null === $post_id ? null : $this->read_previous( $type, $post_id );
        $previous      = null === $previous_read ? null : $previous_read['previous'];

        list( $name, $args ) = self::publish_call( $type, $stored['markup'], $title, $status, $post_id, $slug, $parent );
        $data                = $this->ask( $name, $args, true, null === $post_id ? null : self::target( 'blocks_publish', $post_id, $type ) );

        $link = isset( $data['link'] ) && is_string( $data['link'] ) ? $data['link'] : ( isset( $data['permalink'] ) && is_string( $data['permalink'] ) ? $data['permalink'] : '' );
        if ( ! self::is_positive_int( $data['id'] ?? null ) || ! isset( $data['status'] ) || ! is_string( $data['status'] ) ) {
            return array(
                'message' => 'This site answered with something unexpected, so check the result:',
                'answer'  => $data,
            );
        }
        $id           = $data['id'];
        $saved_status = $data['status'];
        $asked        = null === $post_id ? ( $status ?? 'draft' ) : $status;
        $notice       = null;
        if ( isset( $data['notice'] ) && is_string( $data['notice'] ) ) {
            $notice = $data['notice'];
        } elseif ( null !== $asked && $asked !== $saved_status ) {
            $notice = sprintf( 'This site saved it as %1$s, not %2$s%3$s.', $saved_status, $asked, null === $post_id ? ': its EasyMCP AI "Draft mode" setting (New content or Strict) may be on' : '' );
        }
        $output = array(
            'id'     => $id,
            'type'   => $type,
            'status' => $saved_status,
            'link'   => $link,
        );
        if ( null !== $notice ) {
            $output['notice'] = $notice;
        }
        $done = sprintf( '%1$s %2$s %3$d on this site: %4$s, %5$s.', null === $post_id ? 'Created' : 'Updated', $type, $id, $saved_status, '' !== $link ? $link : 'no link' );

        $was = 'unknown';
        if ( null !== $previous_homepage ) {
            if ( 'page' === $previous_homepage['showOnFront'] ) {
                $was = $previous_homepage['pageOnFront'] ? 'page ' . $previous_homepage['pageOnFront'] : 'no page selected';
            } elseif ( 'posts' === $previous_homepage['showOnFront'] ) {
                $was = 'the latest posts';
            }
            $output['previousHomepage'] = $previous_homepage;
        }
        $homepage_problem = null;
        $homepage_pause   = null;
        if ( true === $homepage ) {
            if ( 'publish' !== $saved_status ) {
                $homepage_problem = sprintf( 'it was saved as %s. %s', $saved_status, Block_Checks::HOMEPAGE_NEEDS_PUBLISH );
            } else {
                $settings = $this->request( 'wp_update_site_settings', array( 'show_on_front' => 'page', 'page_on_front' => $id ), true );
                if ( $settings['paused'] ) {
                    $homepage_pause = $settings;
                } elseif ( null === $settings['data'] ) {
                    $homepage_problem = $settings['text'];
                } else {
                    $output['homepage'] = true;
                }
            }
        }

        if ( 'product' !== $type ) {
            $saved = $this->ask_quietly( 'page' === $type ? 'wp_get_page' : 'wp_get_post', 'page' === $type ? array( 'page_id' => $id ) : array( 'post_id' => $id ) );
            if ( null === $saved || ! isset( $saved['content'] ) || ! is_string( $saved['content'] ) ) {
                $warnings[] = sprintf( "Couldn't read the %s back to check what the site saved (it needs posts read, and the answer must fit the size limit).", $type );
            } else {
                $removed     = Block_Checks::removed_tags( $stored['markup'], $saved['content'] );
                $sent_blocks = Block_Checks::root_block_count( $stored['markup'] );
                $kept_blocks = Block_Checks::root_block_count( $saved['content'] );
                if ( null !== $removed ) {
                    $warnings[] = sprintf( 'The site removed %1$s from the saved %2$s, likely because the connected WordPress user lacks unfiltered_html. Check the %2$s.', $removed, $type );
                }
                if ( $kept_blocks !== $sent_blocks ) {
                    $warnings[] = sprintf( 'The site saved %1$d of %2$d top-level blocks; the markup was changed, likely because the connected WordPress user lacks unfiltered_html.', $kept_blocks, $sent_blocks );
                }
            }
        }
        if ( null !== $previous ) {
            $output['previous'] = array() === $previous ? new \stdClass() : $previous;
        }
        if ( array() !== $warnings ) {
            $output['warnings'] = $warnings;
        }
        $restore = array();
        if ( null !== $previous ) {
            if ( 'product' === $type ) {
                $restore[] = "A product keeps no revisions and the tools can't read its old description, so the previous one can't be recovered from here.";
            } else {
                $restore[] = $this->revision_line( $previous_read['revisions'], $previous );
            }
        }

        if ( null !== $homepage_pause ) {
            $lines = array(
                sprintf(
                    "%1\$s Page %2\$d is saved, so don't publish it again. Making it the homepage needs a person's approval: after they approve at the approval link, call wp_update_site_settings with show_on_front \"page\" and page_on_front %2\$d, or set it under Settings > Reading. Before, the homepage was %3\$s.",
                    $done,
                    $id,
                    $was
                ),
            );
            $lines = array_merge( $lines, $restore );
            foreach ( $warnings as $warning ) {
                $lines[] = 'Warning: ' . $warning;
            }
            throw self::refusal_for( array_merge( $homepage_pause, array( 'text' => implode( "\n", $lines ) ) ) );
        }
        if ( null !== $homepage_problem ) {
            $lines = array(
                sprintf(
                    "%1\$s Making it the homepage failed: %2\$s The page itself is saved, so don't publish it again: set page %3\$d as the homepage under Settings > Reading, and check what the setting is now (before, the homepage was %4\$s).",
                    $done,
                    $homepage_problem,
                    $id,
                    $was
                ),
            );
            $lines = array_merge( $lines, $restore );
            foreach ( $warnings as $warning ) {
                $lines[] = 'Warning: ' . $warning;
            }
            throw self::refusal( implode( "\n", $lines ) );
        }
        $lines = array( $done );
        if ( true === ( $output['homepage'] ?? null ) ) {
            $lines[] = sprintf( "It's now the homepage. Before, the homepage was %s.", $was );
        }
        if ( null !== $notice ) {
            $lines[] = $notice;
        }
        $lines             = array_merge( $lines, $restore );
        $output['message'] = implode( "\n", $lines );
        return $output;
    }

    private function read_previous( string $type, int $post_id ): array {
        $previous = array();
        $modified = $this->read_modified_quietly( $type, $post_id );
        if ( null !== $modified ) {
            $previous['modified'] = $modified;
        }
        if ( 'product' === $type ) {
            return array( 'previous' => $previous, 'revisions' => 'not_applicable' );
        }
        $listed = $this->ask_quietly( 'wp_list_revisions', array( 'post_id' => $post_id, 'per_page' => 1 ) );
        if ( null === $listed || ! isset( $listed['revisions'] ) || ! is_array( $listed['revisions'] ) ) {
            return array( 'previous' => $previous, 'revisions' => 'failed' );
        }
        $newest = reset( $listed['revisions'] );
        if ( is_array( $newest ) && self::is_positive_int( $newest['id'] ?? null ) ) {
            $previous['revision'] = array( 'id' => $newest['id'] );
            if ( isset( $newest['date'] ) && is_string( $newest['date'] ) ) {
                $previous['revision']['date'] = $newest['date'];
            }
            return array( 'previous' => $previous, 'revisions' => 'found' );
        }
        return array( 'previous' => $previous, 'revisions' => 'none' );
    }

    private function revision_line( string $revisions, array $previous ): string {
        $modified = $previous['modified'] ?? 'unknown';
        if ( 'found' === $revisions && isset( $previous['revision'] ) ) {
            $date = isset( $previous['revision']['date'] ) ? ' (' . $previous['revision']['date'] . ')' : '';
            return sprintf( 'Newest revision: %1$d%2$s, modified of the previous content %3$s. It holds the previous content only if the last save made a revision. %4$s', $previous['revision']['id'], $date, $modified, self::RESTORE_HINT );
        }
        if ( 'none' === $revisions ) {
            return sprintf( "The site lists no revision for the previous content (modified %s), so it can't be restored from here.", $modified );
        }
        return sprintf( "Couldn't read the revisions of the previous content (it needs posts read), so whether it can be restored is unknown (modified %s).", $modified );
    }

    private static function write_tool( string $type, ?int $post_id ): string {
        if ( 'product' === $type ) {
            return null === $post_id ? 'wp_wc_create_product' : 'wp_wc_update_product';
        }
        $kind = 'page' === $type ? 'page' : 'post';
        return ( null === $post_id ? 'wp_create_' : 'wp_update_' ) . $kind;
    }

    private static function publish_call( string $type, string $markup, ?string $title, ?string $status, ?int $post_id, ?string $slug, ?int $parent ): array {
        $optional = array();
        if ( null !== $status ) {
            $optional['status'] = $status;
        }
        if ( null !== $slug ) {
            $optional['slug'] = $slug;
        }
        if ( null !== $parent ) {
            $optional['parent'] = $parent;
        }
        if ( 'product' === $type ) {
            $product = array( 'description' => $markup );
            if ( null !== $title ) {
                $product['name'] = $title;
            }
            if ( null !== $status ) {
                $product['status'] = $status;
            }
            return null === $post_id
                ? array( self::write_tool( $type, $post_id ), array_merge( $product, array( 'status' => $status ?? 'draft' ) ) )
                : array( self::write_tool( $type, $post_id ), array_merge( array( 'id' => $post_id ), $product ) );
        }
        $post = array();
        if ( null !== $title ) {
            $post['title'] = $title;
        }
        $post['content'] = $markup;
        $post            = array_merge( $post, $optional );
        if ( null === $post_id ) {
            return array( self::write_tool( $type, $post_id ), array_merge( $post, array( 'status' => $status ?? 'draft' ) ) );
        }
        return array( self::write_tool( $type, $post_id ), array_merge( array( 'page' === $type ? 'page_id' : 'post_id' => $post_id ), $post ) );
    }
}
