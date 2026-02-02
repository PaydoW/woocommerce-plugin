const settings = window.wc?.wcSettings?.getSetting('paydo_data', {}) || {};
const { decodeEntities } = window.wp.htmlEntities;
const { __ } = window.wp.i18n;
const { createElement, useState, useEffect, Fragment } = window.wp.element;

const label = decodeEntities(settings.title) || __('PayDo', 'paydo-woocommerce');

const methodsMode = !!settings.methods_mode;
const available = settings.available_methods || {};
const enabled = (settings.enabled_methods || []).map(String).filter(Boolean);

const Content = (props) => {
	const description = decodeEntities(settings.description || '');

	const [selected, setSelected] = useState(enabled[0] || '');

	useEffect(() => {
		if (!props || !props.eventRegistration || !props.emitResponse) return;

		const unsubscribe = props.eventRegistration.onPaymentSetup(async () => {
			return {
				type: props.emitResponse.responseTypes.SUCCESS,
				meta: {
					paymentMethodData: {
						paydo_method: selected || '',
					},
				},
			};
		});

		return unsubscribe;
	}, [props, selected]);

	const list = enabled
		.map((id) => {
			const item = available[id];

			if (item && typeof item === 'object') {
				return {
					id,
					title: item.title || `Method #${id}`,
					logo: item.logo || '',
				};
			}

			return {
				id,
				title: item || `Method #${id}`,
				logo: '',
			};
		})
		.filter((m) => m.id);

	if (!methodsMode || !list.length) {
		return createElement(
			Fragment,
			null,
			description ? createElement('p', null, description) : null
		);
	}

	return createElement(
		Fragment,
		null,
		description ? createElement('p', null, description) : null,

		createElement(
			'div',
			{ style: { marginTop: '10px' } },
			createElement('strong', null, __('Choose PayDo method:', 'paydo-woocommerce')),

			list.map((m) =>
				createElement(
					'label',
					{
						key: m.id,
						style: {
							display: 'flex',
							alignItems: 'center',
							gap: '10px',
							padding: '8px 10px',
							margin: '6px 0',
							border: '1px solid rgba(0,0,0,.12)',
							borderRadius: '10px',
							cursor: 'pointer',
							userSelect: 'none',
						},
					},
					createElement('input', {
						type: 'radio',
						name: 'paydo_method',
						value: m.id,
						checked: selected === m.id,
						onChange: () => setSelected(m.id),
						style: { margin: 0 },
					}),
					m.logo
						? createElement('img', {
								src: m.logo,
								alt: '',
								loading: 'lazy',
								style: {
									height: '22px',
									width: 'auto',
									maxWidth: '60px',
									objectFit: 'contain',
								},
						  })
						: null,
					createElement(
						'span',
						{ style: { lineHeight: 1.2 } },
						decodeEntities(m.title)
					)
				)
			)
		)
	);
};

const Block_Gateway = {
	name: 'paydo',
	label,
	content: createElement(Content, null),
	edit: createElement(Content, null),
	canMakePayment: () => true,
	ariaLabel: label,
	supports: {
		features: ['products'],
	},
};

if (window.wc?.wcBlocksRegistry) {
	window.wc.wcBlocksRegistry.registerPaymentMethod(Block_Gateway);
}
