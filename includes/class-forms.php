<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MoshCW_Forms {

	const OPTION = 'moshcw_forms';

	public static function init() {
		add_shortcode( 'mosh_ai_form', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'mosh_form', array( __CLASS__, 'shortcode' ) ); // Old name, still works.
	}

	public static function defaults( $id = 'form' ) {
		return array(
			'id'          => $id,
			'name'        => 'idea-generator' === $id ? 'Blog Idea Generator' : $id,
			'fields'      => "topic|Your topic|text\naudience|Who is it for?|text\ntone|Tone|select|Friendly,Professional,Bold,Playful",
			'prompt'      => "Give me 5 blog post ideas about {topic} for {audience}. Tone: {tone}. For each idea give a catchy title and one sentence on the angle.",
			'system'      => 'You are a sharp content strategist. Be concrete and skip filler.',
			'provider'    => '',
			'model'       => '',
			'button'      => 'Generate',
			'temperature' => 0.8,
			'max_tokens'  => 1024,
			'guests'      => 1,
		);
	}

	public static function all() {
		$forms = get_option( self::OPTION, array() );
		if ( ! is_array( $forms ) ) {
			$forms = array();
		}
		foreach ( $forms as $id => $form ) {
			$forms[ $id ] = wp_parse_args( (array) $form, self::defaults( $id ) );
		}
		return $forms;
	}

	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	public static function save( $form ) {
		$all                = self::all();
		$all[ $form['id'] ] = $form;
		update_option( self::OPTION, $all, false );
	}

	public static function sanitize( $p, $id ) {
		$p    = (array) $p;
		$form = self::defaults( $id );
		foreach ( array( 'name', 'model', 'button' ) as $k ) {
			$form[ $k ] = isset( $p[ $k ] ) ? sanitize_text_field( (string) $p[ $k ] ) : $form[ $k ];
		}
		foreach ( array( 'fields', 'prompt', 'system' ) as $k ) {
			$form[ $k ] = isset( $p[ $k ] ) ? sanitize_textarea_field( (string) $p[ $k ] ) : $form[ $k ];
		}
		$form['provider']    = isset( $p['provider'] ) && array_key_exists( $p['provider'], MoshCW_Providers::names() ) ? $p['provider'] : '';
		$form['temperature'] = max( 0, min( 2, (float) ( isset( $p['temperature'] ) ? $p['temperature'] : 0.8 ) ) );
		$form['max_tokens']  = max( 16, min( 64000, (int) ( isset( $p['max_tokens'] ) ? $p['max_tokens'] : 1024 ) ) );
		$form['guests']      = empty( $p['guests'] ) ? 0 : 1;
		if ( '' === $form['name'] ) {
			$form['name'] = $id;
		}
		if ( '' === $form['button'] ) {
			$form['button'] = 'Generate';
		}
		return $form;
	}

	public static function delete( $id ) {
		$all = self::all();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * One field per line: name|Label|type|option1,option2
	 * Types: text, textarea, select, number, email, url.
	 */
	public static function parse_fields( $text ) {
		$fields = array();
		foreach ( preg_split( "/\r\n|\n|\r/", (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line ) );
			$name  = sanitize_key( $parts[0] );
			if ( '' === $name ) {
				continue;
			}
			$type     = isset( $parts[2] ) ? strtolower( $parts[2] ) : 'text';
			$fields[] = array(
				'name'    => $name,
				'label'   => isset( $parts[1] ) && '' !== $parts[1] ? $parts[1] : ucfirst( $name ),
				'type'    => in_array( $type, array( 'text', 'textarea', 'select', 'number', 'email', 'url' ), true ) ? $type : 'text',
				'options' => isset( $parts[3] ) ? array_values( array_filter( array_map( 'trim', explode( ',', $parts[3] ) ), 'strlen' ) ) : array(),
			);
		}
		return $fields;
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => '' ), $atts, 'mosh_ai_form' );
		$form = self::get( sanitize_key( $atts['id'] ) );
		if ( ! $form || ! MoshCW_Settings::has_ai() || ( empty( $form['guests'] ) && ! is_user_logged_in() ) ) {
			return '';
		}
		MoshCW_Chatbots::register_assets();
		wp_enqueue_style( 'moshcw-front' );
		wp_enqueue_script( 'moshcw-front' );

		$uid = 'moshcw-f-' . wp_rand( 1000, 999999 );
		$h   = '<form class="moshcw-form" data-form="' . esc_attr( $form['id'] ) . '">';
		foreach ( self::parse_fields( $form['fields'] ) as $f ) {
			$fid = $uid . '-' . $f['name'];
			$h  .= '<p class="moshcw-field"><label for="' . esc_attr( $fid ) . '">' . esc_html( $f['label'] ) . '</label>';
			if ( 'textarea' === $f['type'] ) {
				$h .= '<textarea id="' . esc_attr( $fid ) . '" name="' . esc_attr( $f['name'] ) . '" rows="4" required></textarea>';
			} elseif ( 'select' === $f['type'] ) {
				$h .= '<select id="' . esc_attr( $fid ) . '" name="' . esc_attr( $f['name'] ) . '">';
				foreach ( $f['options'] as $o ) {
					$h .= '<option value="' . esc_attr( $o ) . '">' . esc_html( $o ) . '</option>';
				}
				$h .= '</select>';
			} else {
				$h .= '<input id="' . esc_attr( $fid ) . '" type="' . esc_attr( $f['type'] ) . '" name="' . esc_attr( $f['name'] ) . '" required>';
			}
			$h .= '</p>';
		}
		$h .= '<p><button type="submit" class="moshcw-form-submit">' . esc_html( $form['button'] ) . '</button></p>';
		$h .= '<div class="moshcw-form-output" hidden></div></form>';
		return $h;
	}
}
