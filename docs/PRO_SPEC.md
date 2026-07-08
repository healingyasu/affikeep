# AffiKeep Pro 仕様書

最終更新: 2026-07-08
現状バージョン: v0.4.3（無料単体プラグイン）

## 1. 目的・全体方針

AffiKeepを「無料版はそのまま維持しつつ、ライセンスキーでPro機能を解除する単一プラグイン」に拡張する。

- **提供形態**: 単一プラグイン＋ライセンスキー解除（フリーミアム）。WordPress.org配布の無料版と完全に同じコードベースを使う。
- **決済・ライセンス基盤**: フェーズ0では最小実装（手動キー発行）から始める。Stripe決済リンク等で購入を受け付け、購入者にライセンスキーを手動発行・メール送付する運用でスタートし、契約数が増えたらFreemius/EDD等の自動化基盤に載せ替える。そのため、ライセンス検証ロジックは `AffiKeep_License` 1クラスに集約し、後から検証方式（オフライン→リモートAPI）を差し替えられる形にしておく。
- **Pro機能の優先順位**:
  1. クリック計測・収益分析ダッシュボード
  2. CSV/レポートエクスポート
  3. 対応モール拡張

補足: プラグイン説明文には「記事別クリック計測」と書かれているが、現状のコードには実装がない（`includes/`配下にクリック計測の痕跡なし）。これをPro機能の目玉として正式に実装する。

## 2. 全体アーキテクチャ

### 2.1 ライセンス機構

- 新規オプション `affikeep_license`（`{ key, status, activated_at, expires_at }`）
- 新規クラス `AffiKeep_License`（`includes/class-license.php`）
  - `AffiKeep_License::is_active(): bool` — **Pro機能ゲートの唯一の入口**。他の全クラスはこの関数の戻り値だけを見て分岐する（検証方式が変わっても呼び出し側に影響しない）。
  - `AffiKeep_License::validate_key( string $key ): array` — フェーズ0はオフライン検証。
    - キー形式案: `AK-{plan}-{expiry:YYYYMMDD}-{sig}`（`sig` はプラグイン内に埋め込んだ秘密鍵とのHMAC）。
    - これは「抑止」であり「防御」ではない（オフライン検証はローカルで解析されうる）ことを前提として明記し、過剰な難読化に工数を使わない。
  - 期限切れ後7日間はグレースピリオドとして機能を止めず警告表示のみ（フェーズ0はオフライン検証なので単純な日付比較で十分）。
  - 設定画面（`class-admin.php` の設定ページ）に「Proライセンス」セクションを追加。キー入力欄・有効化ボタン・現在のステータス表示（有効／期限切れ／未登録）。
- 将来のフェーズ（Freemius/EDD移行時）は `validate_key()` の中身をリモートAPI呼び出しに差し替えるだけで済むようにする。過剰な抽象化（プロバイダー切り替えの設定画面等）は今回作らない。

### 2.2 モールレジストリへのリファクタ

現状、`class-settings.php`（`affiliate_url()`）や `class-link-checker.php`（判定文言）で amazon/rakuten/yahoo が switch文でハードコードされている。Pro機能でモールを追加するたびに複数ファイルへ手を入れるのを避けるため、以下を導入する。

- 新規クラス `AffiKeep_Malls`（`includes/class-malls.php`）
  - モール定義を配列で一元管理: `id`, `label`, `is_pro`（bool）, `dead_phrases`（配列）, `affiliate_url_callback`, `meta_key_prefix`
  - 無料3モール（amazon, rakuten, yahoo）は `is_pro = false` として既存動作を完全維持。
  - Pro追加モールは `is_pro = true`。`AffiKeep_License::is_active()` が false の場合、設定画面・商品編集メタボックス・Gutenbergブロックのいずれにも表示しない。
- 既存の `class-settings.php::affiliate_url()` / `class-link-checker.php` の判定ロジックはこのレジストリを参照する形に置き換える。**無料ユーザーへの挙動変化がないことをこのリファクタの完了条件とする。**

## 3. Pro機能仕様

### 3.1 クリック計測・収益分析ダッシュボード

#### データモデル

新規テーブル `{$wpdb->prefix}affikeep_clicks`（`dbDelta` で有効化時に作成・更新）

| 列 | 型 | 説明 |
|---|---|---|
| id | BIGINT AUTO_INCREMENT | PK |
| click_date | DATE | 集計日 |
| product_id | BIGINT | 商品CPTのID |
| post_id | BIGINT | クリックが発生した記事のID |
| mall | VARCHAR(20) | amazon / rakuten / yahoo / ... |
| clicks | INT | その日・その組み合わせの累積クリック数 |

`UNIQUE KEY (click_date, product_id, post_id, mall)` とし、`INSERT ... ON DUPLICATE KEY UPDATE clicks = clicks + 1` で加算する。1クリック=1行の生ログにせず日次集計にすることで、テーブル肥大化を防ぎ、個人を特定しうる情報（IP・UA等）を保持しないプライバシー配慮も両立する。

#### 計測の仕組み

