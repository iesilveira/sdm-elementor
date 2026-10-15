/* Acordeão nativo, sem jQuery. Delegação atende também a renders novos do Elementor. */
(function () {
    'use strict';
    if (window.icdSdmAccordionLoaded) { return; }
    window.icdSdmAccordionLoaded = true;

    function summaryOf(details) {
        return Array.prototype.find.call(details.children, function (el) {
            return el.matches('summary.icd-heading');
        });
    }
    function sync(details) {
        var summary = summaryOf(details);
        if (summary) { summary.setAttribute('aria-expanded', details.open ? 'true' : 'false'); }
    }
    function closeSiblings(details) {
        var root = details.closest('.icd-central');
        if (!root || root.getAttribute('data-single') !== '1' || !details.open) { return; }
        Array.prototype.forEach.call(details.parentElement.children, function (sibling) {
            if (sibling !== details && sibling.matches('details.icd-category') && sibling.open) {
                sibling.open = false; sync(sibling);
            }
        });
    }
    document.addEventListener('click', function (event) {
        if (!(event.target instanceof Element)) { return; }
        var summary = event.target.closest('.icd-central summary.icd-heading');
        if (!summary || event.defaultPrevented) { return; }
        var details = summary.parentElement;
        if (!details.matches('details.icd-category')) { return; }
        // O summary nativo produz click com Enter/Espaço, sem emular teclas.
        event.preventDefault();
        details.open = !details.open;
        closeSiblings(details); sync(details);
    });
    document.addEventListener('toggle', function (event) {
        var details = event.target;
        if (details instanceof Element && details.matches('.icd-central details.icd-category')) {
            closeSiblings(details); sync(details);
        }
    }, true);
    // Navegação opcional entre cabeçalhos irmãos: setas, Home e End.
    document.addEventListener('keydown', function (event) {
        if (!(event.target instanceof Element) || !event.target.matches('.icd-central summary.icd-heading')) { return; }
        if (['ArrowDown', 'ArrowUp', 'Home', 'End'].indexOf(event.key) === -1) { return; }
        var list = event.target.parentElement.parentElement;
        var summaries = Array.prototype.filter.call(list.children, function (el) {
            return el.matches('details.icd-category');
        }).map(summaryOf).filter(Boolean);
        var index = summaries.indexOf(event.target);
        if (index < 0 || !summaries.length) { return; }
        event.preventDefault();
        var next = event.key === 'Home' ? 0 : event.key === 'End' ? summaries.length - 1 :
            (index + (event.key === 'ArrowDown' ? 1 : -1) + summaries.length) % summaries.length;
        summaries[next].focus();
    });
}());
