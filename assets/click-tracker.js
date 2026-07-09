/* AffiKeep Pro: 商品ボタンのクリックを計測してREST APIへ送信する（Pro有効時のみ読み込まれる） */
(function () {
	if (typeof affikeepClickTracker === 'undefined' || !affikeepClickTracker.endpoint) {
		return;
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.affikeep-btn') : null;
		if (!btn) {
			return;
		}

		var payload = {
			product_id: btn.getAttribute('data-product-id') || 0,
			post_id:    btn.getAttribute('data-post-id') || 0,
			mall:       btn.getAttribute('data-mall') || '',
		};
		if (!payload.product_id || !payload.mall) {
			return;
		}

		var body = JSON.stringify(payload);

		// リンク遷移を妨げないfire-and-forget送信。
		if (navigator.sendBeacon) {
			var blob = new Blob([body], { type: 'application/json' });
			navigator.sendBeacon(affikeepClickTracker.endpoint, blob);
		} else {
			fetch(affikeepClickTracker.endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: body,
				keepalive: true,
			}).catch(function () {});
		}
	}, false);
})();
