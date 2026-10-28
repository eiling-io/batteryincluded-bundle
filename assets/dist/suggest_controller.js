import { Controller } from '@hotwired/stimulus';

/**
 * Suggestions of BatteryIncluded grouped by document type, e.g. products, content pages and blog posts.
 *
 * <form action="/search" data-controller="batteryincluded--batteryincluded-bundle--suggest"
 *       data-batteryincluded--batteryincluded-bundle--suggest-url-value="/batteryincluded/suggest">
 *     <input name="q" data-batteryincluded--batteryincluded-bundle--suggest-target="input">
 * </form>
 *
 * The endpoint (`@BatteryincludedBundle/config/routes/suggest.yaml`) delivers the html of the titles escaped, only
 * the <mark> tags of the highlighting are kept.
 *
 * Registered by Symfony UX as `batteryincluded--batteryincluded-bundle--suggest`.
 *
 * Events (prefix: the identifier, e.g. `batteryincluded--batteryincluded-bundle--suggest:`):
 * - results: before the suggestions are rendered, detail.result is the json, preventDefault() renders nothing
 * - select: before an item or completion is opened, detail.item, preventDefault() keeps the dropdown
 */
export default class extends Controller {
    static targets = ['input', 'results'];

    static values = {
        url: String,
        locale: { type: String, default: '' },
        minLength: { type: Number, default: 2 },
        delay: { type: Number, default: 150 },
        // group headings per type, e.g. { "PRODUCT": "Products", "BLOG": "Blog" }, the type is shown otherwise
        labels: { type: Object, default: {} },
        // currency of the prices, e.g. EUR, without it the prices are not shown
        currency: { type: String, default: '' },
        showAllLabel: { type: String, default: 'Show all %count% results' },
    };

    connect() {
        this.timeout = null;
        this.abortController = null;
        this.activeIndex = -1;
        this.listId = `bi-suggest-${Math.random().toString(36).slice(2, 10)}`;

        if (!this.hasResultsTarget) {
            const results = document.createElement('div');
            results.className = 'bi-suggest';
            results.setAttribute(`data-${this.identifier}-target`, 'results');
            this.inputTarget.insertAdjacentElement('afterend', results);
        }
        this.resultsTarget.id = this.listId;
        this.resultsTarget.setAttribute('role', 'listbox');
        this.resultsTarget.hidden = true;

        this.inputTarget.setAttribute('role', 'combobox');
        this.inputTarget.setAttribute('autocomplete', 'off');
        this.inputTarget.setAttribute('aria-autocomplete', 'list');
        this.inputTarget.setAttribute('aria-controls', this.listId);
        this.inputTarget.setAttribute('aria-expanded', 'false');

        this.onInput = this.onInput.bind(this);
        this.onKeydown = this.onKeydown.bind(this);
        this.onDocumentClick = this.onDocumentClick.bind(this);
        this.inputTarget.addEventListener('input', this.onInput);
        this.inputTarget.addEventListener('keydown', this.onKeydown);
        this.inputTarget.addEventListener('focus', this.onInput);
        document.addEventListener('click', this.onDocumentClick);
    }

    disconnect() {
        clearTimeout(this.timeout);
        this.abortController?.abort();
        this.inputTarget.removeEventListener('input', this.onInput);
        this.inputTarget.removeEventListener('keydown', this.onKeydown);
        this.inputTarget.removeEventListener('focus', this.onInput);
        document.removeEventListener('click', this.onDocumentClick);
    }

    onInput() {
        clearTimeout(this.timeout);
        const query = this.inputTarget.value.trim();
        if (query.length < this.minLengthValue) {
            this.abortController?.abort();
            this.close();

            return;
        }
        this.timeout = setTimeout(() => this.load(query), this.delayValue);
    }

