<?php
defined( 'ABSPATH' ) || exit;

class AffiKeep_Settings {

	const OPTION_KEY = 'affikeep_settings';

	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
	}

	public static function register_settings(): void {
		register_setting(
			'affikeep_settings_group',
			self::OPTION_KEY,
			[ 'sanitize_callback' => [ __CLASS__, 'sanitize' ] ]
		);
	}

	/** 設定値を取得（キー指定で個別取得も可） */
	public static function get( string $key = '' ) {
		$defaults = self::defaults();
		$saved    = get_option( self::OPTION_KEY, [] );
		$all      = array_merge( $defaults, (array) $saved );

		if ( $key ) {
			return $all[ $key ] ?? '';
		}
		return $all;
	}

	/** デフォルト値 */
	public static function defaults(): array {
		return [
			// 楽天API（2026年5月以降はURL・アクセスキーも必須）
			'rakuten_app_id'        => '',
			'rakuten_access_key'    => '',
			'rakuten_app_url'       => '',  // アプリケーションURL（例: https://example.com）
			'rakuten_affiliate_id'  => '',

			// Amazon アソシエイツ
			'amazon_tracking_id'    => '',  // トラッキングID（例: yoursite-22）

			// Yahoo!ショッピング（バリューコマース）
			'yahoo_linkswitch'      => '',  // LinkSwitch（アフィリエイトIDより優先）
			'yahoo_affiliate_id'    => '',  // アフィリエイトID（LinkSwitchがない場合）

			// もしもアフィリエイト（全モール共通のa_id）
			'moshimo_aid'           => '',

			// リンク切れチェック設定
			'check_interval_hours'  => 24,  // チェック間隔（時間）
			'notify_email'          => '',  // 通知先メール（空=管理者メール）

			// 表示設定
			'button_text_amazon'    => 'Amazonで見る',
			'button_text_rakuten'   => '楽天で見る',
			'button_text_yahoo'     => 'Yahoo!で見る',
		];
	}

	/** 保存時のサニタイズ */
	public static function sanitize( $input ): array {
		$clean = [];

		$text_fields = [
			'rakuten_app_id', 'rakuten_access_key', 'rakuten_app_url', 'rakuten_affiliate_id',
			'amazon_tracking_id',
			'yahoo_linkswitch', 'yahoo_affiliate_id',
			'moshimo_aid',
			'notify_email',
			'button_text_amazon', 'button_text_rakuten', 'button_text_yahoo',
		];

		foreach ( $text_fields as $field ) {
			$clean[ $field ] = sanitize_text_field( $input[ $field ] ?? '' );
		}

		$clean['check_interval_hours'] = absint( $input['check_interval_hours'] ?? 24 );
		if ( $clean['check_interval_hours'] < 1 ) {
			$clean['check_interval_hours'] = 24;
		}

		return $clean;
	}

	/**
	 * AmazonリンクURLをもしも経由に変換する。
	 * もしもIDが設定されていない場合は元のURLをそのまま返す。
	 */
	public static function convert_to_moshimo( string $url, string $mall ): string {
		$aid = self::get( 'moshimo_aid' );
		if ( empty( $aid ) ) {
			return $url;
		}

		// p_id・pc_id・pl_id はもしもが各モールに割り当てた固定値
		switch ( $mall ) {
			case 'amazon':
				return 'https://af.moshimo.com/af/c/click?a_id=' . urlencode( $aid )
					. '&p_id=170&pc_id=185&pl_id=4062&url=' . urlencode( $url );

			case 'rakuten':
				return 'https://af.moshimo.com/af/c/click?a_id=' . urlencode( $aid )
					. '&p_id=54&pc_id=54&pl_id=616&url=' . urlencode( $url );

			case 'yahoo':
				return 'https://af.moshimo.com/af/c/click?a_id=' . urlencode( $aid )
					. '&p_id=1&pc_id=1&pl_id=1&url=' . urlencode( $url );

			default:
				return $url;
		}
	}
}
