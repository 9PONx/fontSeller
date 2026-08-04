/**
 * FontSeller — hash router (#/view?key=value)
 */
'use strict';

const Router = {
    parse() {
        const hash = location.hash.replace(/^#\/?/, ''); // strip #/
        const [path, query] = hash.split('?');
        const params = {};
        if (query) {
            for (const pair of query.split('&')) {
                const [k, v] = pair.split('=');
                if (k) params[decodeURIComponent(k)] = decodeURIComponent(v || '');
            }
        }
        return { view: path || 'home', params };
    },

    navigate(view, params = {}) {
        const qs = Object.entries(params)
            .map(([k, v]) => k + '=' + encodeURIComponent(v))
            .join('&');
        location.hash = '#/' + view + (qs ? '?' + qs : '');
    },

    init(handlers) {
        const render = () => {
            const { view, params } = this.parse();
            const fn = handlers[view] || handlers['404'];
            fn(params);
        };
        window.addEventListener('hashchange', render);
        if (!location.hash) {
            history.replaceState(null, '', '#/');
        }
        render();
    },
};
