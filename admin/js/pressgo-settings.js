/**
 * PressGo Settings page — connection test.
 */
(function () {
	'use strict';

	var testBtn = document.getElementById('pressgo-test-connection');
	if (!testBtn) return;

	testBtn.addEventListener('click', function () {
		var btn = this;
		var result = document.getElementById('pressgo-test-result');
		btn.disabled = true;
		btn.textContent = 'Testing...';
		result.innerHTML = '';
		fetch(pressgoSettings.ajaxUrl + '?action=pressgo_test_connection&nonce=' + pressgoSettings.testNonce)
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (data.success) {
					result.innerHTML = '<span class="pressgo-status-ok">&#10003; ' + data.data.message + '</span>';
				} else {
					result.innerHTML = '<span class="pressgo-status-error">&#10007; ' + data.data.message + '</span>';
				}
			})
			.catch(function (err) {
				result.innerHTML = '<span class="pressgo-status-error">&#10007; Request failed: ' + err.message + '</span>';
			})
			.finally(function () {
				btn.disabled = false;
				btn.textContent = 'Test Connection';
			});
	});
})();
