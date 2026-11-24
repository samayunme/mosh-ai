<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-page admin app: every feature is a tab, every setting saves instantly over the REST API.
 */
class MoshCW_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	const LOGO_PATH = 'M545 405L857 410L618 1416L778 1416L953 719L1248 722L1025 1642L267 1642ZM1493 406L1780 405L1485 1642L1195 1642Z';

	/** WEBMOSH logo as a data URI. Black fill for the admin menu (WordPress recolors it), blue elsewhere. */
	public static function logo_uri( $fill = 'black' ) {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="223 223 1600 1600"><path fill="' . $fill . '" d="' . self::LOGO_PATH . '"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * "Mosh AI" in the top toolbar, front end and admin, so settings are one click away anywhere.
	 */
	public static function admin_bar( $bar ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'moshcw',
				'title' => '<span class="ab-icon" style="background:url(' . esc_attr( self::logo_uri( '#31B5F2' ) ) . ') center/18px no-repeat;width:20px;height:20px;margin-top:6px"></span><span class="ab-label">Mosh AI</span>',
				'href'  => self::url( 'home' ),
			)
		);
		foreach ( self::tabs() as $tab => $t ) {
			if ( 'home' !== $tab && current_user_can( $t[1] ) ) {
				$bar->add_node(
					array(
						'parent' => 'moshcw',
						'id'     => 'moshcw-' . $tab,
						'title'  => esc_html( $t[0] ),
						'href'   => self::url( $tab ),
					)
				);
			}
		}
	}

	public static function tabs() {
		return array(
			'home'      => array( __( 'Home', 'mosh-ai' ), 'edit_posts', 'dashicons-admin-home' ),
			'chatbots'  => array( __( 'Chatbots', 'mosh-ai' ), 'manage_options', 'dashicons-format-chat' ),
			'studio'    => array( __( 'Studio', 'mosh-ai' ), 'edit_posts', 'dashicons-edit-large' ),
			'forms'     => array( __( 'AI Forms', 'mosh-ai' ), 'manage_options', 'dashicons-feedback' ),
			'knowledge' => array( __( 'Knowledge', 'mosh-ai' ), 'manage_options', 'dashicons-database' ),
			'activity'  => array( __( 'Activity', 'mosh-ai' ), 'manage_options', 'dashicons-chart-bar' ),
			'settings'  => array( __( 'Settings', 'mosh-ai' ), 'manage_options', 'dashicons-admin-generic' ),
		);
	}

	public static function slug( $tab ) {
		return 'home' === $tab ? 'moshcw' : 'moshcw-' . $tab;
	}

	public static function url( $tab = 'home' ) {
		return admin_url( 'admin.php?page=' . self::slug( $tab ) );
	}

	public static function menu() {
		add_menu_page( 'Mosh AI', 'Mosh AI', 'edit_posts', 'moshcw', array( __CLASS__, 'page' ), self::logo_uri(), 58 );
		foreach ( self::tabs() as $tab => $t ) {
			add_submenu_page( 'moshcw', $t[0] . ' - Mosh AI', $t[0], $t[1], self::slug( $tab ), array( __CLASS__, 'page' ) );
		}
	}

	private static function current_tab() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'moshcw'; // phpcs:ignore WordPress.Security.NonceVerification
		$tab  = 'moshcw' === $page ? 'home' : substr( $page, 7 );
		$tabs = self::tabs();
		return isset( $tabs[ $tab ] ) && current_user_can( $tabs[ $tab ][1] ) ? $tab : 'home';
	}

	public static function assets( $hook ) {
		if ( false === strpos( $hook, 'moshcw' ) ) {
			return;
		}
		MoshCW_Chatbots::register_assets();
		wp_enqueue_style( 'moshcw-front' );
		wp_enqueue_script( 'moshcw-front' );
		wp_enqueue_style( 'moshcw-admin', MOSHCW_URL . 'assets/css/admin.css', array(), MOSHCW_VERSION );
		wp_enqueue_script( 'moshcw-admin', MOSHCW_URL . 'assets/js/admin.js', array(), MOSHCW_VERSION, true );
		$slugs = array();
		foreach ( array_keys( self::tabs() ) as $tab ) {
			$slugs[ $tab ] = self::slug( $tab );
		}
		$manage = current_user_can( 'manage_options' );
		wp_localize_script(
			'moshcw-admin',
			'MoshCWAdmin',
			array(
				'rest'            => esc_url_raw( rest_url( 'mosh-ai/v1/' ) ),
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'adminUrl'        => admin_url( 'admin.php' ),
				'slugs'           => $slugs,
				'tab'             => self::current_tab(),
				'defaultProvider' => MoshCW_Settings::get( 'default_provider' ),
				'bots'            => $manage ? MoshCW_Chatbots::all() : new stdClass(),
				'botDefaults'     => MoshCW_Chatbots::defaults( '' ),
				'forms'           => $manage ? (object) MoshCW_Forms::all() : new stdClass(),
				'formDefaults'    => MoshCW_Forms::defaults( '' ),
				'popupBot'        => MoshCW_Settings::get( 'popup_bot' ),
				'canManage'       => $manage,
				'hasAI'           => MoshCW_Settings::has_ai(),
				'hasMediaAI'      => MoshCW_Settings::has_media_ai(),
			)
		);
	}

	/* ---------- Small UI helpers ---------- */

	private static function provider_select( $name, $selected, $with_default = true ) {
		$h = '<select name="' . esc_attr( $name ) . '" data-moshcw-provider>';
		if ( $with_default ) {
			$h .= '<option value="">' . esc_html__( 'Default (from Settings)', 'mosh-ai' ) . '</option>';
		}
		foreach ( MoshCW_Providers::names() as $k => $label ) {
			$h .= '<option value="' . esc_attr( $k ) . '"' . selected( $selected, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $h . '</select>';
	}

	private static function model_input( $name, $value, $provider = '' ) {
		$p = $provider ? $provider : MoshCW_Settings::get( 'default_provider' );
		return '<input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" list="moshcw-models-' . esc_attr( $p ) . '" placeholder="' . esc_attr__( 'Default model', 'mosh-ai' ) . '" data-moshcw-model autocomplete="off">';
	}

	private static function field( $label, $control, $help = '', $class = '' ) {
		return '<div class="mx-field ' . esc_attr( $class ) . '"><span class="mx-label">' . esc_html( $label ) . '</span>' . $control . ( $help ? '<span class="mx-help">' . esc_html( $help ) . '</span>' : '' ) . '</div>';
	}

	private static function toggle( $name, $on, $label, $desc = '', $extra = '' ) {
		return '<label class="mx-toggle"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( ! empty( $on ), true, false ) . ' ' . $extra . '><span class="mx-switch" aria-hidden="true"></span><span class="mx-toggle-text"><strong>' . esc_html( $label ) . '</strong>' . ( $desc ? '<small>' . esc_html( $desc ) . '</small>' : '' ) . '</span></label>';
	}

	private static function num( $name, $value, $min, $max, $step = 1 ) {
		return '<input type="number" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" min="' . esc_attr( $min ) . '" max="' . esc_attr( $max ) . '" step="' . esc_attr( $step ) . '">';
	}

	private static function text( $name, $value, $placeholder = '' ) {
		return '<input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '">';
	}

	private static function area( $name, $value, $rows = 4, $placeholder = '', $class = '' ) {
		return '<textarea name="' . esc_attr( $name ) . '" rows="' . (int) $rows . '" placeholder="' . esc_attr( $placeholder ) . '" class="' . esc_attr( $class ) . '">' . esc_textarea( $value ) . '</textarea>';
	}

	private static function go( $tab, $label, $class = 'button' ) {
		return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( self::url( strtok( $tab, ':' ) ) ) . '" data-go="' . esc_attr( $tab ) . '">' . esc_html( $label ) . '</a>';
	}

	private static function connected() {
		return MoshCW_Settings::connected();
	}

	/** Tabs that need a connected AI. */
	public static function locked( $tab ) {
		if ( in_array( $tab, array( 'chatbots', 'studio', 'forms', 'knowledge' ), true ) && ! MoshCW_Settings::has_ai() ) {
			return 'ai';
		}
		if ( 'knowledge' === $tab && ! MoshCW_Settings::has_media_ai() ) {
			return 'media';
		}
		return '';
	}

	private static function lock_card( $tab, $why ) {
		$tabs = self::tabs();
		echo '<div class="mx-card mx-locked"><div class="mx-lock-icon"><span class="dashicons dashicons-lock"></span></div>';
		if ( 'media' === $why ) {
			/* translators: %s feature name */
			echo '<h2>' . esc_html( sprintf( __( '%s needs OpenAI or Google Gemini', 'mosh-ai' ), $tabs[ $tab ][0] ) ) . '</h2><p>' . esc_html__( 'Your connected AI can chat and write, but it cannot read documents into a knowledge base. Add an OpenAI key, or a free Gemini key, to unlock it.', 'mosh-ai' ) . '</p>';
		} else {
			/* translators: %s feature name */
			echo '<h2>' . esc_html( sprintf( __( 'Connect an AI to unlock %s', 'mosh-ai' ), $tabs[ $tab ][0] ) ) . '</h2><p>' . esc_html__( 'AI features stay locked until an AI is connected, so nothing on your site calls an AI by accident. OpenAI, Claude, Gemini, OpenRouter or any other AI with an OpenAI-compatible API works. Gemini has a free tier.', 'mosh-ai' ) . '</p>';
		}
		MoshCW_Kses::out( '<p>' . self::go( 'settings', __( 'Connect an AI', 'mosh-ai' ), 'button button-primary button-hero' ) . '</p>' );
	}

	private static function stat( $label, $value, $sub = '' ) {
		return '<div class="mx-stat"><span class="mx-stat-val">' . esc_html( $value ) . '</span><span class="mx-stat-label">' . esc_html( $label ) . '</span>' . ( $sub ? '<span class="mx-stat-sub">' . wp_kses_post( $sub ) . '</span>' : '' ) . '</div>';
	}

	/* ---------- Page shell ---------- */

	public static function page() {
		$tab       = self::current_tab();
		$s         = MoshCW_Settings::all();
		$connected = self::connected();
		$names     = MoshCW_Providers::names();

		echo '<div class="wrap moshcw-app"><h1 class="screen-reader-text">Mosh AI</h1>';
		echo '<header class="mx-top"><div class="mx-brand"><img src="' . esc_url( MOSHCW_URL . 'assets/img/logo.svg' ) . '" alt="WEBMOSH" width="34" height="34"><span>Mosh AI<small>' . esc_html__( 'by WEBMOSH', 'mosh-ai' ) . '</small></span><span class="mx-ver">' . esc_html( MOSHCW_VERSION ) . '</span></div><div class="mx-pills">';
		if ( $connected ) {
			$label = isset( $connected[ $s['default_provider'] ] ) ? $names[ $s['default_provider'] ] . ' · ' . $s['default_model'] : implode( ', ', $connected );
			/* translators: %s provider and model */
			echo '<a class="mx-pill is-on" href="' . esc_url( self::url( 'settings' ) ) . '" data-go="settings"><span class="mx-dot"></span>' . esc_html( sprintf( __( 'API connected · %s', 'mosh-ai' ), $label ) ) . '</a>';
		} else {
			echo '<a class="mx-pill is-off" href="' . esc_url( self::url( 'settings' ) ) . '" data-go="settings"><span class="mx-dot"></span>' . esc_html__( 'No API connected', 'mosh-ai' ) . '</a>';
		}
		echo '</div>';
		echo '</header><nav class="mx-tabs" role="tablist">';
		foreach ( self::tabs() as $k => $t ) {
			if ( current_user_can( $t[1] ) ) {
				$lock = self::locked( $k );
				echo '<a role="tab" href="' . esc_url( self::url( $k ) ) . '" data-go="' . esc_attr( $k ) . '" class="mx-tab' . ( $k === $tab ? ' is-active' : '' ) . ( $lock ? ' is-locked' : '' ) . '"' . ( $lock ? ' title="' . esc_attr__( 'Connect an AI to unlock', 'mosh-ai' ) . '"' : '' ) . '><span class="dashicons ' . esc_attr( $lock ? 'dashicons-lock' : $t[2] ) . '"></span>' . esc_html( $t[0] ) . '</a>';
			}
		}
		echo '</nav>';

		foreach ( MoshCW_Providers::models() as $p => $models ) {
			echo '<datalist id="moshcw-models-' . esc_attr( $p ) . '">';
			foreach ( $models as $m ) {
				echo '<option value="' . esc_attr( $m ) . '"></option>';
			}
			echo '</datalist>';
		}

		foreach ( self::tabs() as $k => $t ) {
			if ( ! current_user_can( $t[1] ) ) {
				continue;
			}
			echo '<section class="mx-panel" data-panel="' . esc_attr( $k ) . '"' . ( $k === $tab ? '' : ' hidden' ) . '>';
			$lock = self::locked( $k );
			if ( $lock ) {
				self::lock_card( $k, $lock );
			} else {
				call_user_func( array( __CLASS__, 'panel_' . $k ) );
			}
			echo '</section>';
		}
		echo '<div class="mx-toast" role="status" aria-live="polite" hidden></div></div>';
	}

	/* ---------- Home ---------- */

	private static function panel_home() {
		global $wpdb;
		$s         = MoshCW_Settings::all();
		$connected = self::connected();
		$logs      = MoshCW_DB::table( 'logs' );
		$req24     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $logs WHERE time > %s", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ); // phpcs:ignore
		$tok30     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(tokens_in + tokens_out) FROM $logs WHERE time > %s", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ); // phpcs:ignore
		$manage    = current_user_can( 'manage_options' );

		if ( ! $connected && $manage ) {
			echo '<div class="mx-card mx-hero"><div><h2>' . esc_html__( 'Connect your AI in 30 seconds', 'mosh-ai' ) . '</h2><p>' . esc_html__( 'Pick a provider, paste your key, done. Everything else works out of the box.', 'mosh-ai' ) . '</p></div>';
			echo '<div class="mx-connect" data-connect-box><select name="provider">';
			foreach ( MoshCW_Providers::names() as $p => $label ) {
				if ( 'custom' !== $p ) {
					echo '<option value="' . esc_attr( $p ) . '">' . esc_html( $label ) . '</option>';
				}
			}
			echo '</select>';
			echo '<input type="password" name="key" placeholder="' . esc_attr__( 'Paste your API key', 'mosh-ai' ) . '" autocomplete="new-password" spellcheck="false">';
			echo '<button type="button" class="button button-primary button-hero" data-connect>' . esc_html__( 'Connect', 'mosh-ai' ) . '</button></div>';
			MoshCW_Kses::out( '<p class="mx-help">' . esc_html__( 'OpenAI or Gemini unlock everything (chat, images, knowledge base). Gemini has a free tier. Claude and OpenRouter work for chat and writing.', 'mosh-ai' ) . ' <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">' . esc_html__( 'Get a free Gemini key', 'mosh-ai' ) . '</a> · ' . self::go( 'settings', __( 'Using DeepSeek, Groq, Mistral, Grok, Ollama or another AI? Set it up under Others', 'mosh-ai' ), '' ) . '</p></div>' );
		}

		echo '<div class="mx-grid mx-grid-4">';
		MoshCW_Kses::out( self::stat( __( 'Requests today', 'mosh-ai' ), number_format_i18n( $req24 ) ) );
		MoshCW_Kses::out( self::stat( __( 'Tokens, 30 days', 'mosh-ai' ), number_format_i18n( $tok30 ) ) );
		MoshCW_Kses::out( self::stat( __( 'Knowledge chunks', 'mosh-ai' ), number_format_i18n( MoshCW_Core::kb_count() ) ) );
		MoshCW_Kses::out( self::stat( __( 'Chatbots', 'mosh-ai' ), count( MoshCW_Chatbots::all() ) ) );
		echo '</div>';

		echo '<div class="mx-grid mx-grid-2">';
		echo '<div class="mx-card"><h2>' . esc_html__( 'Quick actions', 'mosh-ai' ) . '</h2><div class="mx-actions">';
		MoshCW_Kses::out( self::go( 'studio:article', __( 'Write an article', 'mosh-ai' ), 'mx-action' ) );
		MoshCW_Kses::out( self::go( 'studio:image', __( 'Generate an image', 'mosh-ai' ), 'mx-action' ) );
		MoshCW_Kses::out( self::go( 'studio:chat', __( 'Open the playground', 'mosh-ai' ), 'mx-action' ) );
		if ( $manage ) {
			MoshCW_Kses::out( self::go( 'chatbots', __( 'Edit chatbot', 'mosh-ai' ), 'mx-action' ) );
			MoshCW_Kses::out( self::go( 'knowledge', __( 'Sync knowledge', 'mosh-ai' ), 'mx-action' ) );
		}
		echo '</div></div>';

		if ( $manage ) {
			echo '<div class="mx-card"><h2>' . esc_html__( 'Site chatbot', 'mosh-ai' ) . '</h2>';
			echo '<div class="mx-field"><span class="mx-label">' . esc_html__( 'Floating chat bubble on every page', 'mosh-ai' ) . '</span><select name="popup_bot" data-instant data-popup-select><option value="">' . esc_html__( 'Off', 'mosh-ai' ) . '</option>';
			foreach ( MoshCW_Chatbots::all() as $id => $b ) {
				echo '<option value="' . esc_attr( $id ) . '"' . selected( $s['popup_bot'], $id, false ) . '>' . esc_html( $b['name'] ) . '</option>';
			}
			echo '</select></div>';
			echo '<p class="mx-help">' . esc_html__( 'Or place it inside a page with a shortcode (click to copy):', 'mosh-ai' ) . '</p><p><code class="moshcw-copy">[mosh_ai_chatbot id="default"]</code></p>';
			echo '<p class="mx-help">' . esc_html__( 'Works in Elementor too: drop a Shortcode widget and paste it.', 'mosh-ai' ) . '</p></div>';
		}
		echo '</div>';
	}

	/* ---------- Chatbots ---------- */

	private static function panel_chatbots() {
		echo '<div class="mx-editor" data-editor="bots">';
		echo '<aside class="mx-card mx-list-col"><div class="mx-list-head"><h2>' . esc_html__( 'Chatbots', 'mosh-ai' ) . '</h2><button type="button" class="button button-primary" data-new>+ ' . esc_html__( 'New', 'mosh-ai' ) . '</button></div><ul class="mx-list" data-list></ul></aside>';
		echo '<div class="mx-card mx-edit-col" data-fields>';
		echo '<div class="mx-edit-head"><h2 data-edit-title></h2><code class="moshcw-copy" data-shortcode></code></div><input type="hidden" name="id">';
		echo '<h3 class="mx-section">' . esc_html__( 'Basics', 'mosh-ai' ) . '</h3><div class="mx-grid mx-grid-2">';
		MoshCW_Kses::out( self::field( __( 'Name (admin only)', 'mosh-ai' ), self::text( 'name', '' ) ) );
		MoshCW_Kses::out( self::field( __( 'Window title', 'mosh-ai' ), self::text( 'title', '' ) ) );
		MoshCW_Kses::out( self::field( __( 'ID for the shortcode', 'mosh-ai' ), self::text( 'slug', '', __( 'auto from name', 'mosh-ai' ) ), '', 'mx-new-only' ) );
		MoshCW_Kses::out( self::field( __( 'Welcome message', 'mosh-ai' ), self::text( 'start', '' ), '', 'mx-span-2' ) );
		MoshCW_Kses::out( self::field( __( 'Instructions', 'mosh-ai' ), self::area( 'instructions', '', 6 ), __( 'Who the bot is and how it answers. Placeholders: {site}, {site_url}, {date}, {user_name}', 'mosh-ai' ), 'mx-span-2' ) );
		echo '</div>';
		echo '<h3 class="mx-section">' . esc_html__( 'Brain', 'mosh-ai' ) . '</h3><div class="mx-grid mx-grid-2">';
		MoshCW_Kses::out( self::field( __( 'Provider', 'mosh-ai' ), self::provider_select( 'provider', '' ) ) );
		MoshCW_Kses::out( self::field( __( 'Model', 'mosh-ai' ), self::model_input( 'model', '' ) ) );
		MoshCW_Kses::out( '<div class="mx-span-2">' . self::toggle( 'kb', 0, __( 'Answer from the knowledge base', 'mosh-ai' ), __( 'Uses your synced site content. Sync it in the Knowledge tab.', 'mosh-ai' ) ) . '</div>' );
		MoshCW_Kses::out( '<div class="mx-span-2">' . self::toggle( 'sources', 1, __( 'Show source links under answers', 'mosh-ai' ) ) . '</div>' );
		echo '</div>';
		echo '<h3 class="mx-section">' . esc_html__( 'Look and access', 'mosh-ai' ) . '</h3><div class="mx-grid mx-grid-2">';
		MoshCW_Kses::out( self::field( __( 'Color', 'mosh-ai' ), '<input type="color" name="color">' ) );
		MoshCW_Kses::out( self::field( __( 'Bubble position', 'mosh-ai' ), '<select name="position"><option value="right">' . esc_html__( 'Bottom right', 'mosh-ai' ) . '</option><option value="left">' . esc_html__( 'Bottom left', 'mosh-ai' ) . '</option></select>' ) );
		MoshCW_Kses::out( '<div class="mx-span-2">' . self::toggle( 'site_popup', 0, __( 'Show as the floating chat bubble on every page', 'mosh-ai' ) ) . '</div>' );
		MoshCW_Kses::out( '<div class="mx-span-2">' . self::toggle( 'guests', 1, __( 'Visitors who are not logged in can chat', 'mosh-ai' ) ) . '</div>' );
		echo '</div>';
		echo '<details class="mx-advanced"><summary>' . esc_html__( 'Advanced', 'mosh-ai' ) . '</summary><div class="mx-grid mx-grid-3">';
		MoshCW_Kses::out( self::field( __( 'Input placeholder', 'mosh-ai' ), self::text( 'placeholder', '' ) ) );
		MoshCW_Kses::out( self::field( __( 'Send button', 'mosh-ai' ), self::text( 'send', '' ) ) );
		MoshCW_Kses::out( self::field( __( 'Creativity (temperature)', 'mosh-ai' ), self::num( 'temperature', 0.7, 0, 2, 0.1 ) ) );
		MoshCW_Kses::out( self::field( __( 'Max answer tokens', 'mosh-ai' ), self::num( 'max_tokens', 1024, 16, 64000 ) ) );
		MoshCW_Kses::out( self::field( __( 'Messages of memory', 'mosh-ai' ), self::num( 'context', 10, 0, 50 ) ) );
		MoshCW_Kses::out( self::field( __( 'Max characters per message', 'mosh-ai' ), self::num( 'max_input', 1000, 50, 10000 ) ) );
		MoshCW_Kses::out( self::field( __( 'Knowledge chunks per answer', 'mosh-ai' ), self::num( 'kb_k', 3, 1, 10 ) ) );
		echo '</div></details>';
		echo '<div class="mx-edit-actions"><button type="button" class="button button-primary button-large" data-save>' . esc_html__( 'Save chatbot', 'mosh-ai' ) . '</button><button type="button" class="button-link button-link-delete" data-delete>' . esc_html__( 'Delete', 'mosh-ai' ) . '</button></div>';
		echo '</div>';
		echo '<aside class="mx-card mx-preview-col"><h2>' . esc_html__( 'Live test', 'mosh-ai' ) . '</h2><p class="mx-help">' . esc_html__( 'Chat with the saved version. Save to test changes.', 'mosh-ai' ) . '</p><div data-preview></div></aside>';
		echo '</div>';
	}

	/* ---------- Studio ---------- */

	private static function panel_studio() {
		$s = MoshCW_Settings::all();
		echo '<div class="mx-subtabs" data-subtabs><button type="button" data-sub="chat" class="is-active">' . esc_html__( 'Playground', 'mosh-ai' ) . '</button><button type="button" data-sub="article">' . esc_html__( 'Article writer', 'mosh-ai' ) . '</button><button type="button" data-sub="image">' . esc_html__( 'Images', 'mosh-ai' ) . '</button></div>';

		echo '<div class="mx-card moshcw-tool" data-tool="playground" data-subpanel="chat">';
		echo '<div class="mx-grid mx-grid-4">';
		MoshCW_Kses::out( self::field( __( 'Provider', 'mosh-ai' ), self::provider_select( 'provider', '' ) ) );
		MoshCW_Kses::out( self::field( __( 'Model', 'mosh-ai' ), self::model_input( 'model', '' ) ) );
		MoshCW_Kses::out( self::field( __( 'Creativity', 'mosh-ai' ), self::num( 'temperature', $s['temperature'], 0, 2, 0.1 ) ) );
		MoshCW_Kses::out( self::field( __( 'Max tokens', 'mosh-ai' ), self::num( 'max_tokens', $s['max_tokens'], 16, 64000 ) ) );
		echo '</div>';
		MoshCW_Kses::out( self::field( __( 'System instructions (optional)', 'mosh-ai' ), self::area( 'system', '', 2 ) ) );
		MoshCW_Kses::out( self::field( __( 'Prompt', 'mosh-ai' ), self::area( 'prompt', '', 7, __( 'Ask anything... (Ctrl+Enter to run)', 'mosh-ai' ) ) ) );
		echo '<div class="mx-row"><button type="button" class="button button-primary button-large" data-run>' . esc_html__( 'Run', 'mosh-ai' ) . '</button><span class="moshcw-meta"></span></div>';
		echo '<div class="moshcw-output" hidden></div></div>';

		echo '<div class="mx-card moshcw-tool" data-tool="content" data-subpanel="article" hidden>';
		MoshCW_Kses::out( self::field( __( 'Topic or working title', 'mosh-ai' ), self::text( 'topic', '', __( 'e.g. How to pick a logo color palette that converts', 'mosh-ai' ) ) ) );
		echo '<div class="mx-grid mx-grid-3">';
		MoshCW_Kses::out( self::field( __( 'Language', 'mosh-ai' ), self::text( 'language', 'English' ) ) );
		$tones = '<select name="tone">';
		foreach ( array( 'Friendly and expert', 'Professional', 'Casual', 'Bold and opinionated', 'Playful', 'Academic' ) as $t ) {
			$tones .= '<option>' . esc_html( $t ) . '</option>';
		}
		MoshCW_Kses::out( self::field( __( 'Tone', 'mosh-ai' ), $tones . '</select>' ) );
		MoshCW_Kses::out( self::field( __( 'Length (words)', 'mosh-ai' ), self::num( 'words', 1200, 200, 5000, 100 ) ) );
		MoshCW_Kses::out( self::field( __( 'Sections', 'mosh-ai' ), self::num( 'sections', 5, 2, 15 ) ) );
		MoshCW_Kses::out( self::field( __( 'Provider', 'mosh-ai' ), self::provider_select( 'provider', '' ) ) );
		MoshCW_Kses::out( self::field( __( 'Model', 'mosh-ai' ), self::model_input( 'model', '' ) ) );
		echo '</div>';
		MoshCW_Kses::out( self::field( __( 'Extra instructions (optional)', 'mosh-ai' ), self::area( 'extra', '', 2, __( 'Audience, keywords, call to action...', 'mosh-ai' ) ) ) );
		echo '<div class="mx-row"><button type="button" class="button button-primary button-large" data-run>' . esc_html__( 'Write article', 'mosh-ai' ) . '</button><span class="moshcw-meta"></span></div>';
		echo '<div class="moshcw-content-result" hidden><hr>';
		echo '<div class="mx-field"><span class="mx-label">' . esc_html__( 'Title', 'mosh-ai' ) . '</span><input type="text" data-field="title"></div>';
		echo '<div class="mx-field"><span class="mx-label">' . esc_html__( 'Excerpt', 'mosh-ai' ) . '</span><textarea data-field="excerpt" rows="2"></textarea></div>';
		echo '<div class="mx-grid mx-grid-2"><div class="mx-field"><span class="mx-label">' . esc_html__( 'HTML', 'mosh-ai' ) . '</span><textarea data-field="content" rows="18" class="code"></textarea></div><div class="mx-field"><span class="mx-label">' . esc_html__( 'Preview', 'mosh-ai' ) . '</span><div class="moshcw-preview"></div></div></div>';
		echo '<div class="mx-row"><button type="button" class="button button-primary" data-save-draft="post">' . esc_html__( 'Save as draft post', 'mosh-ai' ) . '</button><button type="button" class="button" data-save-draft="page">' . esc_html__( 'Save as draft page', 'mosh-ai' ) . '</button><span class="moshcw-saved"></span></div>';
		echo '</div><div class="moshcw-output" hidden></div></div>';

		if ( ! MoshCW_Settings::has_media_ai() ) {
			MoshCW_Kses::out( '<div class="mx-card mx-locked" data-subpanel="image" hidden><div class="mx-lock-icon"><span class="dashicons dashicons-lock"></span></div><h2>' . esc_html__( 'Images need OpenAI or Google Gemini', 'mosh-ai' ) . '</h2><p>' . esc_html__( 'Add an OpenAI key or a free Gemini key to generate images.', 'mosh-ai' ) . '</p><p>' . self::go( 'settings', __( 'Add a key', 'mosh-ai' ), 'button button-primary' ) . '</p></div>' );
		} else {
		echo '<div class="mx-card moshcw-tool" data-tool="image" data-subpanel="image" hidden>';
		MoshCW_Kses::out( self::field( __( 'Describe the image', 'mosh-ai' ), self::area( 'prompt', '', 3, __( 'Flat-lay of a designer desk with a laptop showing a logo sketch, soft daylight, minimal', 'mosh-ai' ) ) ) );
		$sizes = '<select name="size">';
		foreach ( array( '1024x1024' => __( 'Square', 'mosh-ai' ), '1536x1024' => __( 'Landscape', 'mosh-ai' ), '1024x1536' => __( 'Portrait', 'mosh-ai' ), '1792x1024' => __( 'Wide (dall-e-3)', 'mosh-ai' ), '1024x1792' => __( 'Tall (dall-e-3)', 'mosh-ai' ) ) as $v => $l ) {
			$sizes .= '<option value="' . esc_attr( $v ) . '">' . esc_html( $l . ' · ' . $v ) . '</option>';
		}
		MoshCW_Kses::out( '<div class="mx-grid mx-grid-3">' . self::field( __( 'Size', 'mosh-ai' ), $sizes . '</select>' ) );
		MoshCW_Kses::out( self::field( __( 'Model', 'mosh-ai' ), '<input type="text" name="model" list="moshcw-image-models" placeholder="' . esc_attr( $s['image_model'] ) . '">' ) . '</div>' );
		echo '<datalist id="moshcw-image-models"><option value="gpt-image-1"></option><option value="gpt-image-1-mini"></option><option value="dall-e-3"></option></datalist>';
		echo '<div class="mx-row"><button type="button" class="button button-primary button-large" data-run>' . esc_html__( 'Generate image', 'mosh-ai' ) . '</button><span class="mx-help">' . esc_html__( 'Saved to the Media Library with alt text.', 'mosh-ai' ) . '</span></div>';
		echo '<div class="moshcw-output" hidden></div><div class="moshcw-gallery"></div></div>';
		}
	}

	/* ---------- AI Forms ---------- */

	private static function panel_forms() {
		echo '<div class="mx-editor" data-editor="forms">';
		echo '<aside class="mx-card mx-list-col"><div class="mx-list-head"><h2>' . esc_html__( 'AI Forms', 'mosh-ai' ) . '</h2><button type="button" class="button button-primary" data-new>+ ' . esc_html__( 'New', 'mosh-ai' ) . '</button></div><ul class="mx-list" data-list></ul></aside>';
		echo '<div class="mx-card mx-edit-col" data-fields>';
		echo '<div class="mx-edit-head"><h2 data-edit-title></h2><code class="moshcw-copy" data-shortcode></code></div><input type="hidden" name="id">';
		echo '<div class="mx-grid mx-grid-2">';
		MoshCW_Kses::out( self::field( __( 'Name', 'mosh-ai' ), self::text( 'name', '' ) ) );
		MoshCW_Kses::out( self::field( __( 'Button label', 'mosh-ai' ), self::text( 'button', '' ) ) );
		MoshCW_Kses::out( self::field( __( 'ID for the shortcode', 'mosh-ai' ), self::text( 'slug', '', __( 'auto from name', 'mosh-ai' ) ), '', 'mx-new-only' ) );
		echo '</div>';
		MoshCW_Kses::out( self::field( __( 'Fields', 'mosh-ai' ), self::area( 'fields', '', 5, '', 'code' ), __( 'One per line: name|Label|type|option1,option2. Types: text, textarea, select, number, email, url.', 'mosh-ai' ) ) );
		MoshCW_Kses::out( self::field( __( 'Prompt template', 'mosh-ai' ), self::area( 'prompt', '', 5 ), __( 'Use {name} to insert what the visitor typed in that field.', 'mosh-ai' ) ) );
		echo '<details class="mx-advanced"><summary>' . esc_html__( 'Advanced', 'mosh-ai' ) . '</summary>';
		MoshCW_Kses::out( self::field( __( 'System instructions', 'mosh-ai' ), self::area( 'system', '', 2 ) ) );
		echo '<div class="mx-grid mx-grid-4">';
		MoshCW_Kses::out( self::field( __( 'Provider', 'mosh-ai' ), self::provider_select( 'provider', '' ) ) );
		MoshCW_Kses::out( self::field( __( 'Model', 'mosh-ai' ), self::model_input( 'model', '' ) ) );
		MoshCW_Kses::out( self::field( __( 'Creativity', 'mosh-ai' ), self::num( 'temperature', 0.8, 0, 2, 0.1 ) ) );
		MoshCW_Kses::out( self::field( __( 'Max tokens', 'mosh-ai' ), self::num( 'max_tokens', 1024, 16, 64000 ) ) );
		echo '</div></details>';
		MoshCW_Kses::out( self::toggle( 'guests', 1, __( 'Visitors who are not logged in can use it', 'mosh-ai' ) ) );
		echo '<div class="mx-edit-actions"><button type="button" class="button button-primary button-large" data-save>' . esc_html__( 'Save form', 'mosh-ai' ) . '</button><button type="button" class="button-link button-link-delete" data-delete>' . esc_html__( 'Delete', 'mosh-ai' ) . '</button></div>';
		echo '</div>';
		echo '<aside class="mx-card mx-preview-col"><h2>' . esc_html__( 'Live test', 'mosh-ai' ) . '</h2><p class="mx-help">' . esc_html__( 'Uses the saved version.', 'mosh-ai' ) . '</p><div data-preview></div></aside>';
		echo '</div>';
	}

	/* ---------- Knowledge ---------- */

	private static function panel_knowledge() {
		$s      = MoshCW_Settings::all();
		$synced = (int) get_option( 'moshcw_kb_synced' );
		echo '<div class="moshcw-tool" data-tool="kb"><div class="mx-grid mx-grid-2">';
		echo '<div class="mx-card"><h2>' . esc_html__( 'Teach the chatbot your site', 'mosh-ai' ) . '</h2><p>' . esc_html__( 'Indexes every published post, page and product so chatbots answer from your real content. Re-running replaces, never duplicates.', 'mosh-ai' ) . '</p>';
		echo '<div class="mx-row"><button type="button" class="button button-primary button-large" data-kb-sync>' . esc_html__( 'Sync all content', 'mosh-ai' ) . '</button><span class="moshcw-progress">' . ( $synced ? esc_html( sprintf( /* translators: %s time ago */ __( 'Last full sync %s ago', 'mosh-ai' ), human_time_diff( $synced ) ) ) : '' ) . '</span></div>';
		echo '<div class="mx-progressbar" hidden><span></span></div>';
		MoshCW_Kses::out( self::toggle( 'kb_autosync', $s['kb_autosync'], __( 'Keep it up to date automatically', 'mosh-ai' ), __( 'Re-indexes a post 30 seconds after you update it, removes it when trashed.', 'mosh-ai' ), 'data-instant' ) );
		echo '<h3 class="mx-section">' . esc_html__( 'Test a question', 'mosh-ai' ) . '</h3><div class="mx-row"><input type="text" data-kb-query placeholder="' . esc_attr__( 'What is your refund policy?', 'mosh-ai' ) . '"><button type="button" class="button" data-kb-search>' . esc_html__( 'Search', 'mosh-ai' ) . '</button></div><div class="moshcw-kb-hits"></div></div>';
		echo '<div class="mx-card"><h2>' . esc_html__( 'Add custom knowledge', 'mosh-ai' ) . '</h2><p>' . esc_html__( 'FAQs, policies, pricing, opening hours... anything that is not on a page.', 'mosh-ai' ) . '</p>';
		echo '<div class="mx-field"><span class="mx-label">' . esc_html__( 'Title', 'mosh-ai' ) . '</span><input type="text" data-kb-title></div>';
		echo '<div class="mx-field"><span class="mx-label">' . esc_html__( 'Content', 'mosh-ai' ) . '</span><textarea rows="8" data-kb-content></textarea></div>';
		echo '<button type="button" class="button button-primary" data-kb-add>' . esc_html__( 'Add to knowledge', 'mosh-ai' ) . '</button></div>';
		echo '</div>';
		echo '<div class="mx-card"><div class="mx-list-head"><h2>' . esc_html__( 'Indexed chunks', 'mosh-ai' ) . ' <span class="mx-badge moshcw-kb-total"></span></h2><button type="button" class="button-link button-link-delete" data-kb-clear>' . esc_html__( 'Delete everything', 'mosh-ai' ) . '</button></div>';
		echo '<table class="widefat striped mx-table"><thead><tr><th>' . esc_html__( 'Title', 'mosh-ai' ) . '</th><th>' . esc_html__( 'Preview', 'mosh-ai' ) . '</th><th>' . esc_html__( 'Source', 'mosh-ai' ) . '</th><th></th></tr></thead><tbody class="moshcw-kb-rows"><tr><td colspan="4">' . esc_html__( 'Loading...', 'mosh-ai' ) . '</td></tr></tbody></table></div>';
		echo '<div class="moshcw-output" hidden></div></div>';
	}

	/* ---------- Activity ---------- */

	private static function panel_activity() {
		global $wpdb;
		$t      = MoshCW_DB::table( 'logs' );
		$since  = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$tot    = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, SUM(tokens_in) i, SUM(tokens_out) o, SUM(units) u FROM $t WHERE time > %s", $since ), ARRAY_A ); // phpcs:ignore
		$models = $wpdb->get_results( $wpdb->prepare( "SELECT model, COUNT(*) n, SUM(tokens_in + tokens_out) tok FROM $t WHERE time > %s GROUP BY model ORDER BY n DESC LIMIT 8", $since ), ARRAY_A ); // phpcs:ignore
		$src    = $wpdb->get_results( $wpdb->prepare( "SELECT source, COUNT(*) n, SUM(tokens_in + tokens_out) tok FROM $t WHERE time > %s GROUP BY source ORDER BY n DESC", $since ), ARRAY_A ); // phpcs:ignore
		$days   = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(time) d, COUNT(*) n FROM $t WHERE time > %s GROUP BY DATE(time) ORDER BY d ASC", $since ), ARRAY_A ); // phpcs:ignore

		echo '<h2 class="mx-section">' . esc_html__( 'AI usage, last 30 days', 'mosh-ai' ) . '</h2>';

		echo '<div class="mx-grid mx-grid-4">';
		MoshCW_Kses::out( self::stat( __( 'Requests, 30 days', 'mosh-ai' ), number_format_i18n( (int) $tot['n'] ) ) );
		MoshCW_Kses::out( self::stat( __( 'Tokens in', 'mosh-ai' ), number_format_i18n( (int) $tot['i'] ) ) );
		MoshCW_Kses::out( self::stat( __( 'Tokens out', 'mosh-ai' ), number_format_i18n( (int) $tot['o'] ) ) );
		MoshCW_Kses::out( self::stat( __( 'Images and embeddings', 'mosh-ai' ), number_format_i18n( (int) $tot['u'] ) ) );
		echo '</div>';

		$max = 1;
		foreach ( (array) $days as $d ) {
			$max = max( $max, (int) $d['n'] );
		}
		echo '<div class="mx-card"><h2>' . esc_html__( 'Requests per day', 'mosh-ai' ) . '</h2><div class="mx-bars">';
		if ( ! $days ) {
			echo '<p class="mx-help">' . esc_html__( 'No usage yet.', 'mosh-ai' ) . '</p>';
		}
		foreach ( (array) $days as $d ) {
			echo '<span class="mx-bar" style="height:' . esc_attr( max( 3, round( 100 * (int) $d['n'] / $max ) ) ) . '%" title="' . esc_attr( $d['d'] . ': ' . $d['n'] ) . '"></span>';
		}
		echo '</div></div>';

		echo '<div class="mx-grid mx-grid-2">';
		foreach ( array( __( 'By feature', 'mosh-ai' ) => array( $src, 'source' ), __( 'By model', 'mosh-ai' ) => array( $models, 'model' ) ) as $title => $data ) {
			echo '<div class="mx-card"><h2>' . esc_html( $title ) . '</h2><table class="widefat striped mx-table"><tbody>';
			if ( ! $data[0] ) {
				echo '<tr><td>' . esc_html__( 'No usage yet.', 'mosh-ai' ) . '</td></tr>';
			}
			foreach ( (array) $data[0] as $r ) {
				echo '<tr><td><strong>' . esc_html( $r[ $data[1] ] ) . '</strong></td><td>' . esc_html( number_format_i18n( (int) $r['n'] ) ) . ' ' . esc_html__( 'requests', 'mosh-ai' ) . '</td><td>' . esc_html( number_format_i18n( (int) $r['tok'] ) ) . ' ' . esc_html__( 'tokens', 'mosh-ai' ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</div>';

		$dt    = MoshCW_DB::table( 'discussions' );
		$rows  = $wpdb->get_results( "SELECT * FROM $dt ORDER BY updated DESC LIMIT 40", ARRAY_A ); // phpcs:ignore
		$bots  = MoshCW_Chatbots::all();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $dt" ); // phpcs:ignore
		echo '<div class="mx-card"><div class="mx-list-head"><h2>' . esc_html__( 'Chat conversations', 'mosh-ai' ) . ' <span class="mx-badge">' . esc_html( number_format_i18n( $count ) ) . '</span></h2><span><button type="button" class="button-link button-link-delete" data-clear="discussions">' . esc_html__( 'Delete conversations', 'mosh-ai' ) . '</button> · <button type="button" class="button-link button-link-delete" data-clear="logs">' . esc_html__( 'Reset usage log', 'mosh-ai' ) . '</button></span></div>';
		if ( ! $rows ) {
			echo '<p class="mx-help">' . esc_html__( 'No conversations yet.', 'mosh-ai' ) . '</p>';
		}
		foreach ( (array) $rows as $r ) {
			$msgs = (array) json_decode( $r['messages'], true );
			$user = $r['user_id'] ? get_userdata( (int) $r['user_id'] ) : null;
			echo '<details class="mx-convo"><summary><span class="mx-convo-title">' . esc_html( $r['title'] ) . '</span><span class="mx-convo-meta">' . esc_html( ( isset( $bots[ $r['bot_id'] ] ) ? $bots[ $r['bot_id'] ]['name'] : $r['bot_id'] ) . ' · ' . ( $user ? $user->display_name : __( 'Guest', 'mosh-ai' ) ) . ' · ' . human_time_diff( strtotime( $r['updated'] . ' UTC' ) ) . ' · ' . count( $msgs ) . ' msgs' ) . '</span></summary><div class="mx-thread">';
			foreach ( $msgs as $m ) {
				$role = isset( $m['role'] ) && 'assistant' === $m['role'] ? 'assistant' : 'user';
				echo '<div class="mx-dmsg mx-dmsg-' . esc_attr( $role ) . '">' . nl2br( esc_html( isset( $m['content'] ) ? $m['content'] : '' ) ) . '</div>';
			}
			echo '</div></details>';
		}
		echo '</div>';
	}

	/* ---------- Settings ---------- */

	private static function panel_settings() {
		$s     = MoshCW_Settings::all();
		$keys  = MoshCW_Settings::key_status();
		$names = MoshCW_Providers::names();
		$info  = array(
			'openai'     => array( 'https://platform.openai.com/api-keys', __( 'Chat, images, knowledge base and moderation.', 'mosh-ai' ) ),
			'anthropic'  => array( 'https://console.anthropic.com/settings/keys', __( 'Claude models for chat and writing.', 'mosh-ai' ) ),
			'google'     => array( 'https://aistudio.google.com/app/apikey', __( 'Gemini models, generous free tier.', 'mosh-ai' ) ),
			'openrouter' => array( 'https://openrouter.ai/keys', __( 'One key, hundreds of models.', 'mosh-ai' ) ),
		);

		echo '<div class="mx-settings" data-settings>';
		echo '<nav class="mx-side"><a href="#mx-s-providers">' . esc_html__( 'AI providers', 'mosh-ai' ) . '</a><a href="#mx-s-defaults">' . esc_html__( 'Defaults', 'mosh-ai' ) . '</a><a href="#mx-s-limits">' . esc_html__( 'Limits and safety', 'mosh-ai' ) . '</a><a href="#mx-s-editor">' . esc_html__( 'Editor and SEO', 'mosh-ai' ) . '</a><a href="#mx-s-data">' . esc_html__( 'Data', 'mosh-ai' ) . '</a></nav>';
		echo '<div class="mx-settings-body">';

		echo '<div class="mx-card" id="mx-s-providers"><h2>' . esc_html__( 'AI providers', 'mosh-ai' ) . '</h2><p class="mx-help">' . esc_html__( 'Paste a key and hit Test. Keys are never shown again in full.', 'mosh-ai' ) . '</p><div class="mx-grid mx-grid-2">';
		foreach ( $names as $p => $label ) {
			if ( 'custom' === $p ) {
				continue;
			}
			$has = '' !== $keys[ $p ]['mask'];
			echo '<div class="mx-key" data-provider="' . esc_attr( $p ) . '"><div class="mx-key-head"><strong>' . esc_html( $label ) . '</strong><span class="mx-badge ' . ( $has ? 'is-on' : 'is-off' ) . '" data-key-status>' . ( $has ? esc_html__( 'Connected', 'mosh-ai' ) : esc_html__( 'Not set', 'mosh-ai' ) ) . '</span></div>';
			if ( $keys[ $p ]['config'] ) {
				echo '<p class="mx-help">' . esc_html__( 'Set in wp-config.php', 'mosh-ai' ) . ' (MOSHCW_' . esc_html( strtoupper( $p ) ) . '_KEY)</p><div class="mx-row"><button type="button" class="button" data-test-key="' . esc_attr( $p ) . '">' . esc_html__( 'Test', 'mosh-ai' ) . '</button></div>';
			} else {
				echo '<div class="mx-row"><input type="password" name="' . esc_attr( $p ) . '_key" data-key-input placeholder="' . esc_attr( $has ? $keys[ $p ]['mask'] : __( 'Paste your key', 'mosh-ai' ) ) . '" autocomplete="new-password" spellcheck="false"><button type="button" class="button" data-test-key="' . esc_attr( $p ) . '">' . esc_html__( 'Test', 'mosh-ai' ) . '</button></div>';
				echo '<button type="button" class="button-link button-link-delete mx-remove-key" data-remove-key="' . esc_attr( $p ) . '"' . ( $has ? '' : ' hidden' ) . '>' . esc_html__( 'Remove key', 'mosh-ai' ) . '</button>';
			}
			echo '<p class="mx-help">' . esc_html( $info[ $p ][1] ) . ' <a href="' . esc_url( $info[ $p ][0] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Get a key', 'mosh-ai' ) . '</a></p></div>';
		}
		$on = MoshCW_Settings::is_connected( 'custom' );
		echo '<div class="mx-key mx-key-wide" data-provider="custom"><div class="mx-key-head"><strong>' . esc_html__( 'Others', 'mosh-ai' ) . ( $on ? ' · ' . esc_html( MoshCW_Providers::custom_label() ) : '' ) . '</strong><span class="mx-badge ' . ( $on ? 'is-on' : 'is-off' ) . '" data-key-status>' . ( $on ? esc_html__( 'Connected', 'mosh-ai' ) : esc_html__( 'Not set', 'mosh-ai' ) ) . '</span></div>';
		echo '<p class="mx-help">' . esc_html__( 'Any AI with an OpenAI-compatible API: DeepSeek, Groq, Mistral, xAI Grok, Together, Perplexity, Qwen, or your own Ollama / LM Studio server.', 'mosh-ai' ) . '</p>';
		echo '<div class="mx-grid mx-grid-4">';
		$opts = '<select data-custom-preset><option value="">' . esc_html__( 'Pick a preset…', 'mosh-ai' ) . '</option>';
		foreach ( MoshCW_Providers::custom_presets() as $k => $pr ) {
			$opts .= '<option value="' . esc_attr( $k ) . '" data-name="' . esc_attr( $pr[0] ) . '" data-url="' . esc_attr( $pr[1] ) . '" data-model="' . esc_attr( $pr[2] ) . '">' . esc_html( $pr[0] ) . '</option>';
		}
		MoshCW_Kses::out( self::field( __( 'Preset', 'mosh-ai' ), $opts . '</select>' ) );
		MoshCW_Kses::out( self::field( __( 'Name', 'mosh-ai' ), self::text( 'custom_name', $s['custom_name'], 'DeepSeek' ) ) );
		MoshCW_Kses::out( self::field( __( 'API URL', 'mosh-ai' ), self::text( 'custom_url', $s['custom_url'], 'https://api.example.com/v1' ) ) );
		MoshCW_Kses::out( self::field( __( 'Model', 'mosh-ai' ), '<input type="text" name="custom_model" value="' . esc_attr( $s['custom_model'] ) . '" list="moshcw-models-custom" placeholder="model-name">' ) );
		echo '</div><div class="mx-row"><input type="password" name="custom_key" data-key-input placeholder="' . esc_attr( '' !== $keys['custom']['mask'] ? $keys['custom']['mask'] : __( 'API key (leave empty for Ollama / LM Studio)', 'mosh-ai' ) ) . '" autocomplete="new-password" spellcheck="false"><button type="button" class="button" data-test-key="custom">' . esc_html__( 'Test and save', 'mosh-ai' ) . '</button></div>';
		echo '<button type="button" class="button-link button-link-delete mx-remove-key" data-remove-key="custom"' . ( $on ? '' : ' hidden' ) . '>' . esc_html__( 'Disconnect', 'mosh-ai' ) . '</button></div>';
		echo '</div></div>';

		echo '<div class="mx-card" id="mx-s-defaults"><h2>' . esc_html__( 'Defaults', 'mosh-ai' ) . '</h2><p class="mx-help">' . esc_html__( 'Used everywhere unless a chatbot or form picks its own.', 'mosh-ai' ) . '</p><div class="mx-grid mx-grid-2">';
		MoshCW_Kses::out( self::field( __( 'Provider', 'mosh-ai' ), self::provider_select( 'default_provider', $s['default_provider'], false ) ) );
		MoshCW_Kses::out( self::field( __( 'Model', 'mosh-ai' ), self::model_input( 'default_model', $s['default_model'], $s['default_provider'] ), __( 'Test a key to load its full model list.', 'mosh-ai' ) ) );
		MoshCW_Kses::out( self::field( __( 'Creativity (temperature)', 'mosh-ai' ), self::num( 'temperature', $s['temperature'], 0, 2, 0.1 ), __( '0 = precise, 1 = creative.', 'mosh-ai' ) ) );
		MoshCW_Kses::out( self::field( __( 'Max answer tokens', 'mosh-ai' ), self::num( 'max_tokens', $s['max_tokens'], 16, 64000 ) ) );
		MoshCW_Kses::out( self::field( __( 'Image model', 'mosh-ai' ), '<input type="text" name="image_model" value="' . esc_attr( $s['image_model'] ) . '" list="moshcw-image-models">' ) );
		MoshCW_Kses::out( self::field( __( 'Embedding model', 'mosh-ai' ), '<input type="text" name="embedding_model" value="' . esc_attr( $s['embedding_model'] ) . '" list="moshcw-emb-models"><datalist id="moshcw-emb-models"><option value="text-embedding-3-small"></option><option value="text-embedding-3-large"></option></datalist>', __( 'Changing it means re-syncing knowledge.', 'mosh-ai' ) ) );
		echo '</div></div>';

		echo '<div class="mx-card" id="mx-s-limits"><h2>' . esc_html__( 'Limits and safety', 'mosh-ai' ) . '</h2><p class="mx-help">' . esc_html__( 'Protects your API bill from abuse. Admins are never limited.', 'mosh-ai' ) . '</p>';
		MoshCW_Kses::out( self::toggle( 'limits_enabled', $s['limits_enabled'], __( 'Daily limits for chatbots and forms', 'mosh-ai' ) ) );
		echo '<div class="mx-grid mx-grid-3">';
		MoshCW_Kses::out( self::field( __( 'Per visitor, 24h', 'mosh-ai' ), self::num( 'limit_guest', $s['limit_guest'], 0, 100000 ) ) );
		MoshCW_Kses::out( self::field( __( 'Per logged-in user, 24h', 'mosh-ai' ), self::num( 'limit_user', $s['limit_user'], 0, 100000 ) ) );
		MoshCW_Kses::out( self::field( __( 'Per minute (burst)', 'mosh-ai' ), self::num( 'rate_per_minute', $s['rate_per_minute'], 0, 1000 ), __( '0 = off', 'mosh-ai' ) ) );
		echo '</div>';
		MoshCW_Kses::out( self::field( __( 'Message when the limit is hit', 'mosh-ai' ), self::text( 'limit_message', $s['limit_message'] ) ) );
		MoshCW_Kses::out( self::toggle( 'moderation', $s['moderation'], __( 'Block abusive messages', 'mosh-ai' ), __( 'Free OpenAI moderation, needs an OpenAI key.', 'mosh-ai' ) ) );
		MoshCW_Kses::out( self::toggle( 'log_discussions', $s['log_discussions'], __( 'Save chat conversations', 'mosh-ai' ), __( 'Mention it in your privacy policy.', 'mosh-ai' ) ) );
		echo '</div>';

		echo '<div class="mx-card" id="mx-s-editor"><h2>' . esc_html__( 'Editor and SEO', 'mosh-ai' ) . '</h2>';
		MoshCW_Kses::out( self::toggle( 'editor_assistant', $s['editor_assistant'], __( 'AI Assistant box on posts, pages and products', 'mosh-ai' ), __( 'Titles, excerpts, SEO descriptions, tags, full content, product copy.', 'mosh-ai' ) ) );
		MoshCW_Kses::out( self::toggle( 'meta_description', $s['meta_description'], __( 'Meta description field', 'mosh-ai' ), __( 'Printed in the page head. Skipped automatically when Yoast, Rank Math, AIOSEO or SEOPress is active.', 'mosh-ai' ) ) );
		echo '</div>';

		echo '<div class="mx-card" id="mx-s-data"><h2>' . esc_html__( 'Data', 'mosh-ai' ) . '</h2><div class="mx-grid mx-grid-2">';
		MoshCW_Kses::out( self::field( __( 'Keep logs and conversations for (days)', 'mosh-ai' ), self::num( 'retention_days', $s['retention_days'], 0, 3650 ), __( 'Older entries are deleted daily. 0 = keep forever.', 'mosh-ai' ) ) );
		echo '</div>';
		MoshCW_Kses::out( self::toggle( 'clean_uninstall', $s['clean_uninstall'], __( 'Delete all Mosh AI data when the plugin is deleted', 'mosh-ai' ) ) );
		echo '</div>';

		echo '</div>';
		echo '<div class="mx-savebar" data-savebar hidden><span>' . esc_html__( 'You have unsaved changes', 'mosh-ai' ) . '</span><button type="button" class="button" data-discard>' . esc_html__( 'Discard', 'mosh-ai' ) . '</button><button type="button" class="button button-primary" data-save-settings>' . esc_html__( 'Save settings', 'mosh-ai' ) . '</button></div>';
		echo '</div>';
	}
}
