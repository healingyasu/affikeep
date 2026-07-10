<?php
defined( 'ABSPATH' ) || exit;

/**
 * 対応モールの定義を一元管理する。
 * 新しいモールを追加する場合は definitions() に1エントリ足すだけでよい。
 */
class AffiKeep_Malls {

	/**
	 * モール定義。
	 *   label            表示名
	 *   is_pro           Pro限定モールかどうか（無料3モールはfalse）
	 *   moshimo          もしもアフィリエイト変換用のp_id/pc_id/pl_id（省略可。未検証のモールには設定しないこと）
	 *   direct_url       直接アフィリエイトURLを返すコールバック（対象外ならnullを返す）
	 *   bot_phrases      bot検知ページの文言（判定保留扱い。Amazonのみ）
	 *   unknown_phrases  要確認文言（bot検知の可能性あり。Amazonのみ）
	 *   dead_phrases     販売終了・売り切れ等の文言
	 */
	public static function definitions(): array {
		return [
			'amazon' => [
				'label'   => 'Amazon',
				'is_pro'  => false,
				'moshimo' => [ 'p_id' => 170, 'pc_id' => 185, 'pl_id' => 4062 ],
				'direct_url' => function ( string $url ) {
					$tag = AffiKeep_Settings::get( 'amazon_tracking_id' );
					return $tag ? add_query_arg( 'tag', $tag, $url ) : null;
				},
				'bot_phrases' => [
					'ロボットによる', '自動化されたアクセス', 'Type the characters',
					'api-services-support@amazon.com', 'To discuss automated access',
				],
				'unknown_phrases' => [ '現在お取り扱いできません', 'この商品は現在お取り扱いできません' ],
				'dead_phrases'    => [ 'ページが見つかりませんでした', 'Page Not Found' ],
			],

			'rakuten' => [
				'label'   => '楽天',
				'is_pro'  => false,
				'moshimo' => [ 'p_id' => 54, 'pc_id' => 54, 'pl_id' => 616 ],
				'direct_url' => function ( string $url ) {
					// 楽天検索で取得したURLは既にアフィリエイトIDが入っているのでそのまま使う
					return AffiKeep_Settings::get( 'rakuten_affiliate_id' ) ? $url : null;
				},
				'dead_phrases' => [
					'販売を終了', 'ページが見つかりません', '商品が見つかりません',
					'お探しのページは見つかりませんでした', 'この商品は現在販売されておりません',
				],
			],

			'yahoo' => [
				'label'   => 'Yahoo!',
				'is_pro'  => false,
				'moshimo' => [ 'p_id' => 1, 'pc_id' => 1, 'pl_id' => 1 ],
				'direct_url' => function ( string $url ) {
					// LinkSwitchはサイト全体のJSが自動変換するので素のURLを出力
					return AffiKeep_Settings::get( 'yahoo_linkswitch' ) ? $url : null;
				},
				'dead_phrases' => [
					'販売を終了', 'ページが見つかりません', '商品が見つかりません',
					'お探しのページは見つかりませんでした', 'この商品は現在販売されておりません',
				],
			],

			// 以下Pro限定モール。もしもアフィリエイトのp_id/pc_id/pl_idは実アカウントで
			// 検証できていないため意図的に用意していない（誤った値だと成果が発生しないまま
			// 気づけないリスクがあるため）。直接リンクのみ対応。
			'rakuten_travel' => [
				'label'  => '楽天トラベル',
				'is_pro' => true,
				'direct_url' => function ( string $url ) {
					// 楽天アフィリエイトの管理画面で発行した提携済みリンクをそのまま貼る運用のため変換不要
					return $url;
				},
				'dead_phrases' => [
					'ページが見つかりません', 'お探しのページは見つかりませんでした',
					'このプランの提供は終了しました', 'プランが見つかりません',
				],
			],

			'booking' => [
				'label'  => 'Booking.com',
				'is_pro' => true,
				'direct_url' => function ( string $url ) {
					$aid = AffiKeep_Settings::get( 'booking_affiliate_id' );
					return $aid ? add_query_arg( 'aid', $aid, $url ) : null;
				},
				'dead_phrases' => [
					'ページが見つかりません', 'Page Not Found', 'no longer available',
				],
			],
		];
	}

	/** モールIDから定義を取得 */
	public static function get( string $id ): ?array {
		return self::definitions()[ $id ] ?? null;
	}

	/** 全モールIDの配列 */
	public static function ids(): array {
		return array_keys( self::definitions() );
	}

	/** 現在のライセンス状態で利用可能なモール定義のみを返す */
	public static function available(): array {
		$is_pro_active = AffiKeep_License::is_active();
		return array_filter(
			self::definitions(),
			fn( $def ) => $is_pro_active || empty( $def['is_pro'] )
		);
	}
}
