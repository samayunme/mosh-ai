<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Talks to the AI providers. Every chat call returns
 * array( 'text' => string, 'tokens_in' => int, 'tokens_out' => int ) or WP_Error.
 */
class MoshCW_Providers {

	public static function names() {
		return array(
			'openai'     => 'OpenAI',
			'anthropic'  => 'Anthropic (Claude)',
			'google'     => 'Google Gemini',
			'openrouter' => 'OpenRouter',
			'custom'     => self::custom_label(),
		);
	}

	public static function custom_label() {
		$name = trim( (string) MoshCW_Settings::get( 'custom_name', '' ) );
		return '' !== $name ? $name : __( 'Others', 'mosh-ai' );
	}

	/** Base URL of the "Others" provider, e.g. https://api.deepseek.com/v1 or http://localhost:11434/v1 */
	public static function custom_base() {
		return untrailingslashit( (string) MoshCW_Settings::get( 'custom_url', '' ) );
	}

	/** Ready-made settings for popular OpenAI-compatible services. */
	public static function custom_presets() {
		return array(
			'deepseek' => array( 'DeepSeek', 'https://api.deepseek.com/v1', 'deepseek-chat' ),
			'groq'     => array( 'Groq', 'https://api.groq.com/openai/v1', 'llama-3.3-70b-versatile' ),
			'mistral'  => array( 'Mistral', 'https://api.mistral.ai/v1', 'mistral-large-latest' ),
			'xai'      => array( 'xAI Grok', 'https://api.x.ai/v1', 'grok-4' ),
			'together' => array( 'Together AI', 'https://api.together.xyz/v1', 'meta-llama/Llama-3.3-70B-Instruct-Turbo' ),
			'perplexity' => array( 'Perplexity', 'https://api.perplexity.ai', 'sonar' ),
			'qwen'     => array( 'Qwen (Alibaba)', 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1', 'qwen-plus' ),
			'ollama'   => array( 'Ollama (self-hosted)', 'http://localhost:11434/v1', 'llama3.1' ),
			'lmstudio' => array( 'LM Studio (self-hosted)', 'http://localhost:1234/v1', 'local-model' ),
		);
	}

	/**
	 * Suggested models first, then any live list fetched with "Test key" (cached).
	 */
	public static function models() {
		$suggested = array(
			'openai'     => array( 'gpt-4o-mini', 'gpt-4o', 'gpt-4.1', 'gpt-4.1-mini', 'gpt-4.1-nano', 'gpt-5', 'gpt-5-mini', 'gpt-5-nano', 'o4-mini' ),
			'anthropic'  => array( 'claude-sonnet-4-5', 'claude-haiku-4-5', 'claude-opus-4-1' ),
			'google'     => array( 'gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.5-flash-lite', 'gemini-2.0-flash' ),
			'openrouter' => array( 'openai/gpt-4o-mini', 'anthropic/claude-sonnet-4.5', 'google/gemini-2.5-flash', 'meta-llama/llama-3.3-70b-instruct', 'deepseek/deepseek-chat', 'mistralai/mistral-large' ),
			'custom'     => array_values( array_filter( array( (string) MoshCW_Settings::get( 'custom_model', '' ) ) ) ),
		);
		$cache     = (array) get_option( 'moshcw_models_cache', array() );
		foreach ( $suggested as $p => $list ) {
			if ( ! empty( $cache[ $p ] ) && is_array( $cache[ $p ] ) ) {
				$suggested[ $p ] = array_slice( array_values( array_unique( array_merge( $list, $cache[ $p ] ) ) ), 0, 400 );
			}
		}
		return apply_filters( 'moshcw_models', $suggested );
	}

	/**
	 * Validates a key by listing the provider's models. Returns model IDs or WP_Error.
	 */
	public static function list_models( $provider, $key ) {
		switch ( $provider ) {
			case 'custom':
				$base = isset( $key['url'] ) ? $key['url'] : self::custom_base();
				$res  = self::get( untrailingslashit( $base ) . '/models', ! empty( $key['key'] ) ? array( 'Authorization' => 'Bearer ' . $key['key'] ) : array() );
				$ids  = is_wp_error( $res ) ? $res : wp_list_pluck( isset( $res['data'] ) ? (array) $res['data'] : array(), 'id' );
				break;
			case 'anthropic':
				$res = self::get( 'https://api.anthropic.com/v1/models?limit=100', array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01' ) );
				$ids = is_wp_error( $res ) ? $res : wp_list_pluck( isset( $res['data'] ) ? (array) $res['data'] : array(), 'id' );
				break;
			case 'google':
				$res = self::get( 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=200', array( 'x-goog-api-key' => $key ) );
				if ( is_wp_error( $res ) ) {
					$ids = $res;
					break;
				}
				$ids = array();
				foreach ( isset( $res['models'] ) ? (array) $res['models'] : array() as $m ) {
					if ( isset( $m['name'] ) && in_array( 'generateContent', isset( $m['supportedGenerationMethods'] ) ? (array) $m['supportedGenerationMethods'] : array(), true ) ) {
						$ids[] = preg_replace( '#^models/#', '', $m['name'] );
					}
				}
				break;
			case 'openrouter':
				$res = self::get( 'https://openrouter.ai/api/v1/key', array( 'Authorization' => 'Bearer ' . $key ) );
				if ( is_wp_error( $res ) ) {
					$ids = $res;
					break;
				}
				$all = self::get( 'https://openrouter.ai/api/v1/models', array() );
				$ids = is_wp_error( $all ) ? array() : wp_list_pluck( isset( $all['data'] ) ? (array) $all['data'] : array(), 'id' );
				break;
			default:
				$res = self::get( 'https://api.openai.com/v1/models', array( 'Authorization' => 'Bearer ' . $key ) );
				if ( is_wp_error( $res ) ) {
					$ids = $res;
					break;
				}
				$ids = array();
				foreach ( wp_list_pluck( isset( $res['data'] ) ? (array) $res['data'] : array(), 'id' ) as $id ) {
					if ( preg_match( '/^(gpt-|o\d|chatgpt)/', $id ) && ! preg_match( '/(audio|realtime|tts|transcribe|image|search|embedding|instruct|moderation)/', $id ) ) {
						$ids[] = $id;
					}
				}
				rsort( $ids );
		}
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}
		$ids   = array_values( array_filter( array_map( 'strval', (array) $ids ) ) );
		$cache = (array) get_option( 'moshcw_models_cache', array() );
		$cache[ $provider ] = array_slice( $ids, 0, 400 );
		update_option( 'moshcw_models_cache', $cache, false );
		return $ids;
	}

	public static function get( $url, $headers, $timeout = 20 ) {
		$response = wp_remote_get( $url, array( 'timeout' => $timeout, 'headers' => $headers ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) && isset( $data['error']['message'] ) ? $data['error']['message'] : '';
			if ( ! $msg && in_array( $code, array( 401, 403 ), true ) ) {
				$msg = __( 'The provider rejected this key.', 'mosh-ai' );
			}
			/* translators: %d HTTP status */
			return new WP_Error( 'moshcw_api', $msg ? $msg : sprintf( __( 'AI provider returned HTTP %d.', 'mosh-ai' ), $code ), array( 'status' => 400 ) );
		}
		return is_array( $data ) ? $data : new WP_Error( 'moshcw_api', __( 'Invalid response from the AI provider.', 'mosh-ai' ), array( 'status' => 502 ) );
	}

	public static function chat( $args ) {
		$key = MoshCW_Settings::key_for( $args['provider'] );
		if ( 'custom' === $args['provider'] ) {
			if ( '' === self::custom_base() ) {
				return new WP_Error( 'moshcw_no_key', __( 'Set the API URL for "Others" in Mosh AI > Settings.', 'mosh-ai' ), array( 'status' => 400 ) );
			}
			return self::openai_style( self::custom_base() . '/chat/completions', '' !== $key ? $key : 'none', $args );
		}
		if ( '' === $key ) {
			$names = self::names();
			/* translators: %s provider name */
			return new WP_Error( 'moshcw_no_key', sprintf( __( 'No API key set for %s. Add it in Mosh AI > Settings.', 'mosh-ai' ), $names[ $args['provider'] ] ), array( 'status' => 400 ) );
		}
		switch ( $args['provider'] ) {
			case 'anthropic':
				return self::anthropic( $key, $args );
			case 'google':
				return self::google( $key, $args );
			case 'openrouter':
				return self::openai_style(
					'https://openrouter.ai/api/v1/chat/completions',
					$key,
					$args,
					array(
						'HTTP-Referer' => home_url(),
						'X-Title'      => get_bloginfo( 'name' ),
					)
				);
			default:
				return self::openai_style( 'https://api.openai.com/v1/chat/completions', $key, $args );
		}
	}

	private static function openai_style( $url, $key, $args, $headers = array() ) {
		$messages = array();
		if ( '' !== (string) $args['system'] ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => $args['system'],
			);
		}
		foreach ( $args['messages'] as $m ) {
			$messages[] = array(
				'role'    => $m['role'],
				'content' => $m['content'],
			);
		}
		$body      = array(
			'model'    => $args['model'],
			'messages' => $messages,
		);
		$reasoning = (bool) preg_match( '/^(openai\/)?(o\d|gpt-5)/', $args['model'] );
		if ( false !== strpos( $url, 'api.openai.com' ) ) {
			$body['max_completion_tokens'] = $reasoning ? max( (int) $args['max_tokens'], 4096 ) : (int) $args['max_tokens'];
		} else {
			$body['max_tokens'] = (int) $args['max_tokens'];
		}
		if ( ! $reasoning ) {
			$body['temperature'] = (float) $args['temperature'];
		}
		$res = self::post( $url, array_merge( array( 'Authorization' => 'Bearer ' . $key ), $headers ), $body );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$text = isset( $res['choices'][0]['message']['content'] ) ? (string) $res['choices'][0]['message']['content'] : '';
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'moshcw_empty', __( 'The model returned an empty answer. Try a higher max tokens value.', 'mosh-ai' ), array( 'status' => 502 ) );
		}
		return array(
			'text'       => $text,
			'tokens_in'  => isset( $res['usage']['prompt_tokens'] ) ? (int) $res['usage']['prompt_tokens'] : 0,
			'tokens_out' => isset( $res['usage']['completion_tokens'] ) ? (int) $res['usage']['completion_tokens'] : 0,
		);
	}

	private static function anthropic( $key, $args ) {
		$body = array(
			'model'       => $args['model'],
			'max_tokens'  => (int) $args['max_tokens'],
			'messages'    => self::alternate( $args['messages'] ),
			'temperature' => min( 1, (float) $args['temperature'] ),
		);
		if ( '' !== (string) $args['system'] ) {
			$body['system'] = $args['system'];
		}
		$res = self::post(
			'https://api.anthropic.com/v1/messages',
			array(
				'x-api-key'         => $key,
				'anthropic-version' => '2023-06-01',
			),
			$body
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$text = '';
		foreach ( isset( $res['content'] ) ? (array) $res['content'] : array() as $part ) {
			if ( isset( $part['type'], $part['text'] ) && 'text' === $part['type'] ) {
				$text .= $part['text'];
			}
		}
		return array(
			'text'       => $text,
			'tokens_in'  => isset( $res['usage']['input_tokens'] ) ? (int) $res['usage']['input_tokens'] : 0,
			'tokens_out' => isset( $res['usage']['output_tokens'] ) ? (int) $res['usage']['output_tokens'] : 0,
		);
	}

	private static function google( $key, $args ) {
		$contents = array();
		foreach ( self::alternate( $args['messages'] ) as $m ) {
			$contents[] = array(
				'role'  => 'assistant' === $m['role'] ? 'model' : 'user',
				'parts' => array( array( 'text' => $m['content'] ) ),
			);
		}
		$max  = (int) $args['max_tokens'];
		$body = array(
			'contents'         => $contents,
			'generationConfig' => array(
				'temperature'     => (float) $args['temperature'],
				'maxOutputTokens' => false !== strpos( $args['model'], '2.5' ) ? max( $max, 4096 ) : $max,
			),
		);
		if ( '' !== (string) $args['system'] ) {
			$body['systemInstruction'] = array( 'parts' => array( array( 'text' => $args['system'] ) ) );
		}
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $args['model'] ) . ':generateContent';
		$res = self::post( $url, array( 'x-goog-api-key' => $key ), $body );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$text = '';
		if ( isset( $res['candidates'][0]['content']['parts'] ) ) {
			foreach ( (array) $res['candidates'][0]['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) && empty( $part['thought'] ) ) {
					$text .= $part['text'];
				}
			}
		}
		return array(
			'text'       => $text,
			'tokens_in'  => isset( $res['usageMetadata']['promptTokenCount'] ) ? (int) $res['usageMetadata']['promptTokenCount'] : 0,
			'tokens_out' => isset( $res['usageMetadata']['candidatesTokenCount'] ) ? (int) $res['usageMetadata']['candidatesTokenCount'] : 0,
		);
	}

	/**
	 * Claude and Gemini need user/assistant turns that alternate and start with the user.
	 */
	private static function alternate( $messages ) {
		$out = array();
		foreach ( $messages as $m ) {
			$role = 'assistant' === $m['role'] ? 'assistant' : 'user';
			if ( empty( $out ) && 'assistant' === $role ) {
				continue;
			}
			$last = count( $out ) - 1;
			if ( $last >= 0 && $out[ $last ]['role'] === $role ) {
				$out[ $last ]['content'] .= "\n\n" . $m['content'];
			} else {
				$out[] = array(
					'role'    => $role,
					'content' => (string) $m['content'],
				);
			}
		}
		return $out;
	}

	/**
	 * Generates an image with OpenAI and returns the raw bytes.
	 */
	public static function image( $prompt, $size = '1024x1024', $model = '' ) {
		$key = MoshCW_Settings::key_for( 'openai' );
		if ( '' === $key ) {
			if ( '' !== MoshCW_Settings::key_for( 'google' ) ) {
				return self::gemini_image( $prompt, $size );
			}
			return new WP_Error( 'moshcw_no_key', __( 'Image generation needs an OpenAI or Google Gemini key.', 'mosh-ai' ), array( 'status' => 400 ) );
		}
		$model = $model ? $model : MoshCW_Settings::get( 'image_model' );
		$body  = array(
			'model'  => $model,
			'prompt' => $prompt,
			'size'   => $size,
			'n'      => 1,
		);
		if ( 0 === strpos( $model, 'dall-e' ) ) {
			$body['response_format'] = 'b64_json';
		}
		$res = self::post( 'https://api.openai.com/v1/images/generations', array( 'Authorization' => 'Bearer ' . $key ), $body, 240 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$bytes = '';
		if ( ! empty( $res['data'][0]['b64_json'] ) ) {
			$bytes = base64_decode( $res['data'][0]['b64_json'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		} elseif ( ! empty( $res['data'][0]['url'] ) ) {
			$dl    = wp_remote_get( $res['data'][0]['url'], array( 'timeout' => 60 ) );
			$bytes = is_wp_error( $dl ) ? '' : wp_remote_retrieve_body( $dl );
		}
		if ( ! $bytes ) {
			return new WP_Error( 'moshcw_image', __( 'No image was returned.', 'mosh-ai' ), array( 'status' => 502 ) );
		}
		return $bytes;
	}

	/** Gemini image generation (used when there is no OpenAI key). */
	private static function gemini_image( $prompt, $size ) {
		$ratio = '1:1';
		if ( preg_match( '/^(\d+)x(\d+)$/', (string) $size, $m ) && (int) $m[2] > 0 ) {
			$r     = (int) $m[1] / (int) $m[2];
			$ratio = $r > 1.2 ? '16:9' : ( $r < 0.8 ? '9:16' : '1:1' );
		}
		$res = self::post(
			'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent',
			array( 'x-goog-api-key' => MoshCW_Settings::key_for( 'google' ) ),
			array(
				'contents'         => array( array( 'parts' => array( array( 'text' => $prompt ) ) ) ),
				'generationConfig' => array(
					'responseModalities' => array( 'IMAGE' ),
					'imageConfig'        => array( 'aspectRatio' => $ratio ),
				),
			),
			240
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		foreach ( isset( $res['candidates'][0]['content']['parts'] ) ? (array) $res['candidates'][0]['content']['parts'] : array() as $part ) {
			if ( ! empty( $part['inlineData']['data'] ) ) {
				return base64_decode( $part['inlineData']['data'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			}
		}
		return new WP_Error( 'moshcw_image', __( 'No image was returned.', 'mosh-ai' ), array( 'status' => 502 ) );
	}

	/** The embedding model in use: OpenAI when connected, otherwise Gemini. Stored with every vector. */
	public static function embed_model() {
		if ( '' !== MoshCW_Settings::key_for( 'openai' ) ) {
			return (string) MoshCW_Settings::get( 'embedding_model' );
		}
		return '' !== MoshCW_Settings::key_for( 'google' ) ? 'gemini-embedding-001' : '';
	}

	public static function embed( $text ) {
		$key = MoshCW_Settings::key_for( 'openai' );
		if ( '' === $key && '' !== MoshCW_Settings::key_for( 'google' ) ) {
			$res = self::post(
				'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent',
				array( 'x-goog-api-key' => MoshCW_Settings::key_for( 'google' ) ),
				array(
					'content'              => array( 'parts' => array( array( 'text' => mb_substr( (string) $text, 0, 8000 ) ) ) ),
					'outputDimensionality' => 1536,
				),
				60
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			if ( empty( $res['embedding']['values'] ) ) {
				return new WP_Error( 'moshcw_api', __( 'No embedding returned.', 'mosh-ai' ), array( 'status' => 502 ) );
			}
			return array_map( 'floatval', $res['embedding']['values'] );
		}
		if ( '' === $key ) {
			return new WP_Error( 'moshcw_no_key', __( 'The knowledge base needs an OpenAI or Google Gemini key.', 'mosh-ai' ), array( 'status' => 400 ) );
		}
		$res = self::post(
			'https://api.openai.com/v1/embeddings',
			array( 'Authorization' => 'Bearer ' . $key ),
			array(
				'model' => MoshCW_Settings::get( 'embedding_model' ),
				'input' => mb_substr( (string) $text, 0, 24000 ),
			),
			60
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( empty( $res['data'][0]['embedding'] ) ) {
			return new WP_Error( 'moshcw_api', __( 'No embedding returned.', 'mosh-ai' ), array( 'status' => 502 ) );
		}
		return array_map(
			function ( $f ) {
				return round( (float) $f, 6 );
			},
			$res['data'][0]['embedding']
		);
	}

	/**
	 * True when OpenAI moderation flags the text. Fails open (returns false) when moderation is unavailable.
	 */
	public static function flagged( $text ) {
		$key = MoshCW_Settings::key_for( 'openai' );
		if ( '' === $key ) {
			return false;
		}
		$res = self::post(
			'https://api.openai.com/v1/moderations',
			array( 'Authorization' => 'Bearer ' . $key ),
			array(
				'model' => 'omni-moderation-latest',
				'input' => mb_substr( (string) $text, 0, 8000 ),
			),
			20
		);
		return ! is_wp_error( $res ) && ! empty( $res['results'][0]['flagged'] );
	}

	public static function post( $url, $headers, $body, $timeout = 120 ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = '';
			if ( is_array( $data ) ) {
				if ( isset( $data['error']['message'] ) ) {
					$msg = $data['error']['message'];
				} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
					$msg = $data['error'];
				} elseif ( isset( $data[0]['error']['message'] ) ) {
					$msg = $data[0]['error']['message'];
				}
			}
			/* translators: %d HTTP status */
			return new WP_Error( 'moshcw_api', $msg ? $msg : sprintf( __( 'AI provider returned HTTP %d.', 'mosh-ai' ), $code ), array( 'status' => 502 ) );
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'moshcw_api', __( 'Invalid response from the AI provider.', 'mosh-ai' ), array( 'status' => 502 ) );
		}
		return $data;
	}
}
