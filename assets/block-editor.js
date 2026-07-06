(function (blocks, element, blockEditor, components, data) {
	var el              = element.createElement;
	var useState        = element.useState;
	var useBlockProps   = blockEditor.useBlockProps;
	var ComboboxControl = components.ComboboxControl;
	var useSelect       = data.useSelect;

	blocks.registerBlockType('affikeep/product', {
		title:       'AffiKeep 商品',
		icon:        'cart',
		category:    'widgets',
		description: 'AffiKeepに登録した商品カードを挿入します。',

		attributes: {
			product_id:    { type: 'number',  default: 0 },
			product_title: { type: 'string',  default: '' },
			// PHP側と揃えて宣言する（宣言しないと記事の再保存時に属性が消えて、非表示中のブロックが再表示されてしまう）
			hidden:        { type: 'boolean', default: false },
		},

		edit: function (props) {
			var product_id    = props.attributes.product_id;
			var product_title = props.attributes.product_title;

			// 入力文字でサーバー検索する（一覧取得は100件までしか返らないため、
			// 商品が多いサイトでは検索しないと選べない商品が出る）
			var searchState = useState('');
			var search      = searchState[0];
			var setSearch   = searchState[1];

			var products = useSelect(function (select) {
				var query = {
					per_page: 50,
					status:   'publish,draft,pending,private',
					context:  'edit',
					_fields:  'id,title',
				};
				if ( search ) {
					query.search = search;
				}
				return select('core').getEntityRecords('postType', 'affikeep_product', query);
			}, [search]);

			var isLoading = ( products === null );

			var options = ( products || [] ).map(function (p) {
				return {
					label: p.title.rendered || '(タイトルなし)',
					value: p.id,
				};
			});

			// 選択中の商品が検索結果に含まれない場合も表示が壊れないよう先頭に足す
			if ( product_id > 0 && ! options.some(function (o) { return o.value === product_id; }) ) {
				options.unshift({
					label: product_title || ( '商品ID ' + product_id ),
					value: product_id,
				});
			}

			var blockProps = useBlockProps({
				style: {
					padding: '16px',
					background: '#f0f6fc',
					border: '1px solid #c3c4c7',
					borderRadius: '4px'
				}
			});

			return el('div', blockProps,
				el('p', { style: { margin: '0 0 8px', fontWeight: '600', color: '#1d2327' } },
					'AffiKeep 商品',
					props.attributes.hidden
						? el('span', { style: { marginLeft: '8px', fontSize: '11px', background: '#f0f0f1', color: '#787c82', padding: '2px 6px', borderRadius: '3px', fontWeight: '400' } }, '非表示中')
						: null
				),
				el(ComboboxControl, {
					label:   '商品を検索して選択',
					value:   product_id || null,
					options: options,
					onChange: function (val) {
						if ( ! val ) {
							props.setAttributes({ product_id: 0, product_title: '' });
							return;
						}
						var found = options.find(function (o) { return o.value === val; });
						props.setAttributes({
							product_id:    val,
							product_title: found ? found.label : '',
						});
					},
					onFilterValueChange: function (val) {
						setSearch( val || '' );
					},
					help: isLoading
						? '検索中...'
						: '商品名の一部を入力するとサーバーから検索します',
				}),
				product_id > 0
					? el('p', { style: { margin: '8px 0 0', fontSize: '12px', color: '#787c82' } },
						'選択中: ' + ( product_title || '商品ID ' + product_id )
					)
					: null
			);
		},

		save: function () {
			return null;
		},
	});

})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.data);
