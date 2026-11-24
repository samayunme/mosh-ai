<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MoshCW_Chatbots {

	const OPTION = 'moshcw_chatbots';

	public static function init() {
		add_shortcode( 'mosh_ai_chatbot', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'mosh_chatbot', array( __CLASS__, 'shortcode' ) ); // Old name, still works.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'popup' ) );
	}

	public static function defaults( $id = 'default' ) {
		return array(
			'id'           => $id,
			'name'         => 'default' === $id ? 'Default' : $id,
			'title'        => 'AI Assistant',
			'provider'     => '',
			'model'        => '',
			'instructions' => "You are the friendly assistant of {site} ({site_url}). Answer clearly and briefly. If you don't know something, say so and suggest contacting the team.",
			'start'        => 'Hi! How can I help you today?',
			'placeholder'  => 'Type your message...',
			'send'         => 'Send',
			'temperature'  => 0.7,
			'max_tokens'   => 1024,
			'context'      => 10,
			'kb'           => 0,
			'kb_k'         => 3,
			'sources'      => 1,
			'guests'       => 1,
			'color'        => '#31B5F2',
			'position'     => 'right',
			'max_input'    => 1000,
		);
	}

	public static function all() {
		$bots = get_option( self::OPTION, array() );
		if ( empty( $bots ) || ! is_array( $bots ) ) {
			$bots = array( 'default' => self::defaults( 'default' ) );
		}
		foreach ( $bots as $id => $bot ) {
			$bots[ $id ] = wp_parse_args( (array) $bot, self::defaults( $id ) );
		}
		return $bots;
	}

	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	public static function save( $bot ) {
		$all               = self::all();
		$all[ $bot['id'] ] = $bot;
		update_option( self::OPTION, $all, false );
	}

	public static function sanitize( $p, $id ) {
		$p   = (array) $p;
		$bot = self::defaults( $id );
		foreach ( array( 'name', 'title', 'start', 'placeholder', 'send', 'model' ) as $k ) {
			$bot[ $k ] = isset( $p[ $k ] ) ? sanitize_text_field( (string) $p[ $k ] ) : $bot[ $k ];
		}
		$bot['instructions'] = isset( $p['instructions'] ) ? sanitize_textarea_field( (string) $p['instructions'] ) : $bot['instructions'];
		$bot['provider']     = isset( $p['provider'] ) && array_key_exists( $p['provider'], MoshCW_Providers::names() ) ? $p['provider'] : '';
		$bot['temperature']  = max( 0, min( 2, (float) ( isset( $p['temperature'] ) ? $p['temperature'] : 0.7 ) ) );
		$bot['max_tokens']   = max( 16, min( 64000, (int) ( isset( $p['max_tokens'] ) ? $p['max_tokens'] : 1024 ) ) );
		$bot['context']      = max( 0, min( 50, (int) ( isset( $p['context'] ) ? $p['context'] : 10 ) ) );
		$bot['max_input']    = max( 50, min( 10000, (int) ( isset( $p['max_input'] ) ? $p['max_input'] : 1000 ) ) );
		$bot['kb_k']         = max( 1, min( 10, (int) ( isset( $p['kb_k'] ) ? $p['kb_k'] : 3 ) ) );
		foreach ( array( 'kb', 'guests', 'sources' ) as $k ) {
			$bot[ $k ] = empty( $p[ $k ] ) ? 0 : 1;
		}
		$color           = sanitize_hex_color( isset( $p['color'] ) ? (string) $p['color'] : '' );
		$bot['color']    = $color ? $color : '#31B5F2';
		$bot['position'] = isset( $p['position'] ) && 'left' === $p['position'] ? 'left' : 'right';
		if ( '' === $bot['name'] ) {
			$bot['name'] = $id;
		}
		return $bot;
	}

	public static function delete( $id ) {
		$all = self::all();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
	}

	public static function register_assets() {
		if ( wp_script_is( 'moshcw-front', 'registered' ) ) {
			return;
		}
		wp_register_style( 'moshcw-front', MOSHCW_URL . 'assets/css/front.css', array(), MOSHCW_VERSION );
		wp_register_script(
			'moshcw-front',
			MOSHCW_URL . 'assets/js/front.js',
			array(),
			MOSHCW_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script(
			'moshcw-front',
			'MoshCWChat',
			array(
				'rest'  => esc_url_raw( rest_url( 'mosh-ai/v1/' ) ),
				// Only logged-in users get a nonce, so cached pages never serve a stale one to guests.
				'nonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'i18n'  => array(
					'error' => __( 'Something went wrong. Please try again.', 'mosh-ai' ),
					'reset' => __( 'New conversation', 'mosh-ai' ),
					'close' => __( 'Close', 'mosh-ai' ),
					'open'  => __( 'Open chat', 'mosh-ai' ),
					'copy'  => __( 'Copy', 'mosh-ai' ),
				),
			)
		);
	}

	/** Black or white text, whichever reads better on the bot color. */
	public static function on_color( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return '#fff';
		}
		$lum = 0;
		foreach ( array( 0.2126, 0.7152, 0.0722 ) as $i => $w ) {
			$c    = hexdec( substr( $hex, $i * 2, 2 ) ) / 255;
			$c    = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
			$lum += $w * $c;
		}
		return $lum > 0.35 ? '#000' : '#fff';
	}

	public static function render( $bot, $popup = false ) {
		self::register_assets();
		wp_enqueue_style( 'moshcw-front' );
		wp_enqueue_script( 'moshcw-front' );
		$public = array(
			'id'          => $bot['id'],
			'title'       => $bot['title'],
			'start'       => $bot['start'],
			'placeholder' => $bot['placeholder'],
			'send'        => $bot['send'],
			'max_input'   => (int) $bot['max_input'],
			'popup'       => (bool) $popup,
		);
		$class = $popup ? 'moshcw-chatbot moshcw-popup moshcw-pos-' . ( 'left' === $bot['position'] ? 'left' : 'right' ) : 'moshcw-chatbot moshcw-inline';
		return '<div class="' . esc_attr( $class ) . '" data-moshcw="' . esc_attr( wp_json_encode( $public ) ) . '" style="--moshcw-color:' . esc_attr( $bot['color'] ) . ';--moshcw-on:' . esc_attr( self::on_color( $bot['color'] ) ) . '"></div>';
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 'default' ), $atts, 'mosh_ai_chatbot' );
		$bot  = self::get( sanitize_key( $atts['id'] ) );
		if ( ! $bot || ! MoshCW_Settings::has_ai() || ( empty( $bot['guests'] ) && ! is_user_logged_in() ) ) {
			return '';
		}
		return self::render( $bot, false );
	}

	public static function popup() {
		$id = MoshCW_Settings::get( 'popup_bot' );
		if ( ! $id || is_admin() || ! MoshCW_Settings::has_ai() ) {
			return;
		}
		if ( ! apply_filters( 'moshcw_show_popup', true, $id ) ) {
			return;
		}
		$bot = self::get( $id );
		if ( ! $bot || ( empty( $bot['guests'] ) && ! is_user_logged_in() ) ) {
			return;
		}
		MoshCW_Kses::out( self::render( $bot, true ) );
	}
}
