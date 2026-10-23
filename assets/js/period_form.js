// assets/js/period_form.js
// Used only on the "Billing periods" page. Two shortcut buttons fill in the end date
// (and a suggested label) so the admin does not have to work out dates by hand.
// The form works fine without this file: it is only a convenience.

(function () {
    const startInput = document.getElementById('start_date');
    const endInput   = document.getElementById('end_date');
    const labelInput = document.getElementById('label');
    if (!startInput || !endInput || !labelInput) return;

    const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const FULL_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
                         'August', 'September', 'October', 'November', 'December'];

    let suggested = '';   // the last label we filled in ourselves

    // "2026-12-05" -> {y: 2026, m: 11 (0 = January), d: 5}. We avoid new Date("2026-12-05")
    // because browsers treat that as UTC, which can shift the day in some time zones.
    function parse(value) {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
        return m ? { y: +m[1], m: +m[2] - 1, d: +m[3] } : null;
    }

    function format(date) {
        const pad = (n) => String(n).padStart(2, '0');
        return date.getUTCFullYear() + '-' + pad(date.getUTCMonth() + 1) + '-' + pad(date.getUTCDate());
    }

    // Only overwrite the label if it is empty or still our own suggestion.
    function suggestLabel(text) {
        if (labelInput.value.trim() === '' || labelInput.value === suggested) {
            labelInput.value = text;
            suggested = text;
        }
    }

    // Last day of the start date's month: day 0 of the NEXT month is the last day of this one.
    document.getElementById('btn-month-end').addEventListener('click', function () {
        const s = parse(startInput.value);
        if (!s) { startInput.focus(); return; }

        const last = new Date(Date.UTC(s.y, s.m + 1, 0));
        endInput.value = format(last);
        suggestLabel(FULL_MONTHS[s.m] + ' ' + s.y);
    });

    // 3 weeks = 21 days counting the first day, so the end date is start + 20 days.
    document.getElementById('btn-three-weeks').addEventListener('click', function () {
        const s = parse(startInput.value);
        if (!s) { startInput.focus(); return; }

        const end = new Date(Date.UTC(s.y, s.m, s.d + 20));
        endInput.value = format(end);
        suggestLabel(MONTHS[s.m] + ' ' + s.y + ' (3 wks)');
    });
})();
