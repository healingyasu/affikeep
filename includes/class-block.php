<?php
defined( 'ABSPATH' ) || exit;

class AffiKeep_Block {

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ] );
	}

	public static function register(): void {
		try {
			register_block_type( 'affikeep/product', [
				'editor_script'   => 'affikeep-block-editor',
				'render_callback' => [ __CLASS__, 'render' ],
				'attributes'      => [
					'product_id' => [
						'type'    => 'number',
						'default' => 0,
					],
					'hidden' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			] );

			wp_register_script(
				'affikeep-block-editor',
				AFFIKEEP_URL . 'assets/block-editor.js',
				[ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-data' ],
				AFFIKEEP_VERSION,
				true
			);

			wp_register_style(
				'affikeep-frontend',
				AFFIKEEP_URL . 'assets/block-frontend.css',
				[],
				AFFIKEEP_VERSION
			);

			wp_enqueue_style( 'affikeep-frontend' );

		} catch ( Exception $e ) {
			AffiKeep_Logger::error( 'ブロック登録エラー: ' . $e->getMessage() );
		}
	}

	/** フロントエンド・プレビュー共通のレンダリング */
	public static function render( array $attributes ): string {
		$product_id = intval( $attributes['product_id'] ?? 0 );

		if ( ! $product_id || ! empty( $attributes['hidden'] ) ) {
			return '';
		}

		$post = get_post( $product_id );
		if ( ! $post || $post->post_type !== AffiKeep_Post_Type::CPT ) {
			return '';
		}

		ob_start();
		include AFFIKEEP_DIR . 'templates/block-render.php';
		return ob_get_clean();
	}
}
