<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MoshCW_Settings {

	const KEY = 'moshcw_settings';

	private static $cache = null;

	public static function defaults() {
		return array(
			'openai_key'       => '',
			'anthropic_key'    => '',
			'google_key'       => '',
			'openrouter_key'   => '',
			'default_provider' => 'openai',
			'default_model'    => 'gpt-4o-mini',
			'temperature'      => 0.7,
			'max_tokens'       => 1024,
			'image_model'      => 'gpt-image-1',
			'embedding_model'  => 'text-embedding-3-small',
			'moderation'       => 0,
			'log_discussions'  => 1,
			'limits_enabled'   => 1,
			'limit_guest'      => 30,
			'limit_user'       => 150,
			'rate_per_minute'  => 10,
			'limit_message'    => 'You have reached the usage limit for today. Please try again later.',
			'popup_bot'        => '',
			'editor_assistant' => 1,
			'meta_description' => 1,
			'clean_uninstall'  => 0,
			'kb_autosync'      => 1,
			'retention_days'   => 90,
			// "Others": any OpenAI-compatible API (DeepSeek, Groq, Mistral, xAI, Together, Ollama, LM Studio...).
			'custom_name'      => '',
			'custom_url'       => '',
			'custom_key'       => '',
			'custom_model'     => '',
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			self::$cache = wp_parse_args( (array) get_option( self::KEY, array() ), self::defaults() );
		}
		return self::$cache;
	}

	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	public static function update( $values ) {
		$all = array_merge( self::all(), (array) $values );
		update_option( self::KEY, $all, false );
		self::$cache = $all;
	}

	public static function toggles() {
		return array( 'moderation', 'log_discussions', 'limits_enabled', 'editor_assistant', 'meta_description', 'clean_uninstall', 'kb_autosync' );
	}

	/**
	 * Sanitizes a partial settings array. Only keys that are present are returned.
	 */
	public static function sanitize( $p ) {
		$d = self::defaults();
		$v = array();
		foreach ( (array) $p as $k => $val ) {
			if ( ! array_key_exists( $k, $d ) || is_array( $val ) ) {
				continue;
			}
			if ( in_array( $k, self::toggles(), true ) ) {
				$v[ $k ] = ( empty( $val ) || 'false' === $val ) ? 0 : 1;
				continue;
			}
			switch ( $k ) {
				case 'default_provider':
					$v[ $k ] = array_key_exists( $val, MoshCW_Providers::names() ) ? $val : 'openai';
					break;
				case 'popup_bot':
					$val     = sanitize_key( $val );
					$v[ $k ] = ( '' !== $val && MoshCW_Chatbots::get( $val ) ) ? $val : '';
					break;
				case 'temperature':
					$v[ $k ] = max( 0, min( 2, (float) $val ) );
					break;
				case 'max_tokens':
					$v[ $k ] = max( 16, min( 64000, (int) $val ) );
					break;
				case 'retention_days':
					$v[ $k ] = max( 0, min( 3650, (int) $val ) );
					break;
				case 'custom_url':
					$url     = esc_url_raw( trim( (string) $val ), array( 'http', 'https' ) );
					$url     = preg_replace( '#/(chat/completions|completions|models)/?$#', '', untrailingslashit( $url ) );
					$v[ $k ] = (string) $url;
					break;
				default:
					$v[ $k ] = is_int( $d[ $k ] ) ? max( 0, (int) $val ) : trim( sanitize_text_field( (string) $val ) );
			}
		}
		foreach ( array( 'image_model', 'embedding_model', 'default_model' ) as $k ) {
			if ( isset( $v[ $k ] ) && '' === $v[ $k ] ) {
				unset( $v[ $k ] );
			}
		}
		return $v;
	}

	public static function mask( $key ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return '';
		}
		return strlen( $key ) > 12 ? substr( $key, 0, 4 ) . '••••••' . substr( $key, -4 ) : '••••••';
	}

	/**
	 * Masked key per provider ('' when missing). Used by the admin UI.
	 */
	public static function key_status() {
		$out = array();
		foreach ( array_keys( MoshCW_Providers::names() ) as $p ) {
			$const     = 'MOSHCW_' . strtoupper( $p ) . '_KEY';
			$out[ $p ] = array(
				'mask'      => self::mask( self::key_for( $p ) ),
				'config'    => defined( $const ) && constant( $const ),
				'connected' => self::is_connected( $p ),
			);
		}
		$out['custom']['url'] = (string) self::get( 'custom_url', '' );
		return $out;
	}

	/** A provider counts as connected when it has a key ("Others" only needs a URL, e.g. local Ollama). */
	public static function is_connected( $provider ) {
		if ( 'custom' === $provider ) {
			return '' !== (string) self::get( 'custom_url', '' );
		}
		return '' !== self::key_for( $provider );
	}

	/** Connected providers as id => label. */
	public static function connected() {
		$out = array();
		foreach ( MoshCW_Providers::names() as $p => $label ) {
			if ( self::is_connected( $p ) ) {
				$out[ $p ] = $label;
			}
		}
		return $out;
	}

	/** True when at least one AI is connected. Every AI feature is locked until then. */
	public static function has_ai() {
		return (bool) self::connected();
	}

	/** Images and the knowledge base need OpenAI or Gemini. */
	public static function has_media_ai() {
		return self::is_connected( 'openai' ) || self::is_connected( 'google' );
	}

	/**
	 * API key for a provider. A constant in wp-config.php (e.g. MOSHCW_OPENAI_KEY) wins over the saved value.
	 */
	public static function key_for( $provider ) {
		$const = 'MOSHCW_' . strtoupper( $provider ) . '_KEY';
		if ( defined( $const ) && constant( $const ) ) {
			return (string) constant( $const );
		}
		return (string) self::get( $provider . '_key', '' );
	}
}
