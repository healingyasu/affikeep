<?php
defined( 'ABSPATH' ) || exit;

class AffiKeep_Rest_API {

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route( 'affikeep/v1', '/search/rakuten', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'search_rakuten' ],
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
			'args'                => [
				'q' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );
	}

	public static function search_rakuten( WP_REST_Request $request ): WP_REST_Response {
		$keyword    = $request->get_param( 'q' );
		$app_id     = AffiKeep_Settings::get( 'rakuten_app_id' );
		$affiliate_id = AffiKeep_Settings::get( 'rakuten_affiliate_id' );

		if ( empty( $app_id ) ) {
			return new WP_REST_Response(
				[ 'error' => '楽天アプリケーションIDが設定されていません。AffiKeep → 設定 から入力してください。' ],
				400
			);
		}

		$app_url    = AffiKeep_Settings::get( 'rakuten_app_url' );
		$access_key = AffiKeep_Settings::get( 'rakuten_access_key' );

		// 2026年5月以降の新仕様：
		// ・ドメインは openapi.rakuten.co.jp
		// ・applicationId はクエリパラメータ
		// ・accessKey は「accessKey」という独自ヘッダー
		// ・Origin にアプリケーションURLを指定
		$params = [
			'applicationId' => $app_id,
			'keyword'       => $keyword,
			'hits'          => 30,
			'sort'          => 'standard',
			'formatVersion' => 2,
		];

		if ( $affiliate_id ) {
			$params['affiliateId'] = $affiliate_id;
		}

		$headers = [];
		if ( $access_key ) {
			$headers['accessKey'] = $access_key;
		}
		if ( $app_url ) {
			$headers['Origin'] = $app_url;
		}

		AffiKeep_Logger::log( '楽天API送信', AffiKeep_Logger::LEVEL_INFO, [
			'app_id_length'     => strlen( $app_id ),
			'access_key_length' => strlen( $access_key ),
			'origin'            => $app_url,
			'keyword'           => $keyword,
		] );

		$url      = 'https://openapi.rakuten.co.jp/ichibams/api/IchibaItem/Search/20220601?' . http_build_query( $params );
		$response = wp_remote_get( $url, [ 'timeout' => 10, 'headers' => $headers ] );

		if ( is_wp_error( $response ) ) {
			AffiKeep_Logger::error( '楽天API通信エラー: ' . $response->get_error_message() );
			return new WP_REST_Response(
				[ 'error' => '楽天APIへの接続に失敗しました: ' . $response->get_error_message() ],
				500
			);
		}

		$raw  = wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );

		// APIエラーをログに記録して返す
		if ( ! empty( $body['error'] ) ) {
			AffiKeep_Logger::error( '楽天APIエラー: ' . ( $body['error_description'] ?? $body['error'] ), [ 'response' => $raw ] );
			return new WP_REST_Response(
				[ 'error' => '楽天APIエラー: ' . ( $body['error_description'] ?? $body['error'] ) ],
				400
			);
		}

		if ( empty( $body['Items'] ) ) {
			AffiKeep_Logger::log( '楽天API: 検索結果0件。キーワード=' . $keyword . ' レスポンス=' . substr( $raw, 0, 300 ) );
			return new WP_REST_Response( [ 'items' => [] ], 200 );
		}

		$items = array_map( function ( $item ) {
			return [
				'title'       => $item['itemName'],
				'price'       => number_format( $item['itemPrice'] ) . '円',
				'image_url'   => $item['mediumImageUrls'][0] ?? '',
				'rakuten_url' => $item['affiliateUrl'] ?: $item['itemUrl'],
				'item_code'   => $item['itemCode'],
			];
		}, $body['Items'] );

		return new WP_REST_Response( [ 'items' => $items ], 200 );
	}
}
