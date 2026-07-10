# Proライセンス 手動発行手順（フェーズ0）

フェーズ0時点では決済・ライセンス配信を自動化せず、以下の手動フローで運用する。
契約数が増えてきたらFreemius/EDD等への移行を検討する（[`PRO_SPEC.md`](./PRO_SPEC.md) 2.1節）。

## 1. 決済を受け付ける

Stripeの決済リンク（Payment Links）を作成し、購入ページ・メール等に掲載する。
決済完了はStripeダッシュボードの通知（または連携したメール通知）で把握する。

## 2. ライセンスキーを発行する

購入者ごとに、リポジトリ直下で以下を実行する。

```bash
php bin/generate-license-key.php <plan> <expiry>
# 例: 1年ライセンスを発行する場合
php bin/generate-license-key.php pro 20271231
```

- `plan`: 現時点では `pro` のみを想定（将来プランを分ける場合はここに識別子を増やす）
- `expiry`: `YYYYMMDD` 形式の有効期限
- 出力される `AK-xxxx-xxxxxxxx-xxxxxxxxxxxxxxxx` 形式の文字列がライセンスキー

このスクリプトはWordPressを起動せず、`includes/class-license.php` の署名ロジックのみを使う。

## 3. 購入者にキーを送付する

購入者へのメールに、発行したキーと有効化手順を記載する。

> AffiKeep設定画面（AffiKeep → 設定）の「Proライセンス」欄に以下のキーを貼り付けて「有効化」を押してください。
>
> `AK-xxxx-xxxxxxxx-xxxxxxxxxxxxxxxx`

## 4. 更新・失効

- 更新: 新しい有効期限で再度キーを発行し、購入者に再送する（古いキーは自動的に期限切れになる）。
- 即時失効はフェーズ0の仕組み上できない（オフライン検証のため）。返金対応等で即時に止めたい場合は、当面は個別対応とする。フェーズ4でリモート検証に移行した際に失効APIを追加する。

## 5. 秘密鍵の管理

`includes/class-license.php` の `AffiKeep_License::SECRET` が署名鍵。これはリポジトリに含まれる値のため、**配布用ビルド（wordpress.orgやZIP配布）の前に固有の値へ変更すること**。秘密鍵が漏れるとキーが偽造可能になる点に注意（フェーズ0はあくまで「抑止」であり暗号的な「防御」ではない）。
