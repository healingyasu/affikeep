/* AffiKeep Pro: アクセス解析ダッシュボードの日別クリック数チャート（外部ライブラリ不使用の軽量SVG描画） */
(function () {
	'use strict';

	var SVG_NS = 'http://www.w3.org/2000/svg';

	function el(tag, attrs) {
		var node = document.createElementNS(SVG_NS, tag);
		for (var k in attrs) {
			if (Object.prototype.hasOwnProperty.call(attrs, k)) {
				node.setAttribute(k, attrs[k]);
			}
		}
		return node;
	}

	/**
	 * @param {string} containerId  描画先要素のID
	 * @param {object} series       { 'YYYY-MM-DD': { mallId: count, ... }, ... }（日付昇順）
	 * @param {Array}  mallIds      表示するモールIDの配列（固定順）
	 * @param {object} mallLabels   { mallId: '表示名' }
	 * @param {Array}  colors       mallIdsと同じ順のカラー配列
	 */
	window.affikeepDrawChart = function (containerId, series, mallIds, mallLabels, colors) {
		var container = document.getElementById(containerId);
		if (!container) {
			return;
		}
		var dates = Object.keys(series);
		if (!dates.length) {
			container.innerHTML = '<p style="color:#898781;">データがありません。</p>';
			return;
		}

		var W = 900, H = 260, padL = 36, padR = 16, padT = 16, padB = 26;
		var innerW = W - padL - padR, innerH = H - padT - padB;

		var maxVal = 0;
		dates.forEach(function (d) {
			mallIds.forEach(function (m) {
				maxVal = Math.max(maxVal, series[d][m] || 0);
			});
		});
		maxVal = Math.max(1, Math.ceil(maxVal * 1.15));

		function x(i) {
			return padL + (dates.length > 1 ? (innerW * i / (dates.length - 1)) : innerW / 2);
		}
		function y(v) {
			return padT + innerH - (innerH * v / maxVal);
		}

		var svg = el('svg', {
			viewBox: '0 0 ' + W + ' ' + H,
			width: '100%',
			height: H,
			role: 'img',
			'aria-label': '日別クリック数の推移',
		});
		svg.style.overflow = 'visible';
		svg.style.display = 'block';

		// グリッド線（横4本）+ Y軸ラベル
		for (var g = 0; g <= 4; g++) {
			var gy = padT + innerH * g / 4;
			svg.appendChild(el('line', { x1: padL, x2: W - padR, y1: gy, y2: gy, stroke: '#e1e0d9', 'stroke-width': 1 }));
			var label = el('text', { x: padL - 6, y: gy + 3, 'text-anchor': 'end', 'font-size': 10, fill: '#898781' });
			label.textContent = Math.round(maxVal * (4 - g) / 4);
			svg.appendChild(label);
		}

		// ベースライン
		svg.appendChild(el('line', {
			x1: padL, x2: W - padR, y1: padT + innerH, y2: padT + innerH,
			stroke: '#c3c2b7', 'stroke-width': 1,
		}));

		// モールごとの折れ線（固定の色順）
		mallIds.forEach(function (mall, mi) {
			var points = dates.map(function (d, i) {
				return x(i) + ',' + y(series[d][mall] || 0);
			}).join(' ');
			svg.appendChild(el('polyline', {
				points: points,
				fill: 'none',
				stroke: colors[mi],
				'stroke-width': 2,
				'stroke-linecap': 'round',
				'stroke-linejoin': 'round',
			}));
		});

		// X軸ラベル（先頭・中間・末尾のみ）
		[0, Math.floor((dates.length - 1) / 2), dates.length - 1].forEach(function (i, idx, arr) {
			if (i < 0 || i >= dates.length || arr.indexOf(i) !== idx) {
				return;
			}
			var anchor = i === 0 ? 'start' : (i === dates.length - 1 ? 'end' : 'middle');
			var lbl = el('text', { x: x(i), y: H - 6, 'text-anchor': anchor, 'font-size': 10, fill: '#898781' });
			lbl.textContent = dates[i].slice(5);
			svg.appendChild(lbl);
		});

		// ホバー用クロスヘア
		var hoverLine = el('line', {
			y1: padT, y2: padT + innerH, stroke: '#c3c2b7', 'stroke-width': 1,
		});
		hoverLine.style.display = 'none';
		svg.appendChild(hoverLine);

		container.innerHTML = '';
		container.style.position = 'relative';
		container.appendChild(svg);

		// 凡例（色だけに頼らず必ずラベルを添える）
		var legend = document.createElement('div');
		legend.style.cssText = 'display:flex;gap:16px;flex-wrap:wrap;margin-top:8px;font-size:12px;color:#52514e;';
		mallIds.forEach(function (mall, mi) {
			var item = document.createElement('span');
			item.style.cssText = 'display:inline-flex;align-items:center;gap:6px;';
			var swatch = document.createElement('span');
			swatch.style.cssText = 'width:10px;height:10px;border-radius:2px;background:' + colors[mi] + ';display:inline-block;';
			item.appendChild(swatch);
			item.appendChild(document.createTextNode(mallLabels[mall] || mall));
			legend.appendChild(item);
		});
		container.appendChild(legend);

		// ツールチップ
		var tooltip = document.createElement('div');
		tooltip.style.cssText = 'position:absolute;pointer-events:none;background:#0b0b0b;color:#fff;' +
			'font-size:11px;padding:6px 8px;border-radius:4px;display:none;white-space:nowrap;z-index:10;top:4px;';
		container.appendChild(tooltip);

		svg.addEventListener('mousemove', function (e) {
			var rect = svg.getBoundingClientRect();
			var relX = (e.clientX - rect.left) / rect.width * W;
			var i = Math.round((relX - padL) / innerW * (dates.length - 1));
			i = Math.max(0, Math.min(dates.length - 1, i));
			var d = dates[i];

			hoverLine.setAttribute('x1', x(i));
			hoverLine.setAttribute('x2', x(i));
			hoverLine.style.display = 'block';

			var lines = [d];
			mallIds.forEach(function (mall) {
				lines.push((mallLabels[mall] || mall) + ': ' + (series[d][mall] || 0));
			});
			tooltip.innerHTML = lines.join('<br>');
			tooltip.style.display = 'block';
			var leftPx = (rect.width * x(i) / W);
			tooltip.style.left = Math.min(leftPx + 10, rect.width - 140) + 'px';
		});
		svg.addEventListener('mouseleave', function () {
			hoverLine.style.display = 'none';
			tooltip.style.display = 'none';
		});
	};
})();
