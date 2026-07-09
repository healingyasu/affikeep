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

		// メールアドレスはメール形式で検証（不正な値は空になる）
		$clean['notify_email'] = sanitize_email( $input['notify_email'] ?? '' );

		$clean['check_interval_hours'] = absint( $input['check_interval_hours'] ?? 24 );
		if ( $clean['check_interval_hours'] < 1 ) {
			$clean['check_interval_hours'] = 24;
		}

		return $clean;
	}

	/**
	 * 商品URLを「実際に表示するアフィリエイトリンク」に変換する。
	 *
	 * 入力した欄によって方式が自動的に決まる：
	 *   1. 直接アフィリエイト情報が入っていれば直接リンク
	 *   2. なければ もしも経由
	 *   3. それも無ければ 素のURL
	 *
	 * Amazonは直接リンク推奨（PA-API審査に必要な購買実績がもしも経由では貯まらないため）。
	 */
	public static function affiliate_url( string $url, string $mall ): string {
		if ( empty( $url ) ) {
			return $url;
		}

		$def = AffiKeep_Malls::get( $mall );
		if ( ! $def ) {
			return $url;
		}

		if ( isset( $def['direct_url'] ) ) {
			$direct = $def['direct_url']( $url );
			if ( $direct !== null ) {
				return $direct;
			}
		}

		if ( self::get( 'moshimo_aid' ) && isset( $def['moshimo'] ) ) {
			return self::moshimo_wrap( $url, $def['moshimo'] );
		}

		return $url;
	}

	/**
	 * URLをもしも経由リンクに変換する（内部用）。
	 * p_id・pc_id・pl_id はもしもが各モールに割り当てた値（AffiKeep_Mallsで定義）。
	 * ※pc_id/pl_idは登録サイトにより異なる場合があるため、もしも利用者は公開前にボタン動作を確認すること。
	 */
	private static function moshimo_wrap( string $url, array $ids ): string {
		$aid = self::get( 'moshimo_aid' );
		if ( empty( $aid ) ) {
			return $url;
		}

		return 'https://af.moshimo.com/af/c/click?a_id=' . urlencode( $aid )
			. '&p_id=' . $ids['p_id'] . '&pc_id=' . $ids['pc_id'] . '&pl_id=' . $ids['pl_id']
			. '&url=' . urlencode( $url );
	}
}