    async load(query) {
        this.abortController?.abort();
        this.abortController = new AbortController();

        const url = new URL(this.urlValue, window.location.href);
        url.searchParams.set('q', query);
        if (this.localeValue) {
            url.searchParams.set('locale', this.localeValue);
        }

        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: this.abortController.signal });
            if (!response.ok) {
                this.close();

                return;
            }
            this.render(await response.json());
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.close();
            }
        }
    }

    render(result) {
        if (!this.dispatch('results', { detail: { result }, cancelable: true }).defaultPrevented) {
            this.resultsTarget.replaceChildren(...this.renderResult(result));
        }
        this.activeIndex = -1;
        this.inputTarget.removeAttribute('aria-activedescendant');

        const isEmpty = this.options().length === 0;
        this.resultsTarget.hidden = isEmpty;
        this.inputTarget.setAttribute('aria-expanded', isEmpty ? 'false' : 'true');
    }

    renderResult(result) {
        const nodes = [];
        const completions = result.completions || [];
        if (completions.length > 0) {
            const list = this.element.ownerDocument.createElement('div');
            list.className = 'bi-suggest-completions';
            completions.forEach((completion) => {
                const option = this.option('button', 'bi-suggest-completion');
                option.type = 'button';
                option.innerHTML = completion.html;
                option.addEventListener('click', () => this.select({ type: 'completion', value: completion.value }));
                list.append(option);
            });
            nodes.push(list);
        }

        (result.groups || []).forEach((group) => {
            const section = this.element.ownerDocument.createElement('div');
            section.className = 'bi-suggest-group';
            section.dataset.type = group.type;
            section.setAttribute('role', 'group');

            const heading = this.element.ownerDocument.createElement('div');
            heading.className = 'bi-suggest-heading';
            heading.textContent = this.labelsValue[group.type] || (group.label !== group.type ? group.label : group.type);
            section.setAttribute('aria-label', heading.textContent);
            section.append(heading);

            group.items.forEach((item) => section.append(this.renderItem(item)));
            nodes.push(section);
        });

        if ((result.found || 0) > 0 && this.inputTarget.form) {
            const all = this.option('button', 'bi-suggest-all');
            all.type = 'button';
            all.textContent = this.showAllLabelValue.replace('%count%', String(result.found));
            all.addEventListener('click', () => this.select({ type: 'search', value: result.query }));
            nodes.push(all);
        }

        return nodes;
    }

    renderItem(item) {
        const option = this.option(item.url ? 'a' : 'button', 'bi-suggest-item');
        if (item.url) {
            option.href = item.url;
        } else {
            option.type = 'button';
        }
        option.dataset.type = item.type;
        option.addEventListener('click', (event) => {
            if (!this.select({ type: 'item', item, value: item.title })) {
                event.preventDefault();
            }
        });

        const media = this.element.ownerDocument.createElement('span');
        media.className = 'bi-suggest-media';
        if (item.image) {
            const image = this.element.ownerDocument.createElement('img');
            image.src = item.image;
            image.alt = '';
            image.loading = 'lazy';
            media.append(image);
        }

        const body = this.element.ownerDocument.createElement('span');
        body.className = 'bi-suggest-body';
        const title = this.element.ownerDocument.createElement('span');
        title.className = 'bi-suggest-title';
        title.innerHTML = item.html;
        body.append(title);
        if (item.text) {
            const text = this.element.ownerDocument.createElement('span');
            text.className = 'bi-suggest-text';
            text.innerHTML = item.text;
            body.append(text);
        }

        option.append(media, body);
        if (item.price !== null && item.price !== undefined && this.currencyValue) {
            const price = this.element.ownerDocument.createElement('span');
            price.className = 'bi-suggest-price';
            price.textContent = new Intl.NumberFormat(this.localeValue || document.documentElement.lang || undefined, { style: 'currency', currency: this.currencyValue }).format(item.price);
            option.append(price);
        }

        return option;
    }

    option(tag, className) {
        const option = this.element.ownerDocument.createElement(tag);
        option.className = className;
        option.setAttribute('role', 'option');
        option.tabIndex = -1;

        return option;
    }

    options() {
        return Array.from(this.resultsTarget.querySelectorAll('[role="option"]'));
    }

    /**
     * Completions and items without url search for their value, items with url are opened by the link.
     * Returns false if a listener prevented it.
     */
    select(selection) {
        if (this.dispatch('select', { detail: selection, cancelable: true }).defaultPrevented) {
            return false;
        }
        if (selection.type !== 'item' || !selection.item.url) {
            this.inputTarget.value = selection.value;
            this.close();
            this.inputTarget.form?.requestSubmit();
        }

        return true;
    }

    onKeydown(event) {
        const options = this.options();
        if (this.resultsTarget.hidden || options.length === 0) {
            return;
        }

        switch (event.key) {
            case 'ArrowDown':
            case 'ArrowUp':
                event.preventDefault();
                this.activate(options, (this.activeIndex + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length);
                break;
            case 'Enter':
                if (this.activeIndex >= 0) {
                    event.preventDefault();
                    options[this.activeIndex].click();
                }
                break;
            case 'Escape':
                this.close();
                break;
        }
    }

    activate(options, index) {
        options.forEach((option, i) => option.classList.toggle('is-active', i === index));
        options[index].id ||= `${this.listId}-${index}`;
        this.inputTarget.setAttribute('aria-activedescendant', options[index].id);
        options[index].scrollIntoView({ block: 'nearest' });
        this.activeIndex = index;
    }

    onDocumentClick(event) {
        if (!this.element.contains(event.target)) {
            this.close();
        }
    }

    close() {
        this.resultsTarget.hidden = true;
        this.inputTarget.setAttribute('aria-expanded', 'false');
        this.inputTarget.removeAttribute('aria-activedescendant');
        this.activeIndex = -1;
    }
}
