'use strict';

(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
        return;
    }

    root.MetisNewsletterAdapter = factory();
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    const SUPPORTED = new Set([
        'header',
        'hero',
        'heading',
        'text',
        'button',
        'image',
        'video',
        'columns',
        'spacer',
        'footer',
        'social',
        'unsubscribe'
    ]);

    function normalizeType(type) {
        const normalized = String(type || '').trim().toLowerCase();
        return SUPPORTED.has(normalized) ? normalized : 'text';
    }

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function normalizeColumns(data) {
        const payload = data && typeof data === 'object' ? data : {};
        if (Array.isArray(payload.columns) && payload.columns.length) {
            return payload.columns.map(function (column, index) {
                return {
                    key: String(column && column.key || ('col_' + (index + 1))),
                    html: String(column && column.html || '')
                };
            });
        }

        const keys = ['left_html', 'right_html', 'col3_html', 'col4_html'];
        const columns = [];
        keys.forEach(function (key, index) {
            if (!payload[key]) return;
            columns.push({
                key: 'col_' + (index + 1),
                html: String(payload[key] || '')
            });
        });
        return columns;
    }

    function newsletterDocToMubeBlocks(doc) {
        const blocks = Array.isArray(doc && doc.blocks) ? doc.blocks : [];
        return blocks.map(function (block, index) {
            const data = block && typeof block === 'object' ? clone(block.data || {}) : {};
            const type = normalizeType(block && block.type);

            if (type === 'text' && !data.body && data.html) {
                data.body = String(data.html);
            }
            if (type === 'columns') {
                const columns = normalizeColumns(data);
                data.columns = columns;
                data.columns_count = columns.length || Math.max(2, Number(data.columns_count || 2));
                if (columns[0]) data.left_html = columns[0].html;
                if (columns[1]) data.right_html = columns[1].html;
                if (columns[2]) data.col3_html = columns[2].html;
                if (columns[3]) data.col4_html = columns[3].html;
            }

            return {
                id: String(block && block.id || ('block-' + (index + 1))),
                type: type,
                data: data,
                style: clone(block && block.style || {})
            };
        });
    }

    function mubeBlocksToNewsletterDoc(blocks) {
        const source = Array.isArray(blocks) ? blocks : [];
        return {
            version: 1,
            blocks: source.map(function (block, index) {
                const type = normalizeType(block && block.type);
                const data = clone(block && block.data || {});

                if (type === 'text' && !data.body && data.html) {
                    data.body = String(data.html);
                }
                if (type === 'columns') {
                    const columns = normalizeColumns(data);
                    data.columns = columns;
                    data.columns_count = columns.length || Math.max(2, Number(data.columns_count || 2));
                }

                return {
                    id: String(block && block.id || ('block-' + (index + 1))),
                    type: type,
                    data: data
                };
            })
        };
    }

    function isUniversalSafeNewsletterDoc(doc) {
        const blocks = Array.isArray(doc && doc.blocks) ? doc.blocks : [];
        return blocks.every(function (block) {
            return SUPPORTED.has(normalizeType(block && block.type));
        });
    }

    return {
        isUniversalSafeNewsletterDoc: isUniversalSafeNewsletterDoc,
        newsletterDocToMubeBlocks: newsletterDocToMubeBlocks,
        mubeBlocksToNewsletterDoc: mubeBlocksToNewsletterDoc
    };
});
