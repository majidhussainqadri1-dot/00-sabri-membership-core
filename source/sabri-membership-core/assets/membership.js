(() => {
	'use strict';
	const dob = document.querySelector('[name="date_of_birth"]');
	const gender = document.querySelector('[name="gender"]');
	const type = document.querySelector('[name="membership_type"]');
	if (!dob || !gender || !type || !window.smcPolicy) return;

	const status = document.createElement('p');
	status.setAttribute('role', 'status');
	status.setAttribute('aria-live', 'polite');
	dob.insertAdjacentElement('afterend', status);

	const update = () => {
		if (!dob.value || !gender.value) {
			status.textContent = '';
			return;
		}
		const birth = new Date(`${dob.value}T12:00:00`);
		if (Number.isNaN(birth.getTime())) return;
		const today = new Date();
		let age = today.getFullYear() - birth.getFullYear();
		const beforeBirthday = today.getMonth() < birth.getMonth()
			|| (today.getMonth() === birth.getMonth() && today.getDate() < birth.getDate());
		if (beforeBirthday) age -= 1;
		const minimum = gender.value === 'female'
			? Number(window.smcPolicy.femaleMinimumAge)
			: Number(window.smcPolicy.maleMinimumAge);
		if (age < minimum) {
			status.textContent = `The selected date is below the minimum age of ${minimum}.`;
		} else if (age < Number(window.smcPolicy.professionalAge)
			&& ['doctor', 'teacher', 'researcher', 'pharmacy', 'clinic', 'publisher'].includes(type.value)) {
			status.textContent = 'Professional accounts require age 18 or older.';
		} else if (age < Number(window.smcPolicy.guardianAge)) {
			status.textContent = 'Verified guardian consent is required.';
		} else {
			status.textContent = 'The selected age meets the membership age rule.';
		}
	};

	[dob, gender, type].forEach((field) => field.addEventListener('change', update));
})();
