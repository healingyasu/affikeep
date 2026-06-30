document.addEventListener('DOMContentLoaded', function () {

	var searchInput   = document.getElementById('ak-search-input');
	var searchBtn     = document.getElementById('ak-search-btn');
	var searchResults = document.getElementById('ak-search-results');
	var affikeepItems = [];

	if ( ! searchInput || ! searchBtn ) return;

	searchBtn.addEventListener('click', function () {
		var keyword = searchInput.value.trim();
		if ( ! keyword ) return;

		searchBtn.disabled    = true;
		searchBtn.textContent = '検索中...';
		searchResults.innerHTML = '';

		fetch( affikeepSearch.restUrl + '?q=' + encodeURIComponent( keyword ), {
			headers: {
				'X-WP-Nonce': affikeepSearch.nonce,
			}
		} )
		.then( function (res) { return res.json(); } )
		.then( function (data) {
			searchBtn.disabled    = false;
			searchBtn.textContent = '楽天で検索';

			if ( data.error ) {
				searchResults.innerHTML = '<p class="ak-search-error">' + data.error + '</p>';
				return;
			}

			if ( ! data.items || data.items.length === 0 ) {
				searchResults.innerHTML = '<p class="ak-search-empty">商品が見つかりませんでした。別のキーワードで試してください。</p>';
				return;
			}

			var html = '<ul class="ak-result-list">';
			data.items.forEach( function (item, idx) {
				affikeepItems[idx] = item;
				html += '<li class="ak-result-item" data-idx="' + idx + '">';
				if ( item.image_url ) {
					html += '<img src="' + item.image_url + '" alt="" class="ak-result-img">';
				}
				html += '<div class="ak-result-info">';
				html += '<p class="ak-result-title">' + item.title + '</p>';
				html += '<p class="ak-result-price">' + item.price + '</p>';
				html += '</div>';
				html += '<button type="button" class="button ak-select-btn">この商品を選ぶ</button>';
				html += '</li>';
			});
			html += '</ul>';
			searchResults.innerHTML = html;

			// 選択ボタン
			document.querySelectorAll('.ak-select-btn').forEach( function (btn) {
				btn.addEventListener('click', function () {
					var idx  = parseInt( this.closest('.ak-result-item').dataset.idx, 10 );
					var item = affikeepItems[idx];
					fillFields( item );
					searchResults.innerHTML = '<p class="ak-search-selected">✅ 選択しました：' + item.title + '</p>';
				}.bind(btn));
			});
		} )
		.catch( function (err) {
			searchBtn.disabled    = false;
			searchBtn.textContent = '楽天で検索';
			searchResults.innerHTML = '<p class="ak-search-error">通信エラーが発生しました。エラーログを確認してください。</p>';
		} );
	});

	// Enterキーでも検索
	searchInput.addEventListener('keydown', function (e) {
		if ( e.key === 'Enter' ) {
			e.preventDefault();
			searchBtn.click();
		}
	});

	function fillFields( item ) {
		setVal('_affikeep_image_url',   item.image_url   || '');
		setVal('_affikeep_price',       item.price       || '');
		setVal('_affikeep_rakuten_url', item.rakuten_url || '');

		// タイトル欄（WordPressの投稿タイトル）
		var titleInput = document.getElementById('title');
		if ( titleInput && ! titleInput.value ) {
			titleInput.value = item.title;
			// タイトル変更イベントを発火してWordPressに認識させる
			titleInput.dispatchEvent( new Event('input') );
		}
	}

	function setVal( name, val ) {
		var el = document.querySelector('[name="' + name + '"]');
		if ( el ) el.value = val;
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
