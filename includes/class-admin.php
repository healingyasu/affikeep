<?php
defined( 'ABSPATH' ) || exit;

class AffiKeep_Admin {

	public static function init(): void {
		add_action( 'admin_menu',             [ __CLASS__, 'add_menus' ] );
		add_action( 'admin_enqueue_scripts',  [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_post_affikeep_clear_log',   [ __CLASS__, 'handle_clear_log' ] );
		add_action( 'admin_post_affikeep_bulk_delete', [ __CLASS__, 'handle_bulk_delete' ] );
		add_action( 'wp_ajax_affikeep_get_product_articles', [ __CLASS__, 'ajax_get_product_articles' ] );
		add_action( 'wp_ajax_affikeep_hide_in_article',      [ __CLASS__, 'ajax_hide_in_article' ] );
		add_action( 'wp_ajax_affikeep_delete_from_article',  [ __CLASS__, 'ajax_delete_from_article' ] );
	}

	public static function on_activate(): void {
		AffiKeep_Logger::log( 'AffiKeep を有効化しました。', AffiKeep_Logger::LEVEL_INFO );
		AffiKeep_Link_Checker::schedule();
	}

	public static function on_deactivate(): void {
		AffiKeep_Link_Checker::unschedule();
	}

	public static function add_menus(): void {
		// リンク切れ件数バッジ（プラグイン更新数字と同じ仕組み）
		$dead   = AffiKeep_Link_Checker::count_dead();
		$badge  = $dead
			? ' <span class="awaiting-mod count-' . $dead . '"><span class="pending-count">' . $dead . '</span></span>'
			: '';

		// 親メニュー
		add_menu_page(
			'AffiKeep',
			'AffiKeep' . $badge,
			'manage_options',
			'affikeep',
			[ __CLASS__, 'page_dashboard' ],
			'dashicons-cart',
			58
		);

		// ダッシュボード
		add_submenu_page(
			'affikeep',
			'ダッシュボード',
			'ダッシュボード',
			'manage_options',
			'affikeep',
			[ __CLASS__, 'page_dashboard' ]
		);

		// 設定
		add_submenu_page(
			'affikeep',
			'設定 | AffiKeep',
			'設定',
			'manage_options',
			'affikeep-settings',
			[ __CLASS__, 'page_settings' ]
		);

		// 商品一覧
		add_submenu_page(
			'affikeep',
			'商品一覧 | AffiKeep',
			'商品一覧',
			'manage_options',
			'edit.php?post_type=affikeep_product'
		);

		// 商品を追加
		add_submenu_page(
			'affikeep',
			'商品を追加 | AffiKeep',
			'商品を追加',
			'manage_options',
			'post-new.php?post_type=affikeep_product'
		);

		// リンク切れ
		$links_label = 'リンク切れ' . ( $dead ? " ({$dead})" : '' );
		add_submenu_page(
			'affikeep',
			'リンク切れ | AffiKeep',
			$links_label,
			'manage_options',
			'affikeep-links',
			[ __CLASS__, 'page_links' ]
		);

		// 記事整理
		add_submenu_page(
			'affikeep',
			'記事整理 | AffiKeep',
			'記事整理',
			'manage_options',
			'affikeep-cleanup',
			[ 'AffiKeep_Cleanup', 'render_page' ]
		);

		// エラーログ
		add_submenu_page(
			'affikeep',
			'エラーログ | AffiKeep',
			'エラーログ',
			'manage_options',
			'affikeep-error-log',
			[ __CLASS__, 'page_error_log' ]
		);

		// インポート
		add_submenu_page(
			'affikeep',
			'インポート | AffiKeep',
			'インポート',
			'manage_options',
			'affikeep-import',
			[ 'AffiKeep_Rinker_Import', 'render_page' ]
		);
	}

	public static function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, 'affikeep' ) === false ) {
			return;
		}
		wp_enqueue_style(
			'affikeep-admin',
			AFFIKEEP_URL . 'assets/admin.css',
			[],
			AFFIKEEP_VERSION
		);
	}

	// ----------------------------------------------------------------
	// ページ: ダッシュボード
	// ----------------------------------------------------------------
	public static function page_dashboard(): void {
		$log_url      = admin_url( 'admin.php?page=affikeep-error-log' );
		$links_url    = admin_url( 'admin.php?page=affikeep-links' );
		$settings_url = admin_url( 'admin.php?page=affikeep-settings' );
		$errors       = array_filter( AffiKeep_Logger::get_all(), fn( $e ) => $e['level'] === 'error' );
		$error_count  = count( $errors );
		$counts       = AffiKeep_Link_Checker::count_by_status();
		?>
		<div class="wrap affikeep-wrap">
			<h1>AffiKeep ダッシュボード
				<span style="font-size:13px;font-weight:400;color:#787c82;margin-left:12px;">
					v<?php echo AFFIKEEP_VERSION; ?> &nbsp;|&nbsp; ビルド: <?php echo AFFIKEEP_BUILD; ?>
				</span>
			</h1>

			<div class="affikeep-cards">

				<div class="affikeep-card <?php echo $counts['dead'] > 0 ? 'affikeep-card--alert' : ''; ?>">
					<h2>リンク切れ</h2>
					<p class="affikeep-big-num"><?php echo esc_html( $counts['dead'] ); ?></p>
					<p class="affikeep-note">
						正常 <?php echo esc_html( $counts['ok'] ); ?> ／
						要確認 <?php echo esc_html( $counts['unknown'] ); ?>
					</p>
					<a href="<?php echo esc_url( $links_url ); ?>" class="button <?php echo $counts['dead'] > 0 ? 'button-primary' : ''; ?>">リンクを確認</a>
				</div>

				<div class="affikeep-card <?php echo $error_count > 0 ? 'affikeep-card--alert' : ''; ?>">
					<h2>エラーログ</h2>
					<p class="affikeep-big-num"><?php echo esc_html( $error_count ); ?></p>
					<?php if ( $error_count > 0 ) : ?>
						<a href="<?php echo esc_url( $log_url ); ?>" class="button button-primary">ログを確認する</a>
					<?php else : ?>
						<p class="affikeep-note">エラーはありません</p>
					<?php endif; ?>
				</div>

				<div class="affikeep-card">
					<h2>設定</h2>
					<p class="affikeep-note">APIキー・アフィリエイトIDを登録してください</p>
					<a href="<?php echo esc_url( $settings_url ); ?>" class="button">設定を開く</a>
				</div>

			</div>
		</div>
		<?php
	}

	// ----------------------------------------------------------------
	// ページ: リンク切れ
	// ----------------------------------------------------------------
	public static function page_links(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$counts       = AffiKeep_Link_Checker::count_by_status();
		$filter       = isset( $_GET['filter'] ) && in_array( $_GET['filter'], [ 'dead', 'unused' ], true )
		                ? $_GET['filter'] : 'all';
		$paged        = max( 1, intval( $_GET['paged'] ?? 1 ) );
		$per_page     = 20;
		$base_url     = admin_url( 'admin.php?page=affikeep-links' );
		$just_checked = isset( $_GET['checked'] );
		$just_deleted = isset( $_GET['deleted'] );
		$unused_ids   = self::get_unused_product_ids();
		$unused_count = count( $unused_ids );

		// クエリ構築
		$query_args = [
			'post_type'      => AffiKeep_Post_Type::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $paged,
		];
		if ( $filter === 'dead' ) {
			$query_args['meta_query'] = [ [ 'key' => '_affikeep_link_status', 'value' => 'dead' ] ];
		} elseif ( $filter === 'unused' ) {
			$query_args['post__in'] = empty( $unused_ids ) ? [ 0 ] : $unused_ids;
		} else {
			$query_args['meta_query'] = [
				'relation' => 'OR',
				[ 'key' => '_affikeep_link_status', 'value' => 'dead' ],
				[ 'key' => '_affikeep_link_status', 'value' => 'unknown' ],
				[ 'key' => '_affikeep_link_status', 'compare' => 'NOT EXISTS' ],
			];
		}

		$problem     = new WP_Query( $query_args );
		$total       = $problem->found_posts;
		$total_pages = $problem->max_num_pages;
		?>
		<div class="wrap affikeep-wrap">
			<h1>リンク切れチェック</h1>
			<div class="notice notice-info inline" style="padding:10px 16px;margin-bottom:16px;">
				<strong>チェック対象：楽天・Yahoo! のみ（Amazon は除外）</strong><br>
				<span style="font-size:12px;color:#50575e;">
					Amazon はbot検知のため自動判定できません。楽天・Yahoo! の<strong>両方が切れている</strong>商品を「リンク切れ」として扱います。
					どちらか一方でも正常であれば読者は商品ページに到達できるため「正常」と表示されます。
				</span>
			</div>

			<?php if ( $just_checked ) : ?>
				<div class="notice notice-success is-dismissible" style="padding:12px 16px;">
					<strong><?php echo intval( $_GET['checked'] ); ?>件</strong>をチェックしました。
					うち <strong><?php echo intval( $_GET['dead'] ); ?>件</strong> がリンク切れです。
				</div>
			<?php endif; ?>
			<?php if ( $just_deleted ) : ?>
				<div class="notice notice-success is-dismissible" style="padding:12px 16px;">
					<strong><?php echo intval( $_GET['deleted'] ); ?>件</strong>の商品を削除しました。
				</div>
			<?php endif; ?>

			<?php $recalc_nonce = wp_create_nonce( 'affikeep_recalc' ); ?>
			<div class="affikeep-cards" style="margin-bottom:20px;">
				<a href="<?php echo esc_url( $base_url . '&filter=dead' ); ?>" style="text-decoration:none;">
					<div class="affikeep-card <?php echo $counts['dead'] > 0 ? 'affikeep-card--alert' : ''; ?>"
						style="cursor:pointer;">
						<h2>リンク切れ <span style="font-size:11px;font-weight:400;">↗ クリックで表示</span></h2>
						<p class="affikeep-big-num"><?php echo esc_html( $counts['dead'] ); ?></p>
					</div>
				</a>
				<div class="affikeep-card">
					<h2>要確認</h2>
					<p class="affikeep-big-num"><?php echo esc_html( $counts['unknown'] ); ?></p>
					<p class="affikeep-note">楽天・Yahoo判定待ち</p>
				</div>
				<div class="affikeep-card">
					<h2>正常</h2>
					<p class="affikeep-big-num"><?php echo esc_html( $counts['ok'] ); ?></p>
				</div>
			</div>
			<div style="margin-bottom:12px;">
				<button id="affikeep-recalc-btn" class="button"
					data-nonce="<?php echo esc_attr( $recalc_nonce ); ?>">
					ステータス再計算（URLを叩かず即時更新）
				</button>
				<span id="affikeep-recalc-result" style="margin-left:8px;font-size:12px;color:#787c82;"></span>

				<?php if ( AffiKeep_License::is_active() ) : ?>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=affikeep_export_products_csv' ), 'affikeep_export_products_csv' ) ); ?>"
						class="button" style="margin-left:8px;">
						📄 商品一覧をCSVでエクスポート
					</a>
				<?php else : ?>
					<span style="margin-left:8px;font-size:12px;color:#787c82;">
						🔒 <a href="<?php echo esc_url( admin_url( 'admin.php?page=affikeep-settings#ak-license-section' ) ); ?>">Pro版</a>ならCSVエクスポートができます
					</span>
				<?php endif; ?>
			</div>

			<?php
			$total_products = $counts['ok'] + $counts['dead'] + $counts['unknown'];
			$auto_nonce     = wp_create_nonce( 'affikeep_auto_check' );
			?>
			<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="affikeep_check_now">
					<?php wp_nonce_field( 'affikeep_check_now' ); ?>
					<button type="submit" class="button">
						20件だけチェック
					</button>
				</form>
				<button id="affikeep-auto-btn" class="button button-primary">
					全件自動チェック（<?php echo $total_products; ?>件）
				</button>
				<button id="affikeep-auto-stop" class="button" style="display:none;">停止</button>
				<span id="affikeep-auto-hint" style="color:#787c82;font-size:12px;">
					<?php echo AffiKeep_Link_Checker::AJAX_BATCH; ?>件ずつ自動で処理します（約<?php echo ceil( $total_products / AffiKeep_Link_Checker::AJAX_BATCH ); ?>バッチ）
				</span>
			</div>
			<div id="affikeep-progress-wrap" style="display:none;margin-bottom:16px;max-width:600px;">
				<div style="background:#e0e0e0;border-radius:4px;height:10px;overflow:hidden;">
					<div id="affikeep-progress-bar" style="background:#135e96;height:100%;width:0%;transition:width 0.3s;"></div>
				</div>
				<p id="affikeep-progress-text" style="margin:6px 0 0;font-size:12px;color:#787c82;"></p>
			</div>

			<?php /* フィルタータブ + 未使用一括削除 */ ?>
			<div style="margin-bottom:12px;display:flex;gap:4px;flex-wrap:wrap;align-items:center;">
				<a href="<?php echo esc_url( $base_url ); ?>"
					class="button <?php echo $filter === 'all' ? 'button-primary' : ''; ?>">
					全て（<?php echo $counts['dead'] + $counts['unknown']; ?>件）
				</a>
				<a href="<?php echo esc_url( $base_url . '&filter=dead' ); ?>"
					class="button <?php echo $filter === 'dead' ? 'button-primary' : ''; ?>">
					リンク切れのみ（<?php echo $counts['dead']; ?>件）
				</a>
				<a href="<?php echo esc_url( $base_url . '&filter=unused' ); ?>"
					class="button <?php echo $filter === 'unused' ? 'button-primary' : ''; ?>">
					未使用（記事なし）（<?php echo $unused_count; ?>件）
				</a>
				<?php if ( $filter === 'unused' && $unused_count > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					style="margin-left:12px;"
					onsubmit="return confirm('記事に使われていない <?php echo $unused_count; ?> 件の商品を全て削除しますか？\nこの操作は元に戻せません。')">
					<input type="hidden" name="action"            value="affikeep_bulk_delete">
					<input type="hidden" name="filter"            value="unused">
					<input type="hidden" name="delete_all_unused" value="1">
					<?php wp_nonce_field( 'affikeep_bulk_delete' ); ?>
					<button type="submit" class="button" style="color:#d63638;border-color:#d63638;">
						未使用 <?php echo $unused_count; ?>件を全て削除
					</button>
				</form>
				<?php endif; ?>
			</div>

			<?php if ( ! $problem->have_posts() ) : ?>
				<p><?php
					if ( $filter === 'dead' )   echo 'リンク切れの商品はありません。';
					elseif ( $filter === 'unused' ) echo '記事に使われていない商品はありません。';
					else echo 'リンク切れ・要確認の商品はありません。';
				?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="affikeep_bulk_delete">
					<input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>">
					<?php wp_nonce_field( 'affikeep_bulk_delete' ); ?>

					<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
						<p style="color:#787c82;font-size:12px;margin:0;">
							<?php echo $total; ?>件中 <?php echo ( ( $paged - 1 ) * $per_page + 1 ); ?>〜<?php echo min( $paged * $per_page, $total ); ?>件を表示
						</p>
						<button type="submit" class="button button-secondary"
							onclick="return confirm('選択した商品を削除しますか？\nこの操作は元に戻せません。')">
							選択した商品を削除
						</button>
					</div>

					<?php
					// bot検知系（Amazon等）を除いた、判定可能なモールのみ列として表示する
					$table_malls = array_filter(
						AffiKeep_Malls::available(),
						fn( $def ) => empty( $def['bot_phrases'] )
					);
					?>
					<table class="widefat striped">
						<thead><tr>
							<th style="width:32px;"><input type="checkbox" id="affikeep-select-all" title="全選択"></th>
							<th>商品名</th>
							<?php foreach ( $table_malls as $def ) : ?>
								<th style="width:80px;"><?php echo esc_html( $def['label'] ); ?></th>
							<?php endforeach; ?>
							<th style="width:160px;">最終チェック</th>
							<th style="width:60px;">編集</th>
						</tr></thead>
						<tbody>
						<?php while ( $problem->have_posts() ) :
							$problem->the_post();
							$id   = get_the_ID();
							$last = get_post_meta( $id, '_affikeep_last_checked', true );
						?>
							<tr>
								<td><input type="checkbox" name="product_ids[]" value="<?php echo esc_attr( $id ); ?>"></td>
								<td><?php echo esc_html( get_the_title() ); ?></td>
								<?php foreach ( $table_malls as $mall_id => $def ) :
									$mall_status = get_post_meta( $id, "_affikeep_{$mall_id}_status", true ) ?: '';
									$mall_url    = get_post_meta( $id, "_affikeep_{$mall_id}_url", true );
								?>
									<td><?php echo self::mall_badge( $mall_status, ! empty( $mall_url ) ); ?></td>
								<?php endforeach; ?>
								<td><?php echo esc_html( $last ?: '未チェック' ); ?></td>
								<td><a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>">編集</a></td>
							</tr>
						<?php endwhile; wp_reset_postdata(); ?>
						</tbody>
					</table>

					<div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center;">
						<?php if ( $total_pages > 1 ) :
							$paginate_base = $base_url . ( $filter !== 'all' ? '&filter=' . $filter : '' ) . '&paged=%#%';
							echo paginate_links( [
								'base'      => $paginate_base,
								'format'    => '',
								'current'   => $paged,
								'total'     => $total_pages,
								'prev_text' => '&laquo; 前',
								'next_text' => '次 &raquo;',
							] );
						else: ?>
							<span></span>
						<?php endif; ?>
						<button type="submit" class="button button-secondary"
							onclick="return confirm('選択した商品を削除しますか？\nこの操作は元に戻せません。')">
							選択した商品を削除
						</button>
					</div>
				</form>
			<?php endif; ?>
		</div>
		<script>
		(function($){
			var btn = $('#affikeep-recalc-btn');
			if (btn.length) {
				btn.on('click', function(){
					btn.prop('disabled', true).text('再計算中...');
					$.post(ajaxurl, {
						action: 'affikeep_recalc_statuses',
						nonce:  btn.data('nonce')
					}, function(r){
						if(r.success){
							$('#affikeep-recalc-result').text( r.data.updated + '件を更新しました。ページを再読み込みしてください。' );
						} else {
							$('#affikeep-recalc-result').text('エラー: ' + r.data);
						}
						btn.prop('disabled', false).text('ステータス再計算（URLを叩かず即時更新）');
					});
				});
			}
		})(jQuery);

		(function(){
			var all = document.getElementById('affikeep-select-all');
			if (all) {
				all.addEventListener('change', function(){
					document.querySelectorAll('input[name="product_ids[]"]').forEach(function(cb){
						cb.checked = all.checked;
					});
				});
			}
		})();

		(function($){
			var total    = <?php echo intval( $total_products ); ?>;
			var nonce    = '<?php echo esc_js( $auto_nonce ); ?>';
			var running  = false;
			var done     = 0;
			var deadTotal = 0;

			$('#affikeep-auto-btn').on('click', function(){
				running = true; done = 0; deadTotal = 0;
				$(this).prop('disabled', true).text('チェック中...');
				$('#affikeep-auto-stop').show();
				$('#affikeep-auto-hint').hide();
				$('#affikeep-progress-wrap').show();
				setProgress(0, '準備中...', '#135e96');
				tick();
			});

			$('#affikeep-auto-stop').on('click', function(){
				running = false;
				$(this).hide();
				resetBtn();
				setProgress(done, '停止しました（' + done + '件チェック済み、リンク切れ: ' + deadTotal + '件）', '#787c82');
			});

			function tick(){
				if(!running){ return; }
				if(done >= total){ finish(); return; }
				$.ajax({
					url: ajaxurl, method: 'POST', timeout: 90000,
					data: { action: 'affikeep_auto_check', nonce: nonce },
					success: function(r){
						if(!running) return;
						if(r.success){
							done += r.data.checked;
							deadTotal = r.data.dead_total;
							setProgress(done, done + ' / ' + total + '件チェック済み（リンク切れ: ' + deadTotal + '件）', '#135e96');
							if(r.data.checked === 0 || done >= total){ finish(); return; }
							setTimeout(tick, 500);
						} else {
							setProgress(done, 'エラー: ' + r.data, '#d63638');
							running = false; resetBtn();
						}
					},
					error: function(){
						setProgress(done, '通信エラー。ページを再読込して再試行してください。', '#d63638');
						running = false; resetBtn();
					}
				});
			}

			function finish(){
				running = false;
				$('#affikeep-auto-stop').hide();
				resetBtn();
				setProgress(done, '完了！全 ' + total + '件チェック済み（リンク切れ: ' + deadTotal + '件）', '#00a32a');
			}

			function resetBtn(){
				$('#affikeep-auto-btn').prop('disabled', false)
					.text('全件自動チェック（' + total + '件）');
				$('#affikeep-auto-hint').show();
			}

			function setProgress(count, text, color){
				var pct = total > 0 ? Math.min(100, Math.round(count / total * 100)) : 100;
				$('#affikeep-progress-bar').css({width: pct + '%', background: color});
				$('#affikeep-progress-text').text(text);
			}
		})(jQuery);
		</script>
		<?php
	}

	// ----------------------------------------------------------------
	// ページ: 設定
	// ----------------------------------------------------------------
	public static function page_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap affikeep-wrap">
			<h1>AffiKeep 設定</h1>

			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible" style="padding:12px 16px;font-size:14px;">
					<strong>保存しました。</strong>
				</div>
			<?php endif; ?>

			<?php self::render_license_section(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'affikeep_settings_group' ); ?>

				<?php $s = AffiKeep_Settings::get(); ?>

				<div class="notice notice-info inline" style="padding:14px 18px;margin:0 0 20px;">
					<h3 style="margin:0 0 8px;">運用の基本：入力した欄で、リンクの種類が決まります</h3>
					<ul style="margin:0 0 10px;list-style:disc;padding-left:20px;line-height:1.7;">
						<li><strong>Amazon・楽天・Yahoo!に直接登録している人</strong> → 各モールのID欄に入力。<u>直接リンク</u>で出力されます。</li>
						<li><strong>もしもアフィリエイトを使う人</strong> → もしもの <code>a_id</code> だけ入力。全モールが<u>もしも経由</u>になります。</li>
						<li>直接IDともしもIDの両方を入れた場合 → そのモールは<strong>直接が優先</strong>されます。</li>
					</ul>
					<p style="margin:0;padding:10px 12px;background:#fff8e5;border-left:4px solid #dba617;">
						<strong>Amazonは直接リンクを強く推奨します。</strong>
						Amazonの商品検索API（PA-API）は一定期間に売上実績がないと使えません。
						もしも経由だとAmazonアソシエイトの実績にならず、APIをいつまでも有効化できません。
						まず直接リンクで購買実績を作ることをおすすめします。
					</p>
				</div>

				<h2 class="affikeep-section-title">楽天 Web API</h2>
				<p class="description" style="margin-bottom:12px;">
					アプリID・アクセスキー・アプリケーションURLの3つが必要です（2026年5月以降）。<br>
					<a href="https://webservice.rakuten.co.jp/" target="_blank" rel="noopener">楽天デベロッパーサイト</a> でアプリを登録・確認してください。
				</p>
				<table class="form-table">
					<tr>
						<th><label for="ak_rakuten_app_id">アプリケーションID</label></th>
						<td>
							<input type="text" id="ak_rakuten_app_id"
								name="affikeep_settings[rakuten_app_id]"
								value="<?php echo esc_attr( $s['rakuten_app_id'] ); ?>"
								class="regular-text">
						</td>
					</tr>
					<tr>
						<th><label for="ak_rakuten_access_key">アクセスキー</label></th>
						<td>
							<input type="text" id="ak_rakuten_access_key"
								name="affikeep_settings[rakuten_access_key]"
								value="<?php echo esc_attr( $s['rakuten_access_key'] ); ?>"
								class="regular-text">
						</td>
					</tr>
					<tr>
						<th><label for="ak_rakuten_app_url">アプリケーションURL</label></th>
						<td>
							<input type="url" id="ak_rakuten_app_url"
								name="affikeep_settings[rakuten_app_url]"
								value="<?php echo esc_attr( $s['rakuten_app_url'] ); ?>"
								class="regular-text"
								placeholder="https://example.com">
							<p class="description">楽天デベロッパーに登録したサイトのURL</p>
						</td>
					</tr>
					<tr>
						<th><label for="ak_rakuten_affiliate_id">アフィリエイトID</label></th>
						<td>
							<input type="text" id="ak_rakuten_affiliate_id"
								name="affikeep_settings[rakuten_affiliate_id]"
								value="<?php echo esc_attr( $s['rakuten_affiliate_id'] ); ?>"
								class="regular-text">
							<p class="description">
								<a href="https://affiliate.rakuten.co.jp/" target="_blank" rel="noopener">楽天アフィリエイト</a> の管理画面で確認できます。
							</p>
						</td>
					</tr>
				</table>

				<h2 class="affikeep-section-title">Amazon アソシエイツ</h2>
				<p class="description" style="margin-bottom:12px;">
					<a href="https://affiliate.amazon.co.jp/" target="_blank" rel="noopener">Amazonアソシエイト</a> に登録済みのトラッキングIDを入力してください。
				</p>
				<table class="form-table">
					<tr>
						<th><label for="ak_amazon_tracking_id">トラッキングID</label></th>
						<td>
							<input type="text" id="ak_amazon_tracking_id"
								name="affikeep_settings[amazon_tracking_id]"
								value="<?php echo esc_attr( $s['amazon_tracking_id'] ); ?>"
								class="regular-text"
								placeholder="yoursite-22">
							<p class="description">管理画面「トラッキングIDの管理」で確認できます。</p>
						</td>
					</tr>
				</table>

				<h2 class="affikeep-section-title">Amazon PA-API連携（Pro機能・商品検索用）</h2>
				<?php if ( AffiKeep_License::is_active() ) : ?>
					<p class="description" style="margin-bottom:12px;">
						商品編集画面でAmazon商品をキーワード検索できるようになります。
						<a href="https://affiliate.amazon.co.jp/assoc_credentials/home" target="_blank" rel="noopener">Amazonアソシエイトの認証情報管理</a> で発行できます。
						パートナータグは上のトラッキングIDと共通です。
					</p>
					<table class="form-table">
						<tr>
							<th><label for="ak_amazon_paapi_access_key">アクセスキーID</label></th>
							<td>
								<input type="text" id="ak_amazon_paapi_access_key"
									name="affikeep_settings[amazon_paapi_access_key]"
									value="<?php echo esc_attr( $s['amazon_paapi_access_key'] ); ?>"
									class="regular-text">
							</td>
						</tr>
						<tr>
							<th><label for="ak_amazon_paapi_secret_key">シークレットキー</label></th>
							<td>
								<input type="text" id="ak_amazon_paapi_secret_key"
									name="affikeep_settings[amazon_paapi_secret_key]"
									value="<?php echo esc_attr( $s['amazon_paapi_secret_key'] ); ?>"
									class="regular-text">
								<p class="description">
									PA-APIの利用には直近のAmazonアソシエイト経由の売上実績が必要です。資格情報が無い場合はこの機能は使えません（商品の手入力は引き続き可能です）。
								</p>
							</td>
						</tr>
					</table>
				<?php else : ?>
					<div class="notice notice-info inline" style="padding:12px 16px;margin:0;">
						<p style="margin:0;">
							🔒 Pro版ではAmazon PA-APIを使った商品検索・自動入力ができます（商品名・画像・価格・URLを自動取得）。
							<a href="#ak-license-section">上部からライセンスを有効化</a> すると設定できるようになります。
						</p>
					</div>
				<?php endif; ?>

				<h2 class="affikeep-section-title">Yahoo!ショッピング（バリューコマース）</h2>
				<p class="description" style="margin-bottom:12px;">
					LinkSwitch か アフィリエイトID のどちらかを入力してください。両方入力した場合は LinkSwitch が優先されます。<br>
					<a href="https://www.valuecommerce.ne.jp/" target="_blank" rel="noopener">バリューコマース管理画面</a> で確認できます。
				</p>
				<table class="form-table">
					<tr>
						<th><label for="ak_yahoo_linkswitch">LinkSwitch</label></th>
						<td>
							<input type="text" id="ak_yahoo_linkswitch"
								name="affikeep_settings[yahoo_linkswitch]"
								value="<?php echo esc_attr( $s['yahoo_linkswitch'] ); ?>"
								class="regular-text">
						</td>
					</tr>
					<tr>
						<th><label for="ak_yahoo_affiliate_id">アフィリエイトID</label></th>
						<td>
							<input type="text" id="ak_yahoo_affiliate_id"
								name="affikeep_settings[yahoo_affiliate_id]"
								value="<?php echo esc_attr( $s['yahoo_affiliate_id'] ); ?>"
								class="regular-text">
						</td>
					</tr>
				</table>

				<h2 class="affikeep-section-title">もしもアフィリエイト（URL変換用）</h2>
				<div class="notice notice-info inline" style="padding:10px 14px;margin-bottom:14px;">
					<p style="margin:0;">
						<strong>a_id はもしもアカウント共通の1つの数字です。</strong><br>
						Amazon・楽天・Yahoo! で別々に設定する必要はありません。1か所に入力するだけで全モールに適用されます。<br><br>
						<strong>確認方法：</strong>
						もしもで取得した<strong>どの広告コードのHTMLでも</strong>、中に <code>a_id=数字</code> という部分があります。その数字だけをコピーしてください。<br><br>
						例：<code>a_id=<strong>1234567</strong>&amp;p_id=...</code> → 入力するのは <code>1234567</code> だけ
					</p>
				</div>
				<table class="form-table">
					<tr>
						<th><label for="ak_moshimo_aid">a_id</label></th>
						<td>
							<input type="text" id="ak_moshimo_aid"
								name="affikeep_settings[moshimo_aid]"
								value="<?php echo esc_attr( $s['moshimo_aid'] ); ?>"
								class="regular-text"
								placeholder="例: 1234567">
							<p class="description">
								入力するとAmazon・楽天・Yahoo!のリンクがもしも経由に変換されます。もしもを使わない場合は空欄のままでOKです。<br>
								上の各モールに直接IDを入れている場合は、そのモールは直接リンクが優先されます（もしもは使われません）。<br>
								<strong>※もしも経由リンクは、公開前に一度ボタンを押して正しく飛ぶか確認してください。</strong>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="affikeep-section-title">対応モール拡張（Pro機能）</h2>
				<?php if ( AffiKeep_License::is_active() ) : ?>
					<p class="description" style="margin-bottom:12px;">
						楽天トラベルは商品編集画面のURL欄に、楽天アフィリエイトで発行した提携済みリンクをそのまま貼り付けてください（変換不要）。
						Booking.comは通常の宿泊ページURLを貼るだけで、下のアフィリエイトIDが自動的に付与されます。
					</p>
					<table class="form-table">
						<tr>
							<th><label for="ak_booking_affiliate_id">Booking.com アフィリエイトID（aid）</label></th>
							<td>
								<input type="text" id="ak_booking_affiliate_id"
									name="affikeep_settings[booking_affiliate_id]"
									value="<?php echo esc_attr( $s['booking_affiliate_id'] ); ?>"
									class="regular-text"
									placeholder="例: 1234567">
								<p class="description">
									<a href="https://partner.booking.com/" target="_blank" rel="noopener">Booking.comパートナーセンター</a> で確認できます。
								</p>
							</td>
						</tr>
						<tr>
							<th><label for="ak_btn_rakuten_travel">楽天トラベルボタン</label></th>
							<td>
								<input type="text" id="ak_btn_rakuten_travel"
									name="affikeep_settings[button_text_rakuten_travel]"
									value="<?php echo esc_attr( $s['button_text_rakuten_travel'] ); ?>"
									class="regular-text">
							</td>
						</tr>
						<tr>
							<th><label for="ak_btn_booking">Booking.comボタン</label></th>
							<td>
								<input type="text" id="ak_btn_booking"
									name="affikeep_settings[button_text_booking]"
									value="<?php echo esc_attr( $s['button_text_booking'] ); ?>"
									class="regular-text">
							</td>
						</tr>
					</table>
					<p class="description" style="margin-top:-8px;">
						※もしもアフィリエイト経由には対応していません（実アカウントでの提携IDを検証できていないため、誤った値で成果が計測されないようこの2モールは直接リンクのみとしています）。
					</p>
				<?php else : ?>
					<div class="notice notice-info inline" style="padding:12px 16px;margin:0;">
						<p style="margin:0;">
							🔒 Pro版では楽天トラベル・Booking.comにも対応できます（リンク切れチェック・ボタン表示）。
							<a href="#ak-license-section">上部からライセンスを有効化</a> すると設定できるようになります。
						</p>
					</div>
				<?php endif; ?>

				<h2 class="affikeep-section-title">通知設定</h2>
				<table class="form-table">
					<tr>
						<th><label for="ak_notify_email">通知先メールアドレス</label></th>
						<td>
							<input type="email" id="ak_notify_email"
								name="affikeep_settings[notify_email]"
								value="<?php echo esc_attr( $s['notify_email'] ); ?>"
								class="regular-text">
							<p class="description">空欄の場合はWordPress管理者メールに送信</p>
						</td>
					</tr>
				</table>

				<h2 class="affikeep-section-title">ボタン表示テキスト</h2>
				<table class="form-table">
					<tr>
						<th><label for="ak_btn_amazon">Amazonボタン</label></th>
						<td>
							<input type="text" id="ak_btn_amazon"
								name="affikeep_settings[button_text_amazon]"
								value="<?php echo esc_attr( $s['button_text_amazon'] ); ?>"
								class="regular-text">
						</td>
					</tr>
					<tr>
						<th><label for="ak_btn_rakuten">楽天ボタン</label></th>
						<td>
							<input type="text" id="ak_btn_rakuten"
								name="affikeep_settings[button_text_rakuten]"
								value="<?php echo esc_attr( $s['button_text_rakuten'] ); ?>"
								class="regular-text">
						</td>
					</tr>
					<tr>
						<th><label for="ak_btn_yahoo">Yahoo!ボタン</label></th>
						<td>
							<input type="text" id="ak_btn_yahoo"
								name="affikeep_settings[button_text_yahoo]"
								value="<?php echo esc_attr( $s['button_text_yahoo'] ); ?>"
								class="regular-text">
						</td>
					</tr>
				</table>

				<?php submit_button( '保存する' ); ?>
			</form>
		</div>
		<?php
	}

	/** 設定画面のProライセンスセクション */
	private static function render_license_section(): void {
		$license = AffiKeep_License::get_data();
		$active  = AffiKeep_License::is_active();
		?>
		<?php if ( isset( $_GET['license'] ) ) : ?>
			<?php if ( $_GET['license'] === 'activated' ) : ?>
				<div class="notice notice-success is-dismissible" style="padding:12px 16px;"><strong>ライセンスを有効化しました。</strong></div>
			<?php elseif ( $_GET['license'] === 'invalid' ) : ?>
				<div class="notice notice-error is-dismissible" style="padding:12px 16px;"><strong>ライセンスキーが無効です。入力内容をご確認ください。</strong></div>
			<?php elseif ( $_GET['license'] === 'deactivated' ) : ?>
				<div class="notice notice-success is-dismissible" style="padding:12px 16px;"><strong>ライセンスを解除しました。</strong></div>
			<?php endif; ?>
		<?php endif; ?>

		<h2 class="affikeep-section-title" id="ak-license-section">Proライセンス</h2>
		<div class="notice notice-info inline" style="padding:14px 18px;margin:0 0 20px;">
			<?php if ( $active ) : ?>
				<p style="margin:0 0 10px;">
					<span style="background:#00a32a;color:#fff;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:600;">Pro 有効</span>
					<?php if ( ! empty( $license['expires_at'] ) ) : ?>
						&nbsp; 有効期限: <?php echo esc_html( $license['expires_at'] ); ?>
						<?php if ( AffiKeep_License::is_in_grace_period() ) : ?>
							&nbsp;<strong style="color:#b32d2e;">（期限切れ・猶予期間中です。更新をお願いします）</strong>
						<?php endif; ?>
					<?php endif; ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="affikeep_deactivate_license">
					<?php wp_nonce_field( 'affikeep_deactivate_license' ); ?>
					<button type="submit" class="button">ライセンスを解除</button>
				</form>
			<?php else : ?>
				<p style="margin:0 0 10px;">
					<span style="background:#72777c;color:#fff;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:600;">Free版</span>
					&nbsp; ライセンスキーを入力するとPro機能が使えるようになります。
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
					<input type="hidden" name="action" value="affikeep_activate_license">
					<?php wp_nonce_field( 'affikeep_activate_license' ); ?>
					<input type="text" name="license_key" class="regular-text"
						placeholder="AK-xxxx-xxxxxxxx-xxxxxxxxxxxxxxxx"
						value="<?php echo esc_attr( $license['key'] ?? '' ); ?>">
					<button type="submit" class="button button-primary">有効化</button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	// ----------------------------------------------------------------
	// ページ: エラーログ
	// ----------------------------------------------------------------
	public static function page_error_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$entries = AffiKeep_Logger::get_all();
		?>
		<div class="wrap affikeep-wrap">
			<h1>AffiKeep エラーログ</h1>

			<p>ここにプラグインが記録したエラー・警告が表示されます。<br>
			エラーが出たら、このページの内容をコピーしてサポートに貼り付けてください。</p>

			<div style="margin-bottom: 12px; display:flex; gap:8px; flex-wrap:wrap;">
				<button id="affikeep-copy-log" class="button button-primary">
					全ログをコピー
				</button>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					onsubmit="return confirm('ログを全件削除しますか？');">
					<input type="hidden" name="action" value="affikeep_clear_log">
					<?php wp_nonce_field( 'affikeep_clear_log' ); ?>
					<button type="submit" class="button">ログを消去</button>
				</form>
			</div>

			<?php if ( empty( $entries ) ) : ?>
				<p>ログはありません。</p>
			<?php else : ?>
				<div id="affikeep-log-container">
				<table class="widefat striped">
					<thead>
						<tr>
							<th style="width:160px;">日時</th>
							<th style="width:60px;">種類</th>
							<th>メッセージ</th>
							<th>詳細</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $entries as $entry ) :
						$level_class = 'affikeep-level-' . esc_attr( $entry['level'] );
						$context_str = ! empty( $entry['context'] ) ? json_encode( $entry['context'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) : '';
					?>
						<tr>
							<td><?php echo esc_html( $entry['time'] ); ?></td>
							<td><span class="affikeep-badge <?php echo $level_class; ?>">
								<?php echo esc_html( strtoupper( $entry['level'] ) ); ?>
							</span></td>
							<td><?php echo esc_html( $entry['message'] ); ?></td>
							<td>
								<?php if ( $context_str ) : ?>
									<pre style="font-size:11px;margin:0;white-space:pre-wrap;"><?php echo esc_html( $context_str ); ?></pre>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>

				<script>
				document.getElementById('affikeep-copy-log').addEventListener('click', function() {
					var rows = document.querySelectorAll('#affikeep-log-container tbody tr');
					var lines = ['日時\t種類\tメッセージ\t詳細'];
					rows.forEach(function(row) {
						var cells = row.querySelectorAll('td');
						lines.push([
							cells[0].textContent.trim(),
							cells[1].textContent.trim(),
							cells[2].textContent.trim(),
							cells[3].textContent.trim().replace(/\s+/g, ' ')
						].join('\t'));
					});
					var text = lines.join('\n');
					if ( navigator.clipboard ) {
						navigator.clipboard.writeText(text).then(function() {
							alert('コピーしました！チャットに貼り付けてください。');
						});
					} else {
						// フォールバック
						var ta = document.createElement('textarea');
						ta.value = text;
						document.body.appendChild(ta);
						ta.select();
						document.execCommand('copy');
						document.body.removeChild(ta);
						alert('コピーしました！チャットに貼り付けてください。');
					}
				});
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	/** モール別ステータスバッジを返す */
	private static function mall_badge( string $status, bool $has_url ): string {
		if ( ! $has_url ) {
			return '<span style="color:#c3c4c7;font-size:11px;">—</span>';
		}
		switch ( $status ) {
			case 'ok':
				return '<span style="background:#00a32a;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;font-weight:600;">正常</span>';
			case 'dead':
				return '<span style="background:#d63638;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;font-weight:600;">切れ</span>';
			default:
				return '<span style="background:#72777c;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;font-weight:600;">未確認</span>';
		}
	}

	// ----------------------------------------------------------------
	// AJAX: 掲載記事一覧
	// ----------------------------------------------------------------

	public static function ajax_get_product_articles(): void {
		check_ajax_referer( 'affikeep_articles', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '権限がありません' );
		}

		$product_id = intval( $_POST['product_id'] ?? 0 );
		if ( ! $product_id ) {
			wp_send_json_error( '商品IDが無効です' );
		}

		wp_send_json_success( self::get_articles_with_product( $product_id ) );
	}

	public static function ajax_hide_in_article(): void {
		check_ajax_referer( 'affikeep_articles', 'nonce' );

		$product_id = intval( $_POST['product_id'] ?? 0 );
		$post_id    = intval( $_POST['post_id'] ?? 0 );
		$hide       = ! empty( $_POST['hide'] ) && $_POST['hide'] !== '0';

		if ( ! $product_id || ! $post_id ) {
			wp_send_json_error( 'IDが無効です' );
		}

		// 対象記事そのものの編集権限を確認する（edit_postsだけだと他人の記事を操作できてしまう）
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( '権限がありません' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( '記事が見つかりません' );
		}

		$new_content = preg_replace_callback(
			'/<!-- wp:affikeep\/product (\{[^}]*\}) \/-->/',
			function ( $matches ) use ( $product_id, $hide ) {
				$attrs = json_decode( $matches[1], true );
				if ( ! is_array( $attrs ) || intval( $attrs['product_id'] ?? 0 ) !== $product_id ) {
					return $matches[0];
				}
				if ( $hide ) {
					$attrs['hidden'] = true;
				} else {
					unset( $attrs['hidden'] );
				}
				return '<!-- wp:affikeep/product ' . wp_json_encode( $attrs ) . ' /-->';
			},
			$post->post_content
		);

		wp_update_post( [ 'ID' => $post_id, 'post_content' => $new_content ] );
		wp_send_json_success();
	}

	public static function ajax_delete_from_article(): void {
		check_ajax_referer( 'affikeep_articles', 'nonce' );

		$product_id = intval( $_POST['product_id'] ?? 0 );
		$post_id    = intval( $_POST['post_id'] ?? 0 );

		if ( ! $product_id || ! $post_id ) {
			wp_send_json_error( 'IDが無効です' );
		}

		// 対象記事そのものの編集権限を確認する（edit_postsだけだと他人の記事を操作できてしまう）
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( '権限がありません' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( '記事が見つかりません' );
		}

		$new_content = preg_replace_callback(
			'/<!-- wp:affikeep\/product (\{[^}]*\}) \/-->/',
			function ( $matches ) use ( $product_id ) {
				$attrs = json_decode( $matches[1], true );
				if ( ! is_array( $attrs ) || intval( $attrs['product_id'] ?? 0 ) !== $product_id ) {
					return $matches[0];
				}
				return '';
			},
			$post->post_content
		);

		wp_update_post( [ 'ID' => $post_id, 'post_content' => $new_content ] );
		wp_send_json_success();
	}

	private static function get_articles_with_product( int $product_id ): array {
		global $wpdb;
		$pattern = '%"product_id":' . $product_id . '%';
		$rows    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_status
				 FROM {$wpdb->posts}
				 WHERE post_content LIKE %s
				 AND post_status NOT IN ('trash','auto-draft')
				 AND post_type IN ('post','page')",
				$pattern
			),
			ARRAY_A
		);

		$result = [];
		foreach ( $rows as $row ) {
			$p = get_post( intval( $row['ID'] ) );
			if ( ! $p ) continue;
			if ( ! preg_match( '/<!-- wp:affikeep\/product \{[^}]*"product_id"\s*:\s*' . $product_id . '[^}]*\} \/-->/', $p->post_content ) ) {
				continue;
			}
			// 記事内の全ブロックを走査し、この商品のブロックのhidden状態を見る
			// （最初のブロックだけ見ると、複数商品がある記事で誤判定する）
			$hidden = false;
			if ( preg_match_all( '/<!-- wp:affikeep\/product (\{[^}]*\}) \/-->/', $p->post_content, $all ) ) {
				foreach ( $all[1] as $json ) {
					$attrs = json_decode( $json, true );
					if ( is_array( $attrs ) && intval( $attrs['product_id'] ?? 0 ) === $product_id ) {
						$hidden = ! empty( $attrs['hidden'] );
						break;
					}
				}
			}
			$result[] = [
				'id'       => intval( $row['ID'] ),
				'title'    => $row['post_title'],
				'status'   => $row['post_status'],
				'edit_url' => get_edit_post_link( intval( $row['ID'] ), 'raw' ),
				'hidden'   => $hidden,
			];
		}
		return $result;
	}

	/** 商品一括削除ハンドラ */
	public static function handle_bulk_delete(): void {
		check_admin_referer( 'affikeep_bulk_delete' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません' );
		}

		if ( ! empty( $_POST['delete_all_unused'] ) ) {
			$ids = self::get_unused_product_ids();
		} else {
			$ids = array_map( 'intval', (array) ( $_POST['product_ids'] ?? [] ) );
		}

		$deleted = 0;
		foreach ( $ids as $id ) {
			if ( $id > 0 && get_post_type( $id ) === AffiKeep_Post_Type::CPT ) {
				wp_delete_post( $id, true );
				$deleted++;
			}
		}

		$filter   = sanitize_key( $_POST['filter'] ?? 'all' );
		wp_redirect( admin_url( 'admin.php?page=affikeep-links&deleted=' . $deleted . '&filter=' . $filter ) );
		exit;
	}

	/** 記事に使われていない商品IDの配列を返す */
	private static function get_unused_product_ids(): array {
		global $wpdb;

		// post_type を絞らないとリビジョン（編集履歴）内の古いブロックまで「使用中」と数えてしまう
		$contents = $wpdb->get_col(
			"SELECT post_content FROM {$wpdb->posts}
			 WHERE post_content LIKE '%wp:affikeep/product%'
			 AND post_type IN ('post','page')
			 AND post_status NOT IN ('trash','auto-draft')"
		);

		$used = [];
		foreach ( $contents as $c ) {
			preg_match_all( '/"product_id"\s*:\s*(\d+)/', $c, $m );
			foreach ( $m[1] as $pid ) {
				$used[] = intval( $pid );
			}
		}
		$used = array_unique( $used );

		$all = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
			AffiKeep_Post_Type::CPT
		) ) );

		return array_values( array_diff( $all, $used ) );
	}

	/** ログ消去ハンドラ */
	public static function handle_clear_log(): void {
		check_admin_referer( 'affikeep_clear_log' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません' );
		}
		AffiKeep_Logger::clear();
		wp_redirect( admin_url( 'admin.php?page=affikeep-error-log&cleared=1' ) );
		exit;
	}
}
