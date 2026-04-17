/**
 * fstv.rest Console Scraper v3
 * ─────────────────────────────
 * HOW TO USE:
 *   1. Open https://fstv.rest/ in your browser
 *   2. Open DevTools → Console  (F12)
 *   3. Paste this entire script and press Enter
 *   4. channels.json downloads automatically when done
 *
 * HANDLES TWO STREAM TYPES:
 *   • Type A: var su = "https://...something.m3u8"         → direct stream
 *   • Type B: var su = "https://vidl.top/ddl/resolve?..."  → resolves to m3u8 via network
 *
 * ── CONFIG ───────────────────────────────────────────────────────────────────
 */
const CFG = {
    START_ID: 1,
    END_ID: 600,  // raise if you want more IDs scanned
    CONCURRENCY: 8,    // parallel fetches — raise for speed, lower if errors appear
    DELAY_MS: 120,  // ms between launching each slot
    MAX_CONSEC_MISS: 40,   // stop early after N IDs with no channel in a row
};
// ─────────────────────────────────────────────────────────────────────────────

(async () => {
    const BASE = location.origin;
    const channels = [];
    let consecutiveMiss = 0;
    let stopped = false;

    // ── Helpers ──────────────────────────────────────────────────────────────────

    const decodeHtml = (str) => {
        const t = document.createElement('textarea');
        t.innerHTML = str;
        return t.value.replace(/\\"/g, '"').replace(/\\'/g, "'");
    };

    const parseName = (html) => {
        const m = html.match(/<title>([^»<\n]+)/u);
        return m ? m[1].trim() : 'Unknown';
    };

    const parseLogo = (html) => {
        const m = html.match(/<img\s[^>]*src="(\/chaineimg\/[^"]+)"/i);
        return m ? BASE + m[1] : null;
    };

    /** Pull the value of `var su` out of raw page HTML — works on all encoding variants */
    const extractSu = (html) => {
        const tries = [
            html,
            decodeHtml(html),
        ];
        const patterns = [
            /var\s+su\s*=\s*&quot;(https?:\/\/[^&]+)&quot;/i,
            /var\s+su\s*=\s*\\?"(https?:\/\/[^\\"]+)\\?"/i,
            /var\s+su\s*=\s*"(https?:\/\/[^"]+)"/i,
            /var\s+su\s*=\s*'(https?:\/\/[^']+)'/i,
        ];
        for (const src of tries) {
            for (const re of patterns) {
                const m = src.match(re);
                if (m) return m[1];
            }
        }
        return null;
    };

    /**
     * Type A → su is already an m3u8, return as-is.
     * Type B → su is a resolve URL; fetch it to get the real m3u8.
     */
    const resolveStream = async (su) => {
        if (su.includes('.m3u8')) return su;   // Type A — done

        // Type B — call the resolve endpoint
        try {
            const r = await fetch(su, {
                credentials: 'omit',
                headers: { Referer: BASE + '/' }
            });
            if (!r.ok) return null;

            const ct = r.headers.get('content-type') || '';

            if (ct.includes('json')) {
                const j = await r.json();
                // Common key names
                const direct = j.url || j.stream || j.src || j.m3u8 || j.hls || j.link;
                if (direct) return direct;
                // Fallback: scan all string values for an m3u8
                const hit = JSON.stringify(j).match(/"(https?:\/\/[^"]+\.m3u8[^"]*)"/);
                return hit ? hit[1] : null;
            }

            const text = (await r.text()).trim();
            if (text.startsWith('http')) return text;          // plain URL response
            const hit = text.match(/https?:\/\/[^\s"'<>]+\.m3u8[^\s"'<>]*/);
            return hit ? hit[0] : null;

        } catch {
            return null;
        }
    };

    /** Scrape one newsid. Returns a channel object or null. */
    const scrapeId = async (id) => {
        const pageUrl = `${BASE}/index.php?newsid=${id}`;
        let html;
        try {
            const r = await fetch(pageUrl, { credentials: 'include' });
            if (!r.ok) return null;
            html = await r.text();
        } catch {
            return null;
        }

        const su = extractSu(html);
        if (!su) return null;

        const m3u8 = await resolveStream(su);
        if (!m3u8) return null;

        return {
            id,
            name: parseName(html),
            m3u8,
            logo: parseLogo(html),
            page: pageUrl,
            resolveUrl: su !== m3u8 ? su : undefined,
        };
    };

    const download = (data, filename) => {
        const a = Object.assign(document.createElement('a'), {
            href: URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })),
            download: filename,
        });
        a.click();
    };

    // ── Parallel runner ───────────────────────────────────────────────────────────
    console.log('%cfstv.rest Scraper v3', 'font-weight:bold;font-size:15px;color:#e8751a');
    console.log(`IDs ${CFG.START_ID}–${CFG.END_ID}  |  concurrency: ${CFG.CONCURRENCY}  |  Type A = direct m3u8, Type B = resolve`);

    const ids = Array.from({ length: CFG.END_ID - CFG.START_ID + 1 }, (_, i) => i + CFG.START_ID);
    let pointer = 0;
    let active = 0;
    let done = 0;

    await new Promise(resolve => {
        const next = () => {
            if (stopped || pointer >= ids.length) {
                if (active === 0) resolve();
                return;
            }

            const id = ids[pointer++];
            active++;

            scrapeId(id).then(ch => {
                done++;
                active--;

                if (ch) {
                    consecutiveMiss = 0;
                    channels.push(ch);
                    const type = ch.resolveUrl ? 'B-resolved' : 'A-direct';
                    console.log(`%c✓ [${id}] ${ch.name} (${type})`, 'color:#22c55e;font-weight:bold', '\n  ', ch.m3u8);
                } else {
                    consecutiveMiss++;
                    if (done % 25 === 0)
                        console.log(`  … ${done}/${ids.length} scanned, ${channels.length} found, ${consecutiveMiss} consec. misses`);
                    if (consecutiveMiss >= CFG.MAX_CONSEC_MISS) {
                        console.warn(`⚠️  Stopped at ID ${id}: ${CFG.MAX_CONSEC_MISS} consecutive misses.`);
                        stopped = true;
                    }
                }

                if (active === 0 && (stopped || pointer >= ids.length)) resolve();
                else setTimeout(next, CFG.DELAY_MS);
            });

            if (active < CFG.CONCURRENCY && pointer < ids.length && !stopped)
                setTimeout(next, CFG.DELAY_MS);
        };

        for (let i = 0; i < Math.min(CFG.CONCURRENCY, ids.length); i++)
            setTimeout(next, i * CFG.DELAY_MS);
    });

    // ── Finish ────────────────────────────────────────────────────────────────────
    channels.sort((a, b) => a.id - b.id);
    console.log(`\n%c✅ Done — ${channels.length} channel(s) found`, 'font-weight:bold;font-size:14px');
    console.table(channels.map(c => ({ id: c.id, name: c.name, type: c.resolveUrl ? 'B-resolved' : 'A-direct', m3u8: c.m3u8 })));
    download(channels, 'channels.json');
    console.log('%c📥 channels.json downloaded', 'color:#e8751a;font-weight:bold');
})();