- フロント: 新規 `assets/click-tracker.js`（Pro有効時のみ enqueue）
  - `.affikeep-btn`（`templates/block-render.php` が出力するボタン）のクリックをイベント委譲で検知。
  - ボタンに `data-product-id` `data-post-id` `data-mall` 属性を追加（`block-render.php` の改修が必要）。
  - `navigator.sendBeacon`（非対応時は `fetch(..., {keepalive:true})`）で `POST /wp-json/affikeep/v1/click` に送信。リンク遷移は妨げない（fire-and-forget）。
- バックエンド: `class-rest-api.php` に `/click` ルートを追加。
  - `permission_callback` は `__return_true`（未ログイン閲覧者からの送信のため）。
  - スパム・連打対策として、送信元IPをハッシュ化したキーで transient による簡易レート制限（例: 1分間あたり同一IP+商品IDで一定回数まで）。IPそのものは保存しない。
  - Pro無効時はこのルートを登録しない（404）。

#### ダッシュボード画面

新規管理メニュー「AffiKeep → アクセス解析」（Pro限定。無効時はアップグレード訴求画面を表示）

- 期間セレクタ（7日/30日/90日/カスタム範囲）
- 日別クリック数推移（モール別内訳、折れ線グラフ）
- 商品別クリック数ランキング（記事別内訳へのドリルダウン付き）
- 記事別クリック数ランキング
- 任意機能「収益概算」: モールごとに「想定成約率(%)」「平均報酬単価(円)」をユーザーが入力すると `クリック数 × 成約率 × 報酬単価` で概算表示。実際の成果とは異なる旨を常時明記（実績データではなく見積りであることを誤認させない）。
- グラフ描画はライセンス・バンドルサイズの問題があるため外部CDN依存を避け、同梱ライブラリまたは軽量な自前SVG描画で実装する（採用ライブラリはフェーズ1着手時に確定）。

### 3.2 CSV/レポートエクスポート

- **商品一覧CSV**: 商品名、価格、対応モール、リンク状態、最終チェック日時、（Pro時のみ）累計クリック数
- **クリック/収益レポートCSV**: 日付、商品名、記事タイトル、モール、クリック数、（任意）収益概算
- 実装: `admin_post_affikeep_export_csv` ハンドラで `admin-post.php` 経由のストリーム出力。`fputcsv` を使用し、Excelでの文字化けを避けるためUTF-8 BOMを付与。
- 設置場所: 「リンク切れ」ページと「アクセス解析」ページにそれぞれエクスポートボタンを設置。

### 3.3 対応モール拡張

`AffiKeep_Malls` レジストリ（2.2節）を前提に、Pro限定モールを追加する。第一弾候補は楽天トラベル・Yahoo!オークション・Booking.comなど（優先順位と対象モールは実装着手時に改めて確定する）。

各モール追加に必要な作業（レジストリ導入後は本質的にこれだけで済む想定）:
- 設定画面の入力欄（アフィリエイトID・トラッキングID等）
- リンク切れ判定用の「販売終了・売り切れ」文言リスト
- アフィリエイトURL変換ロジック
- 商品編集メタボックスへのURL入力欄
- Gutenbergブロックへのボタン表示

## 4. 今回のロードマップで着手しないもの（明示的な見送り）

- **リンクチェックの高速化・優先実行**（時間単位実行や全件即時チェック等）: ヒアリングの結果、優先度は低いと判断。需要が顕在化してから別途検討する。
- **Freemius/EDD等の本格課金基盤への移行**: フェーズ0は手動キー発行で運用する。
- **チーム機能・複数サイトライセンス管理**: 将来のバックログとし、今回のロードマップには含めない。

## 5. セキュリティ・プライバシー

- クリック計測はCookie不使用。IPアドレスは生保存せず、レート制限用に一時的なハッシュ（transientで自動失効）のみ使用。
- DBに保存するのは集計データ（日付・商品ID・記事ID・モール・件数）のみで、個人を特定しうる情報を含まない。
- ライセンスキー検証はフェーズ0ではローカル完結のため外部通信が発生しない。
- Pro限定の管理系エンドポイント・画面はすべて `current_user_can( 'manage_options' )` を要求する（`/click` エンドポイントのみ未ログイン閲覧者からの送信を許可する例外）。

## 6. 既存コードへの影響まとめ

| ファイル | 変更内容 |
|---|---|
| `affikeep.php` | 新規 require（`class-license.php`, `class-malls.php`, `class-analytics.php`, `class-csv-export.php`）、Pro機能の初期化を `AffiKeep_License::is_active()` で分岐 |
| `includes/class-settings.php` | モール判定を `AffiKeep_Malls` 参照に置き換え（switch文廃止） |
| `includes/class-link-checker.php` | 判定文言をレジストリ経由に変更 |
| `includes/class-admin.php` | 設定画面にライセンスセクション追加、新規メニュー「アクセス解析」追加 |
| `includes/class-rest-api.php` | `/click` ルート追加 |
| `templates/block-render.php` | ボタンに `data-product-id` `data-post-id` `data-mall` 属性を追加 |
| `assets/` | `click-tracker.js` を新規追加（Pro有効時のみ enqueue） |
