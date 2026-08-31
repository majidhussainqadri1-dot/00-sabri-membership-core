(() => {
	'use strict';
	const form = document.getElementById('smc-membership-application');
	const cfg = window.smcEvidenceStage;
	if (!form || !cfg?.ajaxUrl || !cfg?.nonce) return;

	const inputs = Array.from(form.querySelectorAll('input[type="file"][name]'));
	if (!inputs.length) return;
	const pending = new Map();
	let stagingFinalSubmission = false;

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

	const consentValue = (name) => form.elements[name]?.checked ? '1' : '0';

	const stage = async (input) => {
		const file = input.files?.[0];
		if (!file) throw new Error('Select this required identity document before submitting the application.');
		const token = Symbol(input.name);
		pending.set(input.name, token);
		input.dataset.smcStaged = '0';
		setState(input, cfg.messages?.uploading || 'Uploading this identity document securely…');
		const body = new FormData();
		body.append('action', 'smc_stage_identity_document');
		body.append('nonce', cfg.nonce);
		body.append('document_key', input.name);
		body.append('truth', consentValue('truth'));
		body.append('privacy', consentValue('privacy'));
		body.append('terms', consentValue('terms'));
		body.append('ethical', consentValue('ethical'));
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
			if (pending.get(input.name) === token) {
				input.dataset.smcStaged = '0';
				input.required = true;
				setState(input, error?.message || cfg.messages?.failed || 'The identity document could not be uploaded.', true);
			}
			throw error;
		} finally {
			if (pending.get(input.name) === token) pending.delete(input.name);
		}
	};

	inputs.forEach((input) => input.addEventListener('change', () => {
		input.dataset.smcStaged = '0';
		if (input.files?.length) {
			setState(input, cfg.messages?.selected || 'Document selected. It will be transferred securely when you submit the completed application.');
		}
	}));

	form.addEventListener('submit', (event) => {
		if (stagingFinalSubmission) {
			event.preventDefault();
			event.stopImmediatePropagation();
			return;
		}
		const requiredUnstaged = inputs.filter((input) => input.required && input.dataset.smcStaged !== '1');
		if (!requiredUnstaged.length) return;

		event.preventDefault();
		event.stopImmediatePropagation();
		if (!form.checkValidity()) {
			form.reportValidity();
			return;
		}

		stagingFinalSubmission = true;
		(async () => {
			try {
				for (const input of requiredUnstaged) await stage(input);
				stagingFinalSubmission = false;
				form.requestSubmit();
			} catch (error) {
				stagingFinalSubmission = false;
				const failed = requiredUnstaged.find((input) => input.dataset.smcStaged !== '1');
				if (failed) failed.focus();
			}
		})();
	}, true);
})();
