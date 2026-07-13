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

// 署名鍵を読み込む。本物の鍵はリポジトリに置かず、次の優先順位で取得する:
//   1. 環境変数 AFFIKEEP_LICENSE_SECRET（既に設定済みならそれを使う）
//   2. シークレットファイル（AFFIKEEP_SECRET_FILE、既定は ~/Documents/Blog-secrets/.env）内の
//      AFFIKEEP_LICENSE_SECRET= 行
if ( ! getenv( 'AFFIKEEP_LICENSE_SECRET' ) ) {
	$secret_file = getenv( 'AFFIKEEP_SECRET_FILE' );
	if ( ! $secret_file ) {
		$home = getenv( 'HOME' ) ?: getenv( 'USERPROFILE' );
		$secret_file = $home ? $home . '/Documents/Blog-secrets/.env' : '';
	}
	if ( $secret_file && is_readable( $secret_file ) ) {
		foreach ( file( $secret_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			if ( preg_match( '/^\s*AFFIKEEP_LICENSE_SECRET\s*=\s*(.*)$/', $line, $m ) ) {
				putenv( 'AFFIKEEP_LICENSE_SECRET=' . trim( $m[1], " \t\"'" ) );
				break;
			}
		}
	}
}

if ( ! getenv( 'AFFIKEEP_LICENSE_SECRET' ) ) {
	fwrite( STDERR, "署名鍵が見つかりません。~/Documents/Blog-secrets/.env に AFFIKEEP_LICENSE_SECRET を設定してください（docs/PRO_LICENSE_ISSUING.md 5節）。\n" );
	exit( 1 );
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
