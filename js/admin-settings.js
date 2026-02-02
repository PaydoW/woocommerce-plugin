(function ($) {
	function togglePaydoMethodsFields() {
		const mode = $('#woocommerce_paydo_methods_mode').is(':checked');

		const ids = [
			'#woocommerce_paydo_project_id',
			'#woocommerce_paydo_jwt_token',
			'#paydo-methods-search',
			'#woocommerce_paydo_sync_methods',
		];

		ids.forEach((sel) => {
			const $row = $(sel).closest('tr');
			mode ? $row.show() : $row.hide();
		});

		const $syncRow = $('#paydo-sync-methods-btn').closest('tr');
		mode ? $syncRow.show() : $syncRow.hide();

		const $titleRow = $('.wc_settings_divider, h2').filter(function () {
			return $(this).text().trim().toLowerCase().includes('payment methods');
		}).closest('tr');
	}

	$(document).ready(function () {
		togglePaydoMethodsFields();
		$(document).on('change', '#woocommerce_paydo_methods_mode', togglePaydoMethodsFields);
	});
})(jQuery);
