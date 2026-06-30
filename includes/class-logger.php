<?php
defined( 'ABSPATH' ) || exit;

/**
 * エラーログをwp_optionsに保存し、管理画面で表示する。
 * ターミナル不要でエラーを確認できるようにするためのクラス。
 */
class AffiKeep_Logger {

	const OPTION_KEY = 'affikeep_error_log';
	const MAX_ENTRIES = 100;

	const LEVEL_INFO  = 'info';
	const LEVEL_WARN  = 'warn';
	const LEVEL_ERROR = 'error';

	/**
	 * ログを記録する。
	 * 使い方: AffiKeep_Logger::log( 'メッセージ', AffiKeep_Logger::LEVEL_ERROR );
	 */
	public static function log( string $message, string $level = self::LEVEL_INFO, array $context = [] ): void {
		$entries = get_option( self::OPTION_KEY, [] );

		$entry = [
			'time'    => current_time( 'Y-m-d H:i:s' ),
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		];

		array_unshift( $entries, $entry ); // 新しいものを先頭に

		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, 0, self::MAX_ENTRIES );
		}

		update_option( self::OPTION_KEY, $entries, false );
	}

	/** エラーレベルのショートカット */
	public static function error( string $message, array $context = [] ): void {
		self::log( $message, self::LEVEL_ERROR, $context );
	}

	/** 警告レベルのショートカット */
	public static function warn( string $message, array $context = [] ): void {
		self::log( $message, self::LEVEL_WARN, $context );
	}

	/** ログを全件取得 */
	public static function get_all(): array {
		return get_option( self::OPTION_KEY, [] );
	}

	/** ログを全件削除 */
	public static function clear(): void {
		delete_option( self::OPTION_KEY );
	}
}
