<?php
/**
 * Proライセンスキー発行スクリプト（フェーズ0: 手動発行用）。
 * WordPressを起動せずincludes/class-license.phpの署名ロジックだけを使う。
 *
 * 使い方: php bin/generate-license-key.php <plan> <expiry:YYYYMMDD>
 * 例:     php bin/generate-license-key.php pro 20271231
 *
 * 運用手順は docs/PRO_LICENSE_ISSUING.md を参照。
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "CLIから実行してください。\n" );
	exit( 1 );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' ); // class-license.php の ABSPATH ガードを通すためのダミー定義
}

require __DIR__ . '/../includes/class-license.php';

$plan   = $argv[1] ?? null;
$expiry = $argv[2] ?? null;

if ( ! $plan || ! $expiry || ! preg_match( '/^\d{8}$/', $expiry ) ) {
	fwrite( STDERR, "使い方: php bin/generate-license-key.php <plan> <expiry:YYYYMMDD>\n例:     php bin/generate-license-key.php pro 20271231\n" );
	exit( 1 );
}

$sig = AffiKeep_License::sign( $plan, $expiry );
echo "AK-{$plan}-{$expiry}-{$sig}\n";
