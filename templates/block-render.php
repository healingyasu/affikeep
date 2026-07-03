<?php
defined( 'ABSPATH' ) || exit;

// このファイルは class-block.php の render() から呼ばれる
// 利用可能な変数: $post (WP_Post), $product_id (int)

$title         = get_the_title( $post );
$image_url     = get_post_meta( $post->ID, '_affikeep_image_url',    true );
$price         = get_post_meta( $post->ID, '_affikeep_price',        true );
$amazon_price  = get_post_meta( $post->ID, '_affikeep_amazon_price', true );
$amazon_url    = get_post_meta( $post->ID, '_affikeep_amazon_url',   true );
$rakuten_url = get_post_meta( $post->ID, '_affikeep_rakuten_url', true );
$yahoo_url   = get_post_meta( $post->ID, '_affikeep_yahoo_url',   true );
$status      = get_post_meta( $post->ID, '_affikeep_link_status', true ) ?: 'unknown';

$s = AffiKeep_Settings::get();

// 入力したアフィリエイト情報に応じてURLを変換（直接優先・もしもフォールバック・素のURL）
if ( $amazon_url )  $amazon_url  = AffiKeep_Settings::affiliate_url( $amazon_url,  'amazon' );
if ( $rakuten_url ) $rakuten_url = AffiKeep_Settings::affiliate_url( $rakuten_url, 'rakuten' );
if ( $yahoo_url )   $yahoo_url   = AffiKeep_Settings::affiliate_url( $yahoo_url,   'yahoo' );

$btn_amazon  = esc_html( $s['button_text_amazon']  ?: 'Amazonで見る' );
$btn_rakuten = esc_html( $s['button_text_rakuten'] ?: '楽天で見る' );
$btn_yahoo   = esc_html( $s['button_text_yahoo']   ?: 'Yahoo!で見る' );
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
				<?php if ( $amazon_url ) : ?>
					<a href="<?php echo esc_url( $amazon_url ); ?>"
						class="affikeep-btn affikeep-btn-amazon"
						target="_blank" rel="nofollow noopener"><?php echo $btn_amazon; ?></a>
				<?php endif; ?>

				<?php if ( $rakuten_url ) : ?>
					<a href="<?php echo esc_url( $rakuten_url ); ?>"
						class="affikeep-btn affikeep-btn-rakuten"
						target="_blank" rel="nofollow noopener"><?php echo $btn_rakuten; ?></a>
				<?php endif; ?>

				<?php if ( $yahoo_url ) : ?>
					<a href="<?php echo esc_url( $yahoo_url ); ?>"
						class="affikeep-btn affikeep-btn-yahoo"
						target="_blank" rel="nofollow noopener"><?php echo $btn_yahoo; ?></a>
				<?php endif; ?>
			</div>

		</div>
	</div>
</div>
