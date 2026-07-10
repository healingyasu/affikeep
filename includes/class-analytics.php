<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pro機能: クリック計測・収益分析ダッシュボード。
 * 全ての機能はAffiKeep_License::is_active()がtrueの場合のみ動作する。
 */
class AffiKeep_Analytics {

	const DB_VERSION_OPTION = 'affikeep_clicks_db_version';
	const DB_VERSION        = '1.0';
	const REVENUE_OPTION    = 'affikeep_revenue_assumptions';
	const RATE_LIMIT_MAX    = 30; // 同一送信元からの1分間あたりの上限

	public static function init(): void {
		add_action( 'admin_init',        [ __CLASS__, 'maybe_upgrade_db' ] );
		add_action( 'admin_menu',        [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
		add_action( 'rest_api_init',     [ __CLASS__, 'register_routes' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_tracker' ] );
		add_action( 'admin_post_affikeep_save_revenue_assumptions', [ __CLASS__, 'handle_save_revenue_assumptions' ] );
	}

	private static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'affikeep_clicks';
	}

	/** クリック集計テーブルをdbDeltaで作成・更新する（Pro有効時のみ） */
	public static function maybe_upgrade_db(): void {
		if ( ! AffiKeep_License::is_active() ) {
			return;
		}
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  click_date DATE NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  post_id BIGINT UNSIGNED NOT NULL,
  mall VARCHAR(20) NOT NULL,
  clicks INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY click_unique (click_date,product_id,post_id,mall)
) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/** 1クリックを日次集計に加算する */
	public static function record_click( int $product_id, int $post_id, string $mall ): void {
		global $wpdb;
		$table = self::table_name();
		$today = current_time( 'Y-m-d' );

		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (click_date, product_id, post_id, mall, clicks) VALUES (%s, %d, %d, %s, 1)
			 ON DUPLICATE KEY UPDATE clicks = clicks + 1",
			$today, $product_id, $post_id, $mall
		) );
	}

	// ----------------------------------------------------------------
	// フロントエンド計測
	// ----------------------------------------------------------------

	public static function enqueue_tracker(): void {
		if ( ! AffiKeep_License::is_active() ) {
			return;
		}
		wp_enqueue_script(
			'affikeep-click-tracker',
			AFFIKEEP_URL . 'assets/click-tracker.js',
			[],
			AFFIKEEP_VERSION,
			true
		);
		wp_localize_script( 'affikeep-click-tracker', 'affikeepClickTracker', [
			'endpoint' => rest_url( 'affikeep/v1/click' ),
		] );
	}

	public static function register_routes(): void {
		if ( ! AffiKeep_License::is_active() ) {
			return;
		}
		register_rest_route( 'affikeep/v1', '/click', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_record_click' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'product_id' => [ 'required' => true ],
				'post_id'    => [ 'required' => true ],
				'mall'       => [ 'required' => true ],
			],
		] );
	}

	public static function rest_record_click( WP_REST_Request $request ): WP_REST_Response {
		$product_id = absint( $request->get_param( 'product_id' ) );
		$post_id    = absint( $request->get_param( 'post_id' ) );
		$mall       = sanitize_key( (string) $request->get_param( 'mall' ) );

		if ( $product_id && in_array( $mall, AffiKeep_Malls::ids(), true ) && self::rate_limit_ok() ) {
			self::record_click( $product_id, $post_id, $mall );
		}

		// スパム対策のため成否によらず常に同じ空レスポンスを返す
		return new WP_REST_Response( null, 204 );
	}

	/** IPをハッシュ化したtransientキーで簡易レート制限（生IPは保存しない） */
	private static function rate_limit_ok(): bool {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$key   = 'affikeep_crl_' . md5( $ip . wp_salt() );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_MAX ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	// ----------------------------------------------------------------
	// データ集計
	// ----------------------------------------------------------------

	/** 日別×モール別クリック数。戻り値: [ 'YYYY-MM-DD' => [ mall => count, ... ], ... ]（日付昇順、欠損日は0埋め） */
	public static function get_daily_series( int $days ): array {
		global $wpdb;
		$table = self::table_name();
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days", current_time( 'timestamp' ) ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT click_date, mall, SUM(clicks) AS total
			 FROM {$table}
			 WHERE click_date >= %s
			 GROUP BY click_date, mall",
			$since
		), ARRAY_A );

		$mall_ids = AffiKeep_Malls::ids();
		$series   = [];
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date = gmdate( 'Y-m-d', strtotime( "-{$i} days", current_time( 'timestamp' ) ) );
			$series[ $date ] = array_fill_keys( $mall_ids, 0 );
		}
		foreach ( $rows as $row ) {
			if ( isset( $series[ $row['click_date'] ][ $row['mall'] ] ) ) {
				$series[ $row['click_date'] ][ $row['mall'] ] = (int) $row['total'];
			}
		}
		return $series;
	}

	/** 商品別クリック数ランキング */
	public static function get_product_ranking( int $days, int $limit = 10 ): array {
		global $wpdb;
		$table = self::table_name();
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days", current_time( 'timestamp' ) ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT product_id, SUM(clicks) AS total
			 FROM {$table}
			 WHERE click_date >= %s
			 GROUP BY product_id
			 ORDER BY total DESC
			 LIMIT %d",
			$since, $limit
		), ARRAY_A );

		return array_map( function ( $row ) {
			$id = (int) $row['product_id'];
			return [
				'product_id' => $id,
				'title'      => get_the_title( $id ) ?: '(削除済み)',
				'edit_url'   => get_edit_post_link( $id, 'raw' ),
				'total'      => (int) $row['total'],
			];
		}, $rows );
	}

	/** 記事別クリック数ランキング */
	public static function get_post_ranking( int $days, int $limit = 10 ): array {
		global $wpdb;
		$table = self::table_name();
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days", current_time( 'timestamp' ) ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, SUM(clicks) AS total
			 FROM {$table}
			 WHERE click_date >= %s
			 GROUP BY post_id
			 ORDER BY total DESC
			 LIMIT %d",
			$since, $limit
		), ARRAY_A );

		return array_map( function ( $row ) {
			$id = (int) $row['post_id'];
			return [
				'post_id'  => $id,
				'title'    => get_the_title( $id ) ?: '(削除済み)',
				'edit_url' => get_edit_post_link( $id, 'raw' ),
				'total'    => (int) $row['total'],
			];
		}, $rows );
	}

	/** モール別合計クリック数 */
	public static function get_mall_totals( int $days ): array {
		global $wpdb;
		$table = self::table_name();
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days", current_time( 'timestamp' ) ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT mall, SUM(clicks) AS total FROM {$table} WHERE click_date >= %s GROUP BY mall",
			$since
		), ARRAY_A );

		$totals = array_fill_keys( AffiKeep_Malls::ids(), 0 );
		foreach ( $rows as $row ) {
			if ( isset( $totals[ $row['mall'] ] ) ) {
				$totals[ $row['mall'] ] = (int) $row['total'];
			}
		}
		return $totals;
	}

	// ----------------------------------------------------------------
	// 収益概算（あくまで見積り。実績データではない）
	// ----------------------------------------------------------------

	public static function get_revenue_assumptions(): array {
		$defaults = [];
		foreach ( AffiKeep_Malls::ids() as $id ) {
			$defaults[ $id ] = [ 'conversion_rate' => 0.0, 'commission' => 0.0 ];
		}
		$saved = get_option( self::REVENUE_OPTION, [] );
		foreach ( $defaults as $id => $d ) {
			if ( isset( $saved[ $id ] ) ) {
				$defaults[ $id ] = [
					'conversion_rate' => (float) ( $saved[ $id ]['conversion_rate'] ?? 0 ),
					'commission'      => (float) ( $saved[ $id ]['commission'] ?? 0 ),
				];
			}
		}
		return $defaults;
	}

	/** クリック数×成約率×報酬単価の概算合計（円） */
	public static function estimate_revenue( array $mall_totals ): float {
		$assumptions = self::get_revenue_assumptions();
		$total       = 0.0;
		foreach ( $mall_totals as $mall => $clicks ) {
			if ( ! isset( $assumptions[ $mall ] ) ) {
				continue;
			}
			$a      = $assumptions[ $mall ];
			$total += $clicks * ( $a['conversion_rate'] / 100 ) * $a['commission'];
		}
		return $total;
	}

	public static function handle_save_revenue_assumptions(): void {
		check_admin_referer( 'affikeep_save_revenue_assumptions' );
		if ( ! current_user_can( 'manage_options' ) || ! AffiKeep_License::is_active() ) {
			wp_die( '権限がありません' );
		}

		$input = (array) ( $_POST['revenue'] ?? [] );
		$clean = [];
		foreach ( AffiKeep_Malls::ids() as $id ) {
			$row = (array) ( $input[ $id ] ?? [] );
			$clean[ $id ] = [
				'conversion_rate' => max( 0, (float) ( $row['conversion_rate'] ?? 0 ) ),
				'commission'      => max( 0, (float) ( $row['commission'] ?? 0 ) ),
			];
		}
		update_option( self::REVENUE_OPTION, $clean );

		wp_redirect( add_query_arg(
			[ 'page' => 'affikeep-analytics', 'saved' => 1 ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	// ----------------------------------------------------------------
	// 管理画面
	// ----------------------------------------------------------------

	public static function add_menu(): void {
		add_submenu_page(
			'affikeep',
			'アクセス解析 | AffiKeep',
			'アクセス解析',
			'manage_options',
			'affikeep-analytics',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function enqueue_admin_assets( string $hook ): void {
		if ( $hook !== 'affikeep_page_affikeep-analytics' ) {
			return;
		}
		wp_enqueue_script(
			'affikeep-analytics-chart',
			AFFIKEEP_URL . 'assets/analytics-chart.js',
			[],
			AFFIKEEP_VERSION,
			true
		);
	}

	/** 固定順のモール別カラー（dataviz categorical slot 1-3。CVD安全性を検証済み） */
	private static function mall_colors(): array {
		return [ '#2a78d6', '#1baf7a', '#eda100', '#4a3aa7', '#e34948' ];
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! AffiKeep_License::is_active() ) {
			self::render_upgrade_notice();
			return;
		}

		$days     = isset( $_GET['days'] ) ? max( 1, min( 365, intval( $_GET['days'] ) ) ) : 30;
		$base_url = admin_url( 'admin.php?page=affikeep-analytics' );

		$mall_ids    = AffiKeep_Malls::ids();
		$mall_labels = [];
		foreach ( AffiKeep_Malls::definitions() as $id => $def ) {
			$mall_labels[ $id ] = $def['label'];
		}
		$colors = array_slice( self::mall_colors(), 0, count( $mall_ids ) );

		$series           = self::get_daily_series( $days );
		$mall_totals      = self::get_mall_totals( $days );
		$total_clicks     = array_sum( $mall_totals );
		$product_ranking  = self::get_product_ranking( $days );
		$post_ranking     = self::get_post_ranking( $days );
		$revenue_estimate = self::estimate_revenue( $mall_totals );
		$assumptions      = self::get_revenue_assumptions();
		$has_assumptions  = array_sum( array_column( $assumptions, 'commission' ) ) > 0;
		?>
		<div class="wrap affikeep-wrap">
			<h1>アクセス解析</h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible" style="padding:12px 16px;">
					<strong>収益概算の設定を保存しました。</strong>
				</div>
			<?php endif; ?>

			<div style="margin-bottom:16px;display:flex;gap:4px;flex-wrap:wrap;align-items:center;">
				<?php foreach ( [ 7 => '7日間', 30 => '30日間', 90 => '90日間' ] as $d => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'days', $d, $base_url ) ); ?>"
						class="button <?php echo $days === $d ? 'button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
				<a href="<?php echo esc_url( wp_nonce_url(
					add_query_arg( [ 'action' => 'affikeep_export_clicks_csv', 'days' => $days ], admin_url( 'admin-post.php' ) ),
					'affikeep_export_clicks_csv'
				) ); ?>" class="button" style="margin-left:8px;">
					📄 この期間をCSVでエクスポート
				</a>
			</div>

			<div class="affikeep-cards">
				<div class="affikeep-card">
					<h2>期間合計クリック数</h2>
					<p class="affikeep-big-num"><?php echo esc_html( number_format( $total_clicks ) ); ?></p>
					<p class="affikeep-note">直近<?php echo esc_html( $days ); ?>日間</p>
				</div>
				<div class="affikeep-card">
					<h2>収益概算</h2>
					<p class="affikeep-big-num">¥<?php echo esc_html( number_format( $revenue_estimate ) ); ?></p>
					<p class="affikeep-note">
						<?php if ( $has_assumptions ) : ?>
							クリック数×成約率×報酬単価の見積り（実績ではありません）
						<?php else : ?>
							下部の「収益概算の設定」で成約率・報酬単価を入力すると概算が表示されます
						<?php endif; ?>
					</p>
				</div>
				<?php foreach ( $mall_ids as $i => $mall ) : ?>
					<div class="affikeep-card">
						<h2><?php echo esc_html( $mall_labels[ $mall ] ); ?></h2>
						<p class="affikeep-big-num" style="color:<?php echo esc_attr( $colors[ $i ] ); ?>;">
							<?php echo esc_html( number_format( $mall_totals[ $mall ] ) ); ?>
						</p>
					</div>
				<?php endforeach; ?>
			</div>

			<h2 class="affikeep-section-title">日別クリック数の推移</h2>
			<div id="affikeep-analytics-chart" style="max-width:900px;"></div>
			<script>
			document.addEventListener('DOMContentLoaded', function () {
				if (typeof affikeepDrawChart === 'function') {
					affikeepDrawChart(
						'affikeep-analytics-chart',
						<?php echo wp_json_encode( $series ); ?>,
						<?php echo wp_json_encode( array_values( $mall_ids ) ); ?>,
						<?php echo wp_json_encode( $mall_labels ); ?>,
						<?php echo wp_json_encode( $colors ); ?>
					);
				}
			});
			</script>

			<h2 class="affikeep-section-title">商品別クリック数ランキング</h2>
			<?php if ( empty( $product_ranking ) ) : ?>
				<p>この期間のクリックはまだありません。</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:700px;">
					<thead><tr><th>商品名</th><th style="width:120px;">クリック数</th></tr></thead>
					<tbody>
					<?php foreach ( $product_ranking as $row ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( $row['edit_url'] ); ?>"><?php echo esc_html( $row['title'] ); ?></a></td>
							<td><?php echo esc_html( number_format( $row['total'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2 class="affikeep-section-title">記事別クリック数ランキング</h2>
			<?php if ( empty( $post_ranking ) ) : ?>
				<p>この期間のクリックはまだありません。</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:700px;">
					<thead><tr><th>記事名</th><th style="width:120px;">クリック数</th></tr></thead>
					<tbody>
					<?php foreach ( $post_ranking as $row ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( $row['edit_url'] ); ?>"><?php echo esc_html( $row['title'] ); ?></a></td>
							<td><?php echo esc_html( number_format( $row['total'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2 class="affikeep-section-title">収益概算の設定</h2>
			<p class="description">
				モールごとに「クリックのうち何%が購入につながるか（成約率）」「1件あたりの報酬額（円）」を入力すると、上部の収益概算に反映されます。
				<strong>実際の成果とは異なる見積りです。</strong>正確な収益は各ASPの管理画面でご確認ください。
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="affikeep_save_revenue_assumptions">
				<?php wp_nonce_field( 'affikeep_save_revenue_assumptions' ); ?>
				<table class="form-table">
					<?php foreach ( $mall_ids as $mall ) : ?>
						<tr>
							<th><?php echo esc_html( $mall_labels[ $mall ] ); ?></th>
							<td>
								成約率
								<input type="number" step="0.01" min="0" max="100"
									name="revenue[<?php echo esc_attr( $mall ); ?>][conversion_rate]"
									value="<?php echo esc_attr( $assumptions[ $mall ]['conversion_rate'] ); ?>"
									style="width:80px;"> %
								&nbsp;&nbsp;
								報酬単価
								<input type="number" step="1" min="0"
									name="revenue[<?php echo esc_attr( $mall ); ?>][commission]"
									value="<?php echo esc_attr( $assumptions[ $mall ]['commission'] ); ?>"
									style="width:100px;"> 円/件
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button( '保存する' ); ?>
			</form>
		</div>
		<?php
	}

	private static function render_upgrade_notice(): void {
		?>
		<div class="wrap affikeep-wrap">
			<h1>アクセス解析</h1>
			<div class="notice notice-info inline" style="padding:20px 24px;">
				<h2 style="margin-top:0;">Pro機能です</h2>
				<p>記事別・商品別のクリック数推移や収益概算を確認できます。</p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=affikeep-settings' ) ); ?>">
						ライセンスを有効化する
					</a>
				</p>
			</div>
		</div>
		<?php
	}
}
