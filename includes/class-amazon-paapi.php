<?php
defined( 'ABSPATH' ) || exit;

/**
 * Amazon PA-API 5.0（Product Advertising API）連携。Pro限定機能。
 * AWS Signature Version 4によるリクエスト署名を自前実装する（外部SDK不使用）。
 * 日本マーケットプレイス（www.amazon.co.jp）専用。
 */
class AffiKeep_Amazon_PAAPI {

	const HOST        = 'webservices.amazon.co.jp';
	// PA-API日本マーケットプレイスの署名リージョン。エンドポイントは.co.jpだが、
	// Amazon仕様上この操作の署名リージョンはus-west-2固定（.com等とは異なる）。
	const REGION      = 'us-west-2';
	const SERVICE     = 'ProductAdvertisingAPI';
	const MARKETPLACE = 'www.amazon.co.jp';

	/**
	 * キーワード検索（SearchItems操作）。
	 * @return array{items?:array,error?:string}
	 */
	public static function search_items( string $keyword ): array {
		$payload = [
			'Keywords'    => $keyword,
			'PartnerTag'  => AffiKeep_Settings::get( 'amazon_tracking_id' ),
			'PartnerType' => 'Associates',
			'Marketplace' => self::MARKETPLACE,
			'Resources'   => [
				'Images.Primary.Medium',
				'ItemInfo.Title',
				'Offers.Listings.Price',
			],
		];

		return self::request( 'SearchItems', '/paapi5/searchitems', $payload );
	}

	/** PA-APIへ署名付きリクエストを送信し、商品配列に整形して返す */
	private static function request( string $operation, string $path, array $payload ): array {
		$access_key = AffiKeep_Settings::get( 'amazon_paapi_access_key' );
		$secret_key = AffiKeep_Settings::get( 'amazon_paapi_secret_key' );

		if ( empty( $access_key ) || empty( $secret_key ) ) {
			return [ 'error' => 'PA-APIのアクセスキー・シークレットキーが設定されていません。AffiKeep → 設定 から入力してください。' ];
		}
		if ( empty( $payload['PartnerTag'] ) ) {
			return [ 'error' => 'AmazonトラッキングID（パートナータグ）が設定されていません。AffiKeep → 設定 から入力してください。' ];
		}

		$body      = wp_json_encode( $payload );
		$timestamp = gmdate( 'Ymd\THis\Z' );
		$date      = gmdate( 'Ymd' );
		$target    = 'com.amazon.paapi5.v1.ProductAdvertisingAPIv1.' . $operation;

		$headers = self::sign_request( $access_key, $secret_key, $path, $body, $timestamp, $date, $target );

		AffiKeep_Logger::log( 'PA-API送信', AffiKeep_Logger::LEVEL_INFO, [
			'operation'         => $operation,
			'access_key_length' => strlen( $access_key ),
			'keyword'           => $payload['Keywords'] ?? '',
		] );

		$response = wp_remote_post( 'https://' . self::HOST . $path, [
			'timeout' => 10,
			'headers' => $headers,
			'body'    => $body,
		] );

		if ( is_wp_error( $response ) ) {
			AffiKeep_Logger::error( 'PA-API通信エラー: ' . $response->get_error_message() );
			return [ 'error' => 'Amazon PA-APIへの接続に失敗しました: ' . $response->get_error_message() ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code !== 200 ) {
			$message = $data['Errors'][0]['Message'] ?? ( 'HTTPステータス ' . $code );
			AffiKeep_Logger::error( 'PA-APIエラー: ' . $message, [ 'response' => substr( $raw, 0, 500 ) ] );
			return [ 'error' => 'Amazon PA-APIエラー: ' . $message ];
		}

		if ( empty( $data['SearchResult']['Items'] ) ) {
			AffiKeep_Logger::log( 'PA-API: 検索結果0件。キーワード=' . ( $payload['Keywords'] ?? '' ) );
			return [ 'items' => [] ];
		}

		return [ 'items' => self::parse_items( $data['SearchResult']['Items'] ) ];
	}

	/** PA-APIレスポンスの商品配列を、プラグイン共通の商品情報形式に整形する */
	private static function parse_items( array $items ): array {
		return array_map( function ( $item ) {
			return [
				'title'      => $item['ItemInfo']['Title']['DisplayValue'] ?? '',
				'price'      => $item['Offers']['Listings'][0]['Price']['DisplayAmount'] ?? '',
				'image_url'  => $item['Images']['Primary']['Medium']['URL'] ?? '',
				'amazon_url' => $item['DetailPageURL'] ?? '',
				'asin'       => $item['ASIN'] ?? '',
			];
		}, $items );
	}

	/** AWS Signature Version 4でリクエストに署名し、送信用ヘッダー一式を返す */
	private static function sign_request( string $access_key, string $secret_key, string $path, string $body, string $timestamp, string $date, string $target ): array {
		$content_type = 'application/json; charset=utf-8';
		$payload_hash = hash( 'sha256', $body );

		// 署名対象ヘッダー（アルファベット順・小文字。PA-API 5.0公式サンプルに準拠）
		$canonical_headers =
			"content-encoding:amz-1.0\n" .
			"content-type:{$content_type}\n" .
			'host:' . self::HOST . "\n" .
			"x-amz-date:{$timestamp}\n" .
			"x-amz-target:{$target}\n";
		$signed_headers = 'content-encoding;content-type;host;x-amz-date;x-amz-target';

		// CanonicalQueryStringは空（POST・クエリパラメータなし）
		$canonical_request = "POST\n{$path}\n\n{$canonical_headers}\n{$signed_headers}\n{$payload_hash}";

		$credential_scope = "{$date}/" . self::REGION . '/' . self::SERVICE . '/aws4_request';
		$string_to_sign    = "AWS4-HMAC-SHA256\n{$timestamp}\n{$credential_scope}\n" . hash( 'sha256', $canonical_request );

		$signing_key = self::derive_signing_key( $secret_key, $date, self::REGION, self::SERVICE );
		$signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		$authorization = "AWS4-HMAC-SHA256 Credential={$access_key}/{$credential_scope}, SignedHeaders={$signed_headers}, Signature={$signature}";

		return [
			'content-encoding' => 'amz-1.0',
			'Content-Type'     => $content_type,
			'Host'             => self::HOST,
			'X-Amz-Date'       => $timestamp,
			'X-Amz-Target'     => $target,
			'Authorization'    => $authorization,
		];
	}

	/** AWS4署名鍵の導出（kDate → kRegion → kService → kSigning。AWS公式サンプルと同一のHMACチェーン） */
	public static function derive_signing_key( string $secret_key, string $date, string $region, string $service ): string {
		$k_date    = hash_hmac( 'sha256', $date, 'AWS4' . $secret_key, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $service, $k_region, true );
		return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
	}
}
