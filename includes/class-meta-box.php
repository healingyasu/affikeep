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

		<style>
		.affikeep-meta-table { width:100%; border-collapse:collapse; }
		.affikeep-meta-table th { width:160px; padding:10px 12px 10px 0; font-weight:600; vertical-align:top; }
		.affikeep-meta-table td { padding:8px 0; }
		.affikeep-meta-table input[type=text],
		.affikeep-meta-table input[type=url],
		.affikeep-meta-table input[type=number] { width:100%; max-width:480px; }
		.affikeep-meta-table .desc { color:#787c82; font-size:12px; margin-top:4px; }
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
				<th><label for="ak_price">表示価格</label></th>
				<td>
					<input type="text" id="ak_price" name="_affikeep_price"
						value="<?php echo $get( '_affikeep_price' ); ?>" placeholder="例: 2,980円">
					<p class="desc">自由形式。「2,980円」「税込3,278円」など</p>
				</td>
			</tr>
			<tr>
				<th><label for="ak_amazon_url">Amazon URL</label></th>
				<td>
					<input type="url" id="ak_amazon_url" name="_affikeep_amazon_url"
						value="<?php echo $get( '_affikeep_amazon_url' ); ?>" placeholder="https://www.amazon.co.jp/dp/...">
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
					<input type="url" id="ak_rakuten_url" name="_affikeep_rakuten_url"
						value="<?php echo $get( '_affikeep_rakuten_url' ); ?>" placeholder="https://item.rakuten.co.jp/...">
				</td>
			</tr>
			<tr>
				<th><label for="ak_yahoo_url">Yahoo!ショッピング URL</label></th>
				<td>
					<input type="url" id="ak_yahoo_url" name="_affikeep_yahoo_url"
						value="<?php echo $get( '_affikeep_yahoo_url' ); ?>" placeholder="https://shopping.yahoo.co.jp/...">
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

	public static function save( int $post_id ): void {
		if (
			! isset( $_POST['affikeep_meta_nonce'] ) ||
			! wp_verify_nonce( $_POST['affikeep_meta_nonce'], 'affikeep_save_meta' ) ||
			( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
			! current_user_can( 'edit_post', $post_id )
		) {
			return;
		}

		$text_fields = [
			'_affikeep_image_url',
			'_affikeep_price',
			'_affikeep_amazon_url',
			'_affikeep_amazon_asin',
			'_affikeep_rakuten_url',
			'_affikeep_yahoo_url',
		];

		foreach ( $text_fields as $key ) {
			$field = str_replace( '_affikeep_', '', $key );
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, sanitize_text_field( $_POST[ $key ] ) );
			}
		}
	}
}
