<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MoshCW_REST {

	const NS = 'mosh-ai/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'lock' ), 10, 3 );
	}

	/**
	 * Every AI feature stays locked until an AI is connected. Images and the knowledge base
	 * need OpenAI or Gemini. Enforced here for all AI routes, not just hidden in the UI.
	 */
	public static function lock( $result, $server, $request ) {
		$route = (string) $request->get_route();
		if ( null !== $result || 0 !== strpos( $route, '/' . self::NS . '/' ) ) {
			return $result;
		}
		$r = substr( $route, strlen( self::NS ) + 1 );
		if ( preg_match( '#^/(chat|form|admin/(generate|content|editor))$#', $r ) && ! MoshCW_Settings::has_ai() ) {
			return new WP_Error( 'moshcw_locked', __( 'Connect an AI in Mosh AI > Settings to unlock this feature.', 'mosh-ai' ), array( 'status' => 423 ) );
		}
		if ( preg_match( '#^/admin/(image|kb/(add|sync|search))$#', $r ) && ! MoshCW_Settings::has_media_ai() ) {
			return new WP_Error( 'moshcw_locked', __( 'Images and the knowledge base need an OpenAI or Google Gemini key.', 'mosh-ai' ), array( 'status' => 423 ) );
		}
		return $result;
	}

	public static function can_edit() {
		return current_user_can( 'edit_posts' );
	}

	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	public static function can_upload() {
		return current_user_can( 'upload_files' );
	}

	public static function routes() {
		$routes = array(
			'/chat'              => array( 'chat', '__return_true' ),
			'/form'              => array( 'form', '__return_true' ),
			'/admin/generate'    => array( 'generate', array( __CLASS__, 'can_edit' ) ),
			'/admin/content'     => array( 'content', array( __CLASS__, 'can_edit' ) ),
			'/admin/create-post' => array( 'create_post', array( __CLASS__, 'can_edit' ) ),
			'/admin/image'       => array( 'image', array( __CLASS__, 'can_upload' ) ),
			'/admin/editor'      => array( 'editor', array( __CLASS__, 'can_edit' ) ),
			'/admin/kb/list'     => array( 'kb_list', array( __CLASS__, 'can_manage' ) ),
			'/admin/kb/add'      => array( 'kb_add', array( __CLASS__, 'can_manage' ) ),
			'/admin/kb/delete'   => array( 'kb_delete', array( __CLASS__, 'can_manage' ) ),
			'/admin/kb/sync'     => array( 'kb_sync', array( __CLASS__, 'can_manage' ) ),
			'/admin/kb/clear'    => array( 'kb_clear', array( __CLASS__, 'can_manage' ) ),
			'/admin/kb/search'   => array( 'kb_search', array( __CLASS__, 'can_manage' ) ),
			'/admin/settings'    => array( 'save_settings', array( __CLASS__, 'can_manage' ) ),
			'/admin/test-key'    => array( 'test_key', array( __CLASS__, 'can_manage' ) ),
			'/admin/bot/save'    => array( 'bot_save', array( __CLASS__, 'can_manage' ) ),
			'/admin/bot/delete'  => array( 'bot_delete', array( __CLASS__, 'can_manage' ) ),
			'/admin/form/save'   => array( 'form_save', array( __CLASS__, 'can_manage' ) ),
			'/admin/form/delete' => array( 'form_delete', array( __CLASS__, 'can_manage' ) ),
			'/admin/clear'       => array( 'clear', array( __CLASS__, 'can_manage' ) ),
		);
		foreach ( $routes as $route => $def ) {
			register_rest_route(
				self::NS,
				$route,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, $def[0] ),
					'permission_callback' => $def[1],
				)
			);
		}
	}

	private static function fail( $err, $public = false ) {
		if ( $public && ! current_user_can( 'manage_options' ) && ! in_array( $err->get_error_code(), array( 'moshcw_rate', 'moshcw_limit' ), true ) ) {
			return new WP_Error( 'moshcw_error', __( 'Sorry, the assistant is unavailable right now. Please try again shortly.', 'mosh-ai' ), array( 'status' => 500 ) );
		}
		$data = $err->get_error_data();
		if ( ! is_array( $data ) || empty( $data['status'] ) ) {
			$err->add_data( array( 'status' => 500 ) );
		}
		return $err;
	}

	private static function str( $r, $key, $max = 20000 ) {
		return mb_substr( sanitize_textarea_field( (string) $r->get_param( $key ) ), 0, $max );
	}

	/* ---------- Public: chatbot ---------- */

	public static function chat( WP_REST_Request $r ) {
		$bot = MoshCW_Chatbots::get( sanitize_key( (string) $r->get_param( 'bot_id' ) ) );
		if ( ! $bot ) {
			return new WP_Error( 'moshcw_bot', __( 'Chatbot not found.', 'mosh-ai' ), array( 'status' => 404 ) );
		}
		if ( empty( $bot['guests'] ) && ! is_user_logged_in() ) {
			return new WP_Error( 'moshcw_login', __( 'Please log in to use this chatbot.', 'mosh-ai' ), array( 'status' => 403 ) );
		}
		$ok = MoshCW_Core::check_limits();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$message = trim( self::str( $r, 'message', max( 50, (int) $bot['max_input'] ) ) );
		if ( '' === $message ) {
			return new WP_Error( 'moshcw_empty', __( 'Empty message.', 'mosh-ai' ), array( 'status' => 400 ) );
		}

		$history = array();
		$ctx     = max( 0, min( 50, (int) $bot['context'] ) );
		$raw     = (array) $r->get_param( 'history' );
		$raw     = $ctx > 0 ? array_slice( $raw, -$ctx ) : array();
		foreach ( $raw as $m ) {
			if ( ! is_array( $m ) || empty( $m['content'] ) ) {
				continue;
			}
			$history[] = array(
				'role'    => ( isset( $m['role'] ) && 'assistant' === $m['role'] ) ? 'assistant' : 'user',
				'content' => mb_substr( sanitize_textarea_field( (string) $m['content'] ), 0, 4000 ),
			);
		}

		// Keep the history under ~12k characters so long chats stay cheap and fast.
		$chars = 0;
		foreach ( $history as $m ) {
			$chars += mb_strlen( $m['content'] );
		}
		while ( $chars > 12000 && $history ) {
			$first  = array_shift( $history );
			$chars -= mb_strlen( $first['content'] );
		}

		if ( MoshCW_Settings::get( 'moderation' ) && MoshCW_Providers::flagged( $message ) ) {
			return array( 'reply' => __( "Sorry, I can't help with that.", 'mosh-ai' ) );
		}

		$sources = array();
		$system = MoshCW_Core::placeholders( $bot['instructions'] );
		if ( ! empty( $bot['kb'] ) ) {
			$hits = MoshCW_Core::kb_search( $message, (int) $bot['kb_k'] );
			if ( $hits ) {
				$system .= "\n\nUse the following information from the website to answer when it is relevant. Do not invent facts beyond it.\n";
				foreach ( $hits as $h ) {
					$system .= "\n### " . $h['title'] . "\n" . $h['content'] . "\n";
					$pid     = (int) $h['post_id'];
					if ( ! empty( $bot['sources'] ) && $pid && ! isset( $sources[ $pid ] ) && 'publish' === get_post_status( $pid ) ) {
						$sources[ $pid ] = array(
							'title' => get_the_title( $pid ),
							'url'   => get_permalink( $pid ),
						);
					}
				}
			}
		}
		$history[] = array(
			'role'    => 'user',
			'content' => $message,
		);

		$res = MoshCW_Core::run(
			array(
				'provider'    => $bot['provider'],
				'model'       => $bot['model'],
				'system'      => $system,
				'messages'    => $history,
				'temperature' => $bot['temperature'],
				'max_tokens'  => $bot['max_tokens'],
			),
			'chatbot',
			$bot['id']
		);
		if ( is_wp_error( $res ) ) {
			return self::fail( $res, true );
		}

		if ( MoshCW_Settings::get( 'log_discussions' ) ) {
			self::save_discussion( $bot['id'], (string) $r->get_param( 'chat_id' ), $message, $res['text'] );
		}
		return array(
			'reply'   => $res['text'],
			'sources' => array_slice( array_values( $sources ), 0, 3 ),
		);
	}

	private static function save_discussion( $bot_id, $chat_id, $question, $answer ) {
		global $wpdb;
		$chat_id = substr( preg_replace( '/[^a-zA-Z0-9\-]/', '', $chat_id ), 0, 64 );
		if ( strlen( $chat_id ) < 8 ) {
			return;
		}
		$t        = MoshCW_DB::table( 'discussions' );
		$ip       = MoshCW_Core::ip_hash();
		$now      = current_time( 'mysql', true );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, ip_hash, messages FROM $t WHERE chat_id = %s", $chat_id ), ARRAY_A ); // phpcs:ignore
		if ( $existing && $existing['ip_hash'] !== $ip ) {
			$chat_id  = substr( $chat_id, 0, 40 ) . '-' . substr( $ip, 0, 8 );
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, ip_hash, messages FROM $t WHERE chat_id = %s", $chat_id ), ARRAY_A ); // phpcs:ignore
		}
		$msgs   = $existing ? (array) json_decode( $existing['messages'], true ) : array();
		$msgs[] = array(
			'role'    => 'user',
			'content' => $question,
			'time'    => $now,
		);
		$msgs[] = array(
			'role'    => 'assistant',
			'content' => $answer,
			'time'    => $now,
		);
		if ( $existing ) {
			$wpdb->update( $t, array( 'messages' => wp_json_encode( $msgs ), 'updated' => $now ), array( 'id' => $existing['id'] ) ); // phpcs:ignore
		} else {
			$wpdb->insert( // phpcs:ignore
				$t,
				array(
					'chat_id'  => $chat_id,
					'bot_id'   => $bot_id,
					'user_id'  => get_current_user_id(),
					'ip_hash'  => $ip,
					'title'    => mb_substr( $question, 0, 120 ),
					'messages' => wp_json_encode( $msgs ),
					'created'  => $now,
					'updated'  => $now,
				)
			);
		}
	}

	/* ---------- Public: AI forms ---------- */

	public static function form( WP_REST_Request $r ) {
		$form = MoshCW_Forms::get( sanitize_key( (string) $r->get_param( 'form_id' ) ) );
		if ( ! $form ) {
			return new WP_Error( 'moshcw_form', __( 'Form not found.', 'mosh-ai' ), array( 'status' => 404 ) );
		}
		if ( empty( $form['guests'] ) && ! is_user_logged_in() ) {
			return new WP_Error( 'moshcw_login', __( 'Please log in to use this form.', 'mosh-ai' ), array( 'status' => 403 ) );
		}
		$ok = MoshCW_Core::check_limits();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$values = (array) $r->get_param( 'fields' );
		$prompt = $form['prompt'];
		foreach ( MoshCW_Forms::parse_fields( $form['fields'] ) as $f ) {
			$v = isset( $values[ $f['name'] ] ) ? mb_substr( sanitize_textarea_field( (string) $values[ $f['name'] ] ), 0, 2000 ) : '';
			if ( 'select' === $f['type'] && $f['options'] && ! in_array( $v, $f['options'], true ) ) {
				$v = $f['options'][0];
			}
			$prompt = str_replace( '{' . $f['name'] . '}', $v, $prompt );
		}
		if ( MoshCW_Settings::get( 'moderation' ) && MoshCW_Providers::flagged( $prompt ) ) {
			return array( 'reply' => __( "Sorry, I can't help with that.", 'mosh-ai' ) );
		}
		$res = MoshCW_Core::run(
			array(
				'provider'    => $form['provider'],
				'model'       => $form['model'],
				'system'      => MoshCW_Core::placeholders( $form['system'] ),
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => $prompt,
					),
				),
				'temperature' => $form['temperature'],
				'max_tokens'  => $form['max_tokens'],
			),
			'form',
			$form['id']
		);
		return is_wp_error( $res ) ? self::fail( $res, true ) : array( 'reply' => $res['text'] );
	}

	/* ---------- Admin: playground ---------- */

	public static function generate( WP_REST_Request $r ) {
		$prompt = self::str( $r, 'prompt', 60000 );
		if ( '' === trim( $prompt ) ) {
			return new WP_Error( 'moshcw_empty', __( 'Write a prompt first.', 'mosh-ai' ), array( 'status' => 400 ) );
		}
		$res = MoshCW_Core::ask(
			$prompt,
			self::str( $r, 'system', 20000 ),
			'playground',
			array(
				'provider'    => (string) $r->get_param( 'provider' ),
				'model'       => (string) $r->get_param( 'model' ),
				'temperature' => $r->get_param( 'temperature' ),
				'max_tokens'  => (int) $r->get_param( 'max_tokens' ),
			)
		);
		return is_wp_error( $res ) ? self::fail( $res ) : $res;
	}

	/* ---------- Admin: content generator ---------- */

	public static function content( WP_REST_Request $r ) {
		$topic = self::str( $r, 'topic', 500 );
		if ( '' === trim( $topic ) ) {
			return new WP_Error( 'moshcw_empty', __( 'Give the article a topic.', 'mosh-ai' ), array( 'status' => 400 ) );
		}
		$lang     = self::str( $r, 'language', 60 );
		$tone     = self::str( $r, 'tone', 60 );
		$words    = max( 200, min( 5000, (int) $r->get_param( 'words' ) ) );
		$sections = max( 2, min( 15, (int) $r->get_param( 'sections' ) ) );
		$extra    = self::str( $r, 'extra', 3000 );

		$prompt  = "Write a complete blog article about: \"$topic\".\n";
		$prompt .= 'Language: ' . ( $lang ? $lang : 'English' ) . ".\nTone: " . ( $tone ? $tone : 'Friendly and expert' ) . ".\n";
		$prompt .= "Length: about $words words, organised in $sections sections with <h2> headings (use <h3> for sub-points if useful).\n";
		if ( $extra ) {
			$prompt .= "Extra instructions: $extra\n";
		}
		$prompt .= "\nReturn exactly this format and nothing else:\nTITLE: <article title>\nEXCERPT: <one sentence summary, max 30 words>\nCONTENT:\n<the article as clean HTML using only <h2>, <h3>, <p>, <ul>, <ol>, <li>, <strong>, <em>, <blockquote>. No <h1>, no markdown, no code fences.>";

		$res = MoshCW_Core::ask(
			$prompt,
			'You are an expert copywriter and SEO editor. You write human, specific, useful content with no filler.',
			'content',
			array(
				'provider'   => (string) $r->get_param( 'provider' ),
				'model'      => (string) $r->get_param( 'model' ),
				'max_tokens' => (int) max( 2048, $words * 2.5 ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return self::fail( $res );
		}
		$text    = $res['text'];
		$title   = '';
		$excerpt = '';
		$content = $text;
		if ( preg_match( '/TITLE:\s*(.+)/i', $text, $m ) ) {
			$title = trim( wp_strip_all_tags( $m[1] ), " \t\"*#" );
		}
		if ( preg_match( '/EXCERPT:\s*(.+)/i', $text, $m ) ) {
			$excerpt = trim( wp_strip_all_tags( $m[1] ) );
		}
		if ( preg_match( '/CONTENT:\s*(.*)$/is', $text, $m ) ) {
			$content = $m[1];
		}
		return array(
			'title'      => $title,
			'excerpt'    => $excerpt,
			'content'    => wp_kses_post( MoshCW_Core::strip_fences( $content ) ),
			'tokens_in'  => $res['tokens_in'],
			'tokens_out' => $res['tokens_out'],
		);
	}

	public static function create_post( WP_REST_Request $r ) {
		$type = sanitize_key( (string) $r->get_param( 'post_type' ) );
		$type = in_array( $type, array( 'post', 'page' ), true ) ? $type : 'post';
		$id   = wp_insert_post(
			wp_slash(
				array(
					'post_title'   => sanitize_text_field( (string) $r->get_param( 'title' ) ),
					'post_excerpt' => sanitize_textarea_field( (string) $r->get_param( 'excerpt' ) ),
					'post_content' => wp_kses_post( (string) $r->get_param( 'content' ) ),
					'post_status'  => 'draft',
					'post_type'    => $type,
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return self::fail( $id );
		}
		return array(
			'id'   => $id,
			'edit' => get_edit_post_link( $id, 'raw' ),
		);
	}

	/* ---------- Admin: images ---------- */

	public static function image( WP_REST_Request $r ) {
		$prompt = self::str( $r, 'prompt', 4000 );
		if ( '' === trim( $prompt ) ) {
			return new WP_Error( 'moshcw_empty', __( 'Describe the image first.', 'mosh-ai' ), array( 'status' => 400 ) );
		}
		$size = (string) $r->get_param( 'size' );
		if ( ! preg_match( '/^\d{3,4}x\d{3,4}$/', $size ) ) {
			$size = '1024x1024';
		}
		$id = MoshCW_Core::generate_image( $prompt, $size, sanitize_text_field( (string) $r->get_param( 'model' ) ) );
		if ( is_wp_error( $id ) ) {
			return self::fail( $id );
		}
		return array(
			'id'   => $id,
			'url'  => wp_get_attachment_url( $id ),
			'edit' => get_edit_post_link( $id, 'raw' ),
		);
	}

	/* ---------- Admin: editor + WooCommerce assistant ---------- */

	public static function editor( WP_REST_Request $r ) {
		$action  = sanitize_key( (string) $r->get_param( 'task' ) );
		$post_id = (int) $r->get_param( 'post_id' );
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'moshcw_forbidden', __( 'You cannot edit this post.', 'mosh-ai' ), array( 'status' => 403 ) );
		}
		$title   = sanitize_text_field( (string) $r->get_param( 'title' ) );
		$content = mb_substr( MoshCW_Core::plain( (string) $r->get_param( 'content' ) ), 0, 15000 );
		$instr   = self::str( $r, 'instruction', 3000 );
		$ctx     = "Title: $title\n\nContent:\n" . ( $content ? $content : '(empty)' );
		$extra   = $instr ? "\n\nExtra instructions: $instr" : '';
		$lang    = "\nWrite in the same language as the content (or the title if the content is empty).";
		$html    = 'Return clean HTML using only <h2>, <h3>, <p>, <ul>, <ol>, <li>, <strong>, <em>. No <h1>, no markdown, no code fences.';

		switch ( $action ) {
			case 'titles':
				$prompt = "Suggest 5 compelling, specific, SEO-friendly titles for this piece. Return one title per line, no numbering, no quotes.$lang$extra\n\n$ctx";
				break;
			case 'excerpt':
				$prompt = "Write an excerpt of 1 to 2 sentences (max 40 words) for this piece. Return only the excerpt text.$lang$extra\n\n$ctx";
				break;
			case 'seo':
				$prompt = "Write an SEO meta description between 140 and 155 characters for this page. Make it specific and clickable, no quotes. Return only the text.$lang$extra\n\n$ctx";
				break;
			case 'tags':
				$prompt = "Suggest up to 8 relevant tags for this piece. Return them comma-separated on one line, nothing else.$lang\n\n$ctx";
				break;
			case 'expand':
				$prompt = "Write the full body for the piece titled \"$title\"." . ( $content ? ' Build on and improve this existing draft, keep its facts.' : '' ) . " $html$lang$extra\n\n$ctx";
				break;
			case 'product_description':
				$prompt = "Write a persuasive WooCommerce product description for \"$title\": benefits first, then what's included, then who it's for. $html$lang$extra\n\n$ctx";
				break;
			case 'product_short':
				$prompt = "Write a short WooCommerce product description: one sentence plus 3 short bullet points. $html$lang$extra\n\n$ctx";
				break;
			case 'custom':
				if ( '' === $instr ) {
					return new WP_Error( 'moshcw_empty', __( 'Write what you want the AI to do.', 'mosh-ai' ), array( 'status' => 400 ) );
				}
				$prompt = "$instr\n\nIf you return formatted content, $html$lang\n\n$ctx";
				break;
			default:
				return new WP_Error( 'moshcw_task', __( 'Unknown task.', 'mosh-ai' ), array( 'status' => 400 ) );
		}

		$res = MoshCW_Core::ask( $prompt, 'You are an expert editor and copywriter. Human, direct, no filler, no em dashes.', 'editor', array( 'max_tokens' => in_array( $action, array( 'expand', 'product_description', 'custom' ), true ) ? 4000 : 600 ) );
		if ( is_wp_error( $res ) ) {
			return self::fail( $res );
		}
		$text = MoshCW_Core::strip_fences( $res['text'] );
		$out  = array(
			'task' => $action,
			'text' => wp_kses_post( $text ),
		);
		if ( 'titles' === $action ) {
			$out['items'] = array_values(
				array_filter(
					array_map(
						function ( $l ) {
							return trim( preg_replace( '/^\s*(\d+[\.\)]|[-*•])\s*/', '', wp_strip_all_tags( $l ) ), " \t\"" );
						},
						preg_split( "/\r\n|\n/", $text )
					),
					'strlen'
				)
			);
		}
		if ( in_array( $action, array( 'excerpt', 'seo', 'tags' ), true ) ) {
			$out['text'] = trim( wp_strip_all_tags( $text ), " \t\"" );
		}
		return $out;
	}

	/* ---------- Admin: knowledge base ---------- */

	public static function kb_list() {
		global $wpdb;
		$t    = MoshCW_DB::table( 'vectors' );
		$rows = $wpdb->get_results( "SELECT id, title, post_id, LEFT(content, 220) AS preview, created FROM $t ORDER BY id DESC LIMIT 300", ARRAY_A ); // phpcs:ignore
		return array(
			'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ), // phpcs:ignore
			'items' => $rows,
		);
	}

	public static function kb_add( WP_REST_Request $r ) {
		$title   = sanitize_text_field( (string) $r->get_param( 'title' ) );
		$content = self::str( $r, 'content', 200000 );
		if ( '' === trim( $content ) ) {
			return new WP_Error( 'moshcw_empty', __( 'Add some content first.', 'mosh-ai' ), array( 'status' => 400 ) );
		}
		$n = MoshCW_Core::kb_add( $title ? $title : wp_trim_words( $content, 8, '' ), $content, 0 );
		return is_wp_error( $n ) ? self::fail( $n ) : array( 'added' => $n );
	}

	public static function kb_delete( WP_REST_Request $r ) {
		global $wpdb;
		$wpdb->delete( MoshCW_DB::table( 'vectors' ), array( 'id' => (int) $r->get_param( 'id' ) ) ); // phpcs:ignore
		wp_cache_delete( 'moshcw_kb_count' );
		return array( 'deleted' => true );
	}

	public static function kb_clear() {
		global $wpdb;
		$t = MoshCW_DB::table( 'vectors' );
		$wpdb->query( "TRUNCATE TABLE $t" ); // phpcs:ignore
		wp_cache_delete( 'moshcw_kb_count' );
		delete_option( 'moshcw_kb_synced' );
		return array( 'cleared' => true );
	}

	public static function kb_sync( WP_REST_Request $r ) {
		$offset = max( 0, (int) $r->get_param( 'offset' ) );
		$q      = new WP_Query(
			array(
				'post_type'      => MoshCW_Core::kb_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 4,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		$added  = 0;
		foreach ( $q->posts as $pid ) {
			$n = MoshCW_Core::kb_sync_post( $pid );
			if ( is_wp_error( $n ) ) {
				return self::fail( $n );
			}
			$added += (int) $n;
		}
		$next = $offset + count( $q->posts );
		$done = empty( $q->posts ) || $next >= (int) $q->found_posts;
		if ( $done ) {
			update_option( 'moshcw_kb_synced', time(), false );
		}
		return array(
			'next'  => $next,
			'total' => (int) $q->found_posts,
			'added' => $added,
			'done'  => $done,
		);
	}

	public static function kb_search( WP_REST_Request $r ) {
		$query = self::str( $r, 'query', 2000 );
		return array( 'items' => '' === trim( $query ) ? array() : MoshCW_Core::kb_search( $query, 5 ) );
	}

	/* ---------- Admin: settings, keys, chatbots, forms ---------- */

	private static function params( WP_REST_Request $r ) {
		$p = $r->get_json_params();
		return is_array( $p ) ? $p : $r->get_params();
	}

	public static function save_settings( WP_REST_Request $r ) {
		$values = MoshCW_Settings::sanitize( self::params( $r ) );
		MoshCW_Settings::update( $values );
		$all    = MoshCW_Settings::all();
		return array(
			'saved'     => array_keys( $values ),
			'keys'      => MoshCW_Settings::key_status(),
			'popup_bot' => $all['popup_bot'],
			'has_ai'    => MoshCW_Settings::has_ai(),
		);
	}

	public static function test_key( WP_REST_Request $r ) {
		$p        = self::params( $r );
		$provider = isset( $p['provider'] ) ? sanitize_key( $p['provider'] ) : '';
		if ( ! array_key_exists( $provider, MoshCW_Providers::names() ) ) {
			return new WP_Error( 'moshcw_provider', __( 'Unknown provider.', 'mosh-ai' ), array( 'status' => 400 ) );
		}
		$key = isset( $p['key'] ) ? trim( sanitize_text_field( (string) $p['key'] ) ) : '';
		if ( '' === $key ) {
			$key = MoshCW_Settings::key_for( $provider );
		}
		if ( 'custom' === $provider ) {
			return self::test_custom( $p, $key );
		}
		if ( '' === $key ) {
			return new WP_Error( 'moshcw_no_key', __( 'Paste a key first.', 'mosh-ai' ), array( 'status' => 400 ) );
		}
		$models = MoshCW_Providers::list_models( $provider, $key );
		if ( is_wp_error( $models ) ) {
			return self::fail( $models );
		}
		if ( ! empty( $p['save'] ) ) {
			$update = array( $provider . '_key' => $key );
			if ( ! empty( $p['make_default'] ) || ! MoshCW_Settings::is_connected( MoshCW_Settings::get( 'default_provider' ) ) ) {
				$suggested                  = MoshCW_Providers::models();
				$update['default_provider'] = $provider;
				$update['default_model']    = $suggested[ $provider ][0];
			}
			MoshCW_Settings::update( $update );
		}
		return array(
			'ok'     => true,
			'count'  => count( $models ),
			'models' => array_slice( MoshCW_Providers::models()[ $provider ], 0, 400 ),
			'keys'   => MoshCW_Settings::key_status(),
		);
	}

	/**
	 * "Others": any OpenAI-compatible API. Tries the model list first, then a one-word chat
	 * (some providers have no /models route).
	 */
	private static function test_custom( $p, $key ) {
		$clean = MoshCW_Settings::sanitize(
			array(
				'custom_url'   => isset( $p['url'] ) ? $p['url'] : MoshCW_Settings::get( 'custom_url' ),
				'custom_name'  => isset( $p['name'] ) ? $p['name'] : MoshCW_Settings::get( 'custom_name' ),
				'custom_model' => isset( $p['model'] ) ? $p['model'] : MoshCW_Settings::get( 'custom_model' ),
			)
		);
		$url   = isset( $clean['custom_url'] ) ? $clean['custom_url'] : '';
		$model = isset( $clean['custom_model'] ) ? $clean['custom_model'] : '';
		if ( '' === $url ) {
			return new WP_Error( 'moshcw_no_url', __( 'Enter the API URL first, or pick a preset.', 'mosh-ai' ), array( 'status' => 400 ) );
		}
		$models = MoshCW_Providers::list_models(
			'custom',
			array(
				'url' => $url,
				'key' => $key,
			)
		);
		if ( is_wp_error( $models ) || ! $models ) {
			if ( '' === $model ) {
				return self::fail( is_wp_error( $models ) ? $models : new WP_Error( 'moshcw_model', __( 'Connected, but no model list. Type the model name and test again.', 'mosh-ai' ), array( 'status' => 400 ) ) );
			}
			$ping = MoshCW_Providers::post(
				$url . '/chat/completions',
				array( 'Authorization' => 'Bearer ' . ( '' !== $key ? $key : 'none' ) ),
				array(
					'model'      => $model,
					'messages'   => array(
						array(
							'role'    => 'user',
							'content' => 'Reply with OK',
						),
					),
					'max_tokens' => 5,
				),
				30
			);
			if ( is_wp_error( $ping ) ) {
				return self::fail( $ping );
			}
			$models = array( $model );
		}
		if ( '' === $model ) {
			$model = $models[0];
		}
		$update = array(
			'custom_url'   => $url,
			'custom_key'   => $key,
			'custom_model' => $model,
			'custom_name'  => isset( $clean['custom_name'] ) ? $clean['custom_name'] : '',
		);
		if ( ! empty( $p['make_default'] ) || ! MoshCW_Settings::has_ai() ) {
			$update['default_provider'] = 'custom';
			$update['default_model']    = $model;
		}
		MoshCW_Settings::update( $update );
		return array(
			'ok'     => true,
			'count'  => count( $models ),
			'models' => array_slice( array_values( array_unique( array_merge( array( $model ), $models ) ) ), 0, 400 ),
			'keys'   => MoshCW_Settings::key_status(),
		);
	}

	public static function bot_save( WP_REST_Request $r ) {
		$p   = self::params( $r );
		$all = MoshCW_Chatbots::all();
		$id  = isset( $p['id'] ) ? sanitize_key( $p['id'] ) : '';
		if ( '' === $id || ! isset( $all[ $id ] ) ) {
			$base = sanitize_key( ! empty( $p['slug'] ) ? $p['slug'] : sanitize_title( isset( $p['name'] ) ? $p['name'] : '' ) );
			$base = '' === $base ? 'chatbot' : $base;
			$id   = $base;
			$i    = 2;
			while ( isset( $all[ $id ] ) ) {
				$id = $base . '-' . $i;
				++$i;
			}
		}
		$bot = MoshCW_Chatbots::sanitize( $p, $id );
		MoshCW_Chatbots::save( $bot );
		if ( array_key_exists( 'site_popup', $p ) ) {
			if ( ! empty( $p['site_popup'] ) ) {
				MoshCW_Settings::update( array( 'popup_bot' => $id ) );
			} elseif ( MoshCW_Settings::get( 'popup_bot' ) === $id ) {
				MoshCW_Settings::update( array( 'popup_bot' => '' ) );
			}
		}
		return array(
			'bot'   => $bot,
			'bots'  => MoshCW_Chatbots::all(),
			'popup' => MoshCW_Settings::get( 'popup_bot' ),
		);
	}

	public static function bot_delete( WP_REST_Request $r ) {
		$p  = self::params( $r );
		$id = isset( $p['id'] ) ? sanitize_key( $p['id'] ) : '';
		if ( 'default' === $id ) {
			return new WP_Error( 'moshcw_default', __( 'The default chatbot cannot be deleted.', 'mosh-ai' ), array( 'status' => 400 ) );
		}
		MoshCW_Chatbots::delete( $id );
		if ( MoshCW_Settings::get( 'popup_bot' ) === $id ) {
			MoshCW_Settings::update( array( 'popup_bot' => '' ) );
		}
		return array(
			'bots'  => MoshCW_Chatbots::all(),
			'popup' => MoshCW_Settings::get( 'popup_bot' ),
		);
	}

	public static function form_save( WP_REST_Request $r ) {
		$p   = self::params( $r );
		$all = MoshCW_Forms::all();
		$id  = isset( $p['id'] ) ? sanitize_key( $p['id'] ) : '';
		if ( '' === $id || ! isset( $all[ $id ] ) ) {
			$base = sanitize_key( ! empty( $p['slug'] ) ? $p['slug'] : sanitize_title( isset( $p['name'] ) ? $p['name'] : '' ) );
			$base = '' === $base ? 'form' : $base;
			$id   = $base;
			$i    = 2;
			while ( isset( $all[ $id ] ) ) {
				$id = $base . '-' . $i;
				++$i;
			}
		}
		$form = MoshCW_Forms::sanitize( $p, $id );
		MoshCW_Forms::save( $form );
		return array(
			'form'  => $form,
			'forms' => MoshCW_Forms::all(),
		);
	}

	public static function form_delete( WP_REST_Request $r ) {
		$p = self::params( $r );
		MoshCW_Forms::delete( isset( $p['id'] ) ? sanitize_key( $p['id'] ) : '' );
		return array( 'forms' => MoshCW_Forms::all() );
	}

	public static function clear( WP_REST_Request $r ) {
		global $wpdb;
		$p    = self::params( $r );
		$what = isset( $p['what'] ) ? sanitize_key( $p['what'] ) : '';
		if ( ! in_array( $what, array( 'logs', 'discussions' ), true ) ) {
			return new WP_Error( 'moshcw_clear', __( 'Nothing to clear.', 'mosh-ai' ), array( 'status' => 400 ) );
		}
		$wpdb->query( 'TRUNCATE TABLE ' . MoshCW_DB::table( $what ) ); // phpcs:ignore
		return array( 'cleared' => $what );
	}
}
