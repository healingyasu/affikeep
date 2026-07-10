<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pro機能: 商品一覧・クリック/収益レポートのCSVエクスポート。
 */
class AffiKeep_CSV_Export {

	public static function init(): void {
		add_action( 'admin_post_affikeep_export_products_csv', [ __CLASS__, 'handle_export_products' ] );
		add_action( 'admin_post_affikeep_export_clicks_csv',   [ __CLASS__, 'handle_export_clicks' ] );
	}

	/** 商品一覧CSVエクスポート（商品名・価格・対応モール・リンク状態・最終チェック日時・累計クリック数） */
	public static function handle_export_products(): void {
		check_admin_referer( 'affikeep_export_products_csv' );
		if ( ! current_user_can( 'manage_options' ) || ! AffiKeep_License::is_active() ) {
			wp_die( '権限がありません' );
		}

		$posts = get_posts( [
			'post_type'      => AffiKeep_Post_Type::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );

		self::stream_csv_header( 'affikeep-products-' . gmdate( 'Ymd' ) . '.csv' );

		$out       = fopen( 'php://output', 'w' );
		$mall_defs = AffiKeep_Malls::available();

		self::write_csv_row( $out, array_merge(
			[ '商品名', '価格' ],
			array_column( $mall_defs, 'label' ),
			[ 'リンク状態', '最終チェック日時', '累計クリック数' ]
		) );

		$status_labels = [ 'ok' => '正常', 'dead' => 'リンク切れ', 'unknown' => '未チェック' ];

		foreach ( $posts as $id ) {
			$status = get_post_meta( $id, '_affikeep_link_status', true ) ?: 'unknown';
			$price  = get_post_meta( $id, '_affikeep_price', true ) ?: get_post_meta( $id, '_affikeep_amazon_price', true );

			$mall_cells = [];
			foreach ( $mall_defs as $mall_id => $def ) {
				$mall_cells[] = get_post_meta( $id, "_affikeep_{$mall_id}_url", true ) ? '○' : '';
			}

			self::write_csv_row( $out, array_merge(
				[ get_the_title( $id ), $price ],
				$mall_cells,
				[
					$status_labels[ $status ] ?? $status,
					get_post_meta( $id, '_affikeep_last_checked', true ) ?: '',
					self::product_total_clicks( $id ),
				]
			) );
		}

		fclose( $out );
		exit;
	}

	/** クリック/収益レポートCSVエクスポート（期間指定。日付・商品名・記事タイトル・モール・クリック数・収益概算） */
	public static function handle_export_clicks(): void {
		check_admin_referer( 'affikeep_export_clicks_csv' );
		if ( ! current_user_can( 'manage_options' ) || ! AffiKeep_License::is_active() ) {
			wp_die( '権限がありません' );
		}

		$days = max( 1, min( 365, intval( $_GET['days'] ?? 30 ) ) );

		global $wpdb;
		$table = $wpdb->prefix . 'affikeep_clicks';
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days", current_time( 'timestamp' ) ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT click_date, product_id, post_id, mall, clicks FROM {$table} WHERE click_date >= %s ORDER BY click_date ASC",
			$since
		), ARRAY_A );

		$assumptions = AffiKeep_Analytics::get_revenue_assumptions();
		$mall_labels = [];
		foreach ( AffiKeep_Malls::definitions() as $id => $def ) {
			$mall_labels[ $id ] = $def['label'];
		}

		self::stream_csv_header( 'affikeep-clicks-' . gmdate( 'Ymd' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		self::write_csv_row( $out, [ '日付', '商品名', '記事タイトル', 'モール', 'クリック数', '収益概算（円）' ] );

		foreach ( $rows as $row ) {
			$mall     = $row['mall'];
			$a        = $assumptions[ $mall ] ?? [ 'conversion_rate' => 0, 'commission' => 0 ];
			$estimate = round( $row['clicks'] * ( $a['conversion_rate'] / 100 ) * $a['commission'] );

			self::write_csv_row( $out, [
				$row['click_date'],
				get_the_title( (int) $row['product_id'] ) ?: '(削除済み)',
				get_the_title( (int) $row['post_id'] )    ?: '(削除済み)',
				$mall_labels[ $mall ] ?? $mall,
				$row['clicks'],
				$estimate,
			] );
		}

		fclose( $out );
		exit;
	}

	private static function product_total_clicks( int $product_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'affikeep_clicks';
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM(clicks) FROM {$table} WHERE product_id = %d", $product_id
		) );
	}

	/** CSVダウンロード用ヘッダーを出力する（Excelでの文字化け対策としてUTF-8 BOMを付与） */
	private static function stream_csv_header( string $filename ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo "\xEF\xBB\xBF";
	}

	/**
	 * fputcsv()のラッパー。PHP 8.4以降、$escapeを省略すると非推奨警告が出るため明示的に渡す
	 * （警告がストリーム出力に混入してCSVを壊すのを防ぐ）。
	 */
	private static function write_csv_row( $handle, array $fields ): void {
		fputcsv( $handle, $fields, ',', '"', '\\' );
	}
}
