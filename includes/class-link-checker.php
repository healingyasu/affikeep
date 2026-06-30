<?php
defined( 'ABSPATH' ) || exit;

class AffiKeep_Link_Checker {

	const CRON_HOOK = 'affikeep_check_links';
	const BATCH     = 20; // 1回のCronでチェックする最大件数

	public static function init(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'run_batch' ] );
		add_action( 'admin_post_affikeep_check_now', [ __CLASS__, 'handle_check_now' ] );
	}

	/** 有効化時にCronをスケジュール */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'daily', self::CRON_HOOK );
		}
	}

	/** 無効化時にCronを解除 */
	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * 最終チェックが古い順にBATCH件チェックする（Cron用）。
	 * @return array [checked, dead] 件数
	 */
	public static function run_batch(): array {
		// 未チェック商品を先に、次に最終チェックが古い順で取得
		// ※ meta_key と meta_query の同時指定は未チェック商品を除外するためNG
		$unchecked = new WP_Query( [
			'post_type'      => AffiKeep_Post_Type::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => self::BATCH,
			'fields'         => 'ids',
			'meta_query'     => [
				[ 'key' => '_affikeep_last_checked', 'compare' => 'NOT EXISTS' ],
			],
		] );

		$ids = $unchecked->posts;

		// 足りなければ古い順で補完
		$remaining = self::BATCH - count( $ids );
		if ( $remaining > 0 ) {
			$old = new WP_Query( [
				'post_type'      => AffiKeep_Post_Type::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => $remaining,
				'fields'         => 'ids',
				'orderby'        => 'meta_value',
				'meta_key'       => '_affikeep_last_checked',
				'order'          => 'ASC',
				'post__not_in'   => $ids ?: [ 0 ],
				'meta_query'     => [
					[ 'key' => '_affikeep_last_checked', 'compare' => 'EXISTS' ],
				],
			] );
			$ids = array_merge( $ids, $old->posts );
		}

		$query        = new stdClass();
		$query->posts = $ids;

		$checked = 0;
		$dead    = 0;

		foreach ( $query->posts as $post_id ) {
			$result = self::check_product( $post_id );
			$checked++;
			if ( $result === 'dead' ) {
				$dead++;
			}
			// Amazon等のレート制限回避：商品ごとにランダム間隔
			if ( $checked < count( $query->posts ) ) {
				usleep( wp_rand( 500000, 1500000 ) ); // 0.5〜1.5秒
			}
		}

		AffiKeep_Logger::log( "リンクチェック実行: {$checked}件中 {$dead}件がリンク切れ", AffiKeep_Logger::LEVEL_INFO );

		return [ 'checked' => $checked, 'dead' => $dead ];
	}

	/**
	 * 1商品の全モールURLをチェックし、総合ステータスを保存。
	 * @return string ok|dead|unknown
	 */
	public static function check_product( int $post_id ): string {
		$urls = [
			'amazon'  => get_post_meta( $post_id, '_affikeep_amazon_url',  true ),
			'rakuten' => get_post_meta( $post_id, '_affikeep_rakuten_url', true ),
			'yahoo'   => get_post_meta( $post_id, '_affikeep_yahoo_url',   true ),
		];

		$statuses = [];
		foreach ( $urls as $mall => $url ) {
			if ( empty( $url ) ) {
				continue;
			}
			$statuses[] = self::check_url( $url, $mall );
		}

		// 総合判定：1つでもdeadがあればdead、deadはないがunknownがあればunknown、それ以外ok
		if ( in_array( 'dead', $statuses, true ) ) {
			$overall = 'dead';
		} elseif ( in_array( 'unknown', $statuses, true ) ) {
			$overall = 'unknown';
		} elseif ( empty( $statuses ) ) {
			$overall = 'unknown'; // URLが1つもない
		} else {
			$overall = 'ok';
		}

		update_post_meta( $post_id, '_affikeep_link_status', $overall );
		update_post_meta( $post_id, '_affikeep_last_checked', current_time( 'Y-m-d H:i:s' ) );

		return $overall;
	}

	/**
	 * 楽天アフィリエイトURLから実際の商品URLを取り出す。
	 * hb.afl.rakuten.co.jp 経由URLはサーバーから叩くと「リンク先が無効」になるため。
	 */
	private static function resolve_rakuten_url( string $url ): string {
		$host = (string) parse_url( $url, PHP_URL_HOST );
		if ( strpos( $host, 'afl.rakuten.co.jp' ) === false ) {
			return $url;
		}
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $params );
		return ! empty( $params['pc'] ) ? $params['pc'] : $url;
	}

	/**
	 * 単一URLの生死判定。
	 * @return string ok|dead|unknown
	 */
	public static function check_url( string $url, string $mall ): string {
		if ( $mall === 'rakuten' ) {
			$url = self::resolve_rakuten_url( $url );
		}

		$args = [
			'timeout'     => 12,
			'redirection' => 5,
			'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
			'headers'     => [ 'Accept-Language' => 'ja,en;q=0.9' ],
		];

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			// タイムアウト等は一時的な可能性 → unknown（deadと断定しない）
			return 'unknown';
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		// 明確な404 → dead
		if ( $code === 404 || $code === 410 ) {
			return 'dead';
		}

		// 503/429/5xx は一時的 → unknown
		if ( $code === 503 || $code === 429 || $code >= 500 ) {
			return 'unknown';
		}

		// Amazonの特別処理（bot検知・販売終了の本文判定）
		if ( $mall === 'amazon' ) {
			return self::judge_amazon_body( $code, $body );
		}

		// 楽天・Yahoo：200系なら基本ok。本文に終了文言があればdead
		if ( $code >= 200 && $code < 400 ) {
			if ( self::body_has_dead_phrase( $body ) ) {
				return 'dead';
			}
			return 'ok';
		}

		// その他のコードは判断保留
		return 'unknown';
	}

	/** Amazon本文の判定 */
	private static function judge_amazon_body( int $code, string $body ): string {
		// bot検知・CAPTCHAページ → unknown（誤検知を避け再試行待ち）
		$bot_phrases = [
			'ロボットによる', '自動化されたアクセス', 'Type the characters',
			'api-services-support@amazon.com', 'To discuss automated access',
		];
		foreach ( $bot_phrases as $p ) {
			if ( mb_stripos( $body, $p ) !== false ) {
				return 'unknown';
			}
		}

		// 販売終了・取り扱いなし → dead
		$dead_phrases = [
			'現在お取り扱いできません', 'この商品は現在お取り扱いできません',
			'ページが見つかりませんでした', 'Page Not Found',
		];
		foreach ( $dead_phrases as $p ) {
			if ( mb_stripos( $body, $p ) !== false ) {
				return 'dead';
			}
		}

		if ( $code >= 200 && $code < 400 ) {
			return 'ok';
		}
		return 'unknown';
	}

	/** 楽天・Yahoo共通の終了文言判定 */
	private static function body_has_dead_phrase( string $body ): bool {
		$phrases = [
			'販売を終了', 'ページが見つかりません', '商品が見つかりません',
			'お探しのページは見つかりませんでした', 'この商品は現在販売されておりません',
		];
		foreach ( $phrases as $p ) {
			if ( mb_stripos( $body, $p ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/** リンク切れ件数を数える（バッジ・ダッシュボード用） */
	public static function count_dead(): int {
		$q = new WP_Query( [
			'post_type'      => AffiKeep_Post_Type::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [
				[ 'key' => '_affikeep_link_status', 'value' => 'dead' ],
			],
		] );
		return $q->found_posts;
	}

	/** ステータス別の件数をまとめて取得 */
	public static function count_by_status(): array {
		$counts = [ 'ok' => 0, 'dead' => 0, 'unknown' => 0 ];

		$q = new WP_Query( [
			'post_type'      => AffiKeep_Post_Type::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );

		foreach ( $q->posts as $id ) {
			$s = get_post_meta( $id, '_affikeep_link_status', true ) ?: 'unknown';
			if ( ! isset( $counts[ $s ] ) ) {
				$s = 'unknown';
			}
			$counts[ $s ]++;
		}
		return $counts;
	}

	/** 「今すぐチェック」ボタンのハンドラ */
	public static function handle_check_now(): void {
		check_admin_referer( 'affikeep_check_now' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません' );
		}

		$result = self::run_batch();

		$redirect = add_query_arg(
			[
				'page'    => 'affikeep-links',
				'checked' => $result['checked'],
				'dead'    => $result['dead'],
			],
			admin_url( 'admin.php' )
		);
		wp_redirect( $redirect );
		exit;
	}
}
