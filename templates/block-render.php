<?php
defined( 'ABSPATH' ) || exit;

// このファイルは class-block.php の render() から呼ばれる
// 利用可能な変数: $post (WP_Post), $product_id (int)

$title        = get_the_title( $post );
$image_url    = get_post_meta( $post->ID, '_affikeep_image_url',    true );
$price        = get_post_meta( $post->ID, '_affikeep_price',        true );
$amazon_price = get_post_meta( $post->ID, '_affikeep_amazon_price', true );
$status       = get_post_meta( $post->ID, '_affikeep_link_status', true ) ?: 'unknown';

$s = AffiKeep_Settings::get();

// モールごとのURL・ボタン文言をレジストリから組み立てる（新モール追加時もこのファイルは変更不要）
$mall_buttons = [];
foreach ( AffiKeep_Malls::available() as $mall_id => $def ) {
	$url = get_post_meta( $post->ID, "_affikeep_{$mall_id}_url", true );
	if ( ! $url ) {
		continue;
	}
	$mall_buttons[] = [
		'mall'  => $mall_id,
		'url'   => AffiKeep_Settings::affiliate_url( $url, $mall_id ),
		'label' => $s[ "button_text_{$mall_id}" ] ?? ( $def['label'] . 'で見る' ),
	];
}
?>
<div class="affikeep-product-card">

	<?php if ( $status === 'dead' ) : ?>
		<p class="affikeep-card-dead-notice">⚠️ このリンクは現在アクセスできない可能性があります</p>
	<?php endif; ?>

	<div class="affikeep-card-inner">

		<?php if ( $image_url ) : ?>
			<div class="affikeep-card-image">
				<img src="<?php echo esc_url( $image_url ); ?>"
					alt="<?php echo esc_attr( $title ); ?>"
					loading="lazy">
			</div>
		<?php endif; ?>

		<div class="affikeep-card-body">

			<p class="affikeep-card-title"><?php echo esc_html( $title ); ?></p>

			<?php if ( $amazon_price && $price ) : ?>
				<p class="affikeep-card-price">
					<span class="affikeep-price-amazon">Amazon <?php echo esc_html( $amazon_price ); ?></span>
					<span class="affikeep-price-rakuten">楽天 <?php echo esc_html( $price ); ?></span>
				</p>
			<?php elseif ( $amazon_price ) : ?>
				<p class="affikeep-card-price"><?php echo esc_html( $amazon_price ); ?></p>
			<?php elseif ( $price ) : ?>
				<p class="affikeep-card-price"><?php echo esc_html( $price ); ?></p>
			<?php endif; ?>

			<div class="affikeep-card-buttons">
				<?php foreach ( $mall_buttons as $btn ) : ?>
					<a href="<?php echo esc_url( $btn['url'] ); ?>"
						class="affikeep-btn affikeep-btn-<?php echo esc_attr( $btn['mall'] ); ?>"
						data-product-id="<?php echo esc_attr( $product_id ); ?>"
						data-post-id="<?php echo esc_attr( $post->ID ); ?>"
						data-mall="<?php echo esc_attr( $btn['mall'] ); ?>"
						target="_blank" rel="nofollow noopener"><?php echo esc_html( $btn['label'] ); ?></a>
				<?php endforeach; ?>
			</div>

		</div>
	</div>
</div>
