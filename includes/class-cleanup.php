<?php
defined( 'ABSPATH' ) || exit;

/**
 * 記事整理：古い記事からAffiKeepブロックを一括削除する
 */
class AffiKeep_Cleanup {

	const BLOCK_REGEX = '/<!-- wp:affikeep\/product \{[^}]*\} \/-->\n?/';

	public static function init(): void {
		add_action( 'admin_post_affikeep_cleanup_run', [ __CLASS__, 'handle_run' ] );
	}

	/**
	 * 指定年以前に最終更新された、AffiKeepブロックを含む記事を取得
	 * 「2022年以前」= post_modified が 2023-01-01 より前
	 */
	public static function find_articles( int $year ): array {
		global $wpdb;
		$cutoff = sprintf( '%d-01-01 00:00:00', $year + 1 );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title, post_modified, post_content, post_status
			 FROM {$wpdb->posts}
			 WHERE post_type IN ('post','page')
			 AND post_status NOT IN ('trash','auto-draft','inherit')
			 AND post_content LIKE '%wp:affikeep/product%'
			 AND post_modified < %s
			 ORDER BY post_modified ASC",
			$cutoff
		) );

		$result = [];
		foreach ( $rows as $row ) {
			$count = preg_match_all( self::BLOCK_REGEX, $row->post_content, $m );
			if ( $count > 0 ) {
				$result[] = [
					'id'       => (int) $row->ID,
					'title'    => $row->post_title,
					'modified' => $row->post_modified,
					'blocks'   => $count,
					'status'   => $row->post_status,
				];
			}
		}
		return $result;
	}

	/**
	 * 一括削除の実行。
	 * 記事の更新日を変えないよう直接UPDATEし、キャッシュを消す。
	 * @return array [posts => 更新記事数, blocks => 削除ブロック数]
	 */
	public static function run( int $year ): array {
		global $wpdb;

		$articles       = self::find_articles( $year );
		$posts_updated  = 0;
		$blocks_removed = 0;

		foreach ( $articles as $a ) {
			$post = get_post( $a['id'] );
			if ( ! $post ) continue;

			$new_content = preg_replace( self::BLOCK_REGEX, '', $post->post_content, -1, $n );
			if ( $n > 0 && $new_content !== $post->post_content ) {
				// wp_update_post だと post_modified が今日になってしまうため直接UPDATE
				$wpdb->update(
					$wpdb->posts,
					[ 'post_content' => $new_content ],
					[ 'ID' => $post->ID ]
				);
				clean_post_cache( $post->ID );
				$posts_updated++;
				$blocks_removed += $n;
			}
		}

		AffiKeep_Logger::log(
			"記事整理実行: {$year}年以前の {$posts_updated}記事 から {$blocks_removed}ブロック を削除",
			AffiKeep_Logger::LEVEL_INFO
		);

		return [ 'posts' => $posts_updated, 'blocks' => $blocks_removed ];
	}

	/** 実行ハンドラ */
	public static function handle_run(): void {
		check_admin_referer( 'affikeep_cleanup_run' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません' );
		}

		$year = intval( $_POST['cleanup_year'] ?? 0 );
		if ( $year < 2000 || $year > intval( current_time( 'Y' ) ) ) {
			wp_die( '年の指定が不正です' );
		}

		$result = self::run( $year );
		wp_redirect( add_query_arg( [
			'page'           => 'affikeep-cleanup',
			'done'           => 1,
			'posts_updated'  => $result['posts'],
			'blocks_removed' => $result['blocks'],
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	/** 記事整理ページの描画 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;

		$current_year = intval( current_time( 'Y' ) );
		$min_year     = (int) $wpdb->get_var(
			"SELECT MIN(YEAR(post_modified)) FROM {$wpdb->posts}
			 WHERE post_type IN ('post','page') AND post_status = 'publish'"
		);
		if ( $min_year < 2000 ) {
			$min_year = $current_year - 10;
		}

		$selected_year = intval( $_GET['cleanup_year'] ?? 0 );
		$articles      = $selected_year ? self::find_articles( $selected_year ) : null;
		$total_blocks  = $articles ? array_sum( array_column( $articles, 'blocks' ) ) : 0;
		?>
		<div class="wrap affikeep-wrap">
			<h1>記事整理</h1>

			<?php if ( isset( $_GET['done'] ) ) : ?>
				<div class="notice notice-success is-dismissible" style="padding:12px 16px;">
					<strong>削除完了：</strong>
					<?php echo intval( $_GET['posts_updated'] ); ?>記事 から
					<?php echo intval( $_GET['blocks_removed'] ); ?>個 の商品ブロックを削除しました。<br>
					記事から外れた商品は
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=affikeep-links&filter=unused' ) ); ?>">
						リンク切れチェック → 未使用（記事なし）</a>
					に表示されるので、そこから商品ごと削除できます。
				</div>
			<?php endif; ?>

			<div class="notice notice-info inline" style="padding:10px 16px;margin-bottom:16px;">
				古い記事からAffiKeepの商品ブロックだけを一括で取り除きます。
				<strong>記事本文の他の部分は変更されず、記事の更新日も変わりません。</strong><br>
				<span style="font-size:12px;color:#50575e;">
					選んだ年の12月31日までに最終更新された記事が対象です。実行前に必ず対象一覧を確認してください。
				</span>
			</div>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>"
				style="display:flex;align-items:center;gap:8px;margin-bottom:20px;">
				<input type="hidden" name="page" value="affikeep-cleanup">
				<label for="ak_cleanup_year"><strong>対象：</strong></label>
				<select name="cleanup_year" id="ak_cleanup_year">
					<?php for ( $y = $current_year - 1; $y >= $min_year; $y-- ) : ?>
						<option value="<?php echo $y; ?>" <?php selected( $selected_year, $y ); ?>>
							<?php echo $y; ?>年以前に更新された記事
						</option>
					<?php endfor; ?>
				</select>
				<button type="submit" class="button button-primary">対象を確認</button>
			</form>

			<?php if ( $articles !== null ) : ?>
				<?php if ( empty( $articles ) ) : ?>
					<p><?php echo $selected_year; ?>年以前に更新された記事に、商品ブロックは見つかりませんでした。</p>
				<?php else : ?>
					<h2 style="font-size:16px;">
						対象：<?php echo count( $articles ); ?>記事 ／ <?php echo $total_blocks; ?>ブロック
					</h2>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						style="margin-bottom:16px;"
						onsubmit="return confirm('<?php echo count( $articles ); ?>記事から <?php echo $total_blocks; ?>個 の商品ブロックを削除します。\nこの操作は元に戻せません。実行しますか？');">
						<input type="hidden" name="action"       value="affikeep_cleanup_run">
						<input type="hidden" name="cleanup_year" value="<?php echo esc_attr( $selected_year ); ?>">
						<?php wp_nonce_field( 'affikeep_cleanup_run' ); ?>
						<button type="submit" class="button" style="color:#d63638;border-color:#d63638;">
							この <?php echo count( $articles ); ?>記事 からブロックを一括削除
						</button>
					</form>

					<table class="widefat striped">
						<thead><tr>
							<th>記事タイトル</th>
							<th style="width:100px;">ブロック数</th>
							<th style="width:160px;">最終更新日</th>
							<th style="width:80px;">状態</th>
							<th style="width:60px;">編集</th>
						</tr></thead>
						<tbody>
						<?php foreach ( $articles as $a ) : ?>
							<tr>
								<td><?php echo esc_html( $a['title'] ?: '（無題）' ); ?></td>
								<td><?php echo intval( $a['blocks'] ); ?></td>
								<td><?php echo esc_html( mb_substr( $a['modified'], 0, 10 ) ); ?></td>
								<td><?php echo $a['status'] === 'publish' ? '公開' : esc_html( $a['status'] ); ?></td>
								<td><a href="<?php echo esc_url( get_edit_post_link( $a['id'] ) ); ?>">編集</a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
