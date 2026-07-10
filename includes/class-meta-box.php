<?php
defined( 'ABSPATH' ) || exit;

class AffiKeep_Meta_Box {

	public static function init(): void {
		add_action( 'add_meta_boxes',        [ __CLASS__, 'add' ] );
		add_action( 'save_post',             [ __CLASS__, 'save' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	public static function enqueue( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== AffiKeep_Post_Type::CPT ) return;

		wp_enqueue_script(
			'affikeep-product-search',
			AFFIKEEP_URL . 'assets/product-search.js',
			[],
			AFFIKEEP_VERSION,
			true
		);

		wp_localize_script( 'affikeep-product-search', 'affikeepSearch', [
			'restUrl' => rest_url( 'affikeep/v1/search/rakuten' ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		] );

		// Amazon商品検索はPro限定。ライセンス無効時はスクリプト変数を渡さず、
		// product-search.js側の `typeof affikeepAmazonSearch !== 'undefined'` チェックで自動的に無効化される。
		if ( AffiKeep_License::is_active() ) {
			wp_localize_script( 'affikeep-product-search', 'affikeepAmazonSearch', [
				'restUrl' => rest_url( 'affikeep/v1/search/amazon' ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			] );
		}
	}

	public static function add(): void {
		add_meta_box(
			'affikeep_product_data',
			'商品情報',
			[ __CLASS__, 'render' ],
			AffiKeep_Post_Type::CPT,
			'normal',
			'high'
		);
		add_meta_box(
			'affikeep_product_articles',
			'掲載記事一覧',
			[ __CLASS__, 'render_articles' ],
			AffiKeep_Post_Type::CPT,
			'normal',
			'default'
		);
	}

	public static function render( WP_Post $post ): void {
		wp_nonce_field( 'affikeep_save_meta', 'affikeep_meta_nonce' );

		$get = fn( $key ) => esc_attr( get_post_meta( $post->ID, $key, true ) );
		?>
		<style>
		/* 検索エリア */
		.ak-search-area {
			background: #f0f6fc;
			border: 1px solid #c3c4c7;
			border-radius: 4px;
			padding: 14px 16px;
			margin-bottom: 16px;
		}
		.ak-search-area p { margin: 0 0 8px; font-weight: 600; }
		.ak-search-row { display: flex; gap: 8px; }
		.ak-search-row input { flex: 1; }
		.ak-result-list { margin: 12px 0 0; padding: 0; list-style: none; }
		.ak-result-item {
			display: flex; align-items: center; gap: 12px;
			padding: 10px; border: 1px solid #e0e0e0; border-radius: 4px;
			margin-bottom: 8px; background: #fff;
		}
		.ak-result-img { width: 60px; height: 60px; object-fit: contain; flex-shrink: 0; }
		.ak-result-info { flex: 1; min-width: 0; }
		.ak-result-title { margin: 0 0 4px; font-size: 13px; line-height: 1.4; }
		.ak-result-price { margin: 0; font-weight: 700; color: #c0392b; }
		.ak-search-error   { color: #d63638; }
		.ak-search-empty   { color: #787c82; }
		.ak-search-selected{ color: #00a32a; font-weight: 600; }
		</style>

		<div class="ak-search-area">
			<p>楽天で商品を検索して自動入力</p>
			<div class="ak-search-row">
				<input type="text" id="ak-search-input" placeholder="例: ランニングシューズ メンズ">
				<button type="button" id="ak-search-btn" class="button button-primary">楽天で検索</button>
			</div>
			<div id="ak-search-results"></div>
		</div>

		<?php if ( AffiKeep_License::is_active() ) : ?>
			<div class="ak-search-area">
				<p>Amazonで商品を検索して自動入力（PA-API）</p>
				<div class="ak-search-row">
					<input type="text" id="ak-amazon-search-input" placeholder="例: ランニングシューズ メンズ">
					<button type="button" id="ak-amazon-search-btn" class="button button-primary">Amazonで検索</button>
				</div>
				<div id="ak-amazon-search-results"></div>
			</div>
		<?php else : ?>
			<p class="description" style="margin:0 0 16px;">
				🔒 Pro版ではAmazon PA-APIを使った商品検索・自動入力ができます。
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=affikeep-settings' ) ); ?>">ライセンスを有効化する</a>
			</p>
		<?php endif; ?>

		<style>
		.affikeep-meta-table { width:100%; border-collapse:collapse; }
		.affikeep-meta-table th { width:160px; padding:10px 12px 10px 0; font-weight:600; vertical-align:top; }
		.affikeep-meta-table td { padding:8px 0; }
		.affikeep-meta-table input[type=text],
		.affikeep-meta-table input[type=url],
		.affikeep-meta-table input[type=number] { width:100%; max-width:480px; }
		.affikeep-meta-table .desc { color:#787c82; font-size:12px; margin-top:4px; }
		.ak-url-row { display:flex; gap:8px; align-items:center; max-width:600px; }
		.ak-url-row input { flex:1; }
		.ak-open-url { flex-shrink:0; white-space:nowrap; }
		.affikeep-status { display:inline-block; padding:2px 8px; border-radius:3px; font-size:12px; font-weight:600; }
		.affikeep-status-ok     { background:#00a32a; color:#fff; }
		.affikeep-status-dead   { background:#d63638; color:#fff; }
		.affikeep-status-unknown{ background:#72777c; color:#fff; }
		</style>

		<table class="affikeep-meta-table">
			<tr>
				<th><label for="ak_image_url">商品画像URL</label></th>
				<td>
					<input type="url" id="ak_image_url" name="_affikeep_image_url"
						value="<?php echo $get( '_affikeep_image_url' ); ?>" placeholder="https://...">
					<p class="desc">AmazonやASPの商品画像URLをそのまま貼り付けてください</p>
				</td>
			</tr>
			<tr>
				<th><label for="ak_price">楽天価格</label></th>
				<td>
					<input type="text" id="ak_price" name="_affikeep_price"
						value="<?php echo $get( '_affikeep_price' ); ?>" placeholder="例: 3,280円">
					<p class="desc">楽天APIで自動取得。手動入力も可。</p>
				</td>
			</tr>
			<tr>
				<th><label for="ak_amazon_price">Amazon価格</label></th>
				<td>
					<input type="text" id="ak_amazon_price" name="_affikeep_amazon_price"
						value="<?php echo $get( '_affikeep_amazon_price' ); ?>" placeholder="例: 2,980円">
					<p class="desc">手動入力。両方入力するとブロックに2つの価格を表示します。</p>
				</td>
			</tr>
			<tr>
				<th><label for="ak_amazon_url">Amazon URL</label></th>
				<td>
					<div class="ak-url-row">
						<input type="url" id="ak_amazon_url" name="_affikeep_amazon_url"
							value="<?php echo $get( '_affikeep_amazon_url' ); ?>" placeholder="https://www.amazon.co.jp/dp/...">
						<button type="button" class="button ak-open-url" data-target="ak_amazon_url">🔗 開く</button>
					</div>
				</td>
			</tr>
			<tr>
				<th><label for="ak_amazon_asin">ASIN</label></th>
				<td>
					<input type="text" id="ak_amazon_asin" name="_affikeep_amazon_asin"
						value="<?php echo $get( '_affikeep_amazon_asin' ); ?>" placeholder="例: B08XYZ1234">
					<p class="desc">Amazon URLの /dp/ の後ろの英数字10桁</p>
				</td>
			</tr>
			<tr>
				<th><label for="ak_rakuten_url">楽天市場 URL</label></th>
				<td>
					<div class="ak-url-row">
						<input type="url" id="ak_rakuten_url" name="_affikeep_rakuten_url"
							value="<?php echo $get( '_affikeep_rakuten_url' ); ?>" placeholder="https://item.rakuten.co.jp/...">
						<button type="button" class="button ak-open-url" data-target="ak_rakuten_url">🔗 開く</button>
					</div>
				</td>
			</tr>
			<tr>
				<th><label for="ak_yahoo_url">Yahoo!ショッピング URL</label></th>
				<td>
					<div class="ak-url-row">
						<input type="url" id="ak_yahoo_url" name="_affikeep_yahoo_url"
							value="<?php echo $get( '_affikeep_yahoo_url' ); ?>" placeholder="https://shopping.yahoo.co.jp/...">
						<button type="button" class="button ak-open-url" data-target="ak_yahoo_url">🔗 開く</button>
					</div>
				</td>
			</tr>
			<tr>
				<th>リンク状態</th>
				<td>
					<?php
					$status = get_post_meta( $post->ID, '_affikeep_link_status', true ) ?: 'unknown';
					$labels = [ 'ok' => '正常', 'dead' => 'リンク切れ', 'unknown' => '未チェック' ];
					echo '<span class="affikeep-status affikeep-status-' . esc_attr( $status ) . '">'
						. esc_html( $labels[ $status ] ?? '不明' ) . '</span>';

					$last = get_post_meta( $post->ID, '_affikeep_last_checked', true );
					if ( $last ) {
						echo ' <span style="color:#787c82;font-size:12px;">最終確認: ' . esc_html( $last ) . '</span>';
					}
					?>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function render_articles( WP_Post $post ): void {
		$nonce = wp_create_nonce( 'affikeep_articles' );
		?>
		<style>
		.ak-articles-list { margin:0; padding:0; list-style:none; }
		.ak-articles-list li {
			display:flex; align-items:center; gap:8px;
			padding:8px 0; border-bottom:1px solid #f0f0f1;
		}
		.ak-articles-list li:last-child { border-bottom:none; }
		.ak-article-title { flex:1; font-size:13px; }
		.ak-article-status { color:#787c82; font-size:11px; }
		.ak-article-hidden-badge {
			font-size:11px; background:#f0f0f1; color:#787c82;
			padding:1px 6px; border-radius:3px;
		}
		.ak-bulk-btn { opacity:.45; cursor:not-allowed !important; }
		.ak-badge-dev {
			font-size:10px; background:#72777c; color:#fff;
			padding:1px 5px; border-radius:3px; margin-left:4px; vertical-align:middle;
		}
		</style>

		<div id="ak-articles-container">
			<p style="color:#787c82;">読み込み中...</p>
		</div>

		<div style="margin-top:12px; padding-top:12px; border-top:1px solid #f0f0f1;">
			<button type="button" class="button ak-bulk-btn" disabled
				title="この機能は今後のアップデートで追加予定です">
				全記事から一括削除
				<span class="ak-badge-dev">開発中</span>
			</button>
		</div>

		<script>
		(function() {
			var productId = <?php echo intval( $post->ID ); ?>;
			var nonce     = '<?php echo esc_js( $nonce ); ?>';
			var container = document.getElementById('ak-articles-container');

			function load() {
				post('affikeep_get_product_articles', {}).then(function(data) {
					if (!data.success || !data.data.length) {
						container.innerHTML = '<p style="color:#787c82;">この商品を貼り付けた記事はありません。</p>';
						return;
					}
					renderList(data.data);
				}).catch(function() {
					container.innerHTML = '<p style="color:#d63638;">読み込みに失敗しました。</p>';
				});
			}

			function renderList(articles) {
				var ul = document.createElement('ul');
				ul.className = 'ak-articles-list';
				articles.forEach(function(a) {
					var statusMap = {publish:'公開', draft:'下書き', private:'非公開'};
					var li = document.createElement('li');
					li.innerHTML =
						'<span class="ak-article-title">' +
						'  <a href="' + h(a.edit_url) + '" target="_blank">' + h(a.title || '（タイトルなし）') + '</a>' +
						'  <span class="ak-article-status">（' + h(statusMap[a.status] || a.status) + '）</span>' +
						(a.hidden ? ' <span class="ak-article-hidden-badge">非表示中</span>' : '') +
						'</span>' +
						'<button type="button" class="button button-small" data-action="hide" data-post-id="' + a.id + '" data-hide="' + (a.hidden ? '0' : '1') + '">' +
						(a.hidden ? '表示に戻す' : '非表示') + '</button>' +
						'<button type="button" class="button button-small" data-action="delete" data-post-id="' + a.id + '" style="color:#d63638;">削除</button>';
					ul.appendChild(li);
				});

				ul.addEventListener('click', function(e) {
					var btn = e.target.closest('button[data-action]');
					if (!btn) return;
					var action = btn.dataset.action;
					var postId = btn.dataset.postId;
					if (action === 'hide') {
						doHide(postId, btn.dataset.hide);
					} else if (action === 'delete') {
						if (!confirm('この記事からAffiKeepブロックを削除します。元に戻せません。続けますか？')) return;
						doDelete(postId);
					}
				});

				container.innerHTML = '';
				container.appendChild(ul);
			}

			function doHide(postId, hide) {
				post('affikeep_hide_in_article', {post_id: postId, hide: hide}).then(function(d) {
					if (d.success) load(); else alert('処理に失敗しました。');
				});
			}

			function doDelete(postId) {
				post('affikeep_delete_from_article', {post_id: postId}).then(function(d) {
					if (d.success) load(); else alert('処理に失敗しました。');
				});
			}

			function post(action, extra) {
				var fd = new FormData();
				fd.append('action', action);
				fd.append('product_id', productId);
				fd.append('nonce', nonce);
				Object.keys(extra).forEach(function(k) { fd.append(k, extra[k]); });
				return fetch(ajaxurl, {method:'POST', body:fd}).then(function(r) { return r.json(); });
			}

			function h(s) {
				return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
			}

			load();
		})();
		</script>
		<?php
	}

	public static function save( int $post_id ): void {
		if (
			! isset( $_POST['affikeep_meta_nonce'] ) ||
			! wp_verify_nonce( $_POST['affikeep_meta_nonce'], 'affikeep_save_meta' ) ||
			( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
			! current_user_can( 'edit_post', $post_id )
		) {
			return;
		}

		$url_keys = [
			'_affikeep_amazon_url',
			'_affikeep_rakuten_url',
			'_affikeep_yahoo_url',
		];
		$text_fields = array_merge( [
			'_affikeep_image_url',
			'_affikeep_price',
			'_affikeep_amazon_price',
			'_affikeep_amazon_asin',
		], $url_keys );

		$url_changed = false;
		foreach ( $text_fields as $key ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			// URLフィールドはesc_url_raw()で保存（sanitize_text_fieldは%エンコードを破壊する）
			$raw = wp_unslash( $_POST[ $key ] );
			if ( in_array( $key, $url_keys, true ) ) {
				// マークダウンリンク [text](url) が貼られた場合は url 部分だけ取り出す
				if ( preg_match( '/\[.*?\]\(\s*(\S+?)\s*\)/', $raw, $m ) ) {
					$raw = $m[1];
				}
				$new_val = esc_url_raw( $raw );
			} else {
				$new_val = sanitize_text_field( $raw );
			}

			if ( in_array( $key, $url_keys, true ) ) {
				$old_val = get_post_meta( $post_id, $key, true );
				if ( $old_val !== $new_val ) {
					$url_changed = true;
				}
			}
			update_post_meta( $post_id, $key, $new_val );
		}

		// URLが変更されたらリンク状態を「未チェック」にリセット
		// モール別ステータスも消す（残すと再計算ボタンが古い判定を復活させる）
		if ( $url_changed ) {
			delete_post_meta( $post_id, '_affikeep_link_status' );
			delete_post_meta( $post_id, '_affikeep_last_checked' );
			delete_post_meta( $post_id, '_affikeep_rakuten_status' );
			delete_post_meta( $post_id, '_affikeep_yahoo_status' );
		}
	}
}
