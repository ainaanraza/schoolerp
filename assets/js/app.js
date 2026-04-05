document.addEventListener('DOMContentLoaded', function () {
    // Universal Table Search — auto-injects a search bar above every .table-wrap
    document.querySelectorAll('.table-wrap').forEach(function(wrap) {
        var table = wrap.querySelector('table');
        if (!table) return;

        // Gather all data rows (skip thead rows)
        var thead = table.querySelector('thead');
        var allRows = table.querySelectorAll('tr');
        var dataRows = [];
        allRows.forEach(function(row) {
            // Skip rows inside thead
            if (thead && thead.contains(row)) return;
            dataRows.push(row);
        });

        // Skip tables with 0 or 1 data rows
        if (dataRows.length < 1) return;

        // Build search bar container
        var bar = document.createElement('div');
        bar.className = 'table-search-bar';

        var input = document.createElement('input');
        input.type = 'text';
        input.placeholder = '\uD83D\uDD0D Search this table...';
        input.className = 'table-search-input';

        var count = document.createElement('span');
        count.className = 'table-search-count';
        count.textContent = dataRows.length + ' records';

        bar.appendChild(input);
        bar.appendChild(count);

        // Insert BEFORE the table-wrap div
        wrap.parentNode.insertBefore(bar, wrap);

        // Filter on every keystroke
        input.addEventListener('input', function() {
            var terms = this.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
            var visible = 0;

            dataRows.forEach(function(row) {
                if (terms.length === 0) {
                    row.style.display = '';
                    visible++;
                    return;
                }

                var text = row.textContent.toLowerCase();
                var match = true;
                for (var i = 0; i < terms.length; i++) {
                    if (text.indexOf(terms[i]) === -1) {
                        match = false;
                        break;
                    }
                }

                row.style.display = match ? '' : 'none';
                if (match) visible++;
            });

            count.textContent = visible + ' of ' + dataRows.length + ' records';
        });
    });
});
