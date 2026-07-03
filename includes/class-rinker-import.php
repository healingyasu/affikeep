<?php
defined( 'ABSPATH' ) || exit;

class AffiKeep_Rinker_Import {

	const RINKER_CPT    = 'yyi_rinker';
	const RINKER_BLOCK  = 'rinkerg/gutenberg-rinker';

	/**
	 * AffiKeepの取り込み先フィールドごとに、Rinker側で候補となるメタキー名（複数）。
	 * 上から順に試して、最初に値が見つかったものを採用する。
	 * yyi_rinker_ 接頭辞付きが本命（design-spec.mdの実画面確認・公開カスタマイズ記事のフック名から推定）。
	 * それ以外は過去の実装で使われていた／他プラグインでよくある命名の保険。
	 */
	const FIELD_CANDIDATES = [
		'_affikeep_image_url'   => [ 'yyi_rinker_img_s', 'yyi_rinker_img_m', 'yyi_rinker_image_url', 'm_image_url', 's_image_url', 'image_url' ],
		'_affikeep_price'       => [ 'yyi_rinker_price', 'yyi_rinker_rakuten_price', 'price', 'rakuten_price' ],
		'_affikeep_amazon_price'=> [ 'yyi_rinker_amazon_price', 'amazon_price' ],
		'_affikeep_amazon_asin' => [ 'yyi_rinker_asin', 'asin' ],
		'_affikeep_amazon_url'  => [ 'yyi_rinker_amazon_url', 'amazon_url' ],
		'_affikeep_rakuten_url' => [ 'yyi_rinker_rakuten_url', 'rakuten_url' ],
		'_affikeep_yahoo_url'   => [ 'yyi_rinker_yahoo_url', 'yahoo_url' ],
	];

	public static function init(): void {
		add_action( 'admin_post_affikeep_rinker_import',        [ __CLASS__, 'handle_import' ] );
		add_action( 'admin_post_affikeep_rinker_convert_only',  [ __CLASS__, 'handle_convert_only' ] );
		add_action( 'admin_post_affikeep_rinker_cleanup',       [ __CLASS__, 'handle_cleanup' ] );
		add_action( 'admin_post_affikeep_rinker_diagnose',      [ __CLASS__, 'handle_diagnose' ] );
		add_action( 'admin_post_affikeep_rinker_resync',        [ __CLASS__, 'handle_resync' ] );
	}

	/**
	 * 候補キーの中から最初に見つかった値を返す（$meta は get_post_meta( $id ) の生配列）。
	 */
	private static function pick( array $meta, array $candidates ): string {
		foreach ( $candidates as $key ) {
			if ( isset( $meta[ $key ][0] ) && $meta[ $key ][0] !== '' ) {
				return $meta[ $key ][0];
			}
		}
		return '';
	}

	/**
	 * 1件のRinker商品の生メタを丸ごとログに出す（キー名確認用・ターミナル不要）。
	 */
	public static function diagnose(): array {
		$rinker_posts = get_posts( [
			'post_type'      => self::RINKER_CPT,
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => 3,
			'fields'         => 'ids',
		] );

		if ( empty( $rinker_posts ) ) {
			AffiKeep_Logger::warn( 'Rinker診断: Rinker商品が見つかりませんでした。' );
			return [];
		}

		$dump = [];
		foreach ( $rinker_posts as $rinker_id ) {
			$meta = get_post_meta( $rinker_id );
			$flat = [];
			foreach ( $meta as $k => $v ) {
				$flat[ $k ] = $v[0] ?? '';
			}
			$dump[ $rinker_id ] = $flat;
			AffiKeep_Logger::log(
				"Rinker診断: post_id={$rinker_id} のメタキー一覧",
				AffiKeep_Logger::LEVEL_INFO,
				$flat
			);
		}

		return $dump;
	}

