<?php
defined( 'ABSPATH' ) || exit;

class AffiKeep_Rinker_Import {

	const RINKER_CPT    = 'yyi_rinker';
	const RINKER_BLOCK  = 'rinkerg/gutenberg-rinker';

	public static function init(): void {
		add_action( 'admin_post_affikeep_rinker_import', [ __CLASS__, 'handle_import' ] );
	}

	/** Rinker商品の件数を返す */
	public static function count(): int {
		return (int) wp_count_posts( self::RINKER_CPT )->publish
			 + (int) wp_count_posts( self::RINKER_CPT )->draft;
	}

	/**
	 * インポート実行
	 * 戻り値: ['imported' => int, 'skipped' => int, 'blocks_updated' => int]
	 */
	public static function run(): array {
		$rinker_posts = get_posts( [
			'post_type'      => self::RINKER_CPT,
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );

		$imported       = 0;
		$skipped        = 0;
		$id_map         = []; // rinker_id => affikeep_id

		foreach ( $rinker_posts as $rinker_id ) {
			$rinker_post = get_post( $rinker_id );
			if ( ! $rinker_post ) continue;

			// 既インポート済みチェック（同名商品はスキップしない：商品名が同じでも別商品の可能性あり）
			$meta = get_post_meta( $rinker_id );

			$new_id = wp_insert_post( [
				'post_type'   => AffiKeep_Post_Type::CPT,
				'post_title'  => $rinker_post->post_title,
				'post_status' => 'publish',
			] );

			if ( is_wp_error( $new_id ) ) {
				$skipped++;
				continue;
			}

			// 画像：m_image_url → s_image_url の順で使う
			$image_url = $meta['m_image_url'][0] ?? $meta['s_image_url'][0] ?? '';
			$fields = [
				'_affikeep_image_url'   => $image_url,
				'_affikeep_price'       => $meta['price'][0]       ?? '',
				'_affikeep_amazon_asin' => $meta['asin'][0]        ?? '',
				'_affikeep_amazon_url'  => $meta['amazon_url'][0]  ?? '',
				'_affikeep_rakuten_url' => $meta['rakuten_url'][0] ?? '',
				'_affikeep_yahoo_url'   => $meta['yahoo_url'][0]   ?? '',
			];

			foreach ( $fields as $key => $value ) {
				if ( $value !== '' ) {
					update_post_meta( $new_id, $key, $value );
				}
			}

			$id_map[ $rinker_id ] = $new_id;
			$imported++;
		}

		// 記事内ブロックを一括変換
		$blocks_updated = self::convert_blocks( $id_map );

		return [
			'imported'       => $imported,
			'skipped'        => $skipped,
			'blocks_updated' => $blocks_updated,
		];
	}

	/**
	 * 全記事の Rinker ブロックを AffiKeep ブロックに変換する
	 * @param array $id_map [rinker_id => affikeep_id]
	 */
	private static function convert_blocks( array $id_map ): int {
		if ( empty( $id_map ) ) return 0;

		global $wpdb;

		$block_name  = self::RINKER_BLOCK;
		$posts = $wpdb->get_results(
			"SELECT ID, post_content FROM {$wpdb->posts}
			 WHERE post_content LIKE '%{$block_name}%'
			 AND post_status NOT IN ('trash','auto-draft')"
		);

		$updated = 0;
		foreach ( $posts as $post ) {
			$new_content = preg_replace_callback(
				'/<!-- wp:rinkerg\/gutenberg-rinker (\{[^}]*\}) \/-->/',
				function ( $matches ) use ( $id_map ) {
					$attrs      = json_decode( $matches[1], true );
					$rinker_id  = intval( $attrs['post_id'] ?? 0 );
					if ( ! $rinker_id || ! isset( $id_map[ $rinker_id ] ) ) {
						return $matches[0]; // 変換できなければそのまま
					}
					$new_id = $id_map[ $rinker_id ];
					return '<!-- wp:affikeep/product {"product_id":' . $new_id . '} /-->';
				},
				$post->post_content
			);

			if ( $new_content !== $post->post_content ) {
				wp_update_post( [ 'ID' => $post->ID, 'post_content' => $new_content ] );
				$updated++;
			}
		}

		return $updated;
	}

	/** インポート実行ハンドラ（フォーム送信） */
	public static function handle_import(): void {
		check_admin_referer( 'affikeep_rinker_import' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません' );
		}

		$result = self::run();
		wp_redirect( add_query_arg( [
			'page'           => 'affikeep-import',
			'imported'       => $result['imported'],
			'skipped'        => $result['skipped'],
			'blocks_updated' => $result['blocks_updated'],
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	/** インポートページの描画 */
	public static function render_page(): void {
		$count = self::count();
		?>
		<div class="wrap affikeep-wrap">
			<h1>AffiKeep インポート</h1>

			<?php if ( isset( $_GET['imported'] ) ) : ?>
				<div class="notice notice-success is-dismissible" style="padding:12px 16px;">
					<strong>インポート完了：</strong>
					商品 <?php echo intval( $_GET['imported'] ); ?>件 を取り込みました。
					記事内ブロック <?php echo intval( $_GET['blocks_updated'] ); ?>件 を変換しました。
				</div>
			<?php endif; ?>

			<?php /* Rinkerインポート */ ?>
			<div class="affikeep-import-card">
				<h2>Rinker → AffiKeep インポート</h2>
				<p>
					登録されているRinker商品（<strong><?php echo $count; ?>件</strong>）を
					AffiKeepにコピーし、記事内のRinkerブロックをAffiKeepブロックに変換します。
				</p>
				<ul style="margin-bottom:16px;list-style:disc;padding-left:20px;line-height:1.8;">
					<li>Rinkerの商品データは<strong>削除されません</strong>（安全のため）</li>
					<li>商品名・画像・価格・Amazon/楽天/Yahoo URL を取り込みます</li>
					<li>記事内のRinkerブロックをAffiKeepブロックに自動変換します</li>
					<li>商品数が多い場合は時間がかかる場合があります</li>
				</ul>
				<?php if ( $count === 0 ) : ?>
					<p style="color:#787c82;">Rinkerの商品が見つかりません。Rinkerがインストール・有効化されているか確認してください。</p>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						onsubmit="return confirm('<?php echo esc_js( $count ); ?>件の商品をインポートします。続けますか？');">
						<input type="hidden" name="action" value="affikeep_rinker_import">
						<?php wp_nonce_field( 'affikeep_rinker_import' ); ?>
						<button type="submit" class="button button-primary button-large">
							Rinkerからインポート（<?php echo $count; ?>件）
						</button>
					</form>
				<?php endif; ?>
			</div>

			<?php /* Pochippインポート（開発中） */ ?>
			<div class="affikeep-import-card affikeep-import-card--disabled">
				<h2>Pochipp → AffiKeep インポート
					<span style="font-size:11px;background:#72777c;color:#fff;padding:2px 8px;border-radius:3px;margin-left:8px;vertical-align:middle;">開発中</span>
				</h2>
				<p style="color:#787c82;">
					Pochippからのインポート機能は現在開発中です。今後のアップデートで追加予定です。
				</p>
				<button type="button" class="button button-large" disabled>
					Pochippからインポート（準備中）
				</button>
			</div>

		</div>
		<style>
		.affikeep-import-card {
			background: #fff;
			border: 1px solid #c3c4c7;
			border-radius: 4px;
			padding: 24px;
			margin-bottom: 20px;
			max-width: 700px;
		}
		.affikeep-import-card h2 { margin-top: 0; }
		.affikeep-import-card--disabled { opacity: .6; }
		</style>
		<?php
	}
}
