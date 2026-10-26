(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        // Instant filter of the rows on the current inventory page.
        var input = document.getElementById('d2w-search');
        if (input) {
            input.addEventListener('input', function () {
                var term = this.value.toLowerCase();
                document.querySelectorAll('#the-list tr').forEach(function (row) {
                    row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
                });
            });
        }
    });
}());