	public static function handle_diagnose(): void {
		check_admin_referer( 'affikeep_rinker_diagnose' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません' );
		}
		self::diagnose();
		wp_redirect( add_query_arg( [
			'page'      => 'affikeep-error-log',
			'diagnosed' => 1,
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * 既にインポート済みだが空欄になっている商品のフィールドを、
	 * 現在のFIELD_CANDIDATESマッピングで再取得して埋め直す（新規作成はしない）。
	 */
	public static function resync(): int {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT post_id, meta_value as rinker_id FROM {$wpdb->postmeta}
			 WHERE meta_key = '_affikeep_rinker_source_id'"
		);

		$updated = 0;
		foreach ( $rows as $row ) {
			$affikeep_id = intval( $row->post_id );
			$rinker_id   = intval( $row->rinker_id );
			if ( ! $affikeep_id || ! $rinker_id ) continue;

			$meta = get_post_meta( $rinker_id );
			$any_filled = false;

			foreach ( self::FIELD_CANDIDATES as $target_key => $candidates ) {
				$value = self::pick( $meta, $candidates );
				if ( $value !== '' ) {
					update_post_meta( $affikeep_id, $target_key, $value );
					$any_filled = true;
				}
			}

			if ( $any_filled ) $updated++;
		}

		return $updated;
	}

	public static function handle_resync(): void {
		check_admin_referer( 'affikeep_rinker_resync' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません' );
		}
		$updated = self::resync();
		wp_redirect( add_query_arg( [
			'page'    => 'affikeep-import',
			'resynced'=> $updated,
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Rinker商品の件数を返す */
	public static function count(): int {
		return (int) wp_count_posts( self::RINKER_CPT )->publish
			 + (int) wp_count_posts( self::RINKER_CPT )->draft;
	}

	/**
	 * インポート実行（重複スキップ対応）
	 * 戻り値: ['imported' => int, 'skipped' => int, 'blocks_updated' => int]
	 */
	public static function run(): array {
		$rinker_posts = get_posts( [
			'post_type'      => self::RINKER_CPT,
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );

		$imported = 0;
		$skipped  = 0;
		$id_map   = []; // rinker_id => affikeep_id

		foreach ( $rinker_posts as $rinker_id ) {
			$rinker_post = get_post( $rinker_id );
			if ( ! $rinker_post ) continue;

			// 既インポート済みチェック（_affikeep_rinker_source_id で判定）
			$existing = self::find_affikeep_by_rinker_id( $rinker_id );
			if ( $existing ) {
				$id_map[ $rinker_id ] = $existing;
				$skipped++;
				continue;
			}

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

			// Rinkerソースを記録（重複防止・変換再実行用）
			update_post_meta( $new_id, '_affikeep_rinker_source_id', $rinker_id );

			$any_filled = false;
			foreach ( self::FIELD_CANDIDATES as $target_key => $candidates ) {
				$value = self::pick( $meta, $candidates );
				if ( $value !== '' ) {
					update_post_meta( $new_id, $target_key, $value );
					$any_filled = true;
				}
			}

			if ( ! $any_filled ) {
				AffiKeep_Logger::warn(
					"Rinkerインポート: post_id={$rinker_id}（{$rinker_post->post_title}）で候補キーが1件もヒットしませんでした。メタキー名が想定と違う可能性があります。",
					array_map( fn( $v ) => $v[0] ?? '', $meta )
				);
			}

			$id_map[ $rinker_id ] = $new_id;
			$imported++;
		}

		$blocks_updated = self::convert_blocks( $id_map );

		return [
			'imported'       => $imported,
			'skipped'        => $skipped,
			'blocks_updated' => $blocks_updated,
		];
	}

	/**
	 * ブロック変換のみ再実行（商品は再作成しない）
	 * 既存のAffiKeep商品からID対応表を再構築して変換する
	 */
	public static function run_convert_only(): int {
		$id_map = self::rebuild_id_map();
		if ( empty( $id_map ) ) return 0;
		return self::convert_blocks( $id_map );
	}

	/**
	 * 全記事の Rinker ブロックを AffiKeep ブロックに変換する
	 * @param array $id_map [rinker_id => affikeep_id]
	 */
	private static function convert_blocks( array $id_map ): int {
		if ( empty( $id_map ) ) return 0;

		global $wpdb;

		$block_name = self::RINKER_BLOCK;
		$posts = $wpdb->get_results(
			"SELECT ID, post_content FROM {$wpdb->posts}
			 WHERE post_content LIKE '%{$block_name}%'
			 AND post_status NOT IN ('trash','auto-draft')"
		);

		$updated = 0;
		foreach ( $posts as $post ) {
			// 開閉タグ形式: <!-- wp:rinkerg/... {attrs} -->HTML<!-- /wp:rinkerg/... -->
			// self-closing形式: <!-- wp:rinkerg/... {attrs} /-->
			// 両方に対応
			$new_content = preg_replace_callback(
				'/<!-- wp:rinkerg\/gutenberg-rinker (\{[^}]+\}) (?:-->.*?<!-- \/wp:rinkerg\/gutenberg-rinker -->|\/-->)/s',
				function ( $matches ) use ( $id_map ) {
					$attrs     = json_decode( $matches[1], true );
					$rinker_id = intval( $attrs['post_id'] ?? 0 );
					if ( ! $rinker_id || ! isset( $id_map[ $rinker_id ] ) ) {
						return $matches[0];
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

	/**
	 * 既存のAffiKeep商品からRinker ID → AffiKeep IDのマップを再構築する
	 * 優先: _affikeep_rinker_source_id メタ
	 * フォールバック: 商品タイトル一致
	 */
	private static function rebuild_id_map(): array {
		// source_idメタから直接復元
		$affi_posts = get_posts( [
			'post_type'      => AffiKeep_Post_Type::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		] );

		$id_map = [];
		foreach ( $affi_posts as $p ) {
			$src = get_post_meta( $p->ID, '_affikeep_rinker_source_id', true );
			if ( $src ) {
				$id_map[ intval( $src ) ] = $p->ID;
			}
		}

		if ( ! empty( $id_map ) ) {
			return $id_map;
		}

		// フォールバック：タイトルで照合（初回インポート前にsource_idが未保存の場合）
		$affi_by_title = [];
		foreach ( $affi_posts as $p ) {
			$affi_by_title[ $p->post_title ] = $p->ID;
		}

		$rinker_posts = get_posts( [
			'post_type'      => self::RINKER_CPT,
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => -1,
		] );

		foreach ( $rinker_posts as $p ) {
			if ( isset( $affi_by_title[ $p->post_title ] ) ) {
				$id_map[ $p->ID ] = $affi_by_title[ $p->post_title ];
			}
		}

		return $id_map;
	}

	/** _affikeep_rinker_source_id メタから既存AffiKeep商品IDを返す（なければ0） */
	private static function find_affikeep_by_rinker_id( int $rinker_id ): int {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key = '_affikeep_rinker_source_id' AND meta_value = %d LIMIT 1",
			$rinker_id
		) );
		return $found ? intval( $found ) : 0;
	}

	/** インポート実行ハンドラ */
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

	/**
	 * 重複商品クリーンアップ
	 * _affikeep_rinker_source_id メタを持つ商品（2回目インポートで作られた側）を削除し、
	 * 元の商品にソースIDを付与する
	 */
	public static function cleanup_duplicates(): array {
		global $wpdb;

		// source_idメタ付きの商品ID（新しく作られた重複側）
		$dup_ids = $wpdb->get_results(
			"SELECT post_id, meta_value as rinker_id FROM {$wpdb->postmeta}
			 WHERE meta_key = '_affikeep_rinker_source_id'"
		);

		if ( empty( $dup_ids ) ) {
			return [ 'deleted' => 0, 'backfilled' => 0 ];
		}

		// タイトルで元の商品IDを探してソースIDを付与する
		$backfilled = 0;
		foreach ( $dup_ids as $row ) {
			$dup_post = get_post( intval( $row->post_id ) );
			if ( ! $dup_post ) continue;

			// 同タイトルでソースIDなしの商品を探す（元の商品）
			$original = $wpdb->get_var( $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm
				   ON pm.post_id = p.ID AND pm.meta_key = '_affikeep_rinker_source_id'
				 WHERE p.post_type = %s
				   AND p.post_status = 'publish'
				   AND p.post_title = %s
				   AND pm.meta_value IS NULL
				 LIMIT 1",
				AffiKeep_Post_Type::CPT,
				$dup_post->post_title
			) );

			if ( $original ) {
				update_post_meta( intval( $original ), '_affikeep_rinker_source_id', intval( $row->rinker_id ) );
				$backfilled++;
			}
		}

		// 重複側を削除
		$deleted = 0;
		foreach ( $dup_ids as $row ) {
			$id = intval( $row->post_id );
			if ( $id > 0 && get_post_type( $id ) === AffiKeep_Post_Type::CPT ) {
				wp_delete_post( $id, true );
				$deleted++;
			}
		}

		return [ 'deleted' => $deleted, 'backfilled' => $backfilled ];
	}

	/** 重複クリーンアップハンドラ */
	public static function handle_cleanup(): void {
		check_admin_referer( 'affikeep_rinker_cleanup' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません' );
		}
		$result = self::cleanup_duplicates();
		wp_redirect( add_query_arg( [
			'page'       => 'affikeep-import',
			'cleanup'    => 1,
			'deleted'    => $result['deleted'],
			'backfilled' => $result['backfilled'],
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	/** ブロック変換のみ実行ハンドラ */
	public static function handle_convert_only(): void {
		check_admin_referer( 'affikeep_rinker_convert_only' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません' );
		}
		$updated = self::run_convert_only();
		wp_redirect( add_query_arg( [
			'page'           => 'affikeep-import',
			'convert_only'   => 1,
			'blocks_updated' => $updated,
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	/** _affikeep_rinker_source_idメタを持つ商品数（重複検出） */
	private static function count_duplicates(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
			 WHERE meta_key = '_affikeep_rinker_source_id'"
		);
	}

	/** インポートページの描画 */
	public static function render_page(): void {
		$count      = self::count();
		$mapped     = count( self::rebuild_id_map() );
		$dup_count  = self::count_duplicates();
		?>
		<div class="wrap affikeep-wrap">
			<h1>AffiKeep インポート</h1>

			<?php if ( isset( $_GET['imported'] ) ) : ?>
				<div class="notice notice-success is-dismissible" style="padding:12px 16px;">
					<strong>インポート完了：</strong>
					商品 <?php echo intval( $_GET['imported'] ); ?>件 を取り込みました
					（スキップ <?php echo intval( $_GET['skipped'] ); ?>件）。
					記事内ブロック <?php echo intval( $_GET['blocks_updated'] ); ?>件 を変換しました。
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['convert_only'] ) ) : ?>
				<div class="notice notice-success is-dismissible" style="padding:12px 16px;">
					<strong>変換完了：</strong>
					記事内ブロック <?php echo intval( $_GET['blocks_updated'] ); ?>件 を変換しました。
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['resynced'] ) ) : ?>
				<div class="notice notice-success is-dismissible" style="padding:12px 16px;">
					<strong>再同期完了：</strong>
					商品 <?php echo intval( $_GET['resynced'] ); ?>件 のフィールドを埋め直しました。商品一覧で画像・価格・URLが入っているか確認してください。
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['cleanup'] ) ) : ?>
				<div class="notice notice-success is-dismissible" style="padding:12px 16px;">
					<strong>クリーンアップ完了：</strong>
					重複商品 <?php echo intval( $_GET['deleted'] ); ?>件 を削除しました
					（元の商品 <?php echo intval( $_GET['backfilled'] ); ?>件 に対応関係を付与）。
					次に「ブロック変換を再実行」を押してください。
				</div>
			<?php endif; ?>

			<?php if ( $dup_count > 0 ) : ?>
				<div class="notice notice-error" style="padding:12px 16px;">
					<strong>重複商品が <?php echo $dup_count; ?>件 検出されました。</strong>
					下の「重複商品を削除して修復」ボタンで元に戻せます。
				</div>
			<?php endif; ?>

			<?php /* 重複クリーンアップ */ ?>
			<?php if ( $dup_count > 0 ) : ?>
			<div class="affikeep-import-card" style="border-color:#d63638;">
				<h2 style="color:#d63638;">重複商品のクリーンアップ</h2>
				<p>
					インポートが2回実行されたため、<strong><?php echo $dup_count; ?>件</strong>の重複商品があります。<br>
					このボタンを押すと2回目に作られた重複を削除し、元の商品にRinkerとの対応関係を付与します。
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					onsubmit="return confirm('重複商品 <?php echo $dup_count; ?>件 を削除して修復しますか？');">
					<input type="hidden" name="action" value="affikeep_rinker_cleanup">
					<?php wp_nonce_field( 'affikeep_rinker_cleanup' ); ?>
					<button type="submit" class="button button-large" style="color:#d63638;border-color:#d63638;">
						重複商品 <?php echo $dup_count; ?>件 を削除して修復
					</button>
				</form>
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
					<li>既にインポート済みの商品はスキップされます（重複しません）</li>
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

			<?php /* Rinkerメタキー診断 */ ?>
			<div class="affikeep-import-card">
				<h2>Rinkerのメタキーを診断</h2>
				<p>
					インポートした商品の画像・価格・URLが空になる場合は、Rinker側のメタキー名が想定と違っている可能性があります。<br>
					このボタンを押すと、Rinker商品（最大3件）の生データを「エラーログ」画面に出力します（ターミナル不要）。
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="affikeep_rinker_diagnose">
					<?php wp_nonce_field( 'affikeep_rinker_diagnose' ); ?>
					<button type="submit" class="button button-large">
						Rinkerのメタキーを診断してログに出力
					</button>
				</form>
			</div>

			<?php /* 既存インポート済みの再同期 */ ?>
			<?php if ( $mapped > 0 ) : ?>
			<div class="affikeep-import-card">
				<h2>インポート済み商品のフィールドを再同期</h2>
				<p>
					画像・価格・Amazon/楽天/Yahoo URLが空のまま取り込まれてしまった場合に使います。<br>
					商品を作り直さず、既にインポート済みの<strong><?php echo $mapped; ?>件</strong>について、最新のマッピングでフィールドだけ埋め直します。
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					onsubmit="return confirm('インポート済み商品のフィールドを再取得して埋め直します。続けますか？');">
					<input type="hidden" name="action" value="affikeep_rinker_resync">
					<?php wp_nonce_field( 'affikeep_rinker_resync' ); ?>
					<button type="submit" class="button button-large">
						フィールドを再同期（<?php echo $mapped; ?>件対応）
					</button>
				</form>
			</div>
			<?php endif; ?>

			<?php /* ブロック変換のみ再実行 */ ?>
			<?php if ( $mapped > 0 ) : ?>
			<div class="affikeep-import-card">
				<h2>ブロック変換のみ再実行</h2>
				<p>
					商品のインポートは済んでいるが、記事内のRinkerブロックがAffiKeepブロックに変換されていない場合に使います。<br>
					<strong><?php echo $mapped; ?>件</strong>の商品との対応関係を元に変換します。商品は作成・変更されません。
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					onsubmit="return confirm('記事内のRinkerブロックをAffiKeepブロックに変換します。続けますか？');">
					<input type="hidden" name="action" value="affikeep_rinker_convert_only">
					<?php wp_nonce_field( 'affikeep_rinker_convert_only' ); ?>
					<button type="submit" class="button button-large">
						ブロック変換を再実行（<?php echo $mapped; ?>件対応）
					</button>
				</form>
			</div>
			<?php endif; ?>

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
