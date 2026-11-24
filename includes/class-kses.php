<?php
/**
 * Late escaping for the admin screens and the chatbot container.
 *
 * The admin UI is built from small helpers that already escape every value. Their
 * finished markup is passed through wp_kses() here with an allow list that covers
 * exactly the form controls and attributes the plugin prints.
 *
 * @package Mosh_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MoshCW_Kses {

	private static $allowed = null;

	/** Allowed tags and attributes for plugin output. */
	public static function allowed() {
		if ( null !== self::$allowed ) {
			return self::$allowed;
		}
		$names = array(
			'accept', 'action', 'alt', 'aria-checked', 'aria-controls', 'aria-current', 'aria-describedby', 'aria-expanded',
			'aria-hidden', 'aria-label', 'aria-labelledby', 'aria-live', 'aria-pressed', 'aria-selected', 'autocomplete',
			'checked', 'class', 'cols', 'colspan', 'content', 'd', 'dir', 'disabled', 'download', 'fill', 'for', 'height',
			'hidden', 'href', 'id', 'label', 'lang', 'list', 'loading', 'max', 'maxlength', 'method', 'min', 'minlength',
			'multiple', 'name', 'open', 'pattern', 'placeholder', 'readonly', 'rel', 'required', 'role', 'rows', 'rowspan',
			'scope', 'selected', 'size', 'spellcheck', 'src', 'step', 'style', 'tabindex', 'target', 'title', 'type',
			'value', 'viewbox', 'width', 'xmlns',
		);
		$attrs           = array_fill_keys( $names, true );
		$attrs['data-*'] = true;
		$tags            = array(
			'a', 'abbr', 'article', 'aside', 'b', 'blockquote', 'br', 'button', 'caption', 'code', 'datalist', 'dd',
			'details', 'div', 'dl', 'dt', 'em', 'fieldset', 'figure', 'figcaption', 'footer', 'form', 'h1', 'h2', 'h3',
			'h4', 'header', 'hr', 'i', 'img', 'input', 'kbd', 'label', 'legend', 'li', 'main', 'mark', 'nav', 'ol',
			'optgroup', 'option', 'p', 'path', 'pre', 'progress', 'section', 'select', 'small', 'span', 'strong', 'sub',
			'summary', 'sup', 'svg', 'table', 'tbody', 'td', 'textarea', 'tfoot', 'th', 'thead', 'time', 'tr', 'u', 'ul',
		);
		$out = wp_kses_allowed_html( 'post' );
		foreach ( $tags as $tag ) {
			$out[ $tag ] = ( isset( $out[ $tag ] ) && is_array( $out[ $tag ] ) ) ? array_merge( $out[ $tag ], $attrs ) : $attrs;
		}
		self::$allowed = $out;
		return $out;
	}

	/** CSS properties the plugin's inline styles use, including its CSS custom properties. */
	public static function style_props( $props ) {
		return array_merge( (array) $props, array( '--*', 'display', 'width', 'height', 'border-radius', 'background', 'background-color', 'margin', 'margin-top', 'margin-right', 'vertical-align' ) );
	}

	/** Echo plugin markup after wp_kses(). */
	public static function out( $html ) {
		add_filter( 'safe_style_css', array( __CLASS__, 'style_props' ) );
		echo wp_kses( (string) $html, self::allowed() );
		remove_filter( 'safe_style_css', array( __CLASS__, 'style_props' ) );
	}
}
