(() => {
	'use strict';
	const form = document.getElementById('smc-membership-application');
	const cfg = window.smcEvidenceStage;
	if (!form || !cfg?.ajaxUrl || !cfg?.nonce) return;

	const inputs = Array.from(form.querySelectorAll('input[type="file"][name]'));
	if (!inputs.length) return;
	const pending = new Map();

	const stateNode = (input) => document.getElementById(`smc-doc-state-${input.name}`);
	const setState = (input, message, isError = false) => {
		const node = stateNode(input);
		if (!node) return;
		node.textContent = message;
		node.setAttribute('role', isError ? 'alert' : 'status');
		node.dataset.smcError = isError ? '1' : '0';
	};

	const refreshProgress = () => {
		const progress = document.getElementById('smc-upload-progress');
		if (!progress) return;
		const received = inputs.filter((input) => input.dataset.smcStaged === '1' || !input.required).length;
		progress.value = Math.min(Number(progress.max || inputs.length), received);
	};

	const stage = async (input) => {
		const file = input.files?.[0];
		if (!file) return;
		const token = Symbol(input.name);
		pending.set(input.name, token);
		input.dataset.smcStaged = '0';
		setState(input, cfg.messages?.uploading || 'Uploading this identity document securely…');
		const body = new FormData();
		body.append('action', 'smc_stage_identity_document');
		body.append('nonce', cfg.nonce);
		body.append('document_key', input.name);
		body.append('evidence_file', file, file.name);
		try {
			const response = await fetch(cfg.ajaxUrl, {method: 'POST', credentials: 'same-origin', body});
			const result = await response.json();
			if (!response.ok || !result?.success) {
				throw new Error(result?.data?.message || cfg.messages?.failed || 'The identity document could not be uploaded.');
			}
			if (pending.get(input.name) !== token) return;
			input.dataset.smcStaged = '1';
			input.required = false;
			input.value = '';
			setState(input, result?.data?.message || cfg.messages?.staged || 'Secure evidence received by the server.');
			refreshProgress();
		} catch (error) {
			if (pending.get(input.name) !== token) return;
			input.dataset.smcStaged = '0';
			input.required = true;
			setState(input, error?.message || cfg.messages?.failed || 'The identity document could not be uploaded.', true);
		} finally {
			if (pending.get(input.name) === token) pending.delete(input.name);
		}
	};

	inputs.forEach((input) => input.addEventListener('change', () => { void stage(input); }));

	form.addEventListener('click', (event) => {
		const next = event.target.closest?.('[data-smc-next]');
		const stepFive = form.querySelector('[data-smc-step="5"]');
		if (!next || !stepFive || stepFive.hidden || pending.size === 0) return;
		event.preventDefault();
		event.stopImmediatePropagation();
		const first = inputs.find((input) => pending.has(input.name));
		if (first) setState(first, cfg.messages?.pending || 'Please wait until the selected identity document finishes uploading.');
	}, true);

	form.addEventListener('submit', (event) => {
		if (pending.size > 0) {
			event.preventDefault();
			event.stopImmediatePropagation();
			const first = inputs.find((input) => pending.has(input.name));
			if (first) setState(first, cfg.messages?.pending || 'Please wait until the selected identity document finishes uploading.');
			return;
		}
		const missing = inputs.find((input) => input.required && input.dataset.smcStaged !== '1' && !input.files?.length);
		if (missing) {
			event.preventDefault();
			event.stopImmediatePropagation();
			setState(missing, 'Select and upload this required identity document before submitting the application.', true);
			missing.focus();
		}
	}, true);
})();
