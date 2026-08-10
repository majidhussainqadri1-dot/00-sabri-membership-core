(() => {
	'use strict';
	const form = document.getElementById('smc-membership-application');
	if (!form || !window.smcPolicy) return;

	const steps = Array.from(form.querySelectorAll('[data-smc-step]'));
	const progress = document.getElementById('smc-application-progress');
	const stepLabel = document.getElementById('smc-step-label');
	const draftStatus = document.getElementById('smc-draft-status');
	const uploadProgress = document.getElementById('smc-upload-progress');
	const retryButton = document.getElementById('smc-retry-submit');
	let errorSummary = document.getElementById('smc-error-summary');
	if (!errorSummary) { errorSummary = document.createElement('div'); errorSummary.id = 'smc-error-summary'; errorSummary.className = 'smc-notice smc-notice--error'; errorSummary.setAttribute('role','alert'); errorSummary.hidden = true; form.prepend(errorSummary); }
	const previous = form.querySelector('[data-smc-prev]');
	const next = form.querySelector('[data-smc-next]');
	const dob = form.querySelector('[name="date_of_birth"]');
	const gender = form.querySelector('[name="gender"]');
	const guardianStep = form.querySelector('.smc-guardian-step');
	const ageStatus = form.querySelector('.smc-age-status');
	let current = Math.max(1, Math.min(steps.length, Number(form.dataset.currentStep || 1)));
	let saveTimer = 0;
	let submitting = false;

	const selectedTypes = () => Array.from(form.querySelectorAll('[name="membership_types[]"]:checked')).map((field) => field.value);
	const calculateAge = () => {
		if (!dob || !dob.value) return null;
		const birth = new Date(`${dob.value}T12:00:00`);
		if (Number.isNaN(birth.getTime())) return null;
		const today = new Date();
		let age = today.getFullYear() - birth.getFullYear();
		if (today.getMonth() < birth.getMonth() || (today.getMonth() === birth.getMonth() && today.getDate() < birth.getDate())) age -= 1;
		return age;
	};

	const updateEligibility = () => {
		const age = calculateAge();
		if (age === null || !gender || !gender.value) {
			if (ageStatus) ageStatus.textContent = '';
			return;
		}
		const minimum = gender.value === 'female' ? Number(window.smcPolicy.femaleMinimumAge) : Number(window.smcPolicy.maleMinimumAge);
		const professional = selectedTypes().some((value) => ['doctor', 'teacher', 'researcher', 'pharmacy', 'clinic', 'publisher'].includes(value));
		const guardianRequired = age < Number(window.smcPolicy.guardianAge);
		if (guardianStep) {
			guardianStep.dataset.required = guardianRequired ? 'true' : 'false';
			for (const control of guardianStep.querySelectorAll('[name="guardian_name"], [name="guardian_relationship"], [name="guardian_email"], [name="guardian_phone"], [name="guardian_authority"]')) control.required = guardianRequired;
		}
		if (age < minimum) ageStatus.textContent = `The selected date is below the minimum age of ${minimum}.`;
		else if (age < Number(window.smcPolicy.professionalAge) && professional) ageStatus.textContent = 'Professional account classes require age 18 or older.';
		else if (age < Number(window.smcPolicy.guardianAge)) ageStatus.textContent = 'Verified guardian consent is required.';
		else ageStatus.textContent = 'The selected age meets the membership age rule.';
	};

	const showStep = (number, focus = true) => {
		current = Math.max(1, Math.min(steps.length, number));
		steps.forEach((step, index) => {
			step.hidden = index + 1 !== current;
			step.setAttribute('aria-hidden', index + 1 === current ? 'false' : 'true');
		});
		if (progress) progress.value = current;
		if (stepLabel) stepLabel.textContent = ` Step ${current} of ${steps.length}`;
		if (previous) previous.hidden = current === 1;
		if (next) next.hidden = current === steps.length;
		if (focus) {
			const heading = steps[current - 1]?.querySelector('legend, h2');
			if (heading) { heading.setAttribute('tabindex', '-1'); heading.focus(); }
		}
		scheduleDraftSave();
	};

	const validateStep = () => {
		const controls = Array.from(steps[current - 1].querySelectorAll('input, select, textarea'));
		if (errorSummary) { errorSummary.hidden = true; errorSummary.textContent = ''; }
		if (current === 1 && selectedTypes().length === 0) {
			if (errorSummary) { errorSummary.textContent = 'Select at least one membership role before continuing.'; errorSummary.hidden = false; }
			steps[0].querySelector('input')?.focus();
			return false;
		}
		for (const control of controls) {
			if (!control.checkValidity()) {
				const label = control.closest('label')?.childNodes?.[0]?.textContent?.trim() || control.name || 'field';
				if (errorSummary) { errorSummary.textContent = `Review ${label}: ${control.validationMessage}`; errorSummary.hidden = false; }
				control.setAttribute('aria-invalid','true'); control.reportValidity(); control.focus(); return false;
			}
			control.removeAttribute('aria-invalid');
		}
		return true;
	};

	const draftPayload = () => ({
		legal_name: form.elements.legal_name?.value || '',
		date_of_birth: form.elements.date_of_birth?.value || '',
		gender: form.elements.gender?.value || '',
		residence_country: form.elements.residence_country?.value || '',
		city: form.elements.city?.value || '',
		address: form.elements.address?.value || '',
		phone: form.elements.phone?.value || '',
		identity_type: form.elements.identity_type?.value || '',
		issuing_country: form.elements.issuing_country?.value || '',
		guardian_name: form.elements.guardian_name?.value || '',
		guardian_relationship: form.elements.guardian_relationship?.value || '',
		guardian_email: form.elements.guardian_email?.value || '',
		guardian_phone: form.elements.guardian_phone?.value || '',
		membership_types: selectedTypes(),
		current_step: current,
	});

	const saveDraft = async () => {
		if (!window.smcPolicy.ajaxUrl || !window.smcPolicy.draftNonce || submitting) return;
		const body = new URLSearchParams({action: 'smc_save_application_draft', nonce: window.smcPolicy.draftNonce, draft: JSON.stringify(draftPayload())});
		try {
			const response = await fetch(window.smcPolicy.ajaxUrl, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'}, body});
			const result = await response.json();
			if (!response.ok || !result.success) throw new Error('draft');
			if (draftStatus) draftStatus.textContent = ` ${window.smcPolicy.messages?.draftSaved || 'Draft saved securely.'}`;
		} catch (error) {
			if (draftStatus) draftStatus.textContent = ` ${window.smcPolicy.messages?.draftFailed || 'Draft could not be saved.'}`;
		}
	};
	function scheduleDraftSave() { window.clearTimeout(saveTimer); saveTimer = window.setTimeout(saveDraft, 700); }

	const updateReview = () => {
		const summary = document.getElementById('smc-review-summary');
		if (!summary) return;
		const roles = selectedTypes().map((type) => form.querySelector(`[name="membership_types[]"][value="${CSS.escape(type)}"]`)?.parentElement?.textContent.trim()).filter(Boolean);
		summary.textContent = `Name: ${form.elements.legal_name?.value || '—'}; roles: ${roles.join(', ') || '—'}; residence: ${form.elements.city?.value || '—'}, ${form.elements.residence_country?.value || '—'}; issuing country: ${form.elements.issuing_country?.value || '—'}. Sensitive document numbers and files are intentionally not repeated here.`;
	};

	const prepareNativeSubmission = (event) => {
		updateReview();
		if (submitting || !form.checkValidity()) {
			event.preventDefault();
			form.reportValidity();
			return;
		}
		submitting = true;
		window.clearTimeout(saveTimer);
		form.setAttribute('aria-busy', 'true');
		if (previous) previous.disabled = true;
		if (next) next.disabled = true;
		if (retryButton) retryButton.disabled = true;
		if (uploadProgress) uploadProgress.removeAttribute('value');
		if (draftStatus) draftStatus.textContent = ` ${window.smcPolicy.messages?.uploading || 'Uploading authenticated evidence…'}`;
		// Allow the browser's native multipart/form-data submission. Shared-host
		// WAF/proxy stacks are more reliable here than XHR for private evidence.
	};

	previous?.addEventListener('click', () => showStep(current - 1));
	next?.addEventListener('click', () => { if (validateStep()) { if (current === steps.length - 1) updateReview(); showStep(current + 1); } });
	retryButton?.addEventListener('click', () => form.requestSubmit());
	form.addEventListener('input', () => { updateEligibility(); scheduleDraftSave(); });
	form.addEventListener('change', () => { updateEligibility(); scheduleDraftSave(); });
	form.addEventListener('submit', prepareNativeSubmission);
	window.addEventListener('online', () => { if (draftStatus) draftStatus.textContent = ' Connection restored; you may retry.'; });
	window.addEventListener('offline', () => { if (draftStatus) draftStatus.textContent = ' Offline. No sensitive data is stored in browser storage.'; });

	updateEligibility();
	showStep(current, false);
})();
