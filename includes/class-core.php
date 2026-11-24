<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MoshCW_Core {

	public static function ip_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return hash( 'sha256', $ip . wp_salt( 'nonce' ) );
	}

	/**
	 * Fills provider, model, temperature and max tokens from settings when missing.
	 */
	public static function resolve( $args ) {
		$args     = wp_parse_args(
			(array) $args,
			array(
				'provider'    => '',
				'model'       => '',
				'system'      => '',
				'messages'    => array(),
				'temperature' => '',
				'max_tokens'  => 0,
			)
		);
		$provider = $args['provider'] ? sanitize_key( $args['provider'] ) : MoshCW_Settings::get( 'default_provider' );
		if ( ! array_key_exists( $provider, MoshCW_Providers::names() ) ) {
			$provider = 'openai';
		}
		$model = trim( sanitize_text_field( (string) $args['model'] ) );
		// The chosen provider has no key: use the first AI that is connected.
		if ( ! MoshCW_Settings::is_connected( $provider ) ) {
			$connected = array_keys( MoshCW_Settings::connected() );
			if ( $connected ) {
				$provider = $connected[0];
				$model    = '';
			}
		}
		if ( '' === $model ) {
			if ( MoshCW_Settings::get( 'default_provider' ) === $provider && MoshCW_Settings::get( 'default_model' ) ) {
				$model = MoshCW_Settings::get( 'default_model' );
			} elseif ( 'custom' === $provider ) {
				$model = (string) MoshCW_Settings::get( 'custom_model', '' );
			} else {
				$list  = MoshCW_Providers::models();
				$model = isset( $list[ $provider ][0] ) ? $list[ $provider ][0] : '';
			}
		}
		$args['provider']    = $provider;
		$args['model']       = $model;
		$args['temperature'] = '' === $args['temperature'] || null === $args['temperature'] ? (float) MoshCW_Settings::get( 'temperature' ) : (float) $args['temperature'];
		$args['temperature'] = max( 0, min( 2, $args['temperature'] ) );
		$args['max_tokens']  = (int) $args['max_tokens'] > 0 ? (int) $args['max_tokens'] : (int) MoshCW_Settings::get( 'max_tokens' );
		$args['max_tokens']  = max( 16, min( 64000, $args['max_tokens'] ) );
		$args['system']      = (string) $args['system'];
		return $args;
	}

	public static function run( $args, $source = 'admin', $ref = '' ) {
		$args = self::resolve( $args );
		$args = apply_filters( 'moshcw_query_args', $args, $source, $ref );
		$res  = MoshCW_Providers::chat( $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		self::log( $source, $ref, $args['provider'], $args['model'], $res['tokens_in'], $res['tokens_out'] );
		return apply_filters( 'moshcw_query_result', $res, $args, $source, $ref );
	}

	/**
	 * One-shot prompt helper.
	 */
	public static function ask( $prompt, $system = '', $source = 'admin', $extra = array() ) {
		$args = array_merge(
			(array) $extra,
			array(
				'system'   => $system,
				'messages' => array(
					array(
						'role'    => 'user',
						'content' => $prompt,
					),
				),
			)
		);
		return self::run( $args, $source );
	}

	public static function placeholders( $text ) {
		$user = wp_get_current_user();
		return strtr(
			(string) $text,
			array(
				'{site}'      => get_bloginfo( 'name' ),
				'{site_url}'  => home_url( '/' ),
				'{date}'      => wp_date( get_option( 'date_format' ) ),
				'{user_name}' => $user && $user->exists() ? $user->display_name : __( 'visitor', 'mosh-ai' ),
			)
		);
	}

	public static function check_limits() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$s    = MoshCW_Settings::all();
		$ip   = self::ip_hash();
		$rate = (int) $s['rate_per_minute'];
		if ( $rate > 0 ) {
			$rk    = 'moshcw_rate_' . substr( $ip, 0, 32 );
			$count = (int) get_transient( $rk );
			if ( $count >= $rate ) {
				return new WP_Error( 'moshcw_rate', __( 'Too many messages in a short time. Please wait a minute.', 'mosh-ai' ), array( 'status' => 429 ) );
			}
			set_transient( $rk, $count + 1, MINUTE_IN_SECONDS );
		}
		if ( empty( $s['limits_enabled'] ) ) {
			return true;
		}
		global $wpdb;
		$t     = MoshCW_DB::table( 'logs' );
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$uid   = get_current_user_id();
		if ( $uid ) {
			$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE user_id = %d AND time > %s AND source IN ('chatbot','form')", $uid, $since ) ); // phpcs:ignore
			$max  = (int) $s['limit_user'];
		} else {
			$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE user_id = 0 AND ip_hash = %s AND time > %s AND source IN ('chatbot','form')", $ip, $since ) ); // phpcs:ignore
			$max  = (int) $s['limit_guest'];
		}
		if ( $max > 0 && $used >= $max ) {
			return new WP_Error( 'moshcw_limit', $s['limit_message'], array( 'status' => 429 ) );
		}
		return true;
	}

	public static function log( $source, $ref, $provider, $model, $in, $out, $units = 0 ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore
			MoshCW_DB::table( 'logs' ),
			array(
				'time'       => current_time( 'mysql', true ),
				'user_id'    => get_current_user_id(),
				'ip_hash'    => self::ip_hash(),
				'source'     => substr( (string) $source, 0, 40 ),
				'ref'        => substr( (string) $ref, 0, 64 ),
				'provider'   => substr( (string) $provider, 0, 30 ),
				'model'      => substr( (string) $model, 0, 120 ),
				'tokens_in'  => (int) $in,
				'tokens_out' => (int) $out,
				'units'      => (int) $units,
			)
		);
	}

	public static function strip_fences( $text ) {
		$text = trim( (string) $text );
		$text = preg_replace( '/^```[a-zA-Z]*\s*/', '', $text );
		$text = preg_replace( '/\s*```$/', '', $text );
		return trim( $text );
	}

	public static function plain( $html ) {
		$html = strip_shortcodes( (string) $html );
		$html = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', '', $html );
		$html = preg_replace( '#<br\s*/?>#i', "\n", $html );
		$html = preg_replace( '#</(p|div|h[1-6]|li|tr|section|article|blockquote)>#i', "$0\n\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		return trim( preg_replace( "/\n\s*\n\s*\n+/", "\n\n", $text ) );
	}

	/* ---------- Images ---------- */

	public static function generate_image( $prompt, $size = '1024x1024', $model = '', $source = 'image' ) {
		$bytes = MoshCW_Providers::image( $prompt, $size, $model );
		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}
		$id = self::save_image( $bytes, $prompt );
		if ( ! is_wp_error( $id ) ) {
			self::log( $source, (string) $id, 'openai', $model ? $model : MoshCW_Settings::get( 'image_model' ), 0, 0, 1 );
		}
		return $id;
	}

	public static function save_image( $bytes, $prompt ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$name = sanitize_title( wp_trim_words( $prompt, 8, '' ) );
		if ( '' === $name ) {
			$name = 'ai-image';
		}
		$ext = 'png';
		if ( 0 === strpos( $bytes, "\xFF\xD8" ) ) {
			$ext = 'jpg';
		} elseif ( 'RIFF' === substr( $bytes, 0, 4 ) ) {
			$ext = 'webp';
		}
		$upload = wp_upload_bits( $name . '-' . time() . '.' . $ext, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'moshcw_upload', $upload['error'], array( 'status' => 500 ) );
		}
		$type = wp_check_filetype( $upload['file'] );
		$id   = wp_insert_attachment(
			array(
				'post_mime_type' => $type['type'],
				'post_title'     => wp_trim_words( $prompt, 12, '' ),
				'post_content'   => $prompt,
				'post_status'    => 'inherit',
			),
			$upload['file'],
			0,
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( wp_trim_words( $prompt, 20, '' ) ) );
		return $id;
	}

	/* ---------- Background jobs ---------- */

	public static function init() {
		add_action( 'moshcw_daily', array( __CLASS__, 'prune' ) );
		add_action( 'moshcw_kb_sync_post', array( __CLASS__, 'kb_sync_post' ) );
		if ( ! wp_next_scheduled( 'moshcw_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'moshcw_daily' );
		}
		if ( MoshCW_Settings::get( 'kb_autosync' ) ) {
			add_action( 'save_post', array( __CLASS__, 'queue_sync' ), 20, 2 );
			add_action( 'wp_trash_post', array( __CLASS__, 'kb_remove_post' ) );
			add_action( 'before_delete_post', array( __CLASS__, 'kb_remove_post' ) );
		}
	}

	public static function kb_types() {
		return array_values( array_intersect( apply_filters( 'moshcw_kb_post_types', array( 'post', 'page', 'product' ) ), get_post_types( array( 'public' => true ) ) ) );
	}

	/**
	 * Re-embeds a post shortly after it is saved, only if it is already in the knowledge base.
	 */
	public static function queue_sync( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! in_array( $post->post_type, self::kb_types(), true ) ) {
			return;
		}
		global $wpdb;
		$known = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . MoshCW_DB::table( 'vectors' ) . ' WHERE post_id = %d', $post_id ) ); // phpcs:ignore
		if ( ! $known && ! (int) get_option( 'moshcw_kb_synced' ) ) {
			return; // The knowledge base was never synced: do not start spending tokens on its own.
		}
		if ( ! wp_next_scheduled( 'moshcw_kb_sync_post', array( $post_id ) ) ) {
			wp_schedule_single_event( time() + 30, 'moshcw_kb_sync_post', array( $post_id ) );
		}
	}

	public static function kb_remove_post( $post_id ) {
		global $wpdb;
		$wpdb->delete( MoshCW_DB::table( 'vectors' ), array( 'post_id' => (int) $post_id ) ); // phpcs:ignore
		wp_cache_delete( 'moshcw_kb_count' );
	}

	/**
	 * Replaces the chunks of one post. Returns chunks added or WP_Error.
	 */
	public static function kb_sync_post( $post_id ) {
		$p = get_post( $post_id );
		self::kb_remove_post( $post_id );
		if ( ! $p || 'publish' !== $p->post_status || ! empty( $p->post_password ) ) {
			return 0;
		}
		$text = self::plain( $p->post_content );
		if ( $p->post_excerpt ) {
			$text = $p->post_excerpt . "\n\n" . $text;
		}
		if ( mb_strlen( $text ) < 40 ) {
			return 0;
		}
		return self::kb_add( get_the_title( $p ), 'URL: ' . get_permalink( $p ) . "\n\n" . $text, $p->ID );
	}

	public static function prune() {
		global $wpdb;
		$days = (int) MoshCW_Settings::get( 'retention_days' );
		if ( $days <= 0 ) {
			return;
		}
		$before = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MoshCW_DB::table( 'logs' ) . ' WHERE time < %s', $before ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MoshCW_DB::table( 'discussions' ) . ' WHERE updated < %s', $before ) ); // phpcs:ignore
	}

	/* ---------- Knowledge base (embeddings) ---------- */

	public static function chunk( $text, $size = 1500 ) {
		$paras  = preg_split( "/\n\s*\n/", trim( (string) $text ) );
		$chunks = array();
		$cur    = '';
		foreach ( $paras as $p ) {
			$p = trim( $p );
			if ( '' === $p ) {
				continue;
			}
			if ( '' !== $cur && mb_strlen( $cur ) + mb_strlen( $p ) + 2 > $size ) {
				$chunks[] = $cur;
				$cur      = '';
			}
			while ( mb_strlen( $p ) > $size ) {
				$chunks[] = mb_substr( $p, 0, $size );
				$p        = mb_substr( $p, $size );
			}
			$cur = '' === $cur ? $p : $cur . "\n\n" . $p;
		}
		if ( '' !== $cur ) {
			$chunks[] = $cur;
		}
		return $chunks;
	}

	/**
	 * Vectors are stored as packed float32 (about 60% smaller and much faster to read than JSON).
	 */
	public static function pack_vec( $vec ) {
		return base64_encode( pack( 'g*', ...array_map( 'floatval', $vec ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	public static function unpack_vec( $raw ) {
		if ( '' === (string) $raw ) {
			return array();
		}
		if ( '[' === $raw[0] ) {
			$v = json_decode( $raw, true );
			return is_array( $v ) ? $v : array();
		}
		$bin = base64_decode( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		return $bin ? array_values( unpack( 'g*', $bin ) ) : array();
	}

	public static function norm( $vec ) {
		$sum = 0.0;
		foreach ( $vec as $f ) {
			$sum += $f * $f;
		}
		return sqrt( $sum );
	}

	public static function kb_add( $title, $content, $post_id = 0 ) {
		global $wpdb;
		$n = 0;
		foreach ( self::chunk( $content ) as $chunk ) {
			$vec = MoshCW_Providers::embed( $title . "\n" . $chunk );
			if ( is_wp_error( $vec ) ) {
				return $vec;
			}
			$wpdb->insert( // phpcs:ignore
				MoshCW_DB::table( 'vectors' ),
				array(
					'title'     => mb_substr( (string) $title, 0, 250 ),
					'content'   => $chunk,
					'post_id'   => (int) $post_id,
					'embedding' => self::pack_vec( $vec ),
					'norm'      => self::norm( $vec ),
					'model'     => MoshCW_Providers::embed_model(),
					'created'   => current_time( 'mysql', true ),
				)
			);
			++$n;
		}
		wp_cache_delete( 'moshcw_kb_count' );
		if ( $n ) {
			self::log( 'embeddings', (string) $post_id, '' !== MoshCW_Settings::key_for( 'openai' ) ? 'openai' : 'google', MoshCW_Providers::embed_model(), 0, 0, $n );
		}
		return $n;
	}

	public static function kb_count() {
		global $wpdb;
		$count = wp_cache_get( 'moshcw_kb_count' );
		if ( false === $count ) {
			$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MoshCW_DB::table( 'vectors' ) ); // phpcs:ignore
			wp_cache_set( 'moshcw_kb_count', $count, '', 300 );
		}
		return (int) $count;
	}

	public static function kb_search( $query, $k = 3 ) {
		global $wpdb;
		if ( ! self::kb_count() ) {
			return array();
		}
		// Repeated questions reuse their embedding instead of paying for a new one.
		$ck = 'moshcw_qe_' . md5( MoshCW_Providers::embed_model() . '|' . mb_strtolower( trim( $query ) ) );
		$q  = get_transient( $ck );
		if ( ! is_array( $q ) ) {
			$q = MoshCW_Providers::embed( $query );
			if ( is_wp_error( $q ) ) {
				return array();
			}
			set_transient( $ck, $q, DAY_IN_SECONDS );
		}
		$qn = self::norm( $q );
		if ( ! $qn ) {
			return array();
		}
		$dim    = count( $q );
		$t      = MoshCW_DB::table( 'vectors' );
		// Only compare vectors made by the same model (OpenAI and Gemini vectors are not comparable).
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, title, post_id, embedding, norm FROM $t WHERE model = %s ORDER BY id DESC LIMIT 5000", MoshCW_Providers::embed_model() ), ARRAY_A ); // phpcs:ignore
		$scored = array();
		foreach ( (array) $rows as $row ) {
			$v = self::unpack_vec( $row['embedding'] );
			if ( count( $v ) !== $dim ) {
				continue;
			}
			$dot = 0.0;
			for ( $i = 0; $i < $dim; $i++ ) {
				$dot += $q[ $i ] * $v[ $i ];
			}
			$n = (float) $row['norm'] > 0 ? (float) $row['norm'] : self::norm( $v );
			if ( $n > 0 ) {
				$scored[ $row['id'] ] = $dot / ( $qn * $n );
			}
		}
		arsort( $scored );
		$top = array();
		foreach ( array_slice( $scored, 0, max( 1, (int) $k ), true ) as $id => $score ) {
			if ( $score >= 0.2 ) {
				$top[ $id ] = round( $score, 4 );
			}
		}
		if ( ! $top ) {
			return array();
		}
		// Only now load the text of the winning chunks.
		$ids  = implode( ',', array_map( 'intval', array_keys( $top ) ) );
		$full = $wpdb->get_results( "SELECT id, title, content, post_id FROM $t WHERE id IN ($ids)", ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( (array) $full as $r ) {
			$r['score']          = $top[ $r['id'] ];
			$out[ $r['id'] ]     = $r;
		}
		uasort(
			$out,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		return array_values( $out );
	}
}
