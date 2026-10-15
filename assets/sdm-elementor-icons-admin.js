(function () {
    'use strict';

    const form = document.getElementById('spses-icon-form');
    if (!form) return;

    const data = window.SPSESSDMIconsData || {};
    const libraries = data.libraries || {};
    const rows = document.getElementById('spses-icon-rows');
    const rowCount = document.getElementById('spses-row-count');
    const template = document.getElementById('spses-icon-row-template');
    const dialog = document.getElementById('spses-icon-dialog');
    const librarySelect = document.getElementById('spses-icon-library');
    const search = document.getElementById('spses-icon-search');
    const grid = document.getElementById('spses-icon-grid');
    const status = document.getElementById('spses-icon-status');
    const more = document.getElementById('spses-icon-more');
    const cache = new Map();

    let activeRow = null;
    let previousFocus = null;
    let limit = 160;
    let generation = 0;
    let timer = null;

    function syncCount() {
        rowCount.value = String(rows.querySelectorAll('[data-icon-row]').length);
    }

    function setIcon(row, library, value) {
        row.querySelector('[data-icon-library]').value = library;
        row.querySelector('[data-icon-value]').value = value;

        const preview = row.querySelector('[data-icon-preview]');
        preview.replaceChildren();

        if (value) {
            const icon = document.createElement('i');
            icon.className = value;
            preview.appendChild(icon);
        }

        row.querySelector('[data-icon-name]').textContent = value || 'Sem ícone';
    }

    Object.keys(libraries).forEach(function (key) {
        const option = document.createElement('option');
        option.value = key;
        option.textContent = libraries[key].label || key;
        librarySelect.appendChild(option);
    });

    async function namesFor(key) {
        if (cache.has(key)) return cache.get(key);

        const config = libraries[key];
        if (!config || !config.fetchJson) {
            throw new Error('Biblioteca indisponível.');
        }

        const pending = fetch(config.fetchJson, {
            credentials: 'same-origin'
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Não foi possível carregar a biblioteca.');
            }
            return response.json();
        }).then(function (json) {
            const source = Array.isArray(json) ? json : json.icons;

            if (!Array.isArray(source)) {
                throw new Error('Formato de catálogo não suportado.');
            }

            return source.filter(function (name) {
                return typeof name === 'string'
                    && /^[A-Za-z0-9_-]+$/.test(name);
            });
        }).catch(function (error) {
            cache.delete(key);
            throw error;
        });

        cache.set(key, pending);
        return pending;
    }

    async function draw() {
        const token = ++generation;
        const key = librarySelect.value;
        const config = libraries[key];

        grid.replaceChildren();
        more.hidden = true;

        if (!config) {
            status.textContent = 'Nenhuma biblioteca disponível.';
            return;
        }

        status.textContent = 'Carregando ícones...';

        try {
            const names = await namesFor(key);
            if (token !== generation) return;

            const query = search.value.trim().toLowerCase();
            const filtered = names.filter(function (name) {
                return !query || name.toLowerCase().includes(query);
            });

            const fragment = document.createDocumentFragment();

            filtered.slice(0, limit).forEach(function (name) {
                const iconName = name.startsWith(config.prefix)
                    ? name : config.prefix + name;
                const value = config.displayPrefix + ' ' + iconName;

                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'spses-picker-icon';
                button.dataset.iconValue = value;
                button.dataset.iconLibrary = key;
                button.title = name;
                button.setAttribute('aria-label', 'Selecionar ' + name);

                const icon = document.createElement('i');
                icon.className = value;
                icon.setAttribute('aria-hidden', 'true');

                const label = document.createElement('span');
                label.textContent = name;

                button.append(icon, label);
                fragment.appendChild(button);
            });

            grid.appendChild(fragment);
            status.textContent = filtered.length
                ? 'Exibindo ' + Math.min(limit, filtered.length)
                    + ' de ' + filtered.length + ' ícones.'
                : 'Nenhum ícone encontrado.';

            more.hidden = filtered.length <= limit;
        } catch (error) {
            if (token === generation) {
                status.textContent = error.message
                    + ' Verifique se os arquivos do Elementor estão acessíveis.';
            }
        }
    }

    form.addEventListener('click', function (event) {
        const pick = event.target.closest('[data-pick-icon]');
        const clear = event.target.closest('[data-clear-icon]');
        const remove = event.target.closest('[data-remove-row]');

        if (remove) {
            remove.closest('tr').remove();
            syncCount();
            return;
        }

        if (clear) {
            setIcon(clear.closest('[data-icon-row]'), '', '');
            return;
        }

        if (!pick) return;

        if (typeof dialog.showModal !== 'function') {
            window.alert('Use um navegador atualizado para abrir o seletor.');
            return;
        }

        if (!Object.keys(libraries).length) {
            window.alert('Nenhuma biblioteca disponível. Confirme o Elementor.');
            return;
        }

        activeRow = pick.closest('[data-icon-row]');
        previousFocus = pick;

        const selected = activeRow.querySelector('[data-icon-library]').value;
        librarySelect.value = libraries[selected]
            ? selected : Object.keys(libraries)[0];

        search.value = '';
        limit = 160;
        dialog.showModal();
        search.focus();
        draw();
    });

    document.getElementById('spses-add-extension').addEventListener(
        'click',
        function () {
            const count = rows.querySelectorAll('[data-icon-row]').length;

            if (count >= Number(data.maxRows || 200)) {
                window.alert('Limite de extensões atingido.');
                return;
            }

            const index = Number(rows.dataset.nextIndex || 0);
            rows.dataset.nextIndex = String(index + 1);

            const fragment = template.content.cloneNode(true);

            fragment.querySelectorAll('[name]').forEach(function (input) {
                input.name = input.name.replace('__INDEX__', String(index));
            });

            const input = fragment.querySelector('input[type="text"]');
            rows.appendChild(fragment);
            syncCount();

            if (input) input.focus();
        }
    );

    dialog.querySelector('[data-close-picker]').addEventListener(
        'click',
        function () { dialog.close(); }
    );

    dialog.addEventListener('close', function () {
        generation += 1;
        activeRow = null;

        if (previousFocus && document.contains(previousFocus)) {
            previousFocus.focus();
        }
    });

    grid.addEventListener('click', function (event) {
        const button = event.target.closest('[data-icon-value]');

        if (!button || !activeRow) return;

        setIcon(
            activeRow,
            button.dataset.iconLibrary,
            button.dataset.iconValue
        );

        dialog.close();
    });

    librarySelect.addEventListener('change', function () {
        limit = 160;
        draw();
    });

    search.addEventListener('input', function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(function () {
            limit = 160;
            draw();
        }, 150);
    });

    more.addEventListener('click', function () {
        limit += 160;
        draw();
    });

    form.addEventListener('submit', syncCount);
    syncCount();
}());
