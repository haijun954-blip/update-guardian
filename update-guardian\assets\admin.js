(function () {
	'use strict';

	document.addEventListener('click', function (event) {
		var confirmButton = event.target.closest('[data-update-guardian-confirm]');
		if (confirmButton && !window.confirm(confirmButton.getAttribute('data-update-guardian-confirm'))) {
			event.preventDefault();
		}

		var copyButton = event.target.closest('[data-update-guardian-copy]');
		if (copyButton) {
			var value = copyButton.getAttribute('data-update-guardian-copy') || '';
			if (!navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
				window.prompt(copyButton.getAttribute('data-prompt-label') || '复制链接', value);
				return;
			}
			navigator.clipboard.writeText(value).then(function () {
				var original = copyButton.textContent;
				copyButton.textContent = copyButton.getAttribute('data-copied-label') || original;
				window.setTimeout(function () {
					copyButton.textContent = original;
				}, 1800);
			}).catch(function () {
				window.prompt(copyButton.getAttribute('data-prompt-label') || '复制链接', value);
			});
		}
	});

	var config = window.UpdateGuardianMonitor;
	if (!config || !config.endpoint || !config.nonce) {
		return;
	}

	var sent = 0;
	var seen = {};

	function pluginSlug(text) {
		var normalized = String(text || '').replace(/\\/g, '/');
		var match = normalized.match(/\/wp-content\/plugins\/([^/?#]+)/i);
		return match ? decodeURIComponent(match[1]).toLowerCase() : '';
	}

	function send(type, message, source, line, column, stack) {
		if (sent >= (config.limit || 12)) {
			return;
		}

		var slug = pluginSlug(source + '\n' + stack);
		if (!slug || slug === 'update-guardian') {
			return;
		}

		var key = [type, message, source, line, column].join('|');
		if (seen[key]) {
			return;
		}
		seen[key] = true;
		sent += 1;

		var payload = {
			type: String(type || 'error').slice(0, 64),
			message: String(message || '').slice(0, 2000),
			source: String(source || '').slice(0, 2000),
			line: Number(line) || 0,
			column: Number(column) || 0,
			stack: String(stack || '').slice(0, 5000),
			page_url: window.location.href,
			error_key: key
		};

		window.fetch(config.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			keepalive: true,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce
			},
			body: JSON.stringify(payload)
		}).catch(function () {
			// Diagnostics must never interrupt the page being inspected.
		});
	}

	window.addEventListener('error', function (event) {
		var target = event.target;
		if (target && target !== window && (target.src || target.href)) {
			var resourceUrl = target.currentSrc || target.src || target.href;
			send('resource', '资源加载失败：' + resourceUrl, resourceUrl, 0, 0, '');
			return;
		}

		send(
			'error',
			event.message || '未知 JavaScript 错误',
			event.filename || '',
			event.lineno || 0,
			event.colno || 0,
			event.error && event.error.stack ? event.error.stack : ''
		);
	}, true);

	window.addEventListener('unhandledrejection', function (event) {
		var reason = event.reason || {};
		send(
			'rejection',
			reason.message || String(reason) || '未处理的 Promise 异常',
			reason.fileName || '',
			reason.lineNumber || 0,
			reason.columnNumber || 0,
			reason.stack || ''
		);
	});
}());

