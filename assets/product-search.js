document.addEventListener('DOMContentLoaded', function () {

	// 外部API由来の文字列をHTMLに入れる前にエスケープする
	function esc( s ) {
		return String( s == null ? '' : s )
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	function setVal( name, val ) {
		var el = document.querySelector('[name="' + name + '"]');
		if ( el ) el.value = val;
	}

	// タイトル欄（WordPressの投稿タイトル）が空の場合のみ検索結果のタイトルを入れる
	function setTitleIfEmpty( title ) {
		var titleInput = document.getElementById('title');
		if ( titleInput && ! titleInput.value ) {
			titleInput.value = title;
			titleInput.dispatchEvent( new Event('input') );
		}
	}

	/**
	 * 商品検索ボックス1つ分の初期化。楽天・Amazon等、モールごとに呼び出す。
	 * @param {object} opts inputId, btnId, resultsId, restUrl, nonce, buttonLabel, fillFields
	 */
	function initSearch( opts ) {
		var input   = document.getElementById( opts.inputId );
		var btn     = document.getElementById( opts.btnId );
		var results = document.getElementById( opts.resultsId );
		var items   = [];

		if ( ! input || ! btn || ! results ) return;

		btn.addEventListener('click', function () {
			var keyword = input.value.trim();
			if ( ! keyword ) return;

			btn.disabled      = true;
			btn.textContent   = '検索中...';
			results.innerHTML = '';

			fetch( opts.restUrl + '?q=' + encodeURIComponent( keyword ), {
				headers: { 'X-WP-Nonce': opts.nonce }
			} )
			.then( function (res) { return res.json(); } )
			.then( function (data) {
				btn.disabled    = false;
				btn.textContent = opts.buttonLabel;

				if ( data.error ) {
					results.innerHTML = '<p class="ak-search-error">' + esc( data.error ) + '</p>';
					return;
				}

				if ( ! data.items || data.items.length === 0 ) {
					results.innerHTML = '<p class="ak-search-empty">商品が見つかりませんでした。別のキーワードで試してください。</p>';
					return;
				}

				var html = '<ul class="ak-result-list">';
				data.items.forEach( function (item, idx) {
					items[idx] = item;
					html += '<li class="ak-result-item" data-idx="' + idx + '">';
					if ( item.image_url ) {
						html += '<img src="' + esc( item.image_url ) + '" alt="" class="ak-result-img">';
					}
					html += '<div class="ak-result-info">';
					html += '<p class="ak-result-title">' + esc( item.title ) + '</p>';
					html += '<p class="ak-result-price">' + esc( item.price ) + '</p>';
					html += '</div>';
					html += '<button type="button" class="button ak-select-btn">この商品を選ぶ</button>';
					html += '</li>';
				});
				html += '</ul>';
				results.innerHTML = html;

				// 選択ボタン
				results.querySelectorAll('.ak-select-btn').forEach( function (selectBtn) {
					selectBtn.addEventListener('click', function () {
						var idx  = parseInt( this.closest('.ak-result-item').dataset.idx, 10 );
						var item = items[idx];
						opts.fillFields( item );
						results.innerHTML = '<p class="ak-search-selected">✅ 選択しました：' + esc( item.title ) + '</p>';
					});
				});
			} )
			.catch( function () {
				btn.disabled       = false;
				btn.textContent    = opts.buttonLabel;
				results.innerHTML  = '<p class="ak-search-error">通信エラーが発生しました。エラーログを確認してください。</p>';
			} );
		});

		// Enterキーでも検索
		input.addEventListener('keydown', function (e) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				btn.click();
			}
		});
	}

	if ( typeof affikeepSearch !== 'undefined' ) {
		initSearch({
			inputId: 'ak-search-input', btnId: 'ak-search-btn', resultsId: 'ak-search-results',
			restUrl: affikeepSearch.restUrl, nonce: affikeepSearch.nonce, buttonLabel: '楽天で検索',
			fillFields: function ( item ) {
				setVal('_affikeep_image_url',   item.image_url   || '');
				setVal('_affikeep_price',       item.price       || '');
				setVal('_affikeep_rakuten_url', item.rakuten_url || '');
				setTitleIfEmpty( item.title );
			}
		});
	}

	// Amazon検索（Pro限定。ライセンス無効時はこの変数自体がPHP側で渡されない）
	if ( typeof affikeepAmazonSearch !== 'undefined' ) {
		initSearch({
			inputId: 'ak-amazon-search-input', btnId: 'ak-amazon-search-btn', resultsId: 'ak-amazon-search-results',
			restUrl: affikeepAmazonSearch.restUrl, nonce: affikeepAmazonSearch.nonce, buttonLabel: 'Amazonで検索',
			fillFields: function ( item ) {
				setVal('_affikeep_image_url',    item.image_url  || '');
				setVal('_affikeep_amazon_price', item.price      || '');
				setVal('_affikeep_amazon_url',   item.amazon_url || '');
				setVal('_affikeep_amazon_asin',  item.asin       || '');
				setTitleIfEmpty( item.title );
			}
		});
	}

	// 「🔗 開く」ボタン：入力欄の現在のURLを別タブで開く（保存前でも確認可能）
	document.querySelectorAll('.ak-open-url').forEach( function (btn) {
		btn.addEventListener('click', function () {
			var input = document.getElementById( this.dataset.target );
			var url   = input ? input.value.trim() : '';
			if ( ! url ) {
				alert('URLが入力されていません。');
				return;
			}
			window.open( url, '_blank', 'noopener' );
		});
	});
});
