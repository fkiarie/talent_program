// assets/js/register_form.js - "Add another child" for the public registration form.
(function () {
    var addBtn = document.getElementById('add-child-btn');
    var box = document.getElementById('children-container');
    var tpl = document.getElementById('child-template');
    if (!addBtn || !box || !tpl) { return; }

    var max = parseInt(addBtn.dataset.max, 10) || 8;

    function addRemoveButton(row) {
        var rm = document.createElement('button');
        rm.type = 'button';
        rm.title = 'Remove this child';
        rm.className = 'absolute -top-3 -right-3 bg-rose-500 text-white rounded-full w-6 h-6 text-xs shadow hover:bg-rose-600';
        rm.textContent = '\u00d7';
        rm.addEventListener('click', function () { row.remove(); refresh(); });
        row.appendChild(rm);
    }

    function refresh() {
        addBtn.disabled = box.querySelectorAll('.child-row').length >= max;
        addBtn.classList.toggle('opacity-50', addBtn.disabled);
    }

    // Rows beyond the first (e.g. after a validation error) get a remove button too.
    box.querySelectorAll('.child-row').forEach(function (row, i) { if (i > 0) { addRemoveButton(row); } });

    addBtn.addEventListener('click', function () {
        if (box.querySelectorAll('.child-row').length >= max) { return; }
        var row = tpl.content.firstElementChild.cloneNode(true);
        addRemoveButton(row);
        box.appendChild(row);
        refresh();
    });
    refresh();
})();
