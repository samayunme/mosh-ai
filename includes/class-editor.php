<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI assistant meta box on posts, pages and WooCommerce products, plus a lightweight meta description output.
 */
class MoshCW_Editor {

	public static function init() {
		if ( MoshCW_Settings::get( 'editor_assistant' ) ) {
			add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		}
		if ( MoshCW_Settings::get( 'meta_description' ) ) {
			add_action( 'save_post', array( __CLASS__, 'save_post' ) );
			add_action( 'wp_head', array( __CLASS__, 'head' ), 1 );
		}
	}

	public static function post_types() {
		$types = get_post_types( array( 'show_ui' => true ) );
		unset( $types['attachment'], $types['wp_block'], $types['wp_navigation'], $types['elementor_library'] );
		return array_values( apply_filters( 'moshcw_editor_post_types', $types ) );
	}

	public static function add_box() {
		if ( ! current_user_can( 'edit_posts' ) || ! MoshCW_Settings::has_ai() ) {
			return;
		}
		add_meta_box( 'moshcw-assistant', __( 'Mosh AI Assistant', 'mosh-ai' ), array( __CLASS__, 'render' ), self::post_types(), 'side', 'high' );
	}

	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'moshcw-admin', MOSHCW_URL . 'assets/css/admin.css', array(), MOSHCW_VERSION );
		wp_enqueue_script( 'moshcw-editor', MOSHCW_URL . 'assets/js/editor.js', array(), MOSHCW_VERSION, true );
		wp_localize_script(
			'moshcw-editor',
			'MoshCWEditor',
			array(
				'rest'  => esc_url_raw( rest_url( 'mosh-ai/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	public static function render( $post ) {
		$is_product = 'product' === $post->post_type;
		$buttons    = array(
			'titles'  => __( 'Suggest titles', 'mosh-ai' ),
			'excerpt' => __( 'Write excerpt', 'mosh-ai' ),
			'seo'     => __( 'SEO description', 'mosh-ai' ),
			'tags'    => __( 'Suggest tags', 'mosh-ai' ),
			'expand'  => __( 'Write / expand content', 'mosh-ai' ),
		);
		if ( $is_product ) {
			$buttons = array(
				'titles'              => __( 'Suggest names', 'mosh-ai' ),
				'product_description' => __( 'Product description', 'mosh-ai' ),
				'product_short'       => __( 'Short description', 'mosh-ai' ),
				'seo'                 => __( 'SEO description', 'mosh-ai' ),
				'tags'                => __( 'Suggest tags', 'mosh-ai' ),
			);
		}
		echo '<div id="moshcw-assistant-box" data-post="' . esc_attr( $post->ID ) . '" data-type="' . esc_attr( $post->post_type ) . '">';
		echo '<div class="moshcw-actions">';
		foreach ( $buttons as $task => $label ) {
			echo '<button type="button" class="button" data-task="' . esc_attr( $task ) . '">' . esc_html( $label ) . '</button>';
		}
		echo '</div>';
		echo '<textarea class="moshcw-instruction widefat" rows="3" placeholder="' . esc_attr__( 'Optional: extra instructions, or a custom request (e.g. "Rewrite the intro shorter").', 'mosh-ai' ) . '"></textarea>';
		echo '<p><button type="button" class="button button-primary" data-task="custom">' . esc_html__( 'Ask AI', 'mosh-ai' ) . '</button></p>';
		echo '<div class="moshcw-result" hidden></div>';
		if ( MoshCW_Settings::get( 'meta_description' ) ) {
			wp_nonce_field( 'moshcw_meta', 'moshcw_meta_nonce' );
			$value = get_post_meta( $post->ID, '_moshcw_meta_description', true );
			echo '<hr><p><label for="moshcw-meta"><strong>' . esc_html__( 'Meta description', 'mosh-ai' ) . '</strong> <span class="moshcw-meta-count"></span></label>';
			echo '<textarea id="moshcw-meta" name="moshcw_meta_description" class="widefat" rows="3" maxlength="320">' . esc_textarea( $value ) . '</textarea>';
			echo '<span class="description">' . esc_html__( 'Saved with the post. Skipped when an SEO plugin is active.', 'mosh-ai' ) . '</span></p>';
		}
		echo '</div>';
	}

	public static function save_post( $post_id ) {
		if ( ! isset( $_POST['moshcw_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['moshcw_meta_nonce'] ) ), 'moshcw_meta' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( isset( $_POST['moshcw_meta_description'] ) ) {
			update_post_meta( $post_id, '_moshcw_meta_description', sanitize_text_field( wp_unslash( $_POST['moshcw_meta_description'] ) ) );
		}
	}

	public static function head() {
		if ( ! is_singular() ) {
			return;
		}
		if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
			return;
		}
		$desc = get_post_meta( get_queried_object_id(), '_moshcw_meta_description', true );
		if ( $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
		}
	}
}
