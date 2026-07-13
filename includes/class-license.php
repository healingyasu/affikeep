<?php
defined( 'ABSPATH' ) || exit;

/**
 * Proライセンスの検証・状態管理。
 * Pro機能は必ず AffiKeep_License::is_active() の戻り値だけを見て分岐すること
 * （検証方式を後からリモートAPI化する場合もこのクラスの中身だけ差し替えれば済む）。
 */
class AffiKeep_License {

	const OPTION_KEY = 'affikeep_license';
	const GRACE_DAYS = 7; // 期限切れ後も機能を止めずに警告表示のみで猶予する日数

	// フェーズ0: オフライン署名検証用の秘密鍵。
	// これは「抑止」であり暗号的な「防御」ではない。
	//
	// 本物の署名鍵はこのファイルに書かない（リポジトリは公開のため、書くと誰でも読めて偽キーを作れる）。
	// secret() が次の優先順位で解決する:
	//   1. 定数 AFFIKEEP_LICENSE_SECRET（自サイトで検証する場合は wp-config.php に define する）
	//   2. 環境変数 AFFIKEEP_LICENSE_SECRET（キー発行スクリプトが Blog-secrets/.env から読み込む）
	//   3. 下記プレースホルダ（配布ビルド時に本物の鍵へ差し替える。docs/PRO_LICENSE_ISSUING.md 5節）
	// プレースホルダのままだと本物のキーは検証を通らない＝Proは有効化されない（安全側に倒れる）。
	const SECRET_PLACEHOLDER = 'affikeep-pro-secret-placeholder-not-for-release';

	public static function init(): void {
		add_action( 'admin_post_affikeep_activate_license',   [ __CLASS__, 'handle_activate' ] );
		add_action( 'admin_post_affikeep_deactivate_license', [ __CLASS__, 'handle_deactivate' ] );
	}

	/** Pro機能が有効かどうか。全てのPro機能ゲートはこの関数の戻り値だけを見る。 */
	public static function is_active(): bool {
		$data = self::get_data();
		if ( empty( $data['key'] ) || $data['status'] !== 'valid' ) {
			return false;
		}
		if ( empty( $data['expires_at'] ) ) {
			return true; // 無期限ライセンス
		}
		$grace_until = strtotime( $data['expires_at'] . ' +' . self::GRACE_DAYS . ' days' );
		return time() <= $grace_until;
	}

	/** 期限切れの猶予期間中かどうか（設定画面での警告表示用） */
	public static function is_in_grace_period(): bool {
		$data = self::get_data();
		if ( empty( $data['expires_at'] ) ) {
			return false;
		}
		return self::is_active() && time() > strtotime( $data['expires_at'] );
	}

	/** 保存されているライセンス情報を取得 */
	public static function get_data(): array {
		return get_option( self::OPTION_KEY, [
			'key'          => '',
			'status'       => 'inactive',
			'activated_at' => '',
			'expires_at'   => '',
		] );
	}

	/**
	 * ライセンスキーを検証する（フェーズ0: オフライン署名検証）。
	 * キー形式: AK-{plan}-{expiry:YYYYMMDD}-{sig}
	 */
	public static function validate_key( string $key ): array {
		$parts = explode( '-', trim( $key ) );

		if ( count( $parts ) !== 4 || $parts[0] !== 'AK' ) {
			return [ 'valid' => false, 'message' => 'キーの形式が正しくありません。' ];
		}

		[ , $plan, $expiry, $sig ] = $parts;

		if ( ! hash_equals( self::sign( $plan, $expiry ), $sig ) ) {
			return [ 'valid' => false, 'message' => 'ライセンスキーが無効です。' ];
		}

		$expiry_date = DateTime::createFromFormat( 'Ymd', $expiry );
		if ( ! $expiry_date ) {
			return [ 'valid' => false, 'message' => '有効期限の形式が正しくありません。' ];
		}

		return [
			'valid'      => true,
			'plan'       => $plan,
			'expires_at' => $expiry_date->format( 'Y-m-d' ),
		];
	}

	/**
	 * キー発行用の署名を生成する（bin/generate-license-key.php から呼ばれる想定）。
	 * 発行手順は docs/PRO_LICENSE_ISSUING.md を参照。
	 */
	public static function sign( string $plan, string $expiry ): string {
		return substr( hash_hmac( 'sha256', $plan . '-' . $expiry, self::secret() ), 0, 16 );
	}

	/**
	 * 署名鍵を解決する。本物の鍵はコードに含めず、定数または環境変数から読み込む。
	 * どちらも無い場合はプレースホルダを返す（本物のキーは検証を通らない＝安全側）。
	 */
	private static function secret(): string {
		if ( defined( 'AFFIKEEP_LICENSE_SECRET' ) && AFFIKEEP_LICENSE_SECRET ) {
			return (string) AFFIKEEP_LICENSE_SECRET;
		}
		$env = getenv( 'AFFIKEEP_LICENSE_SECRET' );
		if ( is_string( $env ) && $env !== '' ) {
			return $env;
		}
		return self::SECRET_PLACEHOLDER;
	}

	public static function handle_activate(): void {
		check_admin_referer( 'affikeep_activate_license' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません' );
		}

		$key    = sanitize_text_field( wp_unslash( $_POST['license_key'] ?? '' ) );
		$result = self::validate_key( $key );

		if ( $result['valid'] ) {
			update_option( self::OPTION_KEY, [
				'key'          => $key,
				'status'       => 'valid',
				'activated_at' => current_time( 'Y-m-d H:i:s' ),
				'expires_at'   => $result['expires_at'],
			] );
			AffiKeep_Logger::log( 'ライセンスを有効化しました。', AffiKeep_Logger::LEVEL_INFO );
			$status = 'activated';
		} else {
			update_option( self::OPTION_KEY, [
				'key'          => $key,
				'status'       => 'invalid',
				'activated_at' => '',
				'expires_at'   => '',
			] );
			AffiKeep_Logger::error( 'ライセンスキーが無効です: ' . $result['message'] );
			$status = 'invalid';
		}

		wp_redirect( add_query_arg(
			[ 'page' => 'affikeep-settings', 'license' => $status ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public static function handle_deactivate(): void {
		check_admin_referer( 'affikeep_deactivate_license' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません' );
		}
		delete_option( self::OPTION_KEY );
		AffiKeep_Logger::log( 'ライセンスを解除しました。', AffiKeep_Logger::LEVEL_INFO );
		wp_redirect( add_query_arg(
			[ 'page' => 'affikeep-settings', 'license' => 'deactivated' ],
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
