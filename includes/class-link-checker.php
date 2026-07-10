<?php
defined( 'ABSPATH' ) || exit;

class AffiKeep_Link_Checker {

	const CRON_HOOK   = 'affikeep_check_links';
	const BATCH       = 20; // 1回のCronでチェックする最大件数
	const AJAX_BATCH  = 5;  // AJAX自動チェック1回の件数（タイムアウト回避）

	public static function init(): void {
		add_action( self::CRON_HOOK,                     [ __CLASS__, 'run_batch' ] );
		add_action( 'admin_post_affikeep_check_now',     [ __CLASS__, 'handle_check_now' ] );
		add_action( 'wp_ajax_affikeep_auto_check',       [ __CLASS__, 'ajax_auto_check' ] );
		add_action( 'wp_ajax_affikeep_recalc_statuses',  [ __CLASS__, 'ajax_recalculate' ] );
		add_action( 'wp_ajax_affikeep_check_single',     [ __CLASS__, 'ajax_check_single' ] );
	}

	/** 商品編集画面用: 1商品だけを即時チェックする（未チェック商品の自動チェック・手動再チェックボタン用） */
	public static function ajax_check_single(): void {
		check_ajax_referer( 'affikeep_check_single', 'nonce' );

		$post_id = intval( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || get_post_type( $post_id ) !== AffiKeep_Post_Type::CPT ) {
			wp_send_json_error( '商品IDが無効です' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( '権限がありません' );
		}

		$status = self::check_product( $post_id );
		wp_send_json_success( [
			'status'       => $status,
			'last_checked' => get_post_meta( $post_id, '_affikeep_last_checked', true ) ?: '',
		] );
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
	 * @param int $limit 処理件数（省略時はBATCH定数）
	 * @return array [checked, dead] 件数
	 */
	public static function run_batch( int $limit = self::BATCH ): array {
		// 未チェック商品を先に、次に最終チェックが古い順で取得
		// ※ meta_key と meta_query の同時指定は未チェック商品を除外するためNG
		$unchecked = new WP_Query( [
			'post_type'      => AffiKeep_Post_Type::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'meta_query'     => [
				[ 'key' => '_affikeep_last_checked', 'compare' => 'NOT EXISTS' ],
			],
		] );

		$ids = $unchecked->posts;

		// 足りなければ古い順で補完
		$remaining = $limit - count( $ids );
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

		// Cronの自動実行時のみメール通知（手動チェック・AJAX連続実行では送らない）
		if ( $dead > 0 && wp_doing_cron() ) {
			self::notify_dead_links( $dead );
		}

		return [ 'checked' => $checked, 'dead' => $dead ];
	}

	/** リンク切れ検出をメールで知らせる */
	private static function notify_dead_links( int $dead ): void {
		$to = AffiKeep_Settings::get( 'notify_email' ) ?: get_option( 'admin_email' );
		if ( ! $to ) {
			return;
		}
		$subject = '[AffiKeep] リンク切れを' . $dead . '件検出しました';
		$body    = "自動リンクチェックで {$dead}件 のリンク切れを検出しました。\n\n"
				 . "管理画面で確認してください：\n"
				 . admin_url( 'admin.php?page=affikeep-links&filter=dead' ) . "\n";
		wp_mail( $to, $subject, $body );
	}

	/**
	 * 1商品の全モールURLをチェックし、ステータスを保存。
	 * 総合ステータスはAmazon除外（常にunknownのため）で判定する。
	 * @return string ok|dead|unknown
	 */
	public static function check_product( int $post_id ): string {
		$urls = [
			'amazon'  => get_post_meta( $post_id, '_affikeep_amazon_url',  true ),
			'rakuten' => get_post_meta( $post_id, '_affikeep_rakuten_url', true ),
			'yahoo'   => get_post_meta( $post_id, '_affikeep_yahoo_url',   true ),
		];

		$non_amazon = []; // 楽天・Yahoo のみ集計

		foreach ( $urls as $mall => $url ) {
			if ( empty( $url ) ) {
				continue;
			}
			$status = self::check_url( $url, $mall );

			// 楽天・Yahoo はモール別にも保存（Amazon はbot検知で常にunknownのため保存しない）
			if ( $mall !== 'amazon' ) {
				update_post_meta( $post_id, "_affikeep_{$mall}_status", $status );
				$non_amazon[] = $status;
			}
		}

		// 総合判定：楽天・Yahoo のみで判定（Amazon除外）
		// ok = 1つでも正常、dead = 全モール切れ、unknown = それ以外
		if ( empty( $non_amazon ) ) {
			$overall = 'unknown'; // 楽天・Yahoo のURLがない（Amazonのみ）
		} elseif ( in_array( 'ok', $non_amazon, true ) ) {
			$overall = 'ok'; // 1つでも正常なら正常
		} elseif ( in_array( 'unknown', $non_amazon, true ) ) {
			$overall = 'unknown'; // 不明が混じる場合は保留
		} else {
			$overall = 'dead'; // 全モール切れのときだけリンク切れ
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
			self::log_check( $mall, $url, 0, 'unknown', '通信エラー: ' . $response->get_error_message() );
			return 'unknown';
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		// 明確な404 → dead
		if ( $code === 404 || $code === 410 ) {
			self::log_check( $mall, $url, $code, 'dead', 'HTTPステータス ' . $code );
			return 'dead';
		}

		// 503/429/5xx は一時的 → unknown
		if ( $code === 503 || $code === 429 || $code >= 500 ) {
			self::log_check( $mall, $url, $code, 'unknown', 'HTTPステータス ' . $code . '（一時的）' );
			return 'unknown';
		}

		$def = AffiKeep_Malls::get( $mall ) ?? [];

		// bot検知文言・要確認文言を持つモール（Amazon）はコード範囲に関わらず本文判定する
		if ( ! empty( $def['bot_phrases'] ) || ! empty( $def['unknown_phrases'] ) ) {
			[ $result, $reason ] = self::judge_by_phrases( $def, $code, $body );
			self::log_check( $mall, $url, $code, $result, $reason );
			return $result;
		}

		// 楽天・Yahoo：200系なら基本ok。本文に終了文言があればdead
		if ( $code >= 200 && $code < 400 ) {
			$matched = self::find_dead_phrase( $body, $def['dead_phrases'] ?? [] );
			$result  = $matched ? 'dead' : 'ok';
			self::log_check( $mall, $url, $code, $result, $matched ? "終了文言「{$matched}」を検出" : '異常なし' );
			return $result;
		}

		// その他のコードは判断保留
		self::log_check( $mall, $url, $code, 'unknown', 'HTTPステータス ' . $code );
		return 'unknown';
	}

	/**
	 * 既存のモール別ステータスメタから総合ステータスを再計算する（URL再確認なし）
	 * ロジック変更後に既存データを即座に修正するために使う
	 */
	public static function recalculate_all_statuses(): int {
		$posts = get_posts( [
			'post_type'      => AffiKeep_Post_Type::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );

		$updated = 0;
		foreach ( $posts as $id ) {
			$rakuten_url    = get_post_meta( $id, '_affikeep_rakuten_url',    true );
			$yahoo_url      = get_post_meta( $id, '_affikeep_yahoo_url',      true );
			$rakuten_status = get_post_meta( $id, '_affikeep_rakuten_status', true );
			$yahoo_status   = get_post_meta( $id, '_affikeep_yahoo_status',   true );

			$non_amazon = [];
			if ( $rakuten_url && $rakuten_status ) $non_amazon[] = $rakuten_status;
			if ( $yahoo_url   && $yahoo_status   ) $non_amazon[] = $yahoo_status;

			if ( empty( $non_amazon ) ) {
				$overall = 'unknown';
			} elseif ( in_array( 'ok', $non_amazon, true ) ) {
				$overall = 'ok';
			} elseif ( in_array( 'unknown', $non_amazon, true ) ) {
				$overall = 'unknown';
			} else {
				$overall = 'dead';
			}

			$current = get_post_meta( $id, '_affikeep_link_status', true ) ?: 'unknown';
			if ( $current !== $overall ) {
				update_post_meta( $id, '_affikeep_link_status', $overall );
				$updated++;
			}
		}
		return $updated;
	}

	/** ステータス再計算AJAXハンドラ */
	public static function ajax_recalculate(): void {
		check_ajax_referer( 'affikeep_recalc', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '権限がありません' );
		}
		$updated = self::recalculate_all_statuses();
		wp_send_json_success( [ 'updated' => $updated ] );
	}

	/** リンクチェックの判定理由をログに残す（誤検知調査用） */
	private static function log_check( string $mall, string $url, int $code, string $result, string $reason ): void {
		AffiKeep_Logger::log( "リンクチェック詳細（{$mall}）", AffiKeep_Logger::LEVEL_INFO, [
			'url'      => $url,
			'code'     => $code,
			'judgment' => $result,
			'reason'   => $reason,
		] );
	}

	/**
	 * bot検知文言・要確認文言を持つモール（Amazon）向けの本文判定。
	 * 優先順位: bot検知 → 要確認 → 販売終了 → HTTPステータスによるok/unknown。
	 * @return array [ 判定結果, 理由文字列 ]
	 */
	private static function judge_by_phrases( array $def, int $code, string $body ): array {
		foreach ( $def['bot_phrases'] ?? [] as $p ) {
			if ( mb_stripos( $body, $p ) !== false ) {
				return [ 'unknown', "bot検知ページ「{$p}」を検出（判定保留）" ];
			}
		}
		foreach ( $def['unknown_phrases'] ?? [] as $p ) {
			if ( mb_stripos( $body, $p ) !== false ) {
				return [ 'unknown', "要確認文言「{$p}」を検出（bot検知の可能性あり）" ];
			}
		}
		foreach ( $def['dead_phrases'] ?? [] as $p ) {
			if ( mb_stripos( $body, $p ) !== false ) {
				return [ 'dead', "終了文言「{$p}」を検出" ];
			}
		}
		if ( $code >= 200 && $code < 400 ) {
			return [ 'ok', '異常なし' ];
		}
		return [ 'unknown', 'HTTPステータス ' . $code ];
	}

	/** 終了文言リストと本文を照合。マッチした文言を返す（なければ空文字） */
	private static function find_dead_phrase( string $body, array $phrases ): string {
		foreach ( $phrases as $p ) {
			if ( mb_stripos( $body, $p ) !== false ) {
				return $p;
			}
		}
		return '';
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

	/** 全件自動チェック用AJAXハンドラ（AJAX_BATCH件だけ実行してJSONを返す） */
	public static function ajax_auto_check(): void {
		check_ajax_referer( 'affikeep_auto_check', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '権限がありません' );
		}
		@set_time_limit( 60 );
		$result = self::run_batch( self::AJAX_BATCH );
		wp_send_json_success( [
			'checked'    => $result['checked'],
			'dead'       => $result['dead'],
			'dead_total' => self::count_dead(),
		] );
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
