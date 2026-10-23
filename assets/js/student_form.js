// assets/js/student_form.js
// Used only on the "Register student" page.
//
// When the staff member types the guardian's ID number, ask the server
// (guardian_lookup.php) whether that guardian already exists. If so, fill in
// their details and lock the name so it cannot be changed by accident.
//
// This is a convenience only. The server checks the guardian again when the
// form is submitted, so the form still works if JavaScript is switched off.

(function () {
    const form = document.getElementById('student-form');
    if (!form) return;

    const idInput    = document.getElementById('g_id_number');
    const nameInput  = document.getElementById('g_full_name');
    const phoneInput = document.getElementById('g_phone');
    const altInput   = document.getElementById('g_alt_phone');
    const notice     = document.getElementById('guardian-notice');
    const lookupUrl  = form.dataset.lookupUrl;

    let timer = null;          // used to wait until typing pauses
    let autoFilled = false;    // true while the fields hold an existing guardian's details
    let lastLookup = '';       // the last ID number we asked the server about

    // Same tidy-up the server does: no spaces, capital letters.
    function normalise(value) {
        return value.replace(/\s+/g, '').toUpperCase();
    }

    function showNotice(text, kind) {
        notice.textContent = text;     // textContent (not innerHTML) so names can never inject HTML
        notice.className = 'mt-2 rounded px-3 py-2 text-sm ' +
            (kind === 'found'
                ? 'bg-green-50 border border-green-200 text-green-800'
                : 'bg-slate-50 border border-slate-200 text-slate-600');
    }

    function hideNotice() {
        notice.className = 'hidden';
        notice.textContent = '';
    }

    function lockName(locked) {
        nameInput.readOnly = locked;
        nameInput.classList.toggle('bg-slate-100', locked);
    }

    // Undo an automatic fill when the ID number changes to one that is not on file.
    function clearAutoFill() {
        if (autoFilled) {
            nameInput.value = '';
            phoneInput.value = '';
            altInput.value = '';
            lockName(false);
            autoFilled = false;
        }
    }

    // fill = false is used on page load after a validation error,
    // so we do not overwrite phone numbers the user had just edited.
    async function lookup(fill) {
        const value = normalise(idInput.value);

        if (value.length < 4) {
            clearAutoFill();
            hideNotice();
            lastLookup = '';
            return;
        }
        if (value === lastLookup) return;
        lastLookup = value;

        try {
            const response = await fetch(lookupUrl + '?id_number=' + encodeURIComponent(value), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error('Lookup failed');
            const data = await response.json();

            // The user kept typing while we waited: ignore this answer.
            if (value !== normalise(idInput.value)) return;

            if (data.found) {
                nameInput.value = data.full_name;
                lockName(true);
                if (fill !== false) {
                    phoneInput.value = data.phone;
                    altInput.value = data.alt_phone;
                }
                autoFilled = true;

                const count = data.children === 1 ? '1 child' : data.children + ' children';
                showNotice('Existing guardian found: ' + data.full_name + ' (' + count +
                           ' already registered). Check the phone numbers and update them if they have changed.', 'found');
            } else {
                clearAutoFill();
                showNotice('New guardian: fill in their details below.', 'new');
            }
        } catch (err) {
            // Stay quiet: the server will still check when the form is submitted.
        }
    }

    idInput.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { lookup(true); }, 400);   // wait 0.4s after the last keystroke
    });
    idInput.addEventListener('blur', function () { lookup(true); });

    // If the page reloads with an ID already filled in (after an error), restore the notice and lock.
    if (idInput.value.trim() !== '') lookup(false);
})();
