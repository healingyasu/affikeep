<?php
defined( 'ABSPATH' ) || exit;

class AffiKeep_Post_Type {

	const CPT = 'affikeep_product';

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_cpt' ] );

		// 商品一覧のカスタム列
		add_filter( 'manage_' . self::CPT . '_posts_columns',       [ __CLASS__, 'columns' ] );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', [ __CLASS__, 'column_content' ], 10, 2 );
	}

	/** 一覧の列を定義 */
	public static function columns( array $cols ): array {
		$new = [];
		$new['cb'] = $cols['cb'] ?? '';
		$new['ak_image']  = '画像';
		$new['title']     = '商品名';
		$new['ak_price']  = '価格';
		$new['ak_malls']  = '対応モール';
		$new['ak_status'] = 'リンク状態';
		$new['date']      = $cols['date'] ?? '日付';
		return $new;
	}

	/** 各列の中身 */
	public static function column_content( string $col, int $post_id ): void {
		switch ( $col ) {
			case 'ak_image':
				$img = get_post_meta( $post_id, '_affikeep_image_url', true );
				if ( $img ) {
					echo '<img src="' . esc_url( $img ) . '" style="width:48px;height:48px;object-fit:contain;border:1px solid #e0e0e0;border-radius:3px;">';
				} else {
					echo '<span style="color:#c3c4c7;">—</span>';
				}
				break;

			case 'ak_price':
				$price = get_post_meta( $post_id, '_affikeep_price', true );
				echo $price ? esc_html( $price ) : '<span style="color:#c3c4c7;">—</span>';
				break;

			case 'ak_malls':
				$malls = [];
				foreach ( AffiKeep_Malls::available() as $mall_id => $def ) {
					if ( get_post_meta( $post_id, "_affikeep_{$mall_id}_url", true ) ) {
						$malls[] = $def['label'];
					}
				}
				echo $malls ? esc_html( implode( ' / ', $malls ) ) : '<span style="color:#c3c4c7;">—</span>';
				break;

			case 'ak_status':
				$status = get_post_meta( $post_id, '_affikeep_link_status', true ) ?: 'unknown';
				$map = [
					'ok'      => [ '正常',       '#00a32a' ],
					'dead'    => [ 'リンク切れ', '#d63638' ],
					'unknown' => [ '未チェック', '#72777c' ],
				];
				$s = $map[ $status ] ?? $map['unknown'];
				echo '<span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:600;background:' . esc_attr( $s[1] ) . ';color:#fff;">' . esc_html( $s[0] ) . '</span>';
				break;
		}
	}

	public static function register_cpt(): void {
		$labels = [
			'name'               => '商品',
			'singular_name'      => '商品',
			'add_new'            => '商品を追加',
			'add_new_item'       => '新しい商品を追加',
			'edit_item'          => '商品を編集',
			'new_item'           => '新しい商品',
			'view_item'          => '商品を表示',
			'search_items'       => '商品を検索',
			'not_found'          => '商品が見つかりません',
			'not_found_in_trash' => 'ゴミ箱に商品はありません',
			'menu_name'          => '商品管理',
		];

		$args = [
			'labels'              => $labels,
			'public'              => false,   // フロントエンドには直接表示しない
			'publicly_queryable'  => false,
			'show_ui'             => true,
			'show_in_menu'        => false,   // AffiKeepメニュー配下に表示するため
			'show_in_rest'        => true,    // Gutenbergブロックからの操作に必要
			'supports'            => [ 'title', 'thumbnail' ],
			'menu_icon'           => 'dashicons-cart',
			'capability_type'     => 'post',
		];

		try {
			register_post_type( self::CPT, $args );
		} catch ( Exception $e ) {
			AffiKeep_Logger::error( 'CPT登録エラー: ' . $e->getMessage() );
		}
	}

	/** メタフィールド一覧（プレフィックス: _affikeep_） */
	public static function meta_keys(): array {
		return [
			'_affikeep_amazon_url',     // AmazonページURL
			'_affikeep_rakuten_url',    // 楽天ページURL
			'_affikeep_yahoo_url',      // Yahoo!ショッピングURL
			'_affikeep_amazon_asin',    // ASIN
			'_affikeep_rakuten_item_code', // 楽天商品コード
			'_affikeep_image_url',      // 商品画像URL
			'_affikeep_price',          // 表示価格（文字列）
			'_affikeep_link_status',    // ok / dead / unknown
			'_affikeep_last_checked',   // 最終チェック日時
			'_affikeep_usage_post_ids', // 掲載記事IDリスト（JSON）
		];
	}
}
