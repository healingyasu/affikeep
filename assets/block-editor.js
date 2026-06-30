(function (blocks, element, blockEditor, components, data) {
	var el              = element.createElement;
	var useBlockProps   = blockEditor.useBlockProps;
	var ComboboxControl = components.ComboboxControl;
	var Spinner         = components.Spinner;
	var useSelect       = data.useSelect;

	blocks.registerBlockType('affikeep/product', {
		title:       'AffiKeep 商品',
		icon:        'cart',
		category:    'widgets',
		description: 'AffiKeepに登録した商品カードを挿入します。',

		attributes: {
			product_id:    { type: 'number', default: 0 },
			product_title: { type: 'string', default: '' },
		},

		edit: function (props) {
			var product_id    = props.attributes.product_id;
			var product_title = props.attributes.product_title;

			var products = useSelect(function (select) {
				return select('core').getEntityRecords(
					'postType',
					'affikeep_product',
					{
						per_page: 100,
						status: 'publish,draft,pending,private',
						context: 'edit',
						_fields: 'id,title'
					}
				);
			}, []);

			var blockProps = useBlockProps({
				style: {
					padding: '16px',
					background: '#f0f6fc',
					border: '1px solid #c3c4c7',
					borderRadius: '4px'
				}
			});

			if ( products === null ) {
				return el('div', blockProps,
					el('p', { style: { margin: 0 } }, el(Spinner), ' 商品を読み込み中...')
				);
			}

			if ( products.length === 0 ) {
				return el('div', blockProps,
					el('p', { style: { margin: 0, color: '#d63638' } },
						'商品が登録されていません。AffiKeep → 商品を追加 から先に商品を登録してください。'
					)
				);
			}

			var options = products.map(function (p) {
				return {
					label: p.title.rendered || '(タイトルなし)',
					value: p.id,
				};
			});

			return el('div', blockProps,
				el('p', { style: { margin: '0 0 8px', fontWeight: '600', color: '#1d2327' } },
					'AffiKeep 商品'
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
					onFilterValueChange: function () {},
					help: '商品名の一部を入力すると絞り込めます',
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
