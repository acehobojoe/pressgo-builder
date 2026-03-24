/**
 * PressGo Settings page — mode toggle, credit balance, connection test.
 */
(function () {
	'use strict';

	// ============ Mode Toggle ============
	function updateModeVisibility() {
		var mode = document.querySelector('input[name="pressgo_api_mode"]:checked');
		if (!mode) return;
		var isPressGo = mode.value === 'pressgo';

		document.querySelectorAll('.pressgo-field-pressgo').forEach(function (row) {
			row.classList.toggle('pressgo-field-hidden', !isPressGo);
		});
		document.querySelectorAll('.pressgo-field-direct').forEach(function (row) {
			row.classList.toggle('pressgo-field-hidden', isPressGo);
		});

		// Fetch credit balance when PressGo mode is active
		if (isPressGo) {
			fetchCreditBalance();
		}
	}

	document.querySelectorAll('input[name="pressgo_api_mode"]').forEach(function (radio) {
		radio.addEventListener('change', updateModeVisibility);
	});
	updateModeVisibility();

	// ============ Credit Balance ============
	function fetchCreditBalance() {
		var keyField = document.getElementById('pressgo_account_key');
		var balanceDiv = document.getElementById('pressgo-credit-balance');
		if (!keyField || !balanceDiv) return;

		var key = keyField.value;
		if (!key || !key.startsWith('pg_')) {
			balanceDiv.style.display = 'none';
			return;
		}

		balanceDiv.style.display = 'block';
		balanceDiv.innerHTML = '<em>Checking credits...</em>';

		fetch('https://pressgo.app/api/plugin/credits', {
			headers: { 'X-PressGo-Key': key }
		})
			.then(function (r) {
				if (r.status === 401) throw new Error('Invalid API key');
				if (!r.ok) throw new Error('Server error');
				return r.json();
			})
			.then(function (data) {
				balanceDiv.innerHTML =
					'<span class="pressgo-status-ok">&#10003; Connected</span> &mdash; ' +
					'<strong>' + data.total + ' credits</strong> remaining (' +
					data.free + ' free, ' + data.purchased + ' purchased)' +
					' &mdash; <a href="https://pressgo.app/dashboard" target="_blank">Buy more</a>';
			})
			.catch(function (err) {
				balanceDiv.innerHTML = '<span class="pressgo-status-error">&#10007; ' + err.message + '</span>';
			});
	}

	// Re-check balance when key field changes
	var accountKeyField = document.getElementById('pressgo_account_key');
	if (accountKeyField) {
		accountKeyField.addEventListener('change', fetchCreditBalance);
	}

	// ============ Connection Test ============
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
