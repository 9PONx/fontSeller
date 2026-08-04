/**
 * FontSeller — font catalog + search + sample previews
 */
'use strict';

const Fonts = {
    catalog: null,       // rows from data/fonts.json
    stats: null,         // computed stats
    sampleFonts: null,   // loaded FontFace list
    loaded: false,

    async loadCatalog() {
        if (this.catalog) return this.catalog;
        const res = await fetch(CONFIG.fontsJsonUrl, { cache: 'no-cache' });
        if (!res.ok) throw new Error('โหลดแคตตาล็อกฟอนต์ไม่สำเร็จ');
        this.catalog = await res.json();
        this.computeStats();
        this.loaded = true;
        return this.catalog;
    },

    computeStats() {
        let totalFiles = 0, ttf = 0, otf = 0, ttc = 0, fon = 0, totalBytes = 0;
        const supportedNames = [];

        for (const row of this.catalog) {
            if (!row.is_active) continue;
            totalFiles++;
            const ext = String(row.extension || '').toLowerCase();
            if (ext === 'ttf') ttf++;
            else if (ext === 'otf') otf++;
            else if (ext === 'ttc') ttc++;
            else if (ext === 'fon') fon++;

            if (ext === 'ttf' || ext === 'otf') {
                totalBytes += Number(row.file_size || 0);
                const display = String(row.display_name || '').trim();
                supportedNames.push(display !== '' ? display : String(row.file_name || ''));
            }
        }

        supportedNames.sort(naturalCompare);

        this.stats = {
            totalFiles,
            ttf, otf, ttc, fon,
            packCount: supportedNames.length,
            totalSizeMB: totalBytes / 1048576,
            supportedNames,
        };
        return this.stats;
    },

    /** Mirrors PHP api_font_names(): active TTF/OTF, case-insensitive match on display+file, natural sort. */
    searchNames(query, limit = 500) {
        const needle = String(query || '').trim().toLowerCase();
        const names = [];
        for (const row of this.catalog) {
            if (!row.is_active) continue;
            const ext = String(row.extension || '').toLowerCase();
            if (ext !== 'ttf' && ext !== 'otf') continue;
            const display = String(row.display_name || '').trim();
            const file = String(row.file_name || '');
            if (needle !== '') {
                const hay = (display + '\n' + file).toLowerCase();
                if (!hay.includes(needle)) continue;
            }
            names.push(display !== '' ? display : file);
        }
        names.sort(naturalCompare);
        const total = names.length;
        const sliced = names.slice(0, limit);
        return { q: query, total, returned: sliced.length, truncated: total > sliced.length, names: sliced };
    },

    /* ---------------- Sample previews (canvas-free, FontFace) ---------------- */

    sampleFontDefs() {
        return [
            { name: 'Sarabun', family: 'Sarabun', files: ['fonts/Sarabun-Regular.ttf', 'fonts/Sarabun-Bold.ttf', 'fonts/Sarabun-Medium.ttf'], thai: true },
            { name: 'Prompt', family: 'Prompt', files: ['fonts/Prompt-Regular.ttf'], thai: true },
            { name: 'Kanit', family: 'Kanit', files: ['fonts/Kanit-Regular.ttf'], thai: true },
            { name: 'Mitr', family: 'Mitr', files: ['fonts/Mitr-Regular.ttf'], thai: true },
            { name: 'Noto Sans Thai', family: 'Noto Sans Thai', files: ['fonts/NotoSansThai-Variable.ttf'], thai: true },
            { name: 'IBM Plex Sans Thai', family: 'IBM Plex Sans Thai', files: ['fonts/IBMPlexSansThai-Regular.ttf'], thai: true },
            { name: 'Open Sans', family: 'Open Sans', files: ['fonts/OpenSans-Variable.ttf'], thai: false },
            { name: 'Playfair Display', family: 'Playfair Display', files: ['fonts/PlayfairDisplay-Variable.ttf'], thai: false },
            { name: 'Caveat', family: 'Caveat', files: ['fonts/Caveat-Variable.ttf'], thai: false },
        ];
    },

    async loadSampleFonts() {
        if (this.sampleFonts) return this.sampleFonts;
        const defs = this.sampleFontDefs();
        const loaded = [];
        for (const def of defs) {
            try {
                for (const file of def.files) {
                    const face = new FontFace(def.family, `url(${file})`);
                    await face.load();
                    document.fonts.add(face);
                }
                loaded.push({ ...def, ok: true });
            } catch (e) {
                loaded.push({ ...def, ok: false });
            }
        }
        this.sampleFonts = loaded;
        return loaded;
    },
};
