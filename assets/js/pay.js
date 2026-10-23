// assets/js/pay.js - sends the M-Pesa prompt, then waits for the result.
(function () {
    var form = document.getElementById('stk-form');
    if (!form) { return; }

    var btn = document.getElementById('pay-btn');
    var box = document.getElementById('status-box');
    var originalLabel = btn.innerHTML;
    var timer = null;
    var polls = 0;

    function show(kind, html) {
        var styles = {
            wait: 'bg-amber-50 text-amber-800 border border-amber-200',
            ok:   'bg-emerald-50 text-emerald-800 border border-emerald-200',
            err:  'bg-rose-50 text-rose-800 border border-rose-200'
        };
        box.className = 'rounded-xl p-4 text-sm ' + styles[kind];
        box.innerHTML = html;
    }
    function text(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function reset(label) {
        clearTimeout(timer);
        btn.disabled = false;
        btn.innerHTML = label || originalLabel;
    }

    function poll(id) {
        polls++;
        fetch(form.dataset.statusUrl + '?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.status === 'SUCCESS') {
                    show('ok', '<strong><i class="fas fa-circle-check"></i> Payment received!</strong><br>Receipt: <strong>' +
                        text(d.receipt_code || '') + '</strong><br>Opening your receipt...');
                    setTimeout(function () {
                        window.location.href = form.dataset.receiptUrl + '?code=' + encodeURIComponent(d.receipt_code);
                    }, 1500);
                } else if (d.status === 'PENDING') {
                    timer = setTimeout(function () { poll(id); }, 3000);
                } else if (d.status === 'DELAYED') {
                    show('wait', text(d.message));
                    timer = setTimeout(function () { poll(id); }, 6000);
                } else {
                    var why = {
                        CANCELLED: 'You cancelled the M-Pesa prompt.',
                        TIMEOUT: 'The prompt timed out before a PIN was entered.',
                        FAILED: 'The payment did not go through.'
                    }[d.status] || 'The payment could not be completed.';
                    show('err', '<i class="fas fa-circle-xmark"></i> ' + text(why) + ' ' + text(d.message || ''));
                    reset('Try again');
                }
            })
            .catch(function () {
                // Network blip: keep trying for a while before giving up.
                if (polls < 60) { timer = setTimeout(function () { poll(id); }, 4000); }
                else { show('err', 'Lost connection. Refresh this page to check your payment.'); reset(); }
            });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending prompt...';
        show('wait', '<i class="fas fa-mobile-retro"></i> Sending the prompt to your phone...');
        polls = 0;

        fetch(form.dataset.pushUrl, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { show('err', '<i class="fas fa-circle-xmark"></i> ' + text(d.message || 'Could not start the payment.')); reset('Try again'); return; }
                show('wait', '<i class="fas fa-mobile-retro"></i> ' + text(d.message) + ' Waiting for your confirmation...');
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Waiting for payment...';
                poll(d.request_id);
            })
            .catch(function () { show('err', 'Could not reach the server. Please try again.'); reset('Try again'); });
    });
})();
