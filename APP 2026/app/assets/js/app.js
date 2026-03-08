/**
 * BOUS'TACOM — Dashboard JavaScript
 */
const APP = {
    url: document.querySelector('meta[name="app-url"]')?.content || '',
    theme: localStorage.getItem('boustacom_theme') || 'dark',
    initTheme() { document.documentElement.setAttribute('data-theme', this.theme); },
    toggleTheme() {
        this.theme = this.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', this.theme);
        localStorage.setItem('boustacom_theme', this.theme);
    },
    async fetch(endpoint, options = {}) {
        try {
            const res = await fetch(this.url + endpoint, { ...options, headers: { 'X-CSRF-TOKEN': document.querySelector('[name="csrf_token"]')?.value || '', ...options.headers } });
            const text = await res.text();
            try { return JSON.parse(text); }
            catch (e) { console.error('API JSON parse error on', endpoint, '- Status:', res.status, '- Response:', text.substring(0, 300)); return { error: 'Réponse non-JSON (HTTP ' + res.status + ')' }; }
        } catch (e) { console.error('API Fetch error:', endpoint, e); return { error: e.message }; }
    },
    // ====================================================================
    // COUCHE DONNÉES PARTAGÉE SUIVI
    // ====================================================================
    suivi: {
        _locationId: null,
        _keywords: [],
        _stats: null,
        _location: null,
        async loadKeywords(locationId) {
            this._locationId = locationId;
            const data = await APP.fetch(`/api/keywords.php?location_id=${locationId}`);
            if (!data.error) {
                this._keywords = data.keywords || [];
                this._stats = data.stats || {};
                this._location = data.location || null;
            }
            return data;
        }
    },

    // ====================================================================
    // MODULE : CARTE DE POSITION (Grille — lecture cache DB uniquement)
    // Moteur : Mapbox GL JS v3 (theme dark-v11)
    // ====================================================================
    positionMap: {
        _locationId: null,
        _selectedKwId: null,
        _map: null,
        _markers: [],
        _currentScan: null,

        async load(locationId) {
            this._locationId = locationId;
            this._map = null; this._markers = [];
            const data = await APP.suivi.loadKeywords(locationId);
            if (data.error) { console.error('positionMap.load error:', data.error); this.render([], locationId); return; }
            this.render(APP.suivi._keywords, locationId);
            // Pre-selection depuis URL ?kw=XX
            const urlKw = new URLSearchParams(window.location.search).get('kw');
            const firstKw = urlKw ? APP.suivi._keywords.find(k => k.id == urlKw) : APP.suivi._keywords[0];
            if (firstKw) this.selectKeyword(firstKw.id);
        },

        render(keywords, locationId) {
            const c = document.getElementById('module-content'); if (!c) return;
            const lid = locationId;
            let h = `<div class="sh" style="justify-content:space-between;flex-wrap:wrap;gap:10px;"><div class="stit">CARTE DE POSITION</div><div style="display:flex;gap:10px;align-items:center;">`;
            if (keywords.length) {
                h += `<select class="si" id="map-kw-select" onchange="APP.positionMap.selectKeyword(parseInt(this.value))" style="max-width:260px;padding:6px 10px;font-size:13px;">`;
                for (const kw of keywords) h += `<option value="${kw.id}">${kw.keyword}${kw.target_city ? ' — ' + kw.target_city : ''}${kw.current_position ? ' (#'+kw.current_position+')' : ''}</option>`;
                h += `</select>`;
                h += `<button class="btn-capture" id="btn-export-story" onclick="APP.positionMap.exportCapture('story')" title="Export Story 9:16"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>Story 9:16</button>`;
                h += `<button class="btn-capture" id="btn-export-post" onclick="APP.positionMap.exportCapture('post')" title="Export Post 4:5"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>Post 4:5</button>`;
            }
            h += `</div></div>`;
            if (!keywords.length) {
                h += `<div style="padding:60px;text-align:center;color:var(--t2);"><div style="font-size:48px;opacity:.3;margin-bottom:16px;">🗺️</div><p>Aucun mot-clé. <a href="?view=client&location=${lid}&tab=keywords" style="color:var(--acc);">Ajoutez des mots-cles</a> pour voir la carte.</p></div>`;
            } else {
                h += `<div class="scan-history-bar" id="scan-history-bar" style="display:none;"></div>`;
                h += `<div style="position:relative;"><div id="biz-card-overlay" class="biz-card-map-overlay" style="display:none;"></div><div id="positions-map" style="width:100%;height:60vh;min-height:400px;"></div><div id="scan-timestamp-overlay" class="scan-timestamp" style="display:none;"></div></div>`;
                h += `<div style="padding:10px 20px;font-size:11px;color:var(--t3);border-top:1px solid var(--bdr);">Les grilles sont actualisées automatiquement chaque jour par le cron. Dernière donnée en cache affichée.</div>`;
                h += `<div id="competitors-below-map"></div>`;
            }
            c.innerHTML = h;
            if (keywords.length) this._initMap();
        },

        selectKeyword(kwId) {
            this._selectedKwId = kwId;
            const sel = document.getElementById('map-kw-select'); if (sel) sel.value = kwId;
            this._loadGridForKeyword(kwId);
        },

        async _loadGridForKeyword(kwId) {
            const lid = this._locationId;
            const data = await APP.fetch(`/api/grid.php?action=list&location_id=${lid}&keyword_id=${kwId}`);
            if (!data.success || !data.scans || !data.scans.length) { this._renderBizCard(null, kwId); this._renderMapEmpty(); this._renderScanHistory([]); this._renderCompetitors([]); return; }
            this._renderScanHistory(data.scans);
            this._loadScan(data.scans[0].id);
        },

        async _loadScan(scanId) {
            const lid = this._locationId;
            const data = await APP.fetch(`/api/grid.php?action=get&location_id=${lid}&scan_id=${scanId}`);
            if (!data.success) return;
            this._currentScan = data;
            this._renderBizCard(data, this._selectedKwId);
            this._renderMap(data.scan, data.points, data.center);
            this._renderCompetitors(data.competitors || []);
            const tsEl = document.getElementById('scan-timestamp-overlay');
            if (tsEl && data.scan.scanned_at) { tsEl.textContent = new Date(data.scan.scanned_at).toLocaleString('fr-FR'); tsEl.style.display = 'block'; }
            document.querySelectorAll('.scan-history-chip').forEach(el => el.classList.toggle('active', el.dataset.scanId == scanId));
        },

        _renderScanHistory(scans) {
            const bar = document.getElementById('scan-history-bar'); if (!bar) return;
            if (!scans || !scans.length) { bar.style.display = 'none'; return; }
            bar.style.display = 'flex';
            let h = '';
            for (let i = 0; i < scans.length; i++) {
                const s = scans[i], date = new Date(s.scanned_at).toLocaleDateString('fr-FR', {day:'numeric',month:'short'}), active = i === 0 ? ' active' : '', vis = s.visibility_score !== null ? s.visibility_score + '%' : '—';
                h += `<div class="scan-history-chip${active}" onclick="APP.positionMap._loadScan(${s.id})" data-scan-id="${s.id}">${date} · ${vis}</div>`;
            }
            bar.innerHTML = h;
        },

        _renderBizCard(data, kwId) {
            const el = document.getElementById('biz-card-overlay'); if (!el) return;
            const kw = APP.suivi._keywords.find(k => k.id == kwId);
            if (!kw) { el.style.display = 'none'; return; }
            const scan = data ? data.scan : null;

            // Position moyenne (sur toute la grille, 101 si pas trouve dans le top 100)
            const avg = scan ? (scan.avg_position ?? '—') : '—';
            const avgNum = parseFloat(avg);
            // Code couleur Localo : vert 1-3, orange 4-10, rose 11-20, rouge 20+
            const avgColor = isNaN(avgNum) ? 'var(--t3)' : avgNum <= 3 ? 'var(--g)' : avgNum <= 10 ? 'var(--o)' : avgNum <= 20 ? 'var(--p)' : 'var(--r)';

            // Rang = classement parmi les concurrents par position moyenne
            const rank = data ? (data.target_rank ?? '—') : '—';
            const totalComp = data ? (data.total_competitors ?? '—') : '—';
            const rankColor = rank === '—' ? 'var(--t3)' : rank <= 3 ? 'var(--g)' : rank <= 10 ? 'var(--o)' : rank <= 20 ? 'var(--p)' : 'var(--r)';

            // Visibilité
            const vis = scan ? (scan.visibility_score ?? '—') : '—';
            const visColor = vis === '—' ? 'var(--t3)' : vis >= 60 ? 'var(--g)' : vis >= 30 ? 'var(--o)' : vis >= 10 ? 'var(--p)' : 'var(--r)';

            // Trend
            let trendHtml = '';
            if (kw.trend !== null && kw.trend !== 0) trendHtml = kw.trend > 0 ? `<span style="color:var(--g);font-size:11px;">▲ +${kw.trend}</span>` : `<span style="color:var(--r);font-size:11px;">▼ ${kw.trend}</span>`;

            el.style.display = 'block';
            el.innerHTML = `<div class="biz-card-name">${kw.keyword} ${trendHtml}</div><div class="biz-card-stats"><div class="biz-card-stat"><div class="biz-card-stat-value" style="color:${avgColor}">${avg}</div><div class="biz-card-stat-label">Pos. moyenne</div></div><div class="biz-card-stat"><div class="biz-card-stat-value" style="color:${rankColor}">${rank}<span style="font-size:11px;color:var(--t3);font-weight:400;">/${totalComp}</span></div><div class="biz-card-stat-label">Rang</div></div><div class="biz-card-stat"><div class="biz-card-stat-value" style="color:${visColor}">${vis}${vis!=='—'?'%':''}</div><div class="biz-card-stat-label">Visibilité</div></div></div>`;
        },

        _renderCompetitors(competitors) {
            const zone = document.getElementById('competitors-below-map'); if (!zone) return;
            if (!competitors || !competitors.length) { zone.innerHTML = ''; return; }
            // Deja tries par rang cote serveur (moyenne stricte avec 101 pour absents)
            const top = competitors.slice(0, 15);
            let h = `<div style="padding:16px 20px;border-top:1px solid var(--bdr);"><div style="font-size:12px;font-weight:600;color:var(--t2);margin-bottom:10px;text-transform:uppercase;letter-spacing:.5px;">Classement concurrents</div><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:8px;">`;
            for (const c of top) {
                const isTarget = parseInt(c.is_target) === 1;
                const avg = parseFloat(c.avg_position) || 101;
                const rank = c.rank || '—';
                // Couleurs coherentes avec les marqueurs Mapbox
                // Code couleur Localo : vert 1-3, orange 4-10, rose 11-20, rouge 20+
                const avgColor = avg <= 3 ? 'rgba(34,197,94,.85)' : avg <= 10 ? 'rgba(245,158,11,.85)' : avg <= 20 ? 'rgba(236,72,153,.7)' : 'rgba(239,68,68,.85)';
                const rankColor = rank <= 3 ? 'var(--g)' : rank <= 10 ? 'var(--o)' : rank <= 20 ? 'var(--p)' : 'var(--r)';
                const rating = c.rating ? parseFloat(c.rating).toFixed(1) : '—';
                // Label dans le badge : position exacte 1-20, "20+" au-dela
                const avgLabel = avg <= 20 ? avg.toFixed(1) : '20+';
                const avgFontSize = avg <= 20 ? '11' : '9';
                const mapsUrl = c.place_id ? `https://www.google.com/maps/place/?q=place_id:${c.place_id}` : '#';
                h += `<a href="${mapsUrl}" target="_blank" style="display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:8px;background:${isTarget?'rgba(0,212,255,.08)':'var(--card)'};border:1px solid ${isTarget?'rgba(0,212,255,.2)':'var(--bdr)'};text-decoration:none;color:inherit;transition:border-color .2s;" onmouseover="this.style.borderColor='var(--acc)'" onmouseout="this.style.borderColor='${isTarget?'rgba(0,212,255,.2)':'var(--bdr)'}'">`
                h += `<div style="min-width:28px;text-align:center;font-size:14px;font-weight:700;color:${rankColor};font-family:'Space Mono',monospace;">#${rank}</div>`;
                h += `<div style="min-width:36px;height:36px;border-radius:50%;background:${avgColor};display:flex;align-items:center;justify-content:center;color:#fff;font-size:${avgFontSize}px;font-weight:700;font-family:'Space Mono',monospace;" title="Pos. moyenne: ${avg.toFixed(1)}">${avgLabel}</div>`;
                h += `<div style="flex:1;min-width:0;"><div style="font-size:13px;font-weight:${isTarget?'700':'500'};white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${isTarget?'⭐ ':''}${c.title}</div>`;
                h += `<div style="font-size:11px;color:var(--t3);">${rating !== '—' ? '★ ' + rating : ''} ${c.reviews_count ? '· ' + c.reviews_count + ' avis' : ''} · Vu ${c.appearances}/${this._currentScan?.scan?.total_points || '?'} pts</div></div></a>`;
            }
            h += `</div></div>`;
            zone.innerHTML = h;
        },

        // Mapbox GL JS — Initialisation
        _initMap() {
            this._loadMapbox(() => {
                const mapEl = document.getElementById('positions-map'); if (!mapEl || this._map) return;
                const token = document.querySelector('meta[name="mapbox-token"]')?.content;
                if (!token) { console.error('Mapbox token missing'); return; }
                mapboxgl.accessToken = token;
                this._map = new mapboxgl.Map({
                    container: mapEl,
                    style: 'mapbox://styles/mapbox/dark-v11',
                    center: [1.53, 45.16],
                    zoom: 9,
                    attributionControl: true
                });
                this._map.addControl(new mapboxgl.NavigationControl(), 'top-right');
            });
        },

        // Mapbox GL JS — Rendu de la grille
        _renderMap(scan, points, center) {
            if (!this._map) { this._loadMapbox(() => this._renderMap(scan, points, center)); return; }
            this._clearMapLayers();
            const map = this._map;
            const bounds = new mapboxgl.LngLatBounds();

            // Attendre que le style soit charge
            const renderLayers = () => {
                // Marker centre (business)
                const centerEl = document.createElement('div');
                centerEl.style.cssText = 'width:14px;height:14px;background:#EF4444;border:3px solid #fff;border-radius:50%;box-shadow:0 2px 8px rgba(0,0,0,.4);';
                const centerMarker = new mapboxgl.Marker({ element: centerEl }).setLngLat([center.lng, center.lat]).addTo(map);
                this._markers.push(centerMarker);
                bounds.extend([center.lng, center.lat]);

                // Points de grille
                for (const pt of points) {
                    const lat = parseFloat(pt.latitude || pt.lat), lng = parseFloat(pt.longitude || pt.lng);
                    if (!lat || !lng) continue;
                    if (parseInt(pt.is_center) === 1) continue;

                    bounds.extend([lng, lat]);
                    const pos = pt.position !== null && pt.position !== '' ? parseInt(pt.position) : null;
                    let bgColor, size, label, tooltipText;
                    // Code couleur Localo : vert 1-3, orange 4-10, rose 11-20, rouge 20+
                    if (pos === null) {
                        bgColor = 'rgba(107,114,128,.4)'; size = 26; label = '?'; tooltipText = 'Pas de donnée';
                    } else if (pos <= 3) {
                        bgColor = 'rgba(34,197,94,.85)'; size = 36; label = pos; tooltipText = `Position ${pos}`;
                    } else if (pos <= 10) {
                        bgColor = 'rgba(245,158,11,.85)'; size = 32; label = pos; tooltipText = `Position ${pos}`;
                    } else if (pos <= 20) {
                        bgColor = 'rgba(236,72,153,.7)'; size = 30; label = pos; tooltipText = `Position ${pos}`;
                    } else {
                        bgColor = 'rgba(239,68,68,.85)'; size = 28; label = '20+'; tooltipText = 'Position ' + pos + ' (hors top 20)';
                    }

                    const el = document.createElement('div');
                    el.style.cssText = `width:${size}px;height:${size}px;background:${bgColor};border:2px solid rgba(255,255,255,.5);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:${pos !== null && pos <= 20 ? '11' : '9'}px;font-weight:700;font-family:'Space Mono',monospace;box-shadow:0 2px 6px rgba(0,0,0,.25);cursor:pointer;`;
                    el.textContent = label;

                    const popup = new mapboxgl.Popup({ offset: 25, closeButton: false, closeOnClick: false }).setText(tooltipText);
                    const marker = new mapboxgl.Marker({ element: el }).setLngLat([lng, lat]).setPopup(popup).addTo(map);

                    // Hover pour afficher le popup
                    el.addEventListener('mouseenter', () => marker.togglePopup());
                    el.addEventListener('mouseleave', () => marker.togglePopup());
                    this._markers.push(marker);
                }

                // Ajuster la vue
                if (!bounds.isEmpty()) {
                    map.fitBounds(bounds, { padding: 50, maxZoom: 12 });
                } else {
                    map.flyTo({ center: [center.lng, center.lat], zoom: 11 });
                }
            };

            if (map.isStyleLoaded()) {
                renderLayers();
            } else {
                map.once('load', renderLayers);
            }
        },

        _renderMapEmpty() { this._clearMapLayers(); },

        _clearMapLayers() {
            // Supprimer les markers
            for (const m of this._markers) m.remove();
            this._markers = [];
            // Supprimer les layers et sources GeoJSON
            if (this._map) {
                // Pas de cercle de couverture — la grille 7×7 materialise la zone
            }
            const tsEl = document.getElementById('scan-timestamp-overlay'); if (tsEl) tsEl.style.display = 'none';
        },

        // Générer un GeoJSON cercle (64 segments)
        _createCircleGeoJSON(lng, lat, radiusKm) {
            const coords = [];
            const segments = 64;
            for (let i = 0; i <= segments; i++) {
                const angle = (i / segments) * 2 * Math.PI;
                const dLat = (radiusKm / 111.32) * Math.cos(angle);
                const dLng = (radiusKm / (111.32 * Math.cos(lat * Math.PI / 180))) * Math.sin(angle);
                coords.push([lng + dLng, lat + dLat]);
            }
            return { type: 'Feature', geometry: { type: 'Polygon', coordinates: [coords] } };
        },

        // ============================================================
        // MODE CAPTURE — Export premium Story (9:16) / Post (4:5)
        // Rendu 100% Canvas pour eviter les bugs html2canvas
        // ============================================================

        async exportCapture(format) {
            const scan = this._currentScan;
            if (!scan || !scan.scan) { APP.toast('Aucun scan disponible. Lancez un scan d\'abord.', 'error'); return; }
            document.querySelectorAll('.btn-capture').forEach(b => b.classList.add('exporting'));

            try {
                const kw = APP.suivi._keywords.find(k => k.id == this._selectedKwId);
                const kwText = kw ? kw.keyword : '';
                const rank = scan.target_rank ?? '—';
                const vis = parseInt(scan.scan.visibility_score) || 0;
                const totalComp = scan.total_competitors ?? '—';
                const top3Count = scan.scan.top3_count ?? 0;
                const totalPts = scan.scan.total_points ?? '?';
                const clientName = document.querySelector('.client-header-title')?.textContent?.trim()?.split('\n')[0]?.trim() || 'Mon Etablissement';

                const W = 1080;
                const H = format === 'story' ? 1920 : 1350;
                const canvas = document.createElement('canvas');
                canvas.width = W;
                canvas.height = H;
                const ctx = canvas.getContext('2d');

                // === FOND DEGRADE ===
                const grad = ctx.createLinearGradient(0, 0, 0, H);
                grad.addColorStop(0, '#000000');
                grad.addColorStop(1, '#020f27');
                ctx.fillStyle = grad;
                ctx.fillRect(0, 0, W, H);

                // === BORDURE CYAN ===
                ctx.strokeStyle = '#00d4ff';
                ctx.lineWidth = 4;
                ctx.shadowColor = 'rgba(0,212,255,.3)';
                ctx.shadowBlur = 30;
                ctx.strokeRect(2, 2, W - 4, H - 4);
                ctx.shadowBlur = 0;

                let y = format === 'story' ? 60 : 48;

                // === LOGO ===
                try {
                    const logoImg = await this._loadImageAsCanvas('https://boustacom.fr/images/logo-en-tete-footer.png');
                    const logoH = 60;
                    const logoW = logoImg.width * (logoH / logoImg.height);
                    ctx.drawImage(logoImg, (W - logoW) / 2, y, logoW, logoH);
                    y += logoH + 28;
                } catch (e) {
                    // Fallback texte si logo pas chargeable
                    ctx.font = '800 36px Inter, sans-serif';
                    ctx.fillStyle = '#00d4ff';
                    ctx.textAlign = 'center';
                    ctx.fillText("NEURA", W / 2, y + 36);
                    y += 64;
                }

                // === NOM DU CLIENT ===
                ctx.textAlign = 'center';
                ctx.font = '800 44px Inter, sans-serif';
                ctx.fillStyle = '#ffffff';
                ctx.fillText(clientName.toUpperCase(), W / 2, y + 40);
                y += 56;

                // === MOT-CLE ===
                ctx.font = '400 18px Inter, sans-serif';
                ctx.fillStyle = 'rgba(255,255,255,.45)';
                ctx.fillText(kwText, W / 2, y + 20);
                y += 44;

                // === SEPARATEUR ===
                ctx.strokeStyle = 'rgba(0,212,255,.15)';
                ctx.lineWidth = 1;
                ctx.beginPath(); ctx.moveTo(W * 0.2, y); ctx.lineTo(W * 0.8, y); ctx.stroke();
                y += 24;

                // === RANG ===
                ctx.font = '800 128px Inter, sans-serif';
                ctx.fillStyle = '#00d4ff';
                ctx.fillText('#' + rank, W / 2, y + 110);
                y += 130;

                ctx.font = '600 13px Inter, sans-serif';
                ctx.fillStyle = 'rgba(255,255,255,.4)';
                ctx.fillText('RANG CONCURRENTIEL LOCAL', W / 2, y + 14);
                y += 22;

                // Message explicatif rang
                ctx.font = '400 15px Inter, sans-serif';
                ctx.fillStyle = 'rgba(255,255,255,.3)';
                ctx.fillText('Classe #' + rank + ' sur ' + totalComp + ' etablissements de la zone', W / 2, y + 18);
                y += 40;

                // === JAUGE VISIBILITE ===
                const gaugeR = 70;
                const gaugeCx = W / 2;
                const gaugeCy = y + gaugeR + 10;
                const gaugeLineW = 12;

                // Fond gris
                ctx.beginPath();
                ctx.arc(gaugeCx, gaugeCy, gaugeR, 0, Math.PI * 2);
                ctx.strokeStyle = 'rgba(255,255,255,.08)';
                ctx.lineWidth = gaugeLineW;
                ctx.stroke();

                // Arc cyan
                if (vis > 0) {
                    ctx.beginPath();
                    const startAngle = -Math.PI / 2;
                    const endAngle = startAngle + (vis / 100) * Math.PI * 2;
                    ctx.arc(gaugeCx, gaugeCy, gaugeR, startAngle, endAngle);
                    ctx.strokeStyle = '#00d4ff';
                    ctx.lineWidth = gaugeLineW;
                    ctx.lineCap = 'round';
                    ctx.shadowColor = 'rgba(0,212,255,.4)';
                    ctx.shadowBlur = 15;
                    ctx.stroke();
                    ctx.shadowBlur = 0;
                    ctx.lineCap = 'butt';
                }

                // Pourcentage au centre
                ctx.font = '800 42px Inter, sans-serif';
                ctx.fillStyle = '#ffffff';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(vis + '%', gaugeCx, gaugeCy);
                ctx.textBaseline = 'alphabetic';

                y = gaugeCy + gaugeR + 20;

                // Label
                ctx.font = '600 13px Inter, sans-serif';
                ctx.fillStyle = 'rgba(255,255,255,.4)';
                ctx.fillText('PARTS DE VISIBILITE TOP 3', W / 2, y + 14);
                y += 22;

                // Message explicatif visibilité
                ctx.font = '400 15px Inter, sans-serif';
                ctx.fillStyle = 'rgba(255,255,255,.3)';
                ctx.fillText(top3Count + ' points sur ' + totalPts + ' dans le Top 3 Google Maps', W / 2, y + 18);
                y += 44;

                // === CARTE MAPBOX + MARQUEURS TOP 3 ===
                const mapTop = y;
                const mapH = H - y - 40;
                const mapX = 40;
                const mapW = W - 80;
                const mapRadius = 16;

                // Clip arrondi pour la carte
                ctx.save();
                ctx.beginPath();
                ctx.moveTo(mapX + mapRadius, mapTop);
                ctx.lineTo(mapX + mapW - mapRadius, mapTop);
                ctx.quadraticCurveTo(mapX + mapW, mapTop, mapX + mapW, mapTop + mapRadius);
                ctx.lineTo(mapX + mapW, mapTop + mapH - mapRadius);
                ctx.quadraticCurveTo(mapX + mapW, mapTop + mapH, mapX + mapW - mapRadius, mapTop + mapH);
                ctx.lineTo(mapX + mapRadius, mapTop + mapH);
                ctx.quadraticCurveTo(mapX, mapTop + mapH, mapX, mapTop + mapH - mapRadius);
                ctx.lineTo(mapX, mapTop + mapRadius);
                ctx.quadraticCurveTo(mapX, mapTop, mapX + mapRadius, mapTop);
                ctx.closePath();
                ctx.clip();

                // Dessiner le fond de carte Mapbox
                try {
                    const mapCanvas = this._map.getCanvas();
                    ctx.drawImage(mapCanvas, mapX, mapTop, mapW, mapH);
                } catch (e) {
                    ctx.fillStyle = '#0a1628';
                    ctx.fillRect(mapX, mapTop, mapW, mapH);
                }

                // Overlay sombre pour masquer les anciens marqueurs
                ctx.fillStyle = 'rgba(0,15,39,.45)';
                ctx.fillRect(mapX, mapTop, mapW, mapH);

                // Dessiner uniquement les marqueurs Top 3 avec glow
                const points = scan.points || [];
                const center = scan.center;
                if (points.length && this._map) {
                    const srcW = this._map.getCanvas().width;
                    const srcH = this._map.getCanvas().height;
                    const scaleX = mapW / srcW;
                    const scaleY = mapH / srcH;

                    for (const pt of points) {
                        const pos = pt.position !== null && pt.position !== '' ? parseInt(pt.position) : null;
                        if (pos === null || pos > 3) continue;

                        const lat = parseFloat(pt.latitude || pt.lat);
                        const lng = parseFloat(pt.longitude || pt.lng);
                        if (!lat || !lng) continue;

                        const pixel = this._map.project([lng, lat]);
                        const px = mapX + pixel.x * scaleX;
                        const py = mapTop + pixel.y * scaleY;

                        // Glow cyan
                        ctx.shadowColor = '#00d4ff';
                        ctx.shadowBlur = 25;

                        // Cercle vert
                        ctx.beginPath();
                        ctx.arc(px, py, 24, 0, Math.PI * 2);
                        ctx.fillStyle = 'rgba(34,197,94,.9)';
                        ctx.fill();

                        // Bordure
                        ctx.shadowBlur = 0;
                        ctx.strokeStyle = 'rgba(255,255,255,.8)';
                        ctx.lineWidth = 2.5;
                        ctx.stroke();

                        // Texte
                        ctx.fillStyle = '#fff';
                        ctx.font = 'bold 18px "Space Mono", monospace';
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'middle';
                        ctx.fillText(pos.toString(), px, py);
                        ctx.textBaseline = 'alphabetic';
                    }

                    // Point rouge centre business
                    if (center) {
                        const cp = this._map.project([center.lng, center.lat]);
                        const cpx = mapX + cp.x * scaleX;
                        const cpy = mapTop + cp.y * scaleY;
                        ctx.shadowColor = '#EF4444';
                        ctx.shadowBlur = 14;
                        ctx.beginPath();
                        ctx.arc(cpx, cpy, 9, 0, Math.PI * 2);
                        ctx.fillStyle = '#EF4444';
                        ctx.fill();
                        ctx.shadowBlur = 0;
                        ctx.strokeStyle = '#fff';
                        ctx.lineWidth = 3;
                        ctx.stroke();
                    }
                }

                ctx.restore(); // Fin clip arrondi

                // Bordure cyan autour de la carte
                ctx.beginPath();
                ctx.moveTo(mapX + mapRadius, mapTop);
                ctx.lineTo(mapX + mapW - mapRadius, mapTop);
                ctx.quadraticCurveTo(mapX + mapW, mapTop, mapX + mapW, mapTop + mapRadius);
                ctx.lineTo(mapX + mapW, mapTop + mapH - mapRadius);
                ctx.quadraticCurveTo(mapX + mapW, mapTop + mapH, mapX + mapW - mapRadius, mapTop + mapH);
                ctx.lineTo(mapX + mapRadius, mapTop + mapH);
                ctx.quadraticCurveTo(mapX, mapTop + mapH, mapX, mapTop + mapH - mapRadius);
                ctx.lineTo(mapX, mapTop + mapRadius);
                ctx.quadraticCurveTo(mapX, mapTop, mapX + mapRadius, mapTop);
                ctx.closePath();
                ctx.strokeStyle = 'rgba(0,212,255,.2)';
                ctx.lineWidth = 1.5;
                ctx.stroke();

                // === WATERMARK ===
                ctx.font = '400 11px Inter, sans-serif';
                ctx.fillStyle = 'rgba(255,255,255,.15)';
                ctx.textAlign = 'center';
                ctx.fillText('neura \u00b7 SEO Local by BOUS\'TACOM', W / 2, H - 14);

                // === TELECHARGER ===
                const link = document.createElement('a');
                const dateStr = new Date().toISOString().slice(0, 10);
                link.download = 'neura-' + format + '-' + kwText.replace(/[^a-z0-9]/gi, '-').toLowerCase() + '-' + dateStr + '.png';
                link.href = canvas.toDataURL('image/png');
                link.click();

            } catch (e) {
                console.error('[CAPTURE] Export error:', e);
                APP.toast('Erreur lors de l\'export: ' + e.message, 'error');
            }

            document.querySelectorAll('.btn-capture').forEach(b => b.classList.remove('exporting'));
        },

        _loadImageAsCanvas(url) {
            return new Promise((resolve, reject) => {
                const img = new Image();
                img.crossOrigin = 'anonymous';
                img.onload = () => resolve(img);
                img.onerror = () => {
                    // Fallback : essayer via fetch blob pour contourner CORS
                    fetch(url, { mode: 'cors' })
                        .then(r => r.blob())
                        .then(blob => {
                            const blobUrl = URL.createObjectURL(blob);
                            const img2 = new Image();
                            img2.onload = () => { URL.revokeObjectURL(blobUrl); resolve(img2); };
                            img2.onerror = () => { URL.revokeObjectURL(blobUrl); reject(new Error('Logo introuvable')); };
                            img2.src = blobUrl;
                        })
                        .catch(reject);
                };
                img.src = url;
            });
        },

        _escHtml(s) {
            const d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        },

        // Chargement dynamique de Mapbox GL JS v3
        _loadMapbox(callback) {
            if (window.mapboxgl) { callback(); return; }
            const css = document.createElement('link'); css.rel = 'stylesheet'; css.href = 'https://api.mapbox.com/mapbox-gl-js/v3.4.0/mapbox-gl.css'; document.head.appendChild(css);
            const js = document.createElement('script'); js.src = 'https://api.mapbox.com/mapbox-gl-js/v3.4.0/mapbox-gl.js'; js.onload = callback; document.head.appendChild(js);
        }
    },


    // ====================================================================
    // MODULE : MOTS-CLES (Tableau simplifie — Position, Variation, Visibilité, MAJ)
    // ====================================================================
    keywords: {
        _locationId: null,

        async load(locationId) {
            this._locationId = locationId;
            // Show skeleton while loading
            const c = document.getElementById('module-content');
            if (c) c.innerHTML = `<div class="sh"><div class="stit">MOTS-CLES</div></div>${APP.skeleton.stats(4)}${APP.skeleton.table(6, 5)}`;
            const data = await APP.suivi.loadKeywords(locationId);
            if (data.error) { console.error('keywords.load error:', data.error); this.render([], {}, locationId); return; }
            this.render(APP.suivi._keywords, APP.suivi._stats, locationId);
        },

        render(keywords, stats, locationId) {
            var c = document.getElementById('module-content'); if (!c) return;
            var lid = locationId;

            // Header
            var h = '<div class="sh" style="justify-content:space-between;align-items:center;gap:10px;">';
            h += '<div class="stit">MOTS-CLES</div>';
            h += '<button class="btn bp bsm" onclick="APP.keywords._toggleAddForm(' + lid + ')" style="gap:6px;"><svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M12 4v16m8-8H4" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round"/></svg> Ajouter</button>';
            h += '</div>';

            // Add keyword form (hidden)
            var defaultCity = (APP.suivi._location && APP.suivi._location.city) ? APP.suivi._location.city : '';
            h += '<div id="add-kw-form" style="display:none;">';
            h += '<div class="kw-add-inline">';
            h += '<input type="text" id="new-kw" placeholder="Service / M\u00e9tier (ex: Huissier)" onkeydown="if(event.key===\'Enter\')document.getElementById(\'new-kw-city\').focus()" style="flex:2">';
            h += '<div style="flex:1;position:relative;" id="city-ac-wrap">';
            h += '<input type="text" id="new-kw-city" placeholder="Ville cible (ex: Tulle)" value="' + (defaultCity ? defaultCity.replace(/"/g, '&quot;') : '') + '" autocomplete="off" onkeydown="if(event.key===\'Enter\'){event.preventDefault();APP.keywords.add(' + lid + ')}" oninput="APP.keywords._cityAutocomplete(this.value)" onblur="setTimeout(function(){var l=document.getElementById(\'city-ac-list\');if(l)l.style.display=\'none\';},200)">';
            h += '<div id="city-ac-list" class="ac-dropdown" style="display:none;"></div>';
            h += '</div>';
            // Grille sunburst 49 pts fixe — plus de select rayon
            h += '<button class="btn bp bsm" onclick="APP.keywords.add(' + lid + ')">Ajouter</button>';
            h += '<button class="btn bs bsm" onclick="document.getElementById(\'add-kw-form\').style.display=\'none\'" style="padding:6px 10px;">Annuler</button>';
            h += '</div></div>';

            // Error zone
            h += '<div id="api-error-zone"></div>';

            if (!keywords.length) {
                h += '<div class="kw-empty"><div class="kw-empty-icon">\uD83D\uDD0D</div>';
                h += '<p style="font-size:15px;margin:0 0 6px;">Aucun mot-clé</p>';
                h += '<p style="font-size:13px;color:var(--t3);margin:0;">Ajoutez vos mots-cles pour suivre vos positions sur Google Maps.</p></div>';
            } else {
                // Stats summary bar
                var top3 = keywords.filter(function(k) { return k.current_position !== null && k.current_position <= 3; }).length;
                var top10 = keywords.filter(function(k) { return k.current_position !== null && k.current_position <= 10; }).length;
                // Position moyenne grille (inclut les 101 pour non-trouve)
                var withAvgPos = keywords.filter(function(k) { return k.grid_avg_position !== null && k.grid_avg_position !== undefined; });
                var avgPos = withAvgPos.length ? (withAvgPos.reduce(function(a,k) { return a + parseFloat(k.grid_avg_position); }, 0) / withAvgPos.length).toFixed(1) : null;
                var avgPosColor = !avgPos ? 'var(--t3)' : avgPos <= 5 ? 'var(--g)' : avgPos <= 10 ? 'var(--o)' : 'var(--r)';
                // Visibilité moyenne (Top3/Total x 100 — calcule par le backend)
                var withVis = keywords.filter(function(k) { return k.visibility_score !== null && k.visibility_score !== undefined; });
                var avgVis = withVis.length ? Math.round(withVis.reduce(function(a,k) { return a + parseFloat(k.visibility_score); }, 0) / withVis.length) : null;

                h += '<div class="kw-stats-bar">';
                h += '<div class="kw-stat"><span class="kw-stat-v">' + keywords.length + '</span><span class="kw-stat-l">Mots-cles</span></div>';
                h += '<div class="kw-stat"><span class="kw-stat-v" style="color:var(--g);">' + top3 + '</span><span class="kw-stat-l">Top 3</span></div>';
                h += '<div class="kw-stat"><span class="kw-stat-v" style="color:var(--o);">' + top10 + '</span><span class="kw-stat-l">Top 10</span></div>';
                h += '<div class="kw-stat"><span class="kw-stat-v" style="color:' + avgPosColor + ';">' + (avgPos ? avgPos : '\u2014') + '</span><span class="kw-stat-l">Pos. moyenne</span></div>';
                h += '<div class="kw-stat"><span class="kw-stat-v" style="color:' + (!avgVis ? 'var(--t3)' : avgVis >= 60 ? 'var(--g)' : avgVis >= 30 ? 'var(--o)' : 'var(--r)') + ';">' + (avgVis !== null ? avgVis + '%' : '\u2014') + '</span><span class="kw-stat-l">Visibilité</span></div>';
                h += '</div>';

                // Keyword cards
                for (var ki = 0; ki < keywords.length; ki++) {
                    var kw = keywords[ki];
                    var rank = kw.grid_rank;

                    // Position badge
                    var posCls = 'none';
                    var posText = '\u2014';
                    if (rank !== null && rank !== undefined) {
                        posText = '#' + rank;
                        // Code couleur Localo : vert 1-3, orange 4-10, rose 11-20, rouge 20+
                        if (rank <= 3) posCls = 'top3';
                        else if (rank <= 10) posCls = 'top10';
                        else if (rank <= 20) posCls = 'top20';
                        else posCls = 'out';
                    }

                    // Trend
                    var trendHtml = '<span class="kw-trend-neutral">\u2014</span>';
                    if (kw.trend !== null && kw.trend !== undefined && kw.trend !== 0) {
                        if (kw.trend > 0) trendHtml = '<span class="kw-trend-up">\u25B2 +' + kw.trend + '</span>';
                        else trendHtml = '<span class="kw-trend-down">\u25BC ' + kw.trend + '</span>';
                    }

                    // Grid avg position
                    var gridAvg = kw.grid_avg_position;
                    var avgHtml = '<span style="color:var(--t3);">\u2014</span>';
                    if (gridAvg !== null && gridAvg !== undefined) {
                        var ga = parseFloat(gridAvg);
                        // Code couleur Localo : vert 1-3, orange 4-10, rose 11-20, rouge 20+
                        var gaColor = ga <= 3 ? 'var(--g)' : ga <= 10 ? 'var(--o)' : ga <= 20 ? 'var(--p)' : 'var(--r)';
                        avgHtml = '<span style="color:' + gaColor + ';font-weight:700;">' + ga.toFixed(1) + '</span>';
                    }

                    // Visibility
                    var vis = kw.visibility_score;
                    var visVal = vis !== null && vis !== undefined ? parseInt(vis) : null;
                    var visColor = visVal === null ? 'var(--t3)' : visVal >= 60 ? 'var(--g)' : visVal >= 30 ? 'var(--o)' : visVal >= 10 ? 'var(--p)' : 'var(--r)';
                    var visHtml = '';
                    if (visVal !== null) {
                        visHtml = '<div class="kw-vis-bar"><div class="kw-vis-fill" style="width:' + visVal + '%;background:' + visColor + ';"></div></div>';
                        visHtml += '<span style="color:' + visColor + ';font-weight:700;font-size:13px;font-family:\'Space Mono\',monospace;">' + visVal + '%</span>';
                    } else {
                        visHtml = '<span style="color:var(--t3);">\u2014</span>';
                    }

                    // Last update — date+heure precise
                    var scanDate = kw.grid_scanned_at || kw.last_tracked;
                    var lastMaj = 'Jamais';
                    if (scanDate) {
                        var d = new Date(scanDate);
                        lastMaj = d.toLocaleDateString('fr-FR', {day:'numeric', month:'short'}) + ' ' + d.toLocaleTimeString('fr-FR', {hour:'2-digit', minute:'2-digit'});
                    }

                    h += '<div class="kw-card" id="kw-row-' + kw.id + '">';

                    // Position badge
                    h += '<span class="kw-pos-badge ' + posCls + '" id="kw-pos-' + kw.id + '">' + posText + '</span>';

                    // Name + meta
                    h += '<div class="kw-card-name" style="cursor:pointer;" onclick="APP.keywords._goToMap(' + kw.id + ',' + lid + ')" title="Voir sur la carte">';
                    h += '<h4>' + kw.keyword + (kw.target_city ? ' <span class="kw-city-badge">' + kw.target_city + '</span>' : '') + '</h4>';
                    h += '<div class="kw-meta">' + trendHtml + '<span style="color:var(--bdr);">\u00B7</span><span>MAJ ' + lastMaj + '</span></div>';
                    h += '</div>';

                    // Metrics
                    h += '<div class="kw-card-metrics">';
                    h += '<div class="kw-metric"><span class="kw-metric-val">' + avgHtml + '</span><span class="kw-metric-lbl">Pos. moy.</span></div>';
                    h += '<div class="kw-metric"><span class="kw-metric-val" style="font-size:13px;">' + visHtml + '</span><span class="kw-metric-lbl">Visibilité</span></div>';
                    h += '</div>';

                    // Actions — scan button avec rate-limit
                    var canScan = kw.can_manual_scan !== false;
                    var scanTitle = 'Mettre \u00e0 jour';
                    if (!canScan && kw.next_manual_scan_at) {
                        var nextD = new Date(kw.next_manual_scan_at);
                        scanTitle = 'Prochain scan : ' + nextD.toLocaleDateString('fr-FR', {day:'numeric', month:'short'}) + ' ' + nextD.toLocaleTimeString('fr-FR', {hour:'2-digit', minute:'2-digit'});
                    }
                    h += '<div class="kw-card-actions">';
                    h += '<button class="kw-btn-update' + (!canScan ? ' rate-limited' : '') + '" id="btn-scan-' + kw.id + '"' + (canScan ? ' onclick="event.stopPropagation();APP.keywords.scanKeyword(' + kw.id + ',' + lid + ')"' : ' disabled') + ' title="' + scanTitle + '"><svg viewBox="0 0 24 24" style="width:16px;height:16px;"><path d="M4 12a8 8 0 0 1 14.93-4M20 12a8 8 0 0 1-14.93 4" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"/><path d="M20 4v4h-4M4 20v-4h4" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></button>';
                    h += '<button class="kw-btn-delete" onclick="event.stopPropagation();APP.keywords.remove(' + kw.id + ',' + lid + ')" title="Supprimer"><svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"/></svg></button>';
                    h += '</div>';

                    h += '</div>';
                }
            }

            c.innerHTML = h;
        },

        _toggleAddForm(lid) {
            var f = document.getElementById('add-kw-form');
            if (!f) return;
            if (f.style.display === 'none') {
                f.style.display = 'block';
                var inp = document.getElementById('new-kw');
                if (inp) inp.focus();
            } else {
                f.style.display = 'none';
            }
        },

        // --- Autocomplete ville via DataForSEO Locations ---
        _acTimer: null,
        _acAbort: null,

        _cityAutocomplete(query) {
            var list = document.getElementById('city-ac-list');
            if (!list) return;
            // Debounce 250ms, min 2 caracteres
            clearTimeout(this._acTimer);
            if (this._acAbort) { this._acAbort.abort(); this._acAbort = null; }
            if (query.length < 2) { list.style.display = 'none'; return; }
            this._acTimer = setTimeout(async () => {
                try {
                    this._acAbort = new AbortController();
                    var resp = await fetch(APP.url + '/api/dataforseo-locations.php?q=' + encodeURIComponent(query), { signal: this._acAbort.signal });
                    var data = await resp.json();
                    this._acAbort = null;
                    if (!data.results || !data.results.length) { list.style.display = 'none'; return; }
                    var html = '';
                    for (var i = 0; i < data.results.length; i++) {
                        var r = data.results[i];
                        var city = r.city || r.name;
                        var dept = r.department || '';
                        var cp = r.postal || '';
                        var pop = r.population || 0;
                        var label = r.name; // "Malemort, Corrèze"
                        // Mettre en gras la partie matchee
                        var idx = city.toLowerCase().indexOf(query.toLowerCase());
                        var displayCity = idx >= 0 ? city.substring(0, idx) + '<b>' + city.substring(idx, idx + query.length) + '</b>' + city.substring(idx + query.length) : city;
                        var popStr = pop > 0 ? (pop > 1000 ? Math.round(pop/1000) + 'k hab.' : pop + ' hab.') : '';
                        html += '<div class="ac-item" onmousedown="APP.keywords._selectCity(\'' + label.replace(/'/g, "\\'") + '\')">';
                        html += '<div><span class="ac-city">' + displayCity + '</span>';
                        if (dept) html += '<span class="ac-dept">' + dept + (cp ? ' (' + cp + ')' : '') + '</span>';
                        html += '</div>';
                        if (popStr) html += '<span class="ac-type">' + popStr + '</span>';
                        html += '</div>';
                    }
                    list.innerHTML = html;
                    list.style.display = 'block';
                } catch (e) {
                    if (e.name !== 'AbortError') console.error('city autocomplete:', e);
                }
            }, 250);
        },

        _selectCity(name) {
            var input = document.getElementById('new-kw-city');
            var list = document.getElementById('city-ac-list');
            if (input) input.value = name;
            if (list) list.style.display = 'none';
        },

        _goToMap(kwId, lid) {
            const url = new URL(window.location);
            url.searchParams.set('tab', 'position-map');
            url.searchParams.set('kw', kwId);
            window.history.pushState(null, '', url);
            APP.positionMap.load(lid);
            document.querySelectorAll('.sidebar-subnav a').forEach(a => {
                a.classList.toggle('active', a.getAttribute('data-tab') === 'position-map');
            });
        },

        _showApiError(msg, type) {
            const zone = document.getElementById('api-error-zone'); if (!zone) return;
            const isCredits = type === 'api_credits';
            zone.innerHTML = `<div style="padding:14px 20px;background:rgba(255,59,48,.1);border:1px solid rgba(255,59,48,.25);margin:0;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--bdr);"><div style="font-size:20px;">${isCredits?'💳':'⚠️'}</div><div style="flex:1;"><div style="font-weight:600;color:#ff3b30;font-size:13px;">${isCredits?'Credits API epuises':'Erreur API'}</div><div style="font-size:12px;color:var(--t2);margin-top:2px;">${msg}</div>${isCredits?'<div style="font-size:11px;color:var(--t3);margin-top:4px;">Verifiez vos credits sur <a href="https://app.dataforseo.com/" target="_blank" style="color:var(--acc);">DataForSEO</a></div>':''}</div><button onclick="this.parentElement.parentElement.innerHTML=\'\'" style="background:none;border:none;color:var(--t3);cursor:pointer;font-size:16px;">✕</button></div>`;
        },

        // ============================================================
        // SCAN — Per-keyword via live_scan.php (ASYNCHRONE)
        // POST start → réponse immédiate → poll status toutes les 5s
        // L'utilisateur peut naviguer librement pendant le scan.
        // ============================================================

        // Timers de polling actifs (pour cleanup)
        _pollTimers: {},

        async scanKeyword(kwId, lid) {
            try {
                var ez = document.getElementById('api-error-zone'); if (ez) ez.innerHTML = '';
            } catch (_) {}
            this._setScanBtnState(kwId, 'loading');
            try {
                var fd = new FormData();
                fd.append('location_id', lid);
                fd.append('keyword_id', kwId);
                fd.append('action', 'start');
                fd.append('csrf_token', document.querySelector('[name="csrf_token"]')?.value || '');

                // Envoyer la demande de scan (réponse immédiate)
                var res = await fetch(APP.url + '/api/live_scan.php', {
                    method: 'POST',
                    body: fd,
                    signal: AbortSignal.timeout(15000)
                });

                var text = await res.text();
                var d = null;
                try { d = JSON.parse(text); } catch (_) { throw new Error('Réponse serveur invalide : ' + text.substring(0, 100)); }

                if (!d || d.error) {
                    if (d && d.rate_limited) {
                        APP.toast(d.error, 'warning');
                        this._setScanBtnState(kwId, 'idle');
                        return;
                    }
                    throw new Error(d ? d.error : 'Réponse vide');
                }

                // Scan lance en arriere-plan → demarrer le polling
                console.log('[SCAN] Scan #' + kwId + ' lance en arriere-plan');
                this._pollScanStatus(kwId, lid);

            } catch (e) {
                console.error('[SCAN] scanKeyword start error:', e);
                this._setScanBtnState(kwId, 'idle');
                this._showApiError(e && e.message ? e.message : String(e), e.message && e.message.indexOf('credit') !== -1 ? 'api_credits' : 'generic');
            }
        },

        /**
         * Poll le statut du scan toutes les 5 secondes.
         * Quand le scan est termine → rafraichit l'UI.
         */
        _pollScanStatus(kwId, lid) {
            var self = this;
            // Nettoyer un eventuel timer precedent
            if (self._pollTimers[kwId]) { clearTimeout(self._pollTimers[kwId]); }

            var pollCount = 0;
            var maxPolls = 72; // 72 × 5s = 6 min max

            function poll() {
                pollCount++;
                fetch(APP.url + '/api/live_scan.php?action=status&keyword_id=' + kwId + '&location_id=' + lid)
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (!d || !d.success) {
                            console.warn('[SCAN] Poll error:', d);
                            if (pollCount < maxPolls) {
                                self._pollTimers[kwId] = setTimeout(poll, 5000);
                            } else {
                                self._setScanBtnState(kwId, 'idle');
                                self._showApiError('Le scan a pris trop de temps. Rafraichissez la page.', 'generic');
                            }
                            return;
                        }

                        var status = d.status || 'idle';

                        if (status === 'completed') {
                            // Scan termine avec succes !
                            delete self._pollTimers[kwId];
                            self._setScanBtnState(kwId, 'done');

                            // Rafraîchir l'affichage des mots-cles
                            APP.suivi.loadKeywords(lid).then(function() {
                                self.render(APP.suivi._keywords, APP.suivi._stats, lid);
                            });

                            if (d.duration_sec) {
                                console.log('[SCAN] Mot-cle #' + kwId + ' scanne en ' + d.duration_sec + 's — Visibilité: ' + d.visibility_score + '%, Pos moy: ' + d.avg_position);
                            }
                            APP.toast('Scan termine — ' + (d.keyword || 'mot-clé'), 'success');
                            return;
                        }

                        if (status === 'failed') {
                            delete self._pollTimers[kwId];
                            self._setScanBtnState(kwId, 'idle');
                            self._showApiError(d.error || 'Erreur pendant le scan', d.error && d.error.indexOf('credit') !== -1 ? 'api_credits' : 'generic');
                            return;
                        }

                        // Encore en cours → re-poll dans 5s
                        if (status === 'running' || status === 'idle') {
                            if (pollCount < maxPolls) {
                                self._pollTimers[kwId] = setTimeout(poll, 5000);
                            } else {
                                delete self._pollTimers[kwId];
                                self._setScanBtnState(kwId, 'idle');
                                self._showApiError('Le scan a pris trop de temps. Rafraichissez la page.', 'generic');
                            }
                        }
                    })
                    .catch(function(err) {
                        console.warn('[SCAN] Poll fetch error:', err);
                        if (pollCount < maxPolls) {
                            self._pollTimers[kwId] = setTimeout(poll, 5000);
                        } else {
                            delete self._pollTimers[kwId];
                            self._setScanBtnState(kwId, 'idle');
                        }
                    });
            }

            // Premier poll apres 5s (laisser le scan demarrer)
            self._pollTimers[kwId] = setTimeout(poll, 5000);
        },


        _setScanBtnState(kwId, state) {
            try {
                var btn = document.getElementById('btn-scan-' + kwId);
                if (!btn) return;
                var svgRefresh = '<svg viewBox="0 0 24 24" style="width:16px;height:16px;"><path d="M4 12a8 8 0 0 1 14.93-4M20 12a8 8 0 0 1-14.93 4" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"/><path d="M20 4v4h-4M4 20v-4h4" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                if (state === 'loading') {
                    btn.disabled = true;
                    btn.classList.add('scanning');
                    btn.innerHTML = svgRefresh;
                } else if (state === 'done') {
                    btn.disabled = false;
                    btn.classList.remove('scanning');
                    btn.innerHTML = '<svg viewBox="0 0 24 24" style="width:16px;height:16px;"><path d="M5 13l4 4L19 7" stroke="var(--g)" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                    setTimeout(function() { try { btn.innerHTML = svgRefresh; } catch (_) {} }, 2500);
                } else {
                    btn.disabled = false;
                    btn.classList.remove('scanning');
                    btn.innerHTML = svgRefresh;
                }
            } catch (_) {}
        },



        async add(lid) {
            const i = document.getElementById('new-kw'), kw = i?.value.trim(); if (!kw) return;
            const cityInput = document.getElementById('new-kw-city');
            const city = cityInput?.value.trim() || '';
            const fd = new FormData();
            fd.append('action', 'add'); fd.append('location_id', lid);
            fd.append('keyword', kw);
            if (city) fd.append('target_city', city);
            const d = await APP.fetch('/api/manage-keywords.php', { method: 'POST', body: fd });
            if (d.success) { i.value = ''; if (cityInput) cityInput.value = ''; document.getElementById('add-kw-form').style.display = 'none'; this.load(lid); } else { APP.toast(d.error || 'Erreur lors de l\'ajout', 'error'); }
        },
        async remove(kid, lid) {
            if (!await APP.modal.confirm('Supprimer définitivement', 'Cette action est irréversible. Toutes les données associées à ce mot-clé (positions, grilles de scan, concurrents) seront définitivement supprimées et ne pourront pas être récupérées.', 'Supprimer', true)) return;
            const fd = new FormData(); fd.append('action', 'delete'); fd.append('location_id', lid); fd.append('keyword_id', kid);
            const d = await APP.fetch('/api/manage-keywords.php', { method: 'POST', body: fd }); if (d.success) this.load(lid);
        }
    },

    // ====================================================================
    // MODULE : CONCURRENTS (Layout Pro Cards)
    // ====================================================================
    competitors: {
        _locationId: null,
        _selectedKwId: null,

        async load(locationId) {
            this._locationId = locationId;
            const data = await APP.suivi.loadKeywords(locationId);
            if (data.error) { console.error('competitors.load error:', data.error); this.render([], locationId); return; }
            this.render(APP.suivi._keywords, locationId);
            if (APP.suivi._keywords.length) this.switchKeyword(APP.suivi._keywords[0].id);
        },

        render(keywords, locationId) {
            const c = document.getElementById('module-content'); if (!c) return;
            const lid = locationId;
            let h = '<div class="comp-header"><div class="comp-header-left"><div class="stit">Concurrents</div><div id="comp-scan-date" class="comp-date"></div></div>';
            if (keywords.length) {
                h += '<div class="comp-header-right"><select class="si comp-kw-sel" id="comp-kw-select" onchange="APP.competitors.switchKeyword(parseInt(this.value))">';
                for (const kw of keywords) h += '<option value="' + kw.id + '">' + kw.keyword + (kw.target_city ? ' — ' + kw.target_city : '') + (kw.current_position ? ' (#' + kw.current_position + ')' : '') + '</option>';
                h += '</select></div>';
            }
            h += '</div>';

            if (!keywords.length) {
                h += '<div class="comp-empty"><svg viewBox="0 0 24 24" style="width:48px;height:48px;stroke:var(--t3);fill:none;stroke-width:1.5;opacity:.4;"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4-4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg><p>Aucun mot-clé configuré.</p><a href="?view=client&location=' + lid + '&tab=keywords" class="comp-empty-link">Ajoutez des mots-cles</a></div>';
            } else {
                h += '<div id="comp-stats-bar" class="comp-stats-bar"></div>';
                h += '<div id="competitors-table-zone"></div>';
            }
            c.innerHTML = h;
        },

        async switchKeyword(kwId) {
            this._selectedKwId = kwId;
            var sel = document.getElementById('comp-kw-select'); if (sel) sel.value = kwId;
            var zone = document.getElementById('competitors-table-zone'); if (!zone) return;
            zone.innerHTML = '<div style="padding:40px;text-align:center;color:var(--t3);"><svg viewBox="0 0 24 24" class="spin" style="width:24px;height:24px;"><path d="M12 2v4m0 12v4m10-10h-4M6 12H2" stroke="currentColor" stroke-width="2" fill="none"/></svg></div>';

            var lid = this._locationId;
            var listData = await APP.fetch('/api/grid.php?action=list&location_id=' + lid + '&keyword_id=' + kwId);
            if (!listData.success || !listData.scans || !listData.scans.length) {
                this._renderEmpty(zone);
                var dateEl = document.getElementById('comp-scan-date'); if (dateEl) dateEl.textContent = '';
                var statsBar = document.getElementById('comp-stats-bar'); if (statsBar) statsBar.innerHTML = '';
                return;
            }

            var scanData = await APP.fetch('/api/grid.php?action=get&location_id=' + lid + '&scan_id=' + listData.scans[0].id);
            if (!scanData.success) { this._renderEmpty(zone); return; }

            var dateEl = document.getElementById('comp-scan-date');
            if (dateEl && scanData.scan.scanned_at) {
                dateEl.textContent = 'Scan du ' + new Date(scanData.scan.scanned_at).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });
            }

            this._renderCards(zone, scanData.competitors || []);
        },

        _renderEmpty(zone) {
            zone.innerHTML = '<div class="comp-empty"><svg viewBox="0 0 24 24" style="width:48px;height:48px;stroke:var(--t3);fill:none;stroke-width:1.5;opacity:.4;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><p>Aucun scan disponible pour ce mot-clé.</p><p style="font-size:12px;color:var(--t3);">Lancez un scan depuis l\'onglet Mots-cles.</p></div>';
        },

        _renderCards(zone, competitors) {
            if (!competitors || !competitors.length) { this._renderEmpty(zone); return; }

            // Stats rapides
            var total = competitors.length;
            var withRating = competitors.filter(function(c) { return c.rating && parseFloat(c.rating) > 0; });
            var avgRating = withRating.length ? (withRating.reduce(function(s, c) { return s + parseFloat(c.rating); }, 0) / withRating.length).toFixed(1) : 0;
            var avgReviews = total ? Math.round(competitors.reduce(function(s, c) { return s + (parseInt(c.reviews_count) || 0); }, 0) / total) : 0;
            var target = competitors.find(function(c) { return parseInt(c.is_target) === 1; });

            var statsBar = document.getElementById('comp-stats-bar');
            if (statsBar) {
                statsBar.innerHTML = '<div class="comp-stat"><span class="comp-stat-val">' + total + '</span><span class="comp-stat-lbl">concurrents</span></div>'
                    + '<div class="comp-stat"><span class="comp-stat-val">' + (avgRating || '—') + '</span><span class="comp-stat-lbl">note moy.</span></div>'
                    + '<div class="comp-stat"><span class="comp-stat-val">' + avgReviews + '</span><span class="comp-stat-lbl">avis moy.</span></div>'
                    + (target ? '<div class="comp-stat comp-stat-you"><span class="comp-stat-val">#' + parseFloat(target.avg_position).toFixed(1) + '</span><span class="comp-stat-lbl">votre rang</span></div>' : '');
            }

            // Afficher dans l'ordre naturel du classement
            var h = '';
            for (var i = 0; i < competitors.length; i++) {
                var isTarget = parseInt(competitors[i].is_target) === 1;
                h += this._card(competitors[i], isTarget);
            }

            zone.innerHTML = '<div class="comp-list">' + h + '</div>';
        },

        _card(comp, isTarget) {
            var avgPos = parseFloat(comp.avg_position);
            var rating = comp.rating ? parseFloat(comp.rating) : 0;
            var reviews = parseInt(comp.reviews_count) || 0;
            var cat = comp.category || '';
            var addr = comp.address || '';
            var mapsUrl = comp.place_id ? 'https://www.google.com/maps/place/?q=place_id:' + comp.place_id : (comp.data_cid ? 'https://www.google.com/maps?cid=' + comp.data_cid : '');
            var posClass = avgPos <= 3 ? 'comp-rank-top3' : avgPos <= 10 ? 'comp-rank-top10' : avgPos <= 20 ? 'comp-rank-top20' : 'comp-rank-out';

            // Barre de rating visuelle (remplie proportionnellement)
            var ratingBar = '';
            if (rating > 0) {
                var pct = (rating / 5) * 100;
                ratingBar = '<div class="comp-rating-bar"><div class="comp-rating-fill" style="width:' + pct + '%;"></div></div>';
            }

            var h = '<div class="comp-card' + (isTarget ? ' comp-card-target' : '') + '">';

            // Rank badge
            h += '<div class="comp-rank ' + posClass + '"><span class="comp-rank-num">' + avgPos.toFixed(1) + '</span></div>';

            // Info principale
            h += '<div class="comp-main">';
            h += '<div class="comp-title-row">';
            h += '<div class="comp-name">' + (isTarget ? '<svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:var(--acc);fill:none;stroke-width:2;vertical-align:-2px;margin-right:4px;"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 22 12 18.27 5.82 22 7 14.14 2 9.27l6.91-1.01z"/></svg>' : '') + comp.title + '</div>';
            if (cat) h += '<span class="comp-cat">' + cat + '</span>';
            h += '</div>';

            // Adresse
            if (addr) h += '<div class="comp-addr">' + addr + '</div>';

            // Meta row : rating + reviews + maps
            h += '<div class="comp-meta">';
            if (rating > 0) {
                h += '<div class="comp-rating-group">';
                h += '<span class="comp-star-val">' + rating.toFixed(1) + '</span>';
                h += ratingBar;
                h += '<span class="comp-reviews-count">' + reviews + ' avis</span>';
                h += '</div>';
            } else {
                h += '<span class="comp-no-rating">Pas de note</span>';
            }

            if (mapsUrl) {
                h += '<a href="' + mapsUrl + '" target="_blank" rel="noopener" class="comp-maps-link"><svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0116 0z" stroke="currentColor" fill="none" stroke-width="2"/><circle cx="12" cy="10" r="3" stroke="currentColor" fill="none" stroke-width="2"/></svg>Maps</a>';
            }
            h += '</div>';

            h += '</div>'; // .comp-main
            h += '</div>'; // .comp-card
            return h;
        }
    },
    reviews: {
        _settings: null,
        _autoGenInProgress: false,
        async load(locationId) {
            const filter = new URLSearchParams(window.location.search).get('review_filter') || 'all';
            // Show skeleton
            const c = document.getElementById('module-content');
            if (c) c.innerHTML = `<div class="sh"><div class="stit">AVIS GOOGLE</div></div>${APP.skeleton.rows(5)}`;
            // Auto-sync silencieux en arrière-plan
            APP.fetch(`/api/reviews.php?action=sync&location_id=${locationId}`).catch(()=>{});
            const [data, settingsData] = await Promise.all([
                APP.fetch(`/api/reviews.php?action=list&location_id=${locationId}&filter=${filter}`),
                APP.fetch(`/api/reviews.php?action=get_settings&location_id=${locationId}`)
            ]);
            if (data.error) { console.error('Reviews API error:', data.error); return; }
            this._settings = settingsData.success ? settingsData.settings : {};
            this.render(data.reviews, data.stats, data.pagination, locationId, filter);

            // Auto-generation IA en arriere-plan pour les avis sans réponse
            if (!this._autoGenInProgress && data.stats && data.stats.unanswered > 0) {
                this._autoGenInProgress = true;
                const bar = document.getElementById('ai-gen-bar');
                if (bar) bar.style.display = 'flex';
                try {
                    const fd = new FormData();
                    fd.append('action', 'generate_all_replies');
                    fd.append('location_id', locationId);
                    const genData = await APP.fetch('/api/reviews.php', { method: 'POST', body: fd });
                    if (genData.success && genData.generated > 0) {
                        APP.toast(genData.generated + ' réponse(s) IA générée(s)', 'success');
                        // Rafraîchir la liste pour afficher les brouillons
                        this._autoGenInProgress = false;
                        this.load(locationId);
                        return;
                    }
                } catch (e) { console.error('Auto-gen error:', e); }
                this._autoGenInProgress = false;
                if (bar) bar.style.display = 'none';
            }
        },
        render(reviews, stats, pagination, locationId, activeFilter) {
            const c = document.getElementById('module-content');
            if (!c) return;
            const avg = stats.avg_rating || 0, total = stats.total || 0, unanswered = stats.unanswered || 0;
            // Compter les brouillons IA
            const aiDraftCount = reviews.filter(r => r.reply_source === 'ai_draft' && r.is_replied == 0).length;
            let h = `<div class="sh" style="flex-wrap:wrap;gap:12px;"><div class="stit">AVIS GOOGLE</div><div style="display:flex;gap:10px;align-items:center;">`;
            if (aiDraftCount > 0) {
                h += `<button class="btn bp bsm" onclick="APP.reviews.publishAllDrafts(${locationId})" style="background:var(--g);"><svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M5 13l4 4L19 7"/></svg> Publier ${aiDraftCount} réponse(s) IA</button>`;
            }
            h += `<button class="btn bs bsm" onclick="APP.reviews.showSettings(${locationId})"><svg viewBox="0 0 24 24"><path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0"/><circle cx="12" cy="12" r="3"/></svg> Profil IA</button></div></div>`;
            // Barre de progression IA (cachee par defaut)
            h += `<div id="ai-gen-bar" style="display:none;align-items:center;gap:10px;padding:10px 20px;background:rgba(0,212,255,.06);border-bottom:1px solid rgba(0,212,255,.15);"><svg class="spin" viewBox="0 0 24 24" style="width:16px;height:16px;stroke:var(--acc);fill:none;stroke-width:2;"><path d="M12 2v4m0 12v4m-7.07-3.93l2.83-2.83m8.48-8.48l2.83-2.83M2 12h4m12 0h4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83"/></svg><span style="font-size:12px;color:var(--acc);">Generation IA en cours...</span></div>`;
            h += `<div style="display:grid;grid-template-columns:auto 1fr auto;gap:24px;padding:20px;border-bottom:1px solid var(--bdr);align-items:center;"><div style="text-align:center;"><div style="font-size:42px;font-weight:700;font-family:'Space Mono',monospace;color:var(--o);">${avg}</div><div style="color:var(--o);font-size:18px;letter-spacing:2px;">${this.renderStars(avg)}</div><div style="font-size:12px;color:var(--t3);margin-top:4px;">${total} avis</div></div><div style="display:flex;flex-direction:column;gap:4px;">`;
            for (const s of [5,4,3,2,1]) { const cnt=stats['stars_'+s]||0,pct=total>0?(cnt/total*100):0; h+=`<div style="display:flex;align-items:center;gap:8px;font-size:12px;"><span style="width:14px;text-align:right;color:var(--t2);">${s}</span><span style="color:var(--o);">★</span><div style="flex:1;height:8px;background:rgba(255,255,255,.06);border-radius:4px;overflow:hidden;"><div style="width:${pct}%;height:100%;background:var(--o);border-radius:4px;"></div></div><span style="width:30px;color:var(--t3);">${cnt}</span></div>`; }
            h += `</div><div style="text-align:center;"><div style="font-size:28px;font-weight:700;font-family:'Space Mono',monospace;color:${unanswered>0?'var(--o)':'var(--g)'};">${unanswered}</div><div style="font-size:12px;color:var(--t3);">sans réponse</div></div></div>`;
            h += `<div style="display:flex;gap:8px;padding:14px 20px;border-bottom:1px solid var(--bdr);flex-wrap:wrap;">`;
            const delCount = stats.deleted_count || 0;
            const labels={all:'Tous',unanswered:'Sans réponse',deleted:`Supprimés (${delCount})`,'5':'5★','4':'4★','3':'3★','2':'2★','1':'1★'};
            for (const f of ['all','unanswered','deleted','5','4','3','2','1']) { h+=`<button class="btn ${f===activeFilter?'bp':'bs'} bsm" onclick="APP.reviews.filter(${locationId},'${f}')"${f==='deleted'?' style="'+(delCount>0?'color:var(--r);':'opacity:.5;')+'"':''}>${labels[f]}</button>`; }
            h += `</div>`;
            const _s = this._settings || {};
            const _owner = this.esc(_s.owner_name || '');
            const _tone = _s.default_tone || 'professional';
            const _gender = _s.gender || 'neutral';
            const _instr = this.esc(_s.custom_instructions || '');
            const _intro = this.esc(_s.review_intro || 'Bonjour {prénom},');
            const _closing = this.esc(_s.review_closing || 'À bientôt,');
            const _sig = this.esc(_s.review_signature || '');
            const _speech = _s.review_speech || 'vous';
            h += `<div id="review-settings" style="display:none;padding:20px;border-bottom:1px solid var(--bdr);background:var(--overlay);">
                <div style="font-weight:600;margin-bottom:12px;">Profil IA — Réponses aux avis</div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px;">
                    <div><label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Ton</label><select id="set-tone" class="si" style="width:100%;"><option value="professional"${_tone==='professional'?' selected':''}>Professionnel</option><option value="friendly"${_tone==='friendly'?' selected':''}>Amical</option><option value="empathetic"${_tone==='empathetic'?' selected':''}>Empathique</option></select></div>
                    <div><label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Je parle en tant que</label><select id="set-gender" class="si" style="width:100%;"><option value="male"${_gender==='male'?' selected':''}>Homme (je suis ravi...)</option><option value="female"${_gender==='female'?' selected':''}>Femme (je suis ravie...)</option><option value="neutral"${_gender==='neutral'?' selected':''}>Neutre / Entreprise (nous sommes ravis...)</option></select></div>
                    <div><label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Forme d'adresse</label><select id="set-review-speech" class="si" style="width:100%;"><option value="vous"${_speech==='vous'?' selected':''}>Vouvoiement (vous)</option><option value="tu"${_speech==='tu'?' selected':''}>Tutoiement (tu)</option></select></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px;">
                    <div><label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Formule d'introduction</label><input type="text" id="set-review-intro" class="si" placeholder="Bonjour {prénom}," style="width:100%;" value="${_intro}"></div>
                    <div><label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Formule de conclusion</label><input type="text" id="set-review-closing" class="si" placeholder="À bientôt," style="width:100%;" value="${_closing}"></div>
                    <div><label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Signature</label><input type="text" id="set-review-signature" class="si" placeholder="Nom de la fiche par défaut" style="width:100%;" value="${_sig}"></div>
                </div>
                <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Instructions personnalisées pour l'IA</label>
                <textarea id="set-instructions" class="si" placeholder="Ex: Toujours mentionner notre engagement qualité..." style="width:100%;height:80px;resize:vertical;">${_instr}</textarea>
                <div style="font-size:11px;color:var(--t3);margin-top:6px;margin-bottom:12px;">Format : "{intro}" → réponse → "{closing}" + retour à la ligne + "{signature}"</div>
                <div style="display:flex;gap:10px;"><button class="btn bp bsm" onclick="APP.reviews.saveSettings(${locationId})">Enregistrer</button><button class="btn bs bsm" onclick="document.getElementById('review-settings').style.display='none'">Fermer</button></div>
            </div>`;
            if (!reviews.length) { h += `<div style="padding:40px;text-align:center;color:var(--t2);"><p>Aucun avis pour le moment. Ajoutez des avis manuellement ou attendez la connexion API Google.</p></div>`; }
            else {
                h += '<div class="reviews-list">';
                for (const r of reviews) {
                    const stars=this.renderStars(r.rating), date=r.review_date?new Date(r.review_date).toLocaleDateString('fr-FR'):'', replied=r.is_replied==1, isDeleted=r.deleted_by_google==1;
                    const deletedBadge = isDeleted ? '<span style="display:inline-flex;align-items:center;gap:3px;padding:2px 8px;background:rgba(239,68,68,.12);color:var(--r);font-size:10px;font-weight:600;border-radius:4px;border:1px solid rgba(239,68,68,.2);">🗑 Supprime par Google</span>' : '';
                    const deletedStyle = isDeleted ? ' style="opacity:.7;border-left:3px solid var(--r);"' : '';
                    h += `<div class="rev-card"${deletedStyle} id="review-${r.id}"><div class="rev-header"><div class="rev-avatar">${(r.author_name||'?')[0].toUpperCase()}</div><div style="flex:1;"><div class="rev-author">${this.esc(r.author_name)}</div><div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;"><span style="color:var(--o);">${stars}</span><span class="kv">${date}</span>${replied?'<span class="ps psp">Répondu</span>':'<span class="ps pss">Sans réponse</span>'}${deletedBadge}</div></div><button class="btn bs bsm" onclick="APP.reviews.remove(${r.id},${locationId})" title="Supprimer" style="opacity:.5;">✕</button></div>`;
                    h += r.comment ? `<div class="rev-comment">${this.esc(r.comment)}</div>` : '<div class="rev-comment" style="color:var(--t3);font-style:italic;">Pas de commentaire</div>';
                    if (replied) {
                        h += `<div class="rev-reply"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;"><div style="font-size:11px;font-weight:600;color:var(--acc);">↩ Votre réponse</div><div style="display:flex;gap:6px;"><button class="btn bs bsm" onclick="APP.reviews.editReply(${r.id},${locationId})" style="font-size:11px;padding:3px 10px;">Modifier</button><button class="btn bs bsm" onclick="APP.reviews.regenReply(${r.id},${locationId})" style="font-size:11px;padding:3px 10px;">Re-générer</button></div></div><div id="reply-display-${r.id}" style="font-size:13px;color:var(--t2);">${this.esc(r.reply_text)}</div><div id="reply-edit-${r.id}" style="display:none;margin-top:8px;"><textarea id="reply-text-${r.id}" class="si" style="width:100%;height:100px;resize:vertical;">${this.esc(r.reply_text)}</textarea><div style="display:flex;gap:10px;margin-top:8px;"><button class="btn bp bsm" onclick="APP.reviews.saveReply(${r.id},${locationId},true)"><svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M5 13l4 4L19 7"/></svg> Publier sur Google</button><button class="btn bs bsm" onclick="APP.reviews.saveReply(${r.id},${locationId},false)">Sauvegarder local</button><button class="btn bs bsm" onclick="document.getElementById('reply-edit-${r.id}').style.display='none';document.getElementById('reply-display-${r.id}').style.display='block'">Annuler</button><button class="btn bs bsm" onclick="navigator.clipboard.writeText(document.getElementById('reply-text-${r.id}').value)">Copier</button></div></div></div>`;
                    }
                    else if (r.reply_text && r.reply_source === 'ai_draft') {
                        // Brouillon IA pre-genere : afficher directement
                        h += `<div class="rev-reply" style="border-left:2px solid var(--acc);background:rgba(0,212,255,.03);">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                                <div style="font-size:11px;font-weight:600;color:var(--acc);">⚡ Réponse IA (brouillon)</div>
                                <div style="display:flex;gap:6px;">
                                    <button class="btn bp bsm" onclick="APP.reviews.saveReply(${r.id},${locationId},true)" style="font-size:11px;padding:3px 10px;"><svg viewBox="0 0 24 24" style="width:12px;height:12px;"><path d="M5 13l4 4L19 7"/></svg> Publier</button>
                                    <button class="btn bs bsm" onclick="APP.reviews.editReply(${r.id},${locationId})" style="font-size:11px;padding:3px 10px;">Modifier</button>
                                    <button class="btn bs bsm" onclick="APP.reviews.regenReply(${r.id},${locationId})" style="font-size:11px;padding:3px 10px;">Re-générer</button>
                                </div>
                            </div>
                            <div id="reply-display-${r.id}" style="font-size:13px;color:var(--t2);">${this.esc(r.reply_text)}</div>
                            <div id="reply-edit-${r.id}" style="display:none;margin-top:8px;">
                                <textarea id="reply-text-${r.id}" class="si" style="width:100%;height:100px;resize:vertical;">${this.esc(r.reply_text)}</textarea>
                                <div style="display:flex;gap:10px;margin-top:8px;">
                                    <button class="btn bp bsm" onclick="APP.reviews.saveReply(${r.id},${locationId},true)"><svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M5 13l4 4L19 7"/></svg> Publier sur Google</button>
                                    <button class="btn bs bsm" onclick="APP.reviews.saveReply(${r.id},${locationId},false)">Sauvegarder</button>
                                    <button class="btn bs bsm" onclick="document.getElementById('reply-edit-${r.id}').style.display='none';document.getElementById('reply-display-${r.id}').style.display='block'">Annuler</button>
                                </div>
                            </div>
                        </div>`;
                    }
                    else { h += `<div class="rev-actions" id="actions-${r.id}"><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;"><button class="btn bp bsm" onclick="APP.reviews.generateReply(${r.id},${locationId})"><svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg> Répondre avec IA</button></div><div id="reply-zone-${r.id}" style="display:none;margin-top:12px;"><textarea id="reply-text-${r.id}" class="si" style="width:100%;height:100px;resize:vertical;"></textarea><div style="display:flex;gap:10px;margin-top:8px;"><button class="btn bp bsm" onclick="APP.reviews.saveReply(${r.id},${locationId},true)"><svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M5 13l4 4L19 7"/></svg> Publier sur Google</button><button class="btn bs bsm" onclick="APP.reviews.saveReply(${r.id},${locationId},false)">Sauvegarder local</button><button class="btn bs bsm" onclick="navigator.clipboard.writeText(document.getElementById('reply-text-${r.id}').value)">Copier</button></div></div></div>`; }
                    h += '</div>';
                }
                h += '</div>';
            }
            c.innerHTML = h;
        },
        renderStars(r) { const f=Math.floor(r),hl=r%1>=.5?1:0,e=5-f-hl; return '★'.repeat(f)+(hl?'½':'')+'☆'.repeat(e); },
        esc(s) { if(!s)return''; const d=document.createElement('div');d.textContent=s;return d.innerHTML; },
        filter(lid,f) { const u=new URL(window.location);u.searchParams.set('review_filter',f);window.history.replaceState(null,'',u);this.load(lid); },
        async showSettings(lid) {
            document.getElementById('review-settings').style.display='block';
            const data = await APP.fetch(`/api/reviews.php?action=get_settings&location_id=${lid}`);
            if (data.success && data.settings) {
                document.getElementById('set-tone').value = data.settings.default_tone || 'professional';
                document.getElementById('set-gender').value = data.settings.gender || 'neutral';
                document.getElementById('set-instructions').value = data.settings.custom_instructions || '';
                document.getElementById('set-review-intro').value = data.settings.review_intro || 'Bonjour {prénom},';
                document.getElementById('set-review-closing').value = data.settings.review_closing || 'À bientôt,';
                document.getElementById('set-review-signature').value = data.settings.review_signature || '';
                document.getElementById('set-review-speech').value = data.settings.review_speech || 'vous';
            }
        },
        async generateReply(rid,lid) {
            const z=document.getElementById(`reply-zone-${rid}`),t=document.getElementById(`reply-text-${rid}`);
            z.style.display='block';t.value='Génération en cours...';t.disabled=true;
            const fd=new FormData();fd.append('action','generate_reply');fd.append('location_id',lid);fd.append('review_id',rid);
            const d=await APP.fetch('/api/reviews.php',{method:'POST',body:fd});t.disabled=false;
            t.value=d.success?d.reply:'Erreur: '+(d.error||'Impossible de générer');
        },
        async saveReply(rid,lid,postToGoogle=false) {
            const t=document.getElementById(`reply-text-${rid}`).value.trim();if(!t)return APP.toast('Réponse vide','warning');
            const fd=new FormData();fd.append('action','save_reply');fd.append('location_id',lid);fd.append('review_id',rid);fd.append('reply_text',t);fd.append('post_to_google',postToGoogle?'1':'0');
            const d=await APP.fetch('/api/reviews.php',{method:'POST',body:fd});
            if(d.success){
                if(d.posted_to_google)APP.toast('Réponse publiée sur Google !','success');
                else if(d.google_error)APP.toast('Sauvegardée. Erreur Google : '+d.google_error,'warning');
                this.load(lid);
            }else APP.toast(d.error||'Erreur','error');
        },
        editReply(rid, lid) {
            document.getElementById(`reply-display-${rid}`).style.display='none';
            document.getElementById(`reply-edit-${rid}`).style.display='block';
        },
        async regenReply(rid, lid) {
            document.getElementById(`reply-display-${rid}`).style.display='none';
            const editZone = document.getElementById(`reply-edit-${rid}`);
            editZone.style.display='block';
            const t = document.getElementById(`reply-text-${rid}`);
            t.value='Re-generation en cours...';t.disabled=true;
            const fd=new FormData();fd.append('action','generate_reply');fd.append('location_id',lid);fd.append('review_id',rid);fd.append('tone','');
            const d=await APP.fetch('/api/reviews.php',{method:'POST',body:fd});t.disabled=false;
            t.value=d.success?d.reply:'Erreur: '+(d.error||'Impossible de générer');
        },
        async publishAllDrafts(lid) {
            if (!await APP.modal.confirm(
                'Publier les réponses IA',
                'Publier toutes les réponses IA (brouillons) sur Google ?\n\nChaque réponse sera envoyee a Google une par une. Vous pourrez toujours les modifier ensuite.',
                'Publier tout'
            )) return;

            // Récupérer tous les avis avec brouillon IA
            const cards = document.querySelectorAll('.rev-card');
            let published = 0, errors = 0;
            for (const card of cards) {
                const replyDisplay = card.querySelector('[id^="reply-display-"]');
                const draftLabel = card.querySelector('[style*="border-left:2px solid var(--acc)"]');
                if (!draftLabel || !replyDisplay) continue;

                const rid = replyDisplay.id.replace('reply-display-', '');
                const replyText = replyDisplay.textContent.trim();
                if (!replyText) continue;

                const fd = new FormData();
                fd.append('action', 'save_reply');
                fd.append('location_id', lid);
                fd.append('review_id', rid);
                fd.append('reply_text', replyText);
                fd.append('post_to_google', '1');
                try {
                    const d = await APP.fetch('/api/reviews.php', { method: 'POST', body: fd });
                    if (d.success && d.posted_to_google) published++;
                    else errors++;
                } catch (e) { errors++; }
                await new Promise(r => setTimeout(r, 1000)); // 1s entre chaque
            }

            if (published > 0) APP.toast(published + ' réponse(s) publiée(s) sur Google !', 'success');
            if (errors > 0) APP.toast(errors + ' erreur(s) de publication', 'warning');
            this.load(lid);
        },
        async saveSettings(lid) {
            const fd=new FormData();
            fd.append('action','save_settings');
            fd.append('location_id',lid);
            fd.append('default_tone',document.getElementById('set-tone').value);
            fd.append('gender',document.getElementById('set-gender').value);
            fd.append('custom_instructions',document.getElementById('set-instructions').value.trim());
            fd.append('review_intro',document.getElementById('set-review-intro').value.trim());
            fd.append('review_closing',document.getElementById('set-review-closing').value.trim());
            fd.append('review_signature',document.getElementById('set-review-signature').value.trim());
            fd.append('review_speech',document.getElementById('set-review-speech').value);
            const d=await APP.fetch('/api/reviews.php',{method:'POST',body:fd});
            if(d.success){
                document.getElementById('review-settings').style.display='none';
                Object.assign(this._settings, {
                    default_tone: document.getElementById('set-tone').value,
                    gender: document.getElementById('set-gender').value,
                    custom_instructions: document.getElementById('set-instructions').value.trim(),
                    review_intro: document.getElementById('set-review-intro').value.trim(),
                    review_closing: document.getElementById('set-review-closing').value.trim(),
                    review_signature: document.getElementById('set-review-signature').value.trim(),
                    review_speech: document.getElementById('set-review-speech').value
                });
                APP.toast('Profil IA Avis sauvegardé !','success');
            }
        },
        async remove(rid,lid) {
            if(!await APP.modal.confirm('Supprimer','Supprimer cet avis ?','Supprimer',true))return;
            const fd=new FormData();fd.append('action','delete');fd.append('location_id',lid);fd.append('review_id',rid);
            const d=await APP.fetch('/api/reviews.php',{method:'POST',body:fd});if(d.success)this.load(lid);
        }
    },

    // ====================================================
    // MODULE VUE D'ENSEMBLE CONTENU
    // ====================================================
    contentOverview: {
        _locationId: null,
        _viewMode: 'list',
        _typeFilter: 'all',
        _statusFilter: 'all',
        _calMonth: new Date().getMonth(),
        _calYear: new Date().getFullYear(),
        _allPosts: [],
        _stats: null,

        async load(locationId, page) {
            this._locationId = locationId;
            const c = document.getElementById('module-content');
            if (c) c.innerHTML = `<div class="sh"><div class="stit">VUE D'ENSEMBLE</div></div>${APP.skeleton.stats(6)}${APP.skeleton.rows(5)}`;
            if (!page) page = 1;
            try {
                const data = await APP.fetch(`/api/posts.php?action=list_all&location_id=${locationId}&page=${page}`);
                if (data.error) { console.error('ContentOverview API error:', data.error); return; }
                this._allPosts = data.posts || [];
                this._stats = data.stats || {};
                this.render(data.posts, data.stats, data.pagination, locationId);
            } catch(e) { console.error('ContentOverview load error:', e); }
        },

        render(posts, stats, pagination, locationId) {
            const c = document.getElementById('module-content');
            if (!c) return;
            const s = stats || {};
            const drafts = parseInt(s.drafts)||0, scheduled = parseInt(s.scheduled)||0,
                  published = parseInt(s.published)||0, failed = parseInt(s.failed)||0;
            const typeFaq = parseInt(s.type_faq)||0, typeMix = parseInt(s.type_mix)||0,
                  typeArticle = parseInt(s.type_article)||0, typeAutolist = parseInt(s.type_autolist)||0,
                  typeEvent = parseInt(s.type_event)||0, typeOffer = parseInt(s.type_offer)||0;

            const types = [
                { icon:'📝', label:'Article', count:typeArticle, color:'var(--acc)', bg:'rgba(0,212,255,.08)' },
                { icon:'🤖', label:'FAQ IA', count:typeFaq, color:'#8a2be2', bg:'rgba(138,43,226,.08)' },
                { icon:'🔀', label:'Mix IA', count:typeMix, color:'#e91e63', bg:'rgba(233,30,99,.08)' },
                { icon:'🔄', label:'Auto-list', count:typeAutolist, color:'var(--g)', bg:'rgba(76,175,80,.08)' },
                { icon:'📌', label:'Événement', count:typeEvent, color:'var(--o)', bg:'rgba(255,152,0,.08)' },
                { icon:'🏷', label:'Offre', count:typeOffer, color:'var(--o)', bg:'rgba(255,152,0,.08)' },
            ];

            let h = `<div class="sh" style="flex-wrap:wrap;gap:12px;">
                <div class="stit">VUE D'ENSEMBLE</div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <button class="btn bp bsm ${this._viewMode==='list'?'':'bgh'}" onclick="APP.contentOverview._switchView('list')" style="${this._viewMode==='list'?'':'opacity:.6'}">
                        <svg viewBox="0 0 24 24" style="width:14px;height:14px"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg> Liste
                    </button>
                    <button class="btn bp bsm ${this._viewMode==='calendar'?'':'bgh'}" onclick="APP.contentOverview._switchView('calendar')" style="${this._viewMode==='calendar'?'':'opacity:.6'}">
                        <svg viewBox="0 0 24 24" style="width:14px;height:14px"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> Calendrier
                    </button>
                </div>
            </div>`;

            // === Type cards grid (cliquables comme filtres) ===
            const filterKeys = ['article','faq_ai','mix','autolist','event','offer'];
            h += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin:16px 0;">';
            for (let ti = 0; ti < types.length; ti++) {
                const t = types[ti];
                const fk = filterKeys[ti];
                const isActive = this._typeFilter === fk;
                h += `<div onclick="APP.contentOverview._setTypeFilter('${fk}')" style="background:${t.bg};border:2px solid ${isActive ? t.color : 'var(--bdr)'};border-radius:10px;padding:14px;text-align:center;cursor:pointer;transition:border-color .15s;${isActive ? 'box-shadow:0 0 12px ' + t.color + '40;' : ''}">
                    <div style="font-size:22px;margin-bottom:4px;">${t.icon}</div>
                    <div style="font-size:22px;font-weight:700;color:${t.color};">${t.count}</div>
                    <div style="font-size:11px;color:var(--t3);margin-top:2px;">${t.label}</div>
                </div>`;
            }
            h += '</div>';
            // Filter bar
            if (this._typeFilter !== 'all') {
                const activeType = types[filterKeys.indexOf(this._typeFilter)];
                h += `<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;padding:8px 14px;background:var(--bg2);border:1px solid var(--bdr);border-radius:8px;">
                    <span style="font-size:12px;color:var(--t2);">Filtre actif : <strong style="color:${activeType?.color || 'var(--t1)'};">${activeType?.icon || ''} ${activeType?.label || ''}</strong></span>
                    <button class="btn bsm bgh" onclick="APP.contentOverview._setTypeFilter('all')" style="font-size:11px;padding:4px 10px;">✕ Tout afficher</button>
                </div>`;
            }

            // === Status cards (cliquables comme filtres) ===
            h += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin:0 0 16px;">';
            const statuses = [
                { key:'draft', label:'Brouillons', count:drafts, color:'var(--t2)', icon:'✏️' },
                { key:'scheduled', label:'Planifiés', count:scheduled, color:'var(--acc)', icon:'📅' },
                { key:'published', label:'Publiés', count:published, color:'var(--g)', icon:'✅' },
                { key:'failed', label:'Échoués', count:failed, color:'var(--r)', icon:'❌' },
            ];
            for (const st of statuses) {
                const isActiveSt = this._statusFilter === st.key;
                h += `<div onclick="APP.contentOverview._setStatusFilter('${st.key}')" style="background:var(--bg2);border:2px solid ${isActiveSt ? st.color : 'var(--bdr)'};border-radius:10px;padding:14px;display:flex;align-items:center;gap:10px;cursor:pointer;transition:border-color .15s;${isActiveSt ? 'box-shadow:0 0 12px rgba(0,212,255,.25);' : ''}">
                    <span style="font-size:18px;">${st.icon}</span>
                    <div>
                        <div style="font-size:20px;font-weight:700;color:${st.color};">${st.count}</div>
                        <div style="font-size:11px;color:var(--t3);">${st.label}</div>
                    </div>
                </div>`;
            }
            h += '</div>';
            // Status filter bar
            if (this._statusFilter !== 'all') {
                const activeSt = statuses.find(s => s.key === this._statusFilter);
                h += `<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;padding:8px 14px;background:var(--bg2);border:1px solid var(--bdr);border-radius:8px;">
                    <span style="font-size:12px;color:var(--t2);">Filtre statut : <strong style="color:${activeSt?.color || 'var(--t1)'};">${activeSt?.icon || ''} ${activeSt?.label || ''}</strong></span>
                    <button class="btn bsm bgh" onclick="APP.contentOverview._setStatusFilter('all')" style="font-size:11px;padding:4px 10px;">✕ Tout afficher</button>
                </div>`;
            }

            // === Legend ===
            h += APP.posts._renderTypeLegend();

            // === View content ===
            h += '<div id="co-view-content" style="margin-top:16px;">';
            if (this._viewMode === 'calendar') {
                h += '<div id="co-calendar"></div>';
            } else {
                // Filtrer les posts par type si filtre actif
                let filteredPosts = posts || [];
                if (this._typeFilter !== 'all') {
                    filteredPosts = filteredPosts.filter(p => {
                        const tb = APP.posts._getTypeBadge(p);
                        const map = {'Article':'article','FAQ IA':'faq_ai','Mix IA':'mix','Auto-list':'autolist','Événement':'event','Offre':'offer'};
                        return map[tb.label] === this._typeFilter;
                    });
                }
                // Filtrer par statut si filtre actif
                if (this._statusFilter !== 'all') {
                    filteredPosts = filteredPosts.filter(p => {
                        if (this._statusFilter === 'draft') return p.status === 'draft';
                        if (this._statusFilter === 'scheduled') return p.status === 'scheduled' || p.status === 'list_pending';
                        if (this._statusFilter === 'published') return p.status === 'published';
                        if (this._statusFilter === 'failed') return p.status === 'failed';
                        return true;
                    });
                }
                // Posts list
                if (!filteredPosts || filteredPosts.length === 0) {
                    h += `<div style="text-align:center;padding:40px;color:var(--t3);">
                        <div style="font-size:36px;margin-bottom:12px;">📭</div>
                        <div style="font-size:14px;">Aucun contenu trouvé</div>
                        <div style="font-size:12px;margin-top:8px;">${(this._typeFilter !== 'all' || this._statusFilter !== 'all') ? 'Aucun contenu pour ces filtres' : 'Créez vos premiers posts dans l\'onglet Posts'}</div>
                    </div>`;
                } else {
                    h += '<div style="display:flex;flex-direction:column;gap:8px;">';
                    for (const p of filteredPosts) {
                        const tb = APP.posts._getTypeBadge(p);
                        const statusLabel = p.status === 'published' ? 'Publié' : p.status === 'scheduled' ? 'Planifié' : p.status === 'list_pending' ? 'Planifié (auto)' : p.status === 'draft' ? 'Brouillon' : p.status === 'failed' ? 'Échoué' : p.status;
                        const statusColor = p.status === 'published' ? 'var(--g)' : p.status === 'scheduled' || p.status === 'list_pending' ? 'var(--acc)' : p.status === 'failed' ? 'var(--r)' : 'var(--t3)';
                        let dateStr = '';
                        if (p.next_publish_at) { dateStr = '📅 ' + new Date(p.next_publish_at).toLocaleDateString('fr-FR',{day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}); }
                        else if (p.scheduled_at) { dateStr = '📅 ' + new Date(p.scheduled_at).toLocaleDateString('fr-FR',{day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}); }
                        else if (p.published_at) { dateStr = '✅ ' + new Date(p.published_at).toLocaleDateString('fr-FR',{day:'numeric',month:'short',year:'numeric'}); }
                        const summary = (p.content || '').replace(/<[^>]*>/g, '').substring(0, 120);
                        const canEdit = !p.list_id && (p.status === 'draft' || p.status === 'scheduled' || p.status === 'failed');
                        const redirectTab = p.list_id ? 'post-lists' : 'posts';
                        const redirectStatus = p.status === 'published' ? 'published' : p.status === 'draft' ? 'draft' : p.status === 'failed' ? 'failed' : 'scheduled';

                        h += `<div class="co-post-card" style="background:var(--bg2);border:1px solid var(--bdr);border-radius:10px;padding:14px;transition:border-color .15s;display:flex;gap:12px;align-items:flex-start;">`;
                        if (p.image_url) {
                            h += `<img src="${p.image_url}" style="width:48px;height:48px;border-radius:8px;object-fit:cover;flex-shrink:0;" loading="lazy" onerror="this.style.display='none'">`;
                        }
                        h += `<div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap;">
                                <span style="background:${tb.bg};color:${tb.color};padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600;white-space:nowrap;">${tb.icon} ${tb.label}</span>
                                <span style="color:${statusColor};font-size:11px;font-weight:600;">${statusLabel}</span>
                                ${dateStr ? `<span style="color:var(--t3);font-size:11px;">${dateStr}</span>` : ''}
                                <span style="margin-left:auto;display:flex;gap:6px;">
                                    ${canEdit ? `<button class="btn bs bsm" onclick="event.stopPropagation();window.location.href='?view=client&location=${locationId}&tab=posts&post_status=${redirectStatus}&edit=${p.id}'" style="font-size:10px;padding:2px 8px;">Modifier</button>` : ''}
                                    <button class="btn bsm bgh" onclick="event.stopPropagation();window.location.href='?view=client&location=${locationId}&tab=${redirectTab}&post_status=${redirectStatus}'" style="font-size:10px;padding:2px 8px;">Voir →</button>
                                </span>
                            </div>
                            <div style="color:var(--t2);font-size:13px;line-height:1.5;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${summary || '<em style="color:var(--t3)">Pas de contenu</em>'}</div>
                        </div>
                        </div>`;
                    }
                    h += '</div>';
                }

                // Pagination
                if (pagination && pagination.total_pages > 1) {
                    h += '<div style="display:flex;justify-content:center;gap:8px;margin-top:20px;">';
                    if (pagination.page > 1) {
                        h += `<button class="btn bsm bgh" onclick="APP.contentOverview.load(${locationId},${pagination.page-1})">← Précédent</button>`;
                    }
                    h += `<span style="padding:8px 12px;font-size:13px;color:var(--t2);">Page ${pagination.page} / ${pagination.total_pages}</span>`;
                    if (pagination.page < pagination.total_pages) {
                        h += `<button class="btn bsm bgh" onclick="APP.contentOverview.load(${locationId},${pagination.page+1})">Suivant →</button>`;
                    }
                    h += '</div>';
                }
            }
            h += '</div>';

            c.innerHTML = h;

            // Render calendar if active
            if (this._viewMode === 'calendar') {
                this._renderCalendar(document.getElementById('co-calendar'));
            }
        },

        _switchView(mode) {
            this._viewMode = mode;
            if (this._stats) {
                this.render(this._allPosts, this._stats, null, this._locationId);
            }
        },

        _setTypeFilter(type) {
            this._typeFilter = (this._typeFilter === type) ? 'all' : type;
            if (this._stats) {
                this.render(this._allPosts, this._stats, null, this._locationId);
            }
        },

        _setStatusFilter(status) {
            this._statusFilter = (this._statusFilter === status) ? 'all' : status;
            if (this._stats) {
                this.render(this._allPosts, this._stats, null, this._locationId);
            }
        },

        _renderCalendar(container) {
            if (!container) return;
            const months = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
            const days = ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
            const y = this._calYear, m = this._calMonth;
            const firstDay = new Date(y, m, 1);
            const lastDay = new Date(y, m + 1, 0);
            let startDow = firstDay.getDay(); // 0=dim
            startDow = startDow === 0 ? 6 : startDow - 1; // 0=lun
            const totalDays = lastDay.getDate();

            // Filter posts by type if active
            let calPosts = this._allPosts;
            if (this._typeFilter !== 'all') {
                calPosts = calPosts.filter(p => {
                    const tb = APP.posts._getTypeBadge(p);
                    const map = {'Article':'article','FAQ IA':'faq_ai','Mix IA':'mix','Auto-list':'autolist','Événement':'event','Offre':'offer'};
                    return map[tb.label] === this._typeFilter;
                });
            }
            // Filter posts by status if active
            if (this._statusFilter !== 'all') {
                calPosts = calPosts.filter(p => {
                    if (this._statusFilter === 'draft') return p.status === 'draft';
                    if (this._statusFilter === 'scheduled') return p.status === 'scheduled' || p.status === 'list_pending';
                    if (this._statusFilter === 'published') return p.status === 'published';
                    if (this._statusFilter === 'failed') return p.status === 'failed';
                    return true;
                });
            }
            // Group posts by date
            const postsByDate = {};
            for (const p of calPosts) {
                const d = p.next_publish_at || p.scheduled_at || p.published_at || p.created_at;
                if (!d) continue;
                const dt = new Date(d);
                if (dt.getFullYear() === y && dt.getMonth() === m) {
                    const key = dt.getDate();
                    if (!postsByDate[key]) postsByDate[key] = [];
                    postsByDate[key].push(p);
                }
            }

            let h = `<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                <button class="btn bsm bgh" onclick="APP.contentOverview._calPrev()">◀</button>
                <span style="font-size:16px;font-weight:700;color:var(--t1);">${months[m]} ${y}</span>
                <div style="display:flex;gap:6px;">
                    <button class="btn bsm bgh" onclick="APP.contentOverview._calToday()">Aujourd'hui</button>
                    <button class="btn bsm bgh" onclick="APP.contentOverview._calNext()">▶</button>
                </div>
            </div>`;

            h += '<div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;">';
            // Headers
            for (const d of days) {
                h += `<div style="text-align:center;font-size:11px;font-weight:600;color:var(--t3);padding:8px 0;">${d}</div>`;
            }
            // Empty cells
            for (let i = 0; i < startDow; i++) {
                h += '<div style="min-height:70px;"></div>';
            }
            // Days
            const today = new Date();
            const isCurrentMonth = today.getFullYear() === y && today.getMonth() === m;
            for (let day = 1; day <= totalDays; day++) {
                const isToday = isCurrentMonth && today.getDate() === day;
                const dayPosts = postsByDate[day] || [];
                h += `<div style="min-height:70px;background:var(--bg2);border:1px solid ${isToday ? 'var(--acc)' : 'var(--bdr)'};border-radius:8px;padding:4px;overflow:hidden;">`;
                h += `<div style="font-size:12px;font-weight:${isToday?'700':'500'};color:${isToday?'var(--acc)':'var(--t2)'};margin-bottom:2px;">${day}</div>`;
                for (const p of dayPosts.slice(0, 3)) {
                    const tb = APP.posts._getTypeBadge(p);
                    h += `<div style="font-size:9px;padding:1px 4px;margin-bottom:1px;border-radius:4px;background:${tb.bg};color:${tb.color};white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer;" title="${(p.content||'').replace(/<[^>]*>/g,'').substring(0,80).replace(/"/g,'&quot;')}">${tb.icon} ${(p.content||'').replace(/<[^>]*>/g,'').substring(0,20)}</div>`;
                }
                if (dayPosts.length > 3) {
                    h += `<div style="font-size:9px;color:var(--t3);text-align:center;">+${dayPosts.length - 3}</div>`;
                }
                h += '</div>';
            }
            h += '</div>';
            container.innerHTML = h;
        },

        _calPrev() {
            this._calMonth--;
            if (this._calMonth < 0) { this._calMonth = 11; this._calYear--; }
            this._renderCalendar(document.getElementById('co-calendar'));
        },
        _calNext() {
            this._calMonth++;
            if (this._calMonth > 11) { this._calMonth = 0; this._calYear++; }
            this._renderCalendar(document.getElementById('co-calendar'));
        },
        _calToday() {
            this._calMonth = new Date().getMonth();
            this._calYear = new Date().getFullYear();
            this._renderCalendar(document.getElementById('co-calendar'));
        },
    },

    // ====================================================
    // MODULE GOOGLE POSTS
    // ====================================================
    posts: {
        _locationId: null,
        _editingId: null,
        _selectMode: false,
        _selectedIds: new Set(),
        _posts: [],

        async load(locationId, page) {
            this._locationId = locationId;
            const c = document.getElementById('module-content');
            if (c) c.innerHTML = `<div class="sh"><div class="stit">GOOGLE POSTS</div></div>${APP.skeleton.stats(4)}${APP.skeleton.rows(4)}`;
            const params = new URLSearchParams(window.location.search);
            const statusFilter = params.get('post_status') || 'all';
            if (!page) page = parseInt(params.get('post_page')) || 1;
            const data = await APP.fetch(`/api/posts.php?action=list&location_id=${locationId}&status=${statusFilter}&page=${page}`);
            if (data.error) { console.error('Posts API error:', data.error); return; }
            this.render(data.posts, data.stats, data.pagination, locationId, statusFilter);
            // Auto-ouvrir l'editeur si param edit= dans l'URL (depuis Vue d'ensemble)
            const editId = params.get('edit');
            if (editId) {
                const u = new URL(window.location);
                u.searchParams.delete('edit');
                window.history.replaceState(null, '', u);
                this.editPost(parseInt(editId), locationId);
            }
        },

        render(posts, stats, pagination, locationId, activeStatus) {
            const c = document.getElementById('module-content');
            if (!c) return;
            this._posts = posts;
            const drafts = parseInt(stats.drafts)||0, scheduled = parseInt(stats.scheduled)||0, published = parseInt(stats.published)||0, failed = parseInt(stats.failed)||0;
            const selCount = this._selectedIds.size;

            let h = `<div class="sh" style="flex-wrap:wrap;gap:12px;">
                <div class="stit">GOOGLE POSTS</div>
                <div style="display:flex;gap:10px;align-items:center;">
                    <button class="btn bp bsm" onclick="APP.posts.showCreateForm(${locationId})" title="Créer un article unique sur un sujet précis de votre choix"><svg viewBox="0 0 24 24"><path d="M12 4v16m8-8H4"/></svg> Créer un post</button>
                    <button class="btn bs bsm" onclick="APP.posts.showBatchForm(${locationId})" style="background:linear-gradient(135deg,rgba(0,212,255,.12),rgba(138,43,226,.12));border-color:rgba(0,212,255,.3);" title="Générer plusieurs contenus d'un coup avec l'IA (Articles, FAQ ou Mix)"><svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:var(--acc);fill:none;stroke-width:2;"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg> Générer un lot IA</button>
                    ${drafts > 0 ? `<button class="btn bs bsm" onclick="APP.posts.showBulkScheduleForm(${locationId})" style="border-color:var(--acc);color:var(--acc);">📅 Planifier les brouillons (${drafts})</button>` : ''}
                    ${(drafts > 0 || failed > 0) ? `<button class="btn bs bsm" onclick="APP.posts.publishAll(${locationId})" style="border-color:var(--g);color:var(--g);"><svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg> Publier tout (${drafts + failed})</button>` : ''}
                </div>
            </div>`;

            // Bulk schedule form (hidden)
            if (drafts > 0) {
                const defStart = new Date(Date.now() + 86400000).toISOString().split('T')[0];
                h += `<div id="bulk-schedule-form" style="display:none;padding:20px;border-bottom:1px solid var(--bdr);background:rgba(0,212,255,.03);">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
                        <span style="font-size:16px;">📅</span>
                        <div style="font-weight:600;">Planifier les ${drafts} brouillons</div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                        <div>
                            <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:6px;">Jours de publication</label>
                            <div id="bulk-days" style="display:flex;gap:8px;">
                                <div class="day-btn" data-day="1" onclick="APP.posts.toggleBulkDay(1)">Lu</div>
                                <div class="day-btn" data-day="2" onclick="APP.posts.toggleBulkDay(2)">Ma</div>
                                <div class="day-btn" data-day="3" onclick="APP.posts.toggleBulkDay(3)">Me</div>
                                <div class="day-btn" data-day="4" onclick="APP.posts.toggleBulkDay(4)">Je</div>
                                <div class="day-btn" data-day="5" onclick="APP.posts.toggleBulkDay(5)">Ve</div>
                                <div class="day-btn" data-day="6" onclick="APP.posts.toggleBulkDay(6)">Sa</div>
                                <div class="day-btn" data-day="7" onclick="APP.posts.toggleBulkDay(7)">Di</div>
                            </div>
                        </div>
                        <div style="display:flex;gap:12px;">
                            <div>
                                <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:6px;">Heure</label>
                                <input type="time" id="bulk-time" class="si" value="10:00" style="width:110px;" onchange="APP.posts._updateBulkPreview()">
                            </div>
                            <div>
                                <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:6px;">A partir du</label>
                                <input type="date" id="bulk-start-date" class="si" value="${defStart}" style="width:150px;">
                            </div>
                        </div>
                    </div>
                    <div id="bulk-schedule-preview" style="padding:10px 12px;border-radius:8px;background:var(--bg2);border:1px solid var(--bdr);margin-bottom:16px;font-size:12px;color:var(--t2);font-family:'Space Mono',monospace;">
                        Selectionnez au moins un jour pour voir la planification
                    </div>
                    <div style="display:flex;gap:10px;">
                        <button class="btn bp bsm" onclick="APP.posts.confirmBulkSchedule(${locationId})" id="btn-bulk-schedule">Planifier</button>
                        <button class="btn bs bsm" onclick="document.getElementById('bulk-schedule-form').style.display='none'">Annuler</button>
                    </div>
                </div>`;
            }

            // Stats bar (cliquable pour filtrer)
            h += `<div style="display:flex;gap:16px;padding:16px 20px;border-bottom:1px solid var(--bdr);flex-wrap:wrap;">
                <div class="post-stat" onclick="APP.posts.filterStatus(${locationId},'draft')" style="cursor:pointer;" title="Filtrer les brouillons"><span class="post-stat-n">${drafts}</span><span class="post-stat-l">Brouillons</span></div>
                <div class="post-stat" onclick="APP.posts.filterStatus(${locationId},'scheduled')" style="cursor:pointer;" title="Filtrer les planifiés"><span class="post-stat-n" style="color:var(--acc);">${scheduled}</span><span class="post-stat-l">Planifiés</span></div>
                <div class="post-stat" onclick="APP.posts.filterStatus(${locationId},'published')" style="cursor:pointer;" title="Filtrer les publiés"><span class="post-stat-n" style="color:var(--g);">${published}</span><span class="post-stat-l">Publiés</span></div>
                <div class="post-stat" onclick="APP.posts.filterStatus(${locationId},'failed')" style="cursor:pointer;" title="Filtrer les échoués"><span class="post-stat-n" style="color:var(--r);">${failed}</span><span class="post-stat-l">Échoués</span></div>
            </div>`;
            // Type legend
            h += this._renderTypeLegend();

            // Status filter + sélection
            h += `<div style="display:flex;gap:8px;padding:14px 20px;border-bottom:1px solid var(--bdr);flex-wrap:wrap;align-items:center;">`;
            const statusLabels = {all:'Tous', draft:'Brouillons', scheduled:'Planifiés', published:'Publiés', failed:'Échoués'};
            for (const s of ['all','draft','scheduled','published','failed']) {
                h += `<button class="btn ${s===activeStatus?'bp':'bs'} bsm" onclick="APP.posts.filterStatus(${locationId},'${s}')">${statusLabels[s]}</button>`;
            }
            h += `<div style="margin-left:auto;">
                <button class="btn ${this._selectMode ? 'bp' : 'bs'} bsm" onclick="APP.posts.toggleSelectMode()" style="font-size:11px;">
                    ${this._selectMode ? '✕ Annuler' : '☐ Sélectionner'}
                </button>
            </div>`;
            h += `</div>`;

            // Barre bulk (mode sélection)
            if (this._selectMode) {
                h += `<div style="padding:8px 20px;border-bottom:1px solid var(--bdr);background:rgba(0,212,255,0.05);display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;color:var(--t2);">
                        <input type="checkbox" ${selCount === posts.length && posts.length > 0 ? 'checked' : ''} onchange="APP.posts.${selCount === posts.length && posts.length > 0 ? 'deselectAll' : 'selectAll'}()" style="accent-color:var(--acc);width:16px;height:16px;">
                        Tout sélectionner
                    </label>
                    <span style="font-size:12px;color:var(--acc);font-weight:600;">${selCount} sélectionné${selCount > 1 ? 's' : ''}</span>`;
                if (selCount > 0) {
                    h += `<span style="width:1px;height:20px;background:var(--bdr);margin:0 4px;"></span>
                        <button class="btn bs bsm" onclick="APP.posts.bulkDelete(${locationId})" style="font-size:11px;color:var(--r);border-color:var(--r);">
                            <svg viewBox="0 0 24 24" style="width:12px;height:12px;stroke:var(--r);fill:none;stroke-width:2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                            Supprimer (${selCount})</button>`;
                }
                h += `</div>`;
            }

            // Batch AI generation form (hidden)
            h += `<div id="batch-gen-form" style="display:none;padding:20px;border-bottom:1px solid var(--bdr);background:linear-gradient(135deg,rgba(0,212,255,.03),rgba(138,43,226,.03));">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
                    <svg viewBox="0 0 24 24" style="width:22px;height:22px;stroke:var(--acc);fill:none;stroke-width:2;flex-shrink:0;"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    <div style="font-weight:600;font-size:15px;">Générer un lot de posts avec l'IA</div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                    <div>
                        <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:6px;">Nombre de posts</label>
                        <select id="batch-count" class="si" style="width:100%;" onchange="APP.posts._updateBatchEst()">
                            <option value="4">4 posts</option>
                            <option value="8">8 posts</option>
                            <option value="12">12 posts</option>
                            <option value="16">16 posts</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:6px;">Type de contenu</label>
                        <select id="batch-category" class="si" style="width:100%;" onchange="APP.posts._updateBatchHint()">
                            <option value="articles">📝 Articles & Conseils</option>
                            <option value="faq_ai">🤖 FAQ optimisées IA</option>
                            <option value="mix">🔀 Mix (FAQ + Articles)</option>
                        </select>
                        <div id="batch-category-hint" style="font-size:11px;color:var(--t3);margin-top:6px;line-height:1.4;">Sujets d'articles et conseils d'expert pour démontrer votre expertise locale</div>
                    </div>
                </div>
                <div style="margin-bottom:16px;">
                    <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:6px;">Mots-clés à prioriser <span style="color:var(--t3);">(cliquez pour sélectionner)</span></label>
                    <div id="batch-kw-list" style="display:flex;flex-wrap:wrap;gap:8px;min-height:36px;padding:10px;border:1px solid var(--bdr);border-radius:8px;background:var(--bg2);">
                        <span style="color:var(--t3);font-size:12px;">Chargement des mots-clés...</span>
                    </div>
                </div>
                <div style="margin-bottom:16px;">
                    <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:6px;">Sujets souhaités <span style="color:var(--t3);">(1 par ligne — laissez vide pour laisser l'IA choisir)</span></label>
                    <textarea id="batch-subjects" class="si" placeholder="Ex :&#10;Quel est le meilleur plombier à Marseille ?&#10;Comment déboucher un évier rapidement ?&#10;5 astuces pour entretenir sa chaudière" style="width:100%;height:80px;resize:vertical;font-size:12px;line-height:1.6;"></textarea>
                </div>
                <div style="padding:12px;border-radius:8px;background:rgba(0,212,255,.05);border:1px solid rgba(0,212,255,.1);margin-bottom:16px;font-size:12px;color:var(--t2);line-height:1.7;">
                    <strong style="color:var(--acc);">Comment ça marche ?</strong><br>
                    L'IA génère des sujets basés sur vos mots-clés, puis rédige chaque post individuellement.<br>
                    Les posts seront créés comme <strong>brouillons</strong> — relisez, modifiez et publiez quand vous êtes prêt.<br>
                    <span id="batch-time-est" style="color:var(--t3);">⏱ Temps estimé : ~20 secondes</span>
                </div>
                <div id="batch-gen-progress" style="display:none;margin-bottom:16px;">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                        <svg viewBox="0 0 24 24" class="spin" style="width:18px;height:18px;stroke:var(--acc);fill:none;stroke-width:2;"><path d="M12 2v4m0 12v4m10-10h-4M6 12H2m15.07-5.07l-2.83 2.83M9.76 14.24l-2.83 2.83m0-10.14l2.83 2.83m4.48 4.48l2.83 2.83"/></svg>
                        <span id="batch-gen-status" style="font-size:13px;color:var(--acc);">Génération en cours...</span>
                    </div>
                    <div style="width:100%;height:4px;border-radius:4px;background:rgba(0,212,255,.1);overflow:hidden;">
                        <div id="batch-gen-bar" style="width:0%;height:100%;background:linear-gradient(90deg,var(--acc),#8a2be2);border-radius:4px;transition:width .5s ease;"></div>
                    </div>
                </div>
                <div style="display:flex;gap:10px;" id="batch-gen-buttons">
                    <button class="btn bp bsm" onclick="APP.posts.startBatchGen(${locationId})" id="btn-batch-gen" style="background:var(--primary);border:none;color:#fff;font-weight:600;">
                        <svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:#fff;fill:none;stroke-width:2;"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg> Lancer la génération
                    </button>
                    <button class="btn bs bsm" onclick="APP.posts.hideBatchForm()">Annuler</button>
                </div>
            </div>`;

            // Create/Edit form (hidden)
            h += `<div id="post-create-form" style="display:none;padding:20px;border-bottom:1px solid var(--bdr);background:var(--overlay);">
                <div style="font-weight:600;margin-bottom:12px;" id="post-form-title">Créer un nouveau post</div>
                <input type="hidden" id="post-edit-id" value="">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                    <div>
                        <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Type de post</label>
                        <select id="post-type" class="si" style="width:100%;" onchange="APP.posts.toggleTypeFields()">
                            <option value="STANDARD">Standard (Actualité)</option>
                            <option value="EVENT">Événement</option>
                            <option value="OFFER">Offre / Promotion</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">URL image (optionnel)</label>
                        <input type="url" id="post-image" class="si" placeholder="https://..." style="width:100%;">
                    </div>
                </div>
                <div id="event-fields" style="display:none;margin-bottom:12px;">
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                        <div>
                            <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Titre événement</label>
                            <input type="text" id="post-event-title" class="si" placeholder="Titre de l'événement" style="width:100%;">
                        </div>
                        <div>
                            <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Début</label>
                            <input type="datetime-local" id="post-event-start" class="si" style="width:100%;">
                        </div>
                        <div>
                            <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Fin</label>
                            <input type="datetime-local" id="post-event-end" class="si" style="width:100%;">
                        </div>
                    </div>
                </div>
                <div id="offer-fields" style="display:none;margin-bottom:12px;">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div>
                            <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Code promo</label>
                            <input type="text" id="post-offer-coupon" class="si" placeholder="Ex: ETE2026" style="width:100%;">
                        </div>
                        <div>
                            <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Conditions de l'offre</label>
                            <input type="text" id="post-offer-terms" class="si" placeholder="Ex: Valable jusqu'au 31/03" style="width:100%;">
                        </div>
                    </div>
                </div>
                <div style="margin-bottom:12px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                        <label style="font-size:12px;color:var(--t2);">Contenu du post</label>
                        <span id="post-char-count" style="font-size:11px;color:var(--t3);">0 / 1500</span>
                    </div>
                    <div style="display:flex;gap:8px;margin-bottom:8px;">
                        <input type="text" id="post-ai-subject" class="si" placeholder="Sujet pour la génération IA..." style="flex:1;">
                        <button class="btn bs bsm" onclick="APP.posts.generateContent(${locationId})" id="btn-gen-content"><svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg> Générer avec IA</button>
                    </div>
                    <textarea id="post-content" class="si" placeholder="Rédigez le contenu de votre post Google..." style="width:100%;height:120px;resize:vertical;" oninput="APP.posts.updateCharCount()"></textarea>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                    <div>
                        <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Bouton d'action (CTA)</label>
                        <select id="post-cta-type" class="si" style="width:100%;">
                            <option value="">Aucun</option>
                            <option value="BOOK">Réserver</option>
                            <option value="ORDER">Commander</option>
                            <option value="SHOP">Acheter</option>
                            <option value="LEARN_MORE">En savoir plus</option>
                            <option value="SIGN_UP">S'inscrire</option>
                            <option value="CALL">Appeler</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">URL du bouton</label>
                        <input type="url" id="post-cta-url" class="si" placeholder="https://..." style="width:100%;">
                    </div>
                </div>
                <div style="padding:12px;margin-bottom:12px;border:1px solid var(--bdr);border-radius:8px;background:rgba(0,212,255,.03);">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                        <label style="font-size:12px;color:var(--t2);display:flex;align-items:center;gap:6px;cursor:pointer;">
                            <input type="checkbox" id="post-schedule-toggle" onchange="document.getElementById('post-schedule-fields').style.display=this.checked?'flex':'none'">
                            📅 Planifier la publication
                        </label>
                    </div>
                    <div id="post-schedule-fields" style="display:none;gap:12px;">
                        <div style="flex:1;">
                            <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Date</label>
                            <input type="date" id="post-schedule-date" class="si" value="${new Date().toISOString().split('T')[0]}" style="width:100%;">
                        </div>
                        <div style="flex:1;">
                            <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Heure</label>
                            <input type="time" id="post-schedule-time" class="si" value="09:00" style="width:100%;">
                        </div>
                    </div>
                </div>
                <div style="display:flex;gap:10px;align-items:center;">
                    <button class="btn bp bsm" onclick="APP.posts.save(${locationId},'draft')"><svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg> Sauvegarder brouillon</button>
                    <button class="btn bs bsm" onclick="APP.posts.save(${locationId},'schedule')" style="border-color:var(--acc);color:var(--acc);"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Planifier</button>
                    <button class="btn bs bsm" onclick="APP.posts.save(${locationId},'publish')" style="border-color:var(--g);color:var(--g);"><svg viewBox="0 0 24 24"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg> Publier maintenant</button>
                    <button class="btn bs bsm" onclick="APP.posts.hideCreateForm()">Annuler</button>
                </div>
            </div>`;

            // Posts list
            if (!posts.length) {
                h += `<div style="padding:40px;text-align:center;color:var(--t2);">
                    <svg viewBox="0 0 24 24" style="width:48px;height:48px;stroke:var(--acc);fill:none;stroke-width:1.5;margin-bottom:16px;opacity:.4;"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    <p style="font-size:15px;margin-bottom:6px;">Aucun post pour le moment</p>
                    <p style="font-size:13px;color:var(--t3);">Créez un post ou importez un fichier CSV pour commencer.</p>
                </div>`;
            } else {
                h += '<div class="posts-list">';
                for (const p of posts) {
                    const statusClass = {draft:'ps-draft', scheduled:'ps-scheduled', published:'ps-published', failed:'ps-failed'}[p.status] || '';
                    const statusLabel = {draft:'Brouillon', scheduled:'Planifié', published:'Publié', failed:'Échoué'}[p.status] || p.status;
                    const typeBadge = this._getTypeBadge(p);
                    const fullContent = p.content || '';
                    const isLong = fullContent.length > 200;
                    const shortContent = isLong ? fullContent.substring(0, 200) + '...' : fullContent;
                    const ctaLabels = {BOOK:'Réserver', ORDER:'Commander', SHOP:'Acheter', LEARN_MORE:'En savoir plus', SIGN_UP:"S'inscrire", CALL:'Appeler'};
                    const canSchedule = (p.status==='draft'||p.status==='failed');
                    const dateStr = p.scheduled_at
                        ? '📅 ' + new Date(p.scheduled_at).toLocaleString('fr-FR', {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'})
                        : p.published_at
                        ? '✅ ' + new Date(p.published_at).toLocaleString('fr-FR', {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'})
                        : '';

                    const isSelected = this._selectedIds.has(p.id);
                    const selBorder = isSelected ? 'border:2px solid var(--acc);' : '';
                    const selClick = this._selectMode ? `onclick="APP.posts.toggleSelect(${p.id})" style="cursor:pointer;${selBorder}"` : '';

                    h += `<div class="post-card" ${selClick} ${!this._selectMode ? '' : ''}>
                        <div class="post-card-header">
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;flex:1;">
                                ${this._selectMode ? `<input type="checkbox" ${isSelected ? 'checked' : ''} onchange="APP.posts.toggleSelect(${p.id})" onclick="event.stopPropagation()" style="width:18px;height:18px;accent-color:var(--acc);cursor:pointer;flex-shrink:0;">` : ''}
                                <span class="ps ${statusClass}">${statusLabel}</span>
                                <span class="ps post-type-badge" style="background:${typeBadge.bg};color:${typeBadge.color};border-color:${typeBadge.color};">${typeBadge.icon} ${typeBadge.label}</span>
                                ${p.call_to_action_type ? `<span style="font-size:11px;color:var(--t3);">🔗 ${ctaLabels[p.call_to_action_type]||p.call_to_action_type}</span>` : ''}
                                ${dateStr ? `<span style="font-size:11px;color:var(--t3);margin-left:auto;white-space:nowrap;">${dateStr}</span>` : ''}
                            </div>
                            ${!this._selectMode ? `<div style="display:flex;gap:6px;flex-wrap:wrap;">
                                ${(p.status==='draft'||p.status==='scheduled'||p.status==='failed') ? `<button class="btn bs bsm" onclick="APP.posts.editPost(${p.id},${locationId})" style="font-size:11px;padding:3px 10px;">Modifier</button>` : ''}
                                ${canSchedule ? `<button class="btn bs bsm" onclick="APP.posts.toggleScheduleRow(${p.id})" style="font-size:11px;padding:3px 10px;border-color:var(--acc);color:var(--acc);">📅 Planifier</button>` : ''}
                                ${(p.status!=='published') ? `<button class="btn bs bsm" onclick="APP.posts.publish(${p.id},${locationId})" style="font-size:11px;padding:3px 10px;border-color:var(--g);color:var(--g);">Publier</button>` : ''}
                                <button class="btn bs bsm" onclick="APP.posts.remove(${p.id},${locationId})" style="font-size:11px;padding:3px 10px;opacity:.5;" title="Supprimer">✕</button>
                            </div>` : ''}
                        </div>`;

                    // Schedule inline picker (hidden)
                    if (canSchedule) {
                        const defDate = new Date(Date.now() + 86400000).toISOString().split('T')[0]; // demain
                        h += `<div id="schedule-row-${p.id}" style="display:none;padding:8px 16px;background:rgba(0,212,255,.04);border-bottom:1px solid var(--bdr);display:none;">
                            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                <span style="font-size:12px;color:var(--t2);">Planifier pour :</span>
                                <input type="date" id="sched-date-${p.id}" class="si" value="${defDate}" style="padding:4px 8px;font-size:12px;width:140px;">
                                <input type="time" id="sched-time-${p.id}" class="si" value="10:00" style="padding:4px 8px;font-size:12px;width:100px;">
                                <button class="btn bp bsm" onclick="APP.posts.schedulePost(${p.id},${locationId})" style="font-size:11px;padding:4px 12px;">Confirmer</button>
                                <button class="btn bs bsm" onclick="document.getElementById('schedule-row-${p.id}').style.display='none'" style="font-size:11px;padding:4px 8px;opacity:.6;">✕</button>
                            </div>
                        </div>`;
                    }

                    if (p.image_url) {
                        h += `<div class="post-card-image"><img src="${this.esc(p.image_url)}" alt="" onerror="this.parentElement.style.display='none'"></div>`;
                    }

                    // Content with expand/collapse
                    h += `<div class="post-card-content">
                        <div id="post-content-short-${p.id}">${this.esc(shortContent)}</div>
                        ${isLong ? `<div id="post-content-full-${p.id}" style="display:none;white-space:pre-wrap;">${this.esc(fullContent)}</div>
                        <button onclick="APP.posts.toggleContent(${p.id})" id="post-toggle-${p.id}" style="background:none;border:none;color:var(--acc);font-size:11px;cursor:pointer;padding:4px 0;margin-top:4px;font-family:inherit;">▼ Voir plus</button>` : ''}
                    </div>`;

                    // Meta info
                    h += `<div class="post-card-meta">`;
                    if (p.status === 'scheduled' && p.scheduled_at) {
                        h += `<span>📅 ${new Date(p.scheduled_at).toLocaleString('fr-FR', {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'})}</span>`;
                    }
                    if (p.status === 'published' && p.published_at) {
                        h += `<span>✅ Publié le ${new Date(p.published_at).toLocaleString('fr-FR', {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'})}</span>`;
                    }
                    if (p.created_at) {
                        h += `<span style="color:var(--t3);">Créé le ${new Date(p.created_at).toLocaleDateString('fr-FR')}</span>`;
                    }
                    h += `</div>`;

                    // Error message
                    if (p.status === 'failed' && p.error_message) {
                        h += `<div class="post-card-error">⚠ ${this.esc(p.error_message)}</div>`;
                    }

                    // Event info
                    if (p.post_type === 'EVENT' && p.event_title) {
                        h += `<div style="padding:0 16px 12px;font-size:12px;color:var(--acc);">📌 ${this.esc(p.event_title)}`;
                        if (p.event_start) h += ` — ${new Date(p.event_start).toLocaleString('fr-FR', {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'})}`;
                        h += `</div>`;
                    }

                    // Offer info
                    if (p.post_type === 'OFFER' && p.offer_coupon_code) {
                        h += `<div style="padding:0 16px 12px;font-size:12px;color:var(--o);">🏷 Code: <strong>${this.esc(p.offer_coupon_code)}</strong>${p.offer_terms ? ' — ' + this.esc(p.offer_terms) : ''}</div>`;
                    }

                    h += `</div>`;
                }
                h += '</div>';

                // Pagination
                if (pagination.pages > 1) {
                    h += `<div style="display:flex;justify-content:center;gap:8px;padding:20px;">`;
                    for (let i = 1; i <= pagination.pages; i++) {
                        h += `<button class="btn ${i===pagination.page?'bp':'bs'} bsm" onclick="APP.posts.goToPage(${locationId},${i},'${activeStatus}')">${i}</button>`;
                    }
                    h += `</div>`;
                }
            }

            c.innerHTML = h;
        },

        esc(s) { if(!s)return''; const d=document.createElement('div');d.textContent=s;return d.innerHTML; },

        _getTypeBadge(p) {
            if (p.post_type === 'EVENT') return { icon:'\u{1F4CC}', label:'Événement', color:'var(--o)', bg:'rgba(255,152,0,.08)' };
            if (p.post_type === 'OFFER') return { icon:'\u{1F3F7}', label:'Offre', color:'var(--o)', bg:'rgba(255,152,0,.08)' };
            if (p.list_id) return { icon:'\u{1F504}', label:'Auto-list', color:'var(--g)', bg:'rgba(76,175,80,.08)' };
            if (p.generation_category === 'faq_ai') return { icon:'\u{1F916}', label:'FAQ IA', color:'#8a2be2', bg:'rgba(138,43,226,.08)' };
            if (p.generation_category === 'mix') return { icon:'\u{1F500}', label:'Mix IA', color:'#e91e63', bg:'rgba(233,30,99,.08)' };
            return { icon:'\u{1F4DD}', label:'Article', color:'var(--acc)', bg:'rgba(0,212,255,.08)' };
        },

        _renderTypeLegend() {
            const types = [
                { icon:'📝', label:'Article', color:'var(--acc)' },
                { icon:'🤖', label:'FAQ IA', color:'#8a2be2' },
                { icon:'🔀', label:'Mix IA', color:'#e91e63' },
                { icon:'📌', label:'Événement', color:'var(--o)' },
                { icon:'🏷', label:'Offre', color:'var(--o)' },
                { icon:'🔄', label:'Auto-list', color:'var(--g)' },
            ];
            let h = '<div class="post-type-legend">';
            for (const t of types) {
                h += `<span class="post-type-legend-item"><span style="color:${t.color};">${t.icon}</span> ${t.label}</span>`;
            }
            h += '</div>';
            return h;
        },

        filterStatus(lid, status) {
            const u = new URL(window.location);
            u.searchParams.set('post_status', status);
            u.searchParams.delete('post_page');
            window.history.replaceState(null, '', u);
            this.load(lid);
        },

        goToPage(lid, page, status) {
            const u = new URL(window.location);
            u.searchParams.set('post_page', page);
            if (status && status !== 'all') u.searchParams.set('post_status', status);
            window.history.replaceState(null, '', u);
            this.load(lid, page);
        },

        toggleTypeFields() {
            const type = document.getElementById('post-type').value;
            document.getElementById('event-fields').style.display = type === 'EVENT' ? 'block' : 'none';
            document.getElementById('offer-fields').style.display = type === 'OFFER' ? 'block' : 'none';
        },

        updateCharCount() {
            const content = document.getElementById('post-content').value;
            const count = content.length;
            const el = document.getElementById('post-char-count');
            el.textContent = `${count} / 1500`;
            el.style.color = count > 1500 ? 'var(--r)' : count > 1200 ? 'var(--o)' : 'var(--t3)';
        },

        showCreateForm(lid) {
            this.hideBatchForm();
            const bulkForm = document.getElementById('bulk-schedule-form');
            if (bulkForm) bulkForm.style.display = 'none';
            this._editingId = null;
            document.getElementById('post-form-title').textContent = 'Créer un nouveau post';
            document.getElementById('post-edit-id').value = '';
            document.getElementById('post-type').value = 'STANDARD';
            document.getElementById('post-content').value = '';
            document.getElementById('post-image').value = '';
            document.getElementById('post-cta-type').value = '';
            document.getElementById('post-cta-url').value = '';
            document.getElementById('post-ai-subject').value = '';
            document.getElementById('post-event-title').value = '';
            document.getElementById('post-event-start').value = '';
            document.getElementById('post-event-end').value = '';
            document.getElementById('post-offer-coupon').value = '';
            document.getElementById('post-offer-terms').value = '';
            document.getElementById('post-schedule-toggle').checked = false;
            document.getElementById('post-schedule-fields').style.display = 'none';
            document.getElementById('post-schedule-date').value = new Date().toISOString().split('T')[0];
            document.getElementById('post-schedule-time').value = '09:00';
            this.toggleTypeFields();
            this.updateCharCount();
            const form = document.getElementById('post-create-form');
            form.style.display = 'block';
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
            document.getElementById('post-ai-subject').focus();
        },

        hideCreateForm() {
            document.getElementById('post-create-form').style.display = 'none';
            this._editingId = null;
        },

        async editPost(postId, lid) {
            const data = await APP.fetch(`/api/posts.php?action=get&location_id=${lid}&post_id=${postId}`);
            if (!data.success || !data.post) { APP.toast(data.error || 'Post non trouvé', 'error'); return; }

            const p = data.post;
            this._editingId = postId;
            document.getElementById('post-form-title').textContent = 'Modifier le post #' + postId;
            document.getElementById('post-edit-id').value = postId;
            document.getElementById('post-type').value = p.post_type || 'STANDARD';
            document.getElementById('post-content').value = p.content || '';
            document.getElementById('post-image').value = p.image_url || '';
            document.getElementById('post-cta-type').value = p.call_to_action_type || '';
            document.getElementById('post-cta-url').value = p.call_to_action_url || '';
            document.getElementById('post-ai-subject').value = '';
            document.getElementById('post-event-title').value = p.event_title || '';
            document.getElementById('post-event-start').value = p.event_start ? p.event_start.replace(' ', 'T').substring(0, 16) : '';
            document.getElementById('post-event-end').value = p.event_end ? p.event_end.replace(' ', 'T').substring(0, 16) : '';
            document.getElementById('post-offer-coupon').value = p.offer_coupon_code || '';
            document.getElementById('post-offer-terms').value = p.offer_terms || '';

            // Planification
            if (p.status === 'scheduled' && p.scheduled_at) {
                document.getElementById('post-schedule-toggle').checked = true;
                document.getElementById('post-schedule-fields').style.display = 'flex';
                const dt = p.scheduled_at.replace(' ', 'T').substring(0, 16).split('T');
                document.getElementById('post-schedule-date').value = dt[0] || '';
                document.getElementById('post-schedule-time').value = dt[1] || '09:00';
            } else {
                document.getElementById('post-schedule-toggle').checked = false;
                document.getElementById('post-schedule-fields').style.display = 'none';
            }

            this.toggleTypeFields();
            this.updateCharCount();
            const editForm = document.getElementById('post-create-form');
            editForm.style.display = 'block';
            editForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },

        async save(lid, mode) {
            const content = document.getElementById('post-content').value.trim();
            if (!content) { APP.toast('Le contenu du post est requis', 'warning'); return; }
            if (content.length > 1500) { APP.toast('Le contenu dépasse 1500 caractères', 'warning'); return; }

            // Vérifier la planification si mode = schedule
            let scheduledAt = null;
            if (mode === 'schedule') {
                const schedDate = document.getElementById('post-schedule-date').value;
                const schedTime = document.getElementById('post-schedule-time').value;
                if (!schedDate || !schedTime) {
                    APP.toast('Veuillez sélectionner une date et une heure de publication', 'warning');
                    document.getElementById('post-schedule-toggle').checked = true;
                    document.getElementById('post-schedule-fields').style.display = 'flex';
                    return;
                }
                scheduledAt = `${schedDate} ${schedTime}:00`;
            }

            const fd = new FormData();
            fd.append('action', 'save');
            fd.append('location_id', lid);
            fd.append('post_type', document.getElementById('post-type').value);
            fd.append('content', content);
            fd.append('image_url', document.getElementById('post-image').value.trim());
            fd.append('cta_type', document.getElementById('post-cta-type').value);
            fd.append('cta_url', document.getElementById('post-cta-url').value.trim());
            fd.append('event_title', document.getElementById('post-event-title').value.trim());
            fd.append('event_start', document.getElementById('post-event-start').value);
            fd.append('event_end', document.getElementById('post-event-end').value);
            fd.append('offer_coupon_code', document.getElementById('post-offer-coupon').value.trim());
            fd.append('offer_terms', document.getElementById('post-offer-terms').value.trim());

            if (mode === 'schedule' && scheduledAt) {
                fd.append('status', 'scheduled');
                fd.append('scheduled_at', scheduledAt);
            } else {
                fd.append('status', 'draft');
            }

            const editId = document.getElementById('post-edit-id').value;
            if (editId) fd.append('post_id', editId);

            const d = await APP.fetch('/api/posts.php', { method: 'POST', body: fd });

            if (d.success) {
                if (mode === 'publish') {
                    // Sauvegarder d'abord puis publier
                    const postId = d.id;
                    await this.publish(postId, lid);
                } else {
                    this.hideCreateForm();
                    this.load(lid);
                }
            } else {
                APP.toast(d.error || 'Erreur lors de la sauvegarde', 'error');
            }
        },

        async publish(postId, lid) {
            if (!await APP.modal.confirm('Publier', 'Publier ce post sur Google Business Profile ?', 'Publier')) return;

            const fd = new FormData();
            fd.append('action', 'publish');
            fd.append('location_id', lid);
            fd.append('post_id', postId);

            const btn = event?.target;
            if (btn) { btn.disabled = true; btn.textContent = 'Publication...'; }

            const d = await APP.fetch('/api/posts.php', { method: 'POST', body: fd });

            if (btn) { btn.disabled = false; }

            if (d.success) {
                this.hideCreateForm();
                this.load(lid);
            } else {
                APP.toast(d.error || 'Erreur de publication', 'error');
                this.load(lid);
            }
        },

        async publishAll(lid) {
            if (!await APP.modal.confirm('Publier tout', 'Publier tous les brouillons et posts échoués sur Google ?', 'Publier tout')) return;

            const fd = new FormData();
            fd.append('action', 'publish_all');
            fd.append('location_id', lid);

            const d = await APP.fetch('/api/posts.php', { method: 'POST', body: fd });

            if (d.success) {
                APP.toast(d.message, 'success');
                this.load(lid);
            } else {
                APP.toast(d.error || 'Erreur', 'error');
                this.load(lid);
            }
        },

        async remove(postId, lid) {
            if (!await APP.modal.confirm('Supprimer', 'Supprimer ce post ? Cette action est irréversible.', 'Supprimer', true)) return;

            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('location_id', lid);
            fd.append('post_id', postId);

            const d = await APP.fetch('/api/posts.php', { method: 'POST', body: fd });
            if (d.success) { this.load(lid); }
            else { APP.toast(d.error || 'Erreur', 'error'); }
        },

        // ---- Bulk selection ----
        toggleSelectMode() {
            this._selectMode = !this._selectMode;
            this._selectedIds = new Set();
            this.load(this._locationId);
        },

        toggleSelect(id) {
            if (this._selectedIds.has(id)) this._selectedIds.delete(id);
            else this._selectedIds.add(id);
            this.load(this._locationId);
        },

        selectAll() {
            this._selectedIds = new Set(this._posts.map(p => p.id));
            this.load(this._locationId);
        },

        deselectAll() {
            this._selectedIds = new Set();
            this.load(this._locationId);
        },

        async bulkDelete(lid) {
            const count = this._selectedIds.size;
            if (!count) return;
            if (!await APP.modal.confirm('Supprimer', `Supprimer ${count} post${count > 1 ? 's' : ''} ? Cette action est irréversible.`, 'Supprimer', true)) return;

            const fd = new FormData();
            fd.append('action', 'bulk_delete');
            fd.append('location_id', lid);
            for (const id of this._selectedIds) {
                fd.append('post_ids[]', id);
            }

            const d = await APP.fetch('/api/posts.php', { method: 'POST', body: fd });
            if (d.success) {
                APP.toast(`${d.deleted} post${d.deleted > 1 ? 's' : ''} supprimé${d.deleted > 1 ? 's' : ''}`, 'success');
                this._selectMode = false;
                this._selectedIds = new Set();
                this.load(lid);
            } else {
                APP.toast(d.error || 'Erreur', 'error');
            }
        },

        async generateContent(lid) {
            const subject = document.getElementById('post-ai-subject').value.trim();
            if (!subject) { APP.toast('Entrez un sujet pour la génération IA', 'warning'); document.getElementById('post-ai-subject').focus(); return; }

            const btn = document.getElementById('btn-gen-content');
            const textarea = document.getElementById('post-content');
            btn.disabled = true;
            btn.innerHTML = '<svg viewBox="0 0 24 24" class="spin"><path d="M12 2v4m0 12v4m10-10h-4M6 12H2m15.07-5.07l-2.83 2.83M9.76 14.24l-2.83 2.83m0-10.14l2.83 2.83m4.48 4.48l2.83 2.83"/></svg> Génération...';

            const fd = new FormData();
            fd.append('action', 'generate_content');
            fd.append('location_id', lid);
            fd.append('subject', subject);
            fd.append('post_type', document.getElementById('post-type').value);

            const d = await APP.fetch('/api/posts.php', { method: 'POST', body: fd });

            btn.disabled = false;
            btn.innerHTML = '<svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg> Générer avec IA';

            if (d.success) {
                textarea.value = d.content;
                this.updateCharCount();
            } else {
                APP.toast(d.error || 'Erreur de génération', 'error');
            }
        },

        // ---- Déplier / Replier contenu ----
        toggleContent(postId) {
            const short = document.getElementById(`post-content-short-${postId}`);
            const full = document.getElementById(`post-content-full-${postId}`);
            const btn = document.getElementById(`post-toggle-${postId}`);
            if (!short || !full || !btn) return;
            const isExpanded = full.style.display !== 'none';
            if (isExpanded) {
                short.style.display = '';
                full.style.display = 'none';
                btn.textContent = '▼ Voir plus';
            } else {
                short.style.display = 'none';
                full.style.display = '';
                btn.textContent = '▲ Réduire';
            }
        },

        // ---- Planification rapide d'un post ----
        toggleScheduleRow(postId) {
            const row = document.getElementById(`schedule-row-${postId}`);
            if (row) row.style.display = row.style.display === 'none' ? 'block' : 'none';
        },

        async schedulePost(postId, lid) {
            const dateVal = document.getElementById(`sched-date-${postId}`)?.value;
            const timeVal = document.getElementById(`sched-time-${postId}`)?.value;
            if (!dateVal || !timeVal) { APP.toast('Sélectionnez une date et une heure', 'warning'); return; }

            const scheduledAt = `${dateVal} ${timeVal}`;
            // Vérif future (côté client)
            if (new Date(scheduledAt) <= new Date()) {
                APP.toast('La date doit être dans le futur', 'warning');
                return;
            }

            const fd = new FormData();
            fd.append('action', 'schedule_post');
            fd.append('location_id', lid);
            fd.append('post_id', postId);
            fd.append('scheduled_at', scheduledAt);

            const d = await APP.fetch('/api/posts.php', { method: 'POST', body: fd });
            if (d.success) {
                APP.toast('Post planifié !', 'success');
                this.load(lid);
            } else {
                APP.toast(d.error || 'Erreur de planification', 'error');
            }
        },

        // ---- Planification en masse ----
        _bulkSelectedDays: [],

        showBulkScheduleForm(lid) {
            this.hideCreateForm();
            this.hideBatchForm();
            this._bulkSelectedDays = [1]; // lundi par defaut
            const form = document.getElementById('bulk-schedule-form');
            if (form) {
                form.style.display = 'block';
                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                this._renderBulkDays();
                this._updateBulkPreview();
            }
        },

        toggleBulkDay(day) {
            const idx = this._bulkSelectedDays.indexOf(day);
            if (idx >= 0) this._bulkSelectedDays.splice(idx, 1);
            else this._bulkSelectedDays.push(day);
            this._bulkSelectedDays.sort();
            this._renderBulkDays();
            this._updateBulkPreview();
        },

        _renderBulkDays() {
            document.querySelectorAll('#bulk-days .day-btn').forEach(btn => {
                const d = parseInt(btn.dataset.day);
                if (this._bulkSelectedDays.includes(d)) btn.classList.add('selected');
                else btn.classList.remove('selected');
            });
        },

        _updateBulkPreview() {
            const preview = document.getElementById('bulk-schedule-preview');
            if (!preview) return;
            const days = this._bulkSelectedDays;
            if (!days.length) {
                preview.textContent = 'Selectionnez au moins un jour pour voir la planification';
                return;
            }
            // Compter les brouillons
            const draftCount = parseInt(document.querySelector('.post-stat-n')?.textContent) || 0;
            const perWeek = days.length;
            const weeks = Math.ceil(draftCount / perWeek);
            const dayNames = {1:'Lundi',2:'Mardi',3:'Mercredi',4:'Jeudi',5:'Vendredi',6:'Samedi',7:'Dimanche'};
            const dayLabels = days.map(d => dayNames[d]).join(', ');
            const time = document.getElementById('bulk-time')?.value || '10:00';
            preview.innerHTML = `<strong>${draftCount} brouillons</strong> → ${perWeek} post${perWeek>1?'s':''}/semaine (${dayLabels}) a ${time}<br><span style="color:var(--acc);">Publication etalee sur ${weeks} semaine${weeks>1?'s':''}</span>`;
        },

        async confirmBulkSchedule(lid) {
            if (!this._bulkSelectedDays.length) {
                APP.toast('Selectionnez au moins un jour', 'warning');
                return;
            }
            const btn = document.getElementById('btn-bulk-schedule');
            btn.disabled = true;
            btn.textContent = 'Planification...';

            const fd = new FormData();
            fd.append('action', 'schedule_bulk');
            fd.append('location_id', lid);
            fd.append('days', this._bulkSelectedDays.join(','));
            fd.append('time', document.getElementById('bulk-time').value);
            fd.append('start_date', document.getElementById('bulk-start-date').value);

            const d = await APP.fetch('/api/posts.php', { method: 'POST', body: fd });
            btn.disabled = false;
            btn.textContent = 'Planifier';

            if (d.success) {
                APP.toast(`${d.scheduled_count} posts planifies !`, 'success');
                document.getElementById('bulk-schedule-form').style.display = 'none';
                this.load(lid);
            } else {
                APP.toast(d.error || 'Erreur', 'error');
            }
        },

        // ---- Génération batch IA ----
        _batchSelectedKw: [],

        async showBatchForm(lid) {
            this.hideCreateForm();
            const bulkForm = document.getElementById('bulk-schedule-form');
            if (bulkForm) bulkForm.style.display = 'none';
            this._batchSelectedKw = [];
            const form = document.getElementById('batch-gen-form');
            if (form) {
                form.style.display = 'block';
                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                document.getElementById('batch-gen-progress').style.display = 'none';
                document.getElementById('batch-gen-buttons').style.display = 'flex';
                document.getElementById('btn-batch-gen').disabled = false;
                document.getElementById('batch-subjects').value = '';
                this._updateBatchEst();
            }
            // Charger les mots-clés de la fiche
            const kwContainer = document.getElementById('batch-kw-list');
            kwContainer.innerHTML = '<span style="color:var(--t3);font-size:12px;">Chargement...</span>';
            const data = await APP.fetch(`/api/keywords.php?location_id=${lid}`);
            const keywords = data.keywords || [];
            if (!keywords.length) {
                kwContainer.innerHTML = '<span style="color:var(--t3);font-size:12px;">Aucun mot-clé suivi — les posts seront basés sur votre activité</span>';
                return;
            }
            let kwH = '';
            for (const kw of keywords) {
                kwH += `<button type="button" class="batch-kw-chip" data-kw="${this.esc(kw.keyword)}" onclick="APP.posts.toggleBatchKw(this)" style="padding:5px 12px;border-radius:20px;font-size:12px;border:1px solid var(--bdr);background:var(--bg);color:var(--t2);cursor:pointer;transition:all .2s;font-family:'Space Mono',monospace;">${this.esc(kw.keyword)}</button>`;
            }
            kwH += `<button type="button" class="batch-kw-chip-all" onclick="APP.posts.selectAllBatchKw()" style="padding:5px 12px;border-radius:20px;font-size:11px;border:1px dashed var(--acc);background:transparent;color:var(--acc);cursor:pointer;">Tout sélectionner</button>`;
            kwContainer.innerHTML = kwH;
        },

        hideBatchForm() {
            const form = document.getElementById('batch-gen-form');
            if (form) form.style.display = 'none';
        },

        toggleBatchKw(btn) {
            const kw = btn.dataset.kw;
            const idx = this._batchSelectedKw.indexOf(kw);
            if (idx >= 0) {
                this._batchSelectedKw.splice(idx, 1);
                btn.style.background = 'var(--bg)';
                btn.style.borderColor = 'var(--bdr)';
                btn.style.color = 'var(--t2)';
            } else {
                this._batchSelectedKw.push(kw);
                btn.style.background = 'rgba(0,212,255,.15)';
                btn.style.borderColor = 'var(--acc)';
                btn.style.color = 'var(--acc)';
            }
        },

        selectAllBatchKw() {
            this._batchSelectedKw = [];
            document.querySelectorAll('.batch-kw-chip').forEach(btn => {
                const kw = btn.dataset.kw;
                if (kw) {
                    this._batchSelectedKw.push(kw);
                    btn.style.background = 'rgba(0,212,255,.15)';
                    btn.style.borderColor = 'var(--acc)';
                    btn.style.color = 'var(--acc)';
                }
            });
        },

        _updateBatchEst() {
            const count = parseInt(document.getElementById('batch-count')?.value || 4);
            const secs = count * 4 + 5;
            const el = document.getElementById('batch-time-est');
            if (el) el.textContent = `⏱ Temps estimé : ~${secs} secondes`;
        },

        _updateBatchHint() {
            const hints = {
                articles: "Sujets d'articles et conseils d'expert pour démontrer votre expertise locale",
                faq_ai: "Questions FAQ optimisées pour apparaître dans les résultats de recherche IA (ChatGPT, Gemini...)",
                mix: "Alternance entre FAQ IA et articles pour une stratégie de contenu équilibrée"
            };
            const sel = document.getElementById('batch-category');
            const el = document.getElementById('batch-category-hint');
            if (sel && el) el.textContent = hints[sel.value] || '';
        },

        async startBatchGen(lid) {
            const count = parseInt(document.getElementById('batch-count').value);
            const category = document.getElementById('batch-category').value;
            const subjects = document.getElementById('batch-subjects').value.trim();
            const keywords = this._batchSelectedKw.join(',');

            const btn = document.getElementById('btn-batch-gen');
            btn.disabled = true;

            // Afficher la progress bar
            document.getElementById('batch-gen-progress').style.display = 'block';
            document.getElementById('batch-gen-buttons').style.display = 'none';
            const statusEl = document.getElementById('batch-gen-status');
            const barEl = document.getElementById('batch-gen-bar');

            statusEl.textContent = 'Génération des sujets...';
            statusEl.style.color = 'var(--acc)';
            barEl.style.width = '5%';

            // Animation progressive
            const totalEst = count * 4 + 5;
            let elapsed = 0;
            const progressTimer = setInterval(() => {
                elapsed += 0.5;
                const pct = Math.min(90, (elapsed / totalEst) * 90);
                barEl.style.width = pct + '%';
                if (elapsed < totalEst * 0.25) {
                    statusEl.textContent = 'Génération des sujets...';
                } else {
                    const done = Math.min(count, Math.floor((elapsed - totalEst * 0.2) / 4));
                    statusEl.textContent = `Rédaction des posts... (${done}/${count})`;
                }
            }, 500);

            try {
                const fd = new FormData();
                fd.append('action', 'batch_generate');
                fd.append('location_id', lid);
                fd.append('count', count);
                fd.append('category', category);
                if (keywords) fd.append('keywords', keywords);
                if (subjects) fd.append('subjects', subjects);

                const d = await APP.fetch('/api/posts.php', { method: 'POST', body: fd });

                clearInterval(progressTimer);
                barEl.style.width = '100%';

                if (d.success) {
                    statusEl.textContent = `✅ ${d.generated} posts générés en brouillon !`;
                    statusEl.style.color = 'var(--g)';
                    APP.toast(`${d.generated} posts générés avec succès !`, 'success');
                    setTimeout(() => {
                        this.hideBatchForm();
                        this.load(lid);
                    }, 1500);
                } else {
                    statusEl.textContent = '❌ ' + (d.error || 'Erreur de génération');
                    statusEl.style.color = 'var(--r)';
                    APP.toast(d.error || 'Erreur de génération', 'error');
                    setTimeout(() => {
                        document.getElementById('batch-gen-progress').style.display = 'none';
                        document.getElementById('batch-gen-buttons').style.display = 'flex';
                        btn.disabled = false;
                    }, 3000);
                }
            } catch (e) {
                clearInterval(progressTimer);
                statusEl.textContent = '❌ Erreur réseau ou timeout';
                statusEl.style.color = 'var(--r)';
                APP.toast('Erreur réseau — réessayez', 'error');
                setTimeout(() => {
                    document.getElementById('batch-gen-progress').style.display = 'none';
                    document.getElementById('batch-gen-buttons').style.display = 'flex';
                    btn.disabled = false;
                }, 3000);
            }
        },

    },

    // ====================================================
    // MODULE AUTO LISTS
    // ====================================================
    postLists: {
        _locationId: null,
        _currentListId: null,
        _selectedDays: [],
        _timeSlots: ['09:00'],
        _postsCache: [],
        _selectMode: false,
        _selectedIds: new Set(),

        async load(locationId) {
            this._locationId = locationId;
            const data = await APP.fetch(`/api/post-lists.php?action=list&location_id=${locationId}`);
            if (data.error) { console.error('Post Lists API error:', data.error); return; }
            this.render(data.lists, data.stats, locationId);
        },

        render(lists, stats, locationId) {
            const c = document.getElementById('module-content');
            if (!c) return;

            let h = `<div class="sh" style="flex-wrap:wrap;gap:12px;">
                <div class="stit">AUTO LISTS</div>
                <div style="display:flex;gap:10px;align-items:center;">
                    <button class="btn bp bsm" onclick="APP.postLists.showCreateForm(${locationId})"><svg viewBox="0 0 24 24"><path d="M12 4v16m8-8H4"/></svg> Créer une liste</button>
                </div>
            </div>`;

            // Stats
            h += `<div style="display:flex;gap:16px;padding:16px 20px;border-bottom:1px solid var(--bdr);flex-wrap:wrap;">
                <div class="post-stat"><span class="post-stat-n">${stats.total||0}</span><span class="post-stat-l">Listes</span></div>
                <div class="post-stat"><span class="post-stat-n" style="color:var(--g);">${stats.active||0}</span><span class="post-stat-l">Actives</span></div>
                <div class="post-stat"><span class="post-stat-n" style="color:var(--acc);">${stats.total_pending||0}</span><span class="post-stat-l">Posts en attente</span></div>
            </div>`;

            // Create form (hidden)
            h += `<div id="list-create-form" style="display:none;padding:20px;border-bottom:1px solid var(--bdr);background:var(--overlay);">
                <div style="font-weight:600;margin-bottom:16px;" id="list-form-title">Créer une nouvelle liste</div>
                <input type="hidden" id="list-edit-id" value="">
                <div style="margin-bottom:16px;">
                    <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Nom de la liste</label>
                    <input type="text" id="list-name" class="si" placeholder="Ex: Promotions Décembre, Posts SEO hebdo..." style="width:100%;">
                </div>
                <div style="margin-bottom:16px;">
                    <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:8px;">Jours de publication</label>
                    <div id="list-days" style="display:flex;gap:8px;">
                        <div class="day-btn" data-day="1" onclick="APP.postLists.toggleDay(1)">Lu</div>
                        <div class="day-btn" data-day="2" onclick="APP.postLists.toggleDay(2)">Ma</div>
                        <div class="day-btn" data-day="3" onclick="APP.postLists.toggleDay(3)">Me</div>
                        <div class="day-btn" data-day="4" onclick="APP.postLists.toggleDay(4)">Je</div>
                        <div class="day-btn" data-day="5" onclick="APP.postLists.toggleDay(5)">Ve</div>
                        <div class="day-btn" data-day="6" onclick="APP.postLists.toggleDay(6)">Sa</div>
                        <div class="day-btn" data-day="7" onclick="APP.postLists.toggleDay(7)">Di</div>
                    </div>
                </div>
                <div style="margin-bottom:16px;">
                    <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:8px;">Horaires de publication</label>
                    <div id="list-times"></div>
                    <button class="btn bs bsm" onclick="APP.postLists.addTimeSlot()" style="margin-top:8px;font-size:11px;">+ Ajouter un horaire</button>
                </div>
                <div style="margin-bottom:16px;display:flex;align-items:center;gap:12px;">
                    <div class="list-toggle" id="list-repeat-toggle" onclick="APP.postLists.toggleRepeatForm()"></div>
                    <label style="font-size:13px;color:var(--t2);cursor:pointer;" onclick="APP.postLists.toggleRepeatForm()">Répéter les posts en boucle une fois tous publiés</label>
                </div>
                <div style="display:flex;gap:10px;">
                    <button class="btn bp bsm" onclick="APP.postLists.saveList(${locationId})" id="btn-save-list">Créer la liste</button>
                    <button class="btn bs bsm" onclick="APP.postLists.hideCreateForm()">Annuler</button>
                </div>
            </div>`;

            // Lists
            if (!lists.length) {
                h += `<div style="padding:40px;text-align:center;color:var(--t2);">
                    <svg viewBox="0 0 24 24" style="width:48px;height:48px;stroke:var(--acc);fill:none;stroke-width:1.5;margin-bottom:16px;opacity:.4;"><path d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                    <p style="font-size:15px;margin-bottom:6px;">Aucune liste automatique</p>
                    <p style="font-size:13px;color:var(--t3);">Créez une liste pour organiser et planifier vos posts Google automatiquement.</p>
                </div>`;
            } else {
                for (const l of lists) {
                    const isActive = parseInt(l.is_active);
                    const isRepeat = parseInt(l.is_repeat);
                    const postCount = parseInt(l.post_count)||0;
                    const pubCount = parseInt(l.published_count)||0;
                    const pendCount = parseInt(l.pending_count)||0;
                    const failCount = parseInt(l.failed_count)||0;

                    h += `<div class="list-card ${!isActive ? 'list-inactive' : ''}">
                        <div class="list-card-header">
                            <div style="display:flex;align-items:center;gap:12px;">
                                <div class="list-toggle ${isActive?'active':''}" onclick="APP.postLists.toggleActive(${l.id},${locationId})" title="${isActive?'Désactiver':'Activer'}"></div>
                                <div>
                                    <div class="list-card-name">${this.esc(l.name)}</div>
                                    <div class="list-card-schedule">${this.formatDays(l.schedule_days)} à ${l.schedule_times.replace(/,/g, ', ')} ${isRepeat?'· Répétition ♻':''}</div>
                                </div>
                            </div>
                            <div style="display:flex;gap:6px;">
                                <button class="btn bp bsm" onclick="APP.postLists.viewList(${l.id},${locationId})" style="font-size:11px;padding:4px 12px;">Voir</button>
                                <button class="btn bs bsm" onclick="APP.postLists.editList(${l.id},${locationId})" style="font-size:11px;padding:4px 12px;">Modifier</button>
                                <button class="btn bs bsm" onclick="APP.postLists.deleteList(${l.id},${locationId})" style="font-size:11px;padding:4px 12px;opacity:.5;">✕</button>
                            </div>
                        </div>
                        <div class="list-card-stats">
                            <span>${postCount} post${postCount>1?'s':''}</span>
                            <span style="color:var(--g);">${pubCount} publié${pubCount>1?'s':''}</span>
                            <span style="color:var(--acc);">${pendCount} en attente</span>
                            ${failCount > 0 ? `<span style="color:var(--r);">${failCount} échoué${failCount>1?'s':''}</span>` : ''}
                            ${!isActive && postCount > 0 && pendCount === 0 && !isRepeat ? '<span style="color:var(--t3);">· Terminée</span>' : ''}
                        </div>
                    </div>`;
                }
            }

            c.innerHTML = h;
        },

        // ---- Vue détail d'une liste ----
        async viewList(listId, lid) {
            this._currentListId = listId;
            const data = await APP.fetch(`/api/post-lists.php?action=get&location_id=${lid}&list_id=${listId}`);
            if (!data.success) { APP.toast(data.error || 'Erreur', 'error'); return; }
            this.renderListDétail(data.list, data.posts, lid);
        },

        renderListDétail(list, posts, locationId) {
            const c = document.getElementById('module-content');
            if (!c) return;
            this._postsCache = posts;

            const isRepeat = parseInt(list.is_repeat);
            const isActive = parseInt(list.is_active);
            const currentIdx = parseInt(list.current_index);

            // Calculer la prochaine publication
            let nextInfo = '';
            if (isActive && posts.length > 0) {
                const nextIdx = currentIdx < posts.length ? currentIdx : 0;
                const dayNames = {1:'Lundi',2:'Mardi',3:'Mercredi',4:'Jeudi',5:'Vendredi',6:'Samedi',7:'Dimanche'};
                const days = list.schedule_days.split(',').map(Number);
                const now = new Date();
                const today = now.getDay() === 0 ? 7 : now.getDay(); // JS: 0=dim, convertir en 1=lun..7=dim
                let nextDay = days.find(d => d >= today) || days[0];
                const time = list.schedule_times.split(',')[0];
                nextInfo = `Prochain: post #${nextIdx+1} · ${dayNames[nextDay] || ''} à ${time}`;
            }

            let h = `<div class="sh" style="flex-wrap:wrap;gap:12px;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <button class="btn bs bsm" onclick="APP.postLists.load(${locationId})" style="font-size:11px;padding:4px 10px;">← Retour</button>
                    <div class="stit">${this.esc(list.name)}</div>
                    <div class="list-toggle ${isActive?'active':''}" onclick="APP.postLists.toggleActive(${list.id},${locationId},true)" title="${isActive?'Désactiver':'Activer'}"></div>
                </div>
            </div>`;

            // Info bar
            h += `<div style="padding:14px 20px;border-bottom:1px solid var(--bdr);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <div style="font-size:12px;color:var(--t2);font-family:'Space Mono',monospace;">
                    ${this.formatDays(list.schedule_days)} à ${list.schedule_times.replace(/,/g,', ')}
                    ${isRepeat ? ' · Répétition ♻' : ' · Publication unique'}
                    ${nextInfo ? ` · <span style="color:var(--acc);">${nextInfo}</span>` : ''}
                </div>
            </div>`;

            // Action buttons
            const lSelCount = this._selectedIds.size;
            h += `<div style="display:flex;gap:10px;padding:14px 20px;border-bottom:1px solid var(--bdr);flex-wrap:wrap;align-items:center;">
                <button class="btn bp bsm" onclick="APP.postLists.showAddPostForm(${list.id},${locationId})"><svg viewBox="0 0 24 24"><path d="M12 4v16m8-8H4"/></svg> Ajouter un post</button>
                <button class="btn bs bsm" onclick="APP.postLists.showListImportForm(${list.id},${locationId})"><svg viewBox="0 0 24 24"><path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg> Importer CSV</button>
                <div style="margin-left:auto;">
                    <button class="btn ${this._selectMode ? 'bp' : 'bs'} bsm" onclick="APP.postLists.toggleSelectMode(${list.id},${locationId})" style="font-size:11px;">
                        ${this._selectMode ? '✕ Annuler' : '☐ Sélectionner'}
                    </button>
                </div>
            </div>`;

            // Barre bulk (mode sélection)
            if (this._selectMode && posts.length > 0) {
                h += `<div style="padding:8px 20px;border-bottom:1px solid var(--bdr);background:rgba(0,212,255,0.05);display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;color:var(--t2);">
                        <input type="checkbox" ${lSelCount === posts.length && posts.length > 0 ? 'checked' : ''} onchange="APP.postLists.${lSelCount === posts.length ? 'lDeselectAll' : 'lSelectAll'}(${list.id},${locationId})" style="accent-color:var(--acc);width:16px;height:16px;">
                        Tout sélectionner
                    </label>
                    <span style="font-size:12px;color:var(--acc);font-weight:600;">${lSelCount} sélectionné${lSelCount > 1 ? 's' : ''}</span>`;
                if (lSelCount > 0) {
                    h += `<span style="width:1px;height:20px;background:var(--bdr);margin:0 4px;"></span>
                        <button class="btn bs bsm" onclick="APP.postLists.bulkRemove(${list.id},${locationId})" style="font-size:11px;border-color:var(--acc);color:var(--acc);">
                            Retirer de la liste (${lSelCount})</button>
                        <button class="btn bs bsm" onclick="APP.postLists.bulkDeletePosts(${list.id},${locationId})" style="font-size:11px;color:var(--r);border-color:var(--r);">
                            <svg viewBox="0 0 24 24" style="width:12px;height:12px;stroke:var(--r);fill:none;stroke-width:2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                            Supprimer (${lSelCount})</button>`;
                }
                h += `</div>`;
            }

            // Add post form (hidden)
            h += `<div id="list-add-post-form" style="display:none;padding:20px;border-bottom:1px solid var(--bdr);background:var(--overlay);">
                <div style="font-weight:600;margin-bottom:12px;">Ajouter un post à la liste</div>
                <div style="display:flex;gap:8px;margin-bottom:8px;">
                    <input type="text" id="list-post-ai-subject" class="si" placeholder="Sujet pour la génération IA..." style="flex:1;">
                    <button class="btn bs bsm" onclick="APP.postLists.generateForList(${locationId})" id="btn-list-gen"><svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg> Générer IA</button>
                </div>
                <textarea id="list-post-content" class="si" placeholder="Contenu du post..." style="width:100%;height:100px;resize:vertical;margin-bottom:12px;" oninput="APP.postLists.updateAddCharCount()"></textarea>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                    <span id="list-post-char-count" style="font-size:11px;color:var(--t3);">0 / 1500</span>
                    <div style="display:flex;gap:8px;">
                        <input type="url" id="list-post-image" class="si" placeholder="URL image (optionnel)" style="width:200px;font-size:12px;">
                    </div>
                </div>
                <div style="display:flex;gap:10px;">
                    <button class="btn bp bsm" onclick="APP.postLists.addPost(${list.id},${locationId})">Ajouter à la liste</button>
                    <button class="btn bs bsm" onclick="document.getElementById('list-add-post-form').style.display='none'">Annuler</button>
                </div>
            </div>`;

            // CSV import form (hidden)
            h += `<div id="list-import-form" style="display:none;padding:20px;border-bottom:1px solid var(--bdr);background:var(--overlay);">
                <div style="font-weight:600;margin-bottom:12px;">Importer des posts dans la liste</div>
                <div style="font-size:12px;color:var(--t3);margin-bottom:12px;padding:10px;border:1px solid var(--bdr);border-radius:8px;background:rgba(0,212,255,.03);">
                    <strong>Format CSV :</strong> Séparateur virgule (,) ou point-virgule (;)<br>
                    Colonnes : <code>description</code> (obligatoire) · <code>image</code> (URL, optionnel)
                </div>
                <div style="display:flex;gap:12px;align-items:center;">
                    <input type="file" id="list-csv-file" accept=".csv" class="si" style="padding:8px;">
                    <button class="btn bp bsm" onclick="APP.postLists.importCsv(${list.id},${locationId})" id="btn-list-import">Importer</button>
                    <button class="btn bs bsm" onclick="document.getElementById('list-import-form').style.display='none'">Annuler</button>
                    <span id="list-import-result" style="font-size:13px;color:var(--g);"></span>
                </div>
            </div>`;

            // Posts list
            if (!posts.length) {
                h += `<div style="padding:40px;text-align:center;color:var(--t2);">
                    <p style="font-size:14px;margin-bottom:6px;">Aucun post dans cette liste</p>
                    <p style="font-size:12px;color:var(--t3);">Ajoutez des posts manuellement ou importez un fichier CSV.</p>
                </div>`;
            } else {
                h += `<div style="display:flex;flex-direction:column;gap:2px;">`;
                for (const p of posts) {
                    const isNext = parseInt(p.list_order) === currentIdx;
                    const statusLabel = {list_pending:'En attente',published:'Publié',failed:'Échoué',draft:'Brouillon'}[p.status]||p.status;
                    const statusColor = {list_pending:'var(--acc)',published:'var(--g)',failed:'var(--r)',draft:'var(--t3)'}[p.status]||'var(--t3)';
                    const fullContent = p.content || '';
                    const previewContent = fullContent.length > 200 ? fullContent.substring(0,200)+'…' : fullContent;
                    const order = parseInt(p.list_order);
                    const hasImg = !!p.image_url;
                    const lpSelected = this._selectedIds.has(p.id);
                    const lpSelStyle = lpSelected ? 'border-left:3px solid var(--acc);background:rgba(0,212,255,0.06);' : '';
                    const lpRowClick = this._selectMode ? `onclick="APP.postLists.lToggleSelect(${p.id},${list.id},${locationId})" style="cursor:pointer;align-items:stretch;padding:12px 16px;gap:14px;${lpSelStyle}"` : `style="align-items:stretch;padding:12px 16px;gap:14px;"`;

                    h += `<div class="list-post-row ${isNext && !this._selectMode ? 'is-next' : ''}" ${lpRowClick}>
                        <!-- Checkbox sélection -->
                        ${this._selectMode ? `
                        <div style="display:flex;align-items:center;justify-content:center;min-width:28px;flex-shrink:0;">
                            <input type="checkbox" ${lpSelected ? 'checked' : ''} onchange="APP.postLists.lToggleSelect(${p.id},${list.id},${locationId})" onclick="event.stopPropagation()" style="width:18px;height:18px;accent-color:var(--acc);cursor:pointer;">
                        </div>` : ''}

                        <!-- Numéro d'ordre -->
                        <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-width:32px;">
                            <div class="list-post-order" style="font-size:${isNext?'16px':'13px'};font-weight:${isNext?'700':'400'};color:${isNext?'var(--acc)':'var(--t3)'};">${isNext ? '►' : order + 1}</div>
                        </div>

                        <!-- Miniature visuel -->
                        ${hasImg ? `
                        <div style="flex-shrink:0;width:80px;height:80px;border-radius:8px;overflow:hidden;border:1px solid var(--bdr);background:var(--bg2);${!this._selectMode ? 'cursor:pointer;' : ''}" ${!this._selectMode ? `onclick="window.open('${this.esc(p.image_url)}','_blank')"` : ''} title="Voir en grand">
                            <img src="${this.esc(p.image_url)}" alt="" style="width:100%;height:100%;object-fit:cover;" loading="lazy" onerror="this.parentElement.innerHTML='<div style=\\'display:flex;align-items:center;justify-content:center;height:100%;color:var(--t3);font-size:10px;\\'>Erreur</div>'">
                        </div>` : `
                        <div style="flex-shrink:0;width:80px;height:80px;border-radius:8px;border:1px dashed var(--bdr);display:flex;align-items:center;justify-content:center;background:var(--bg2);">
                            <svg viewBox="0 0 24 24" style="width:24px;height:24px;stroke:var(--t3);fill:none;stroke-width:1.5;opacity:.4;"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                        </div>`}

                        <!-- Contenu texte -->
                        <div style="flex:1;min-width:0;display:flex;flex-direction:column;justify-content:center;gap:6px;">
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:600;background:${statusColor};color:${p.status==='list_pending'||p.status==='published'?'#000':'#fff'};">${statusLabel}</span>
                                ${isNext ? '<span style="font-size:10px;color:var(--acc);font-weight:600;">⚡ Prochain à publier</span>' : ''}
                                ${p.published_at ? `<span style="font-size:10px;color:var(--t3);">Publié le ${new Date(p.published_at).toLocaleDateString('fr-FR')}</span>` : ''}
                            </div>
                            <div style="color:var(--t1);font-size:13px;line-height:1.5;white-space:pre-wrap;word-break:break-word;" id="lp-text-${p.id}">${this.esc(previewContent)}</div>
                            ${fullContent.length > 200 ? `<button onclick="event.stopPropagation();APP.postLists.togglePostText(${p.id})" id="lp-toggle-${p.id}" style="background:none;border:none;color:var(--acc);font-size:11px;cursor:pointer;padding:0;text-align:left;">Voir plus…</button>` : ''}
                        </div>

                        <!-- Actions -->
                        ${!this._selectMode ? `
                        <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;flex-shrink:0;">
                            <div class="list-post-arrows">
                                ${order > 0 ? `<button onclick="APP.postLists.movePost(${list.id},${p.id},'up',${locationId})" title="Monter">▲</button>` : '<button style="visibility:hidden;">▲</button>'}
                                ${order < posts.length - 1 ? `<button onclick="APP.postLists.movePost(${list.id},${p.id},'down',${locationId})" title="Descendre">▼</button>` : '<button style="visibility:hidden;">▼</button>'}
                            </div>
                            <button class="btn bs bsm" onclick="APP.postLists.removePost(${p.id},${list.id},${locationId})" style="font-size:10px;padding:2px 8px;opacity:.5;" title="Retirer de la liste">✕</button>
                        </div>` : ''}
                    </div>`;
                }
                h += `</div>`;
            }

            c.innerHTML = h;
        },

        // ---- Helpers affichage ----
        esc(s) { if(!s)return''; const d=document.createElement('div');d.textContent=s;return d.innerHTML; },

        formatDays(dayStr) {
            const names = {1:'Lu',2:'Ma',3:'Me',4:'Je',5:'Ve',6:'Sa',7:'Di'};
            return dayStr.split(',').map(d => names[d.trim()]||d).join(', ');
        },

        togglePostText(postId) {
            const el = document.getElementById('lp-text-' + postId);
            const btn = document.getElementById('lp-toggle-' + postId);
            if (!el || !btn) return;
            const post = this._postsCache.find(p => parseInt(p.id) === postId);
            if (!post) return;
            const full = post.content || '';
            const isExpanded = el.dataset.full === '1';
            if (isExpanded) {
                el.textContent = full.length > 200 ? full.substring(0, 200) + '…' : full;
                el.dataset.full = '0';
                btn.textContent = 'Voir plus…';
            } else {
                el.textContent = full;
                el.dataset.full = '1';
                btn.textContent = 'Réduire';
            }
        },

        // ---- Bulk selection (liste détail) ----
        toggleSelectMode(listId, lid) {
            this._selectMode = !this._selectMode;
            this._selectedIds = new Set();
            this.viewList(listId, lid);
        },

        lToggleSelect(postId, listId, lid) {
            if (this._selectedIds.has(postId)) this._selectedIds.delete(postId);
            else this._selectedIds.add(postId);
            this.viewList(listId, lid);
        },

        lSelectAll(listId, lid) {
            this._selectedIds = new Set(this._postsCache.map(p => parseInt(p.id)));
            this.viewList(listId, lid);
        },

        lDeselectAll(listId, lid) {
            this._selectedIds = new Set();
            this.viewList(listId, lid);
        },

        async bulkRemove(listId, lid) {
            const count = this._selectedIds.size;
            if (!count) return;
            if (!await APP.modal.confirm('Retirer', `Retirer ${count} post${count > 1 ? 's' : ''} de la liste ? Ils deviendront des brouillons indépendants.`, 'Retirer', true)) return;

            const fd = new FormData();
            fd.append('action', 'bulk_remove');
            fd.append('location_id', lid);
            fd.append('list_id', listId);
            for (const id of this._selectedIds) fd.append('post_ids[]', id);

            const d = await APP.fetch('/api/post-lists.php', { method: 'POST', body: fd });
            if (d.success) {
                APP.toast(`${d.removed} post${d.removed > 1 ? 's' : ''} retiré${d.removed > 1 ? 's' : ''}`, 'success');
                this._selectMode = false;
                this._selectedIds = new Set();
                this.viewList(listId, lid);
            } else APP.toast(d.error || 'Erreur', 'error');
        },

        async bulkDeletePosts(listId, lid) {
            const count = this._selectedIds.size;
            if (!count) return;
            if (!await APP.modal.confirm('Supprimer', `Supprimer définitivement ${count} post${count > 1 ? 's' : ''} ? Cette action est irréversible.`, 'Supprimer', true)) return;

            const fd = new FormData();
            fd.append('action', 'bulk_delete_posts');
            fd.append('location_id', lid);
            fd.append('list_id', listId);
            for (const id of this._selectedIds) fd.append('post_ids[]', id);

            const d = await APP.fetch('/api/post-lists.php', { method: 'POST', body: fd });
            if (d.success) {
                APP.toast(`${d.deleted} post${d.deleted > 1 ? 's' : ''} supprimé${d.deleted > 1 ? 's' : ''}`, 'success');
                this._selectMode = false;
                this._selectedIds = new Set();
                this.viewList(listId, lid);
            } else APP.toast(d.error || 'Erreur', 'error');
        },

        // ---- Formulaire création/édition ----
        showCreateForm(lid) {
            this._selectedDays = [1,2,3,4,5];
            this._timeSlots = ['09:00'];
            document.getElementById('list-edit-id').value = '';
            document.getElementById('list-name').value = '';
            document.getElementById('list-form-title').textContent = 'Créer une nouvelle liste';
            document.getElementById('btn-save-list').textContent = 'Créer la liste';
            document.getElementById('list-repeat-toggle').classList.remove('active');
            const createListForm = document.getElementById('list-create-form');
            createListForm.style.display = 'block';
            createListForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
            this.renderDays();
            this.renderTimeSlots();
            document.getElementById('list-name').focus();
        },

        hideCreateForm() {
            document.getElementById('list-create-form').style.display = 'none';
        },

        async editList(listId, lid) {
            const data = await APP.fetch(`/api/post-lists.php?action=get&location_id=${lid}&list_id=${listId}`);
            if (!data.success) { APP.toast(data.error||'Erreur', 'error'); return; }
            const l = data.list;

            this._selectedDays = l.schedule_days.split(',').map(Number);
            this._timeSlots = l.schedule_times.split(',').map(t => t.trim());

            document.getElementById('list-edit-id').value = listId;
            document.getElementById('list-name').value = l.name;
            document.getElementById('list-form-title').textContent = 'Modifier la liste';
            document.getElementById('btn-save-list').textContent = 'Enregistrer';

            const toggle = document.getElementById('list-repeat-toggle');
            if (parseInt(l.is_repeat)) toggle.classList.add('active');
            else toggle.classList.remove('active');

            const listEditForm = document.getElementById('list-create-form');
            listEditForm.style.display = 'block';
            listEditForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
            this.renderDays();
            this.renderTimeSlots();
        },

        toggleDay(day) {
            const idx = this._selectedDays.indexOf(day);
            if (idx >= 0) this._selectedDays.splice(idx, 1);
            else this._selectedDays.push(day);
            this._selectedDays.sort();
            this.renderDays();
        },

        renderDays() {
            document.querySelectorAll('#list-days .day-btn').forEach(btn => {
                const d = parseInt(btn.dataset.day);
                if (this._selectedDays.includes(d)) btn.classList.add('selected');
                else btn.classList.remove('selected');
            });
        },

        addTimeSlot() {
            this._timeSlots.push('12:00');
            this.renderTimeSlots();
        },

        removeTimeSlot(idx) {
            if (this._timeSlots.length <= 1) return;
            this._timeSlots.splice(idx, 1);
            this.renderTimeSlots();
        },

        updateTimeSlot(idx, val) {
            this._timeSlots[idx] = val;
        },

        renderTimeSlots() {
            const container = document.getElementById('list-times');
            if (!container) return;
            let h = '';
            this._timeSlots.forEach((t, i) => {
                h += `<div style="display:inline-flex;align-items:center;gap:6px;margin-right:8px;margin-bottom:6px;">
                    <input type="time" value="${t}" class="si" style="width:100px;padding:6px 8px;font-size:12px;" onchange="APP.postLists.updateTimeSlot(${i},this.value)">
                    ${this._timeSlots.length > 1 ? `<button class="btn bs bsm" onclick="APP.postLists.removeTimeSlot(${i})" style="font-size:10px;padding:2px 6px;opacity:.5;">✕</button>` : ''}
                </div>`;
            });
            container.innerHTML = h;
        },

        toggleRepeatForm() {
            document.getElementById('list-repeat-toggle').classList.toggle('active');
        },

        async saveList(lid) {
            const name = document.getElementById('list-name').value.trim();
            if (!name) { APP.toast('Donnez un nom à la liste', 'warning'); return; }
            if (this._selectedDays.length === 0) { APP.toast('Sélectionnez au moins un jour', 'warning'); return; }

            // Relire les valeurs actuelles des inputs time
            document.querySelectorAll('#list-times input[type="time"]').forEach((inp, i) => {
                this._timeSlots[i] = inp.value;
            });

            const isRepeat = document.getElementById('list-repeat-toggle').classList.contains('active') ? 1 : 0;
            const editId = document.getElementById('list-edit-id').value;

            const fd = new FormData();
            fd.append('action', editId ? 'update' : 'create');
            fd.append('location_id', lid);
            fd.append('name', name);
            fd.append('schedule_days', this._selectedDays.join(','));
            fd.append('schedule_times', this._timeSlots.join(','));
            fd.append('is_repeat', isRepeat);
            if (editId) fd.append('list_id', editId);

            const d = await APP.fetch('/api/post-lists.php', { method: 'POST', body: fd });
            if (d.success) {
                this.hideCreateForm();
                this.load(lid);
            } else {
                APP.toast(d.error || 'Erreur', 'error');
            }
        },

        // ---- Actions sur les listes ----
        async toggleActive(listId, lid, inDétail) {
            const fd = new FormData();
            fd.append('action', 'toggle_active');
            fd.append('location_id', lid);
            fd.append('list_id', listId);
            const d = await APP.fetch('/api/post-lists.php', { method: 'POST', body: fd });
            if (d.success) {
                if (inDétail) this.viewList(listId, lid);
                else this.load(lid);
            }
        },

        async deleteList(listId, lid) {
            if (!await APP.modal.confirm('Supprimer', 'Supprimer cette liste ? Les posts deviendront des brouillons indépendants.', 'Supprimer', true)) return;
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('location_id', lid);
            fd.append('list_id', listId);
            const d = await APP.fetch('/api/post-lists.php', { method: 'POST', body: fd });
            if (d.success) this.load(lid);
            else APP.toast(d.error || 'Erreur', 'error');
        },

        // ---- Actions dans le détail ----
        showAddPostForm(listId, lid) {
            document.getElementById('list-import-form').style.display = 'none';
            document.getElementById('list-add-post-form').style.display = 'block';
            document.getElementById('list-post-content').value = '';
            document.getElementById('list-post-ai-subject').value = '';
            document.getElementById('list-post-image').value = '';
            this.updateAddCharCount();
            document.getElementById('list-post-ai-subject').focus();
        },

        showListImportForm(listId, lid) {
            document.getElementById('list-add-post-form').style.display = 'none';
            document.getElementById('list-import-form').style.display = 'block';
            document.getElementById('list-import-result').textContent = '';
        },

        updateAddCharCount() {
            const content = document.getElementById('list-post-content')?.value || '';
            const el = document.getElementById('list-post-char-count');
            if (el) {
                el.textContent = `${content.length} / 1500`;
                el.style.color = content.length > 1500 ? 'var(--r)' : content.length > 1200 ? 'var(--o)' : 'var(--t3)';
            }
        },

        async generateForList(lid) {
            const subject = document.getElementById('list-post-ai-subject').value.trim();
            if (!subject) { APP.toast('Entrez un sujet', 'warning'); document.getElementById('list-post-ai-subject').focus(); return; }

            const btn = document.getElementById('btn-list-gen');
            btn.disabled = true;
            btn.innerHTML = '<svg viewBox="0 0 24 24" class="spin"><path d="M12 2v4m0 12v4m10-10h-4M6 12H2"/></svg> ...';

            const fd = new FormData();
            fd.append('action', 'generate_content');
            fd.append('location_id', lid);
            fd.append('subject', subject);
            fd.append('post_type', 'STANDARD');

            const d = await APP.fetch('/api/posts.php', { method: 'POST', body: fd });
            btn.disabled = false;
            btn.innerHTML = '<svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg> Générer IA';

            if (d.success) {
                document.getElementById('list-post-content').value = d.content;
                this.updateAddCharCount();
            } else {
                APP.toast(d.error || 'Erreur de génération', 'error');
            }
        },

        async addPost(listId, lid) {
            const content = document.getElementById('list-post-content').value.trim();
            if (!content) { APP.toast('Le contenu est requis', 'warning'); return; }
            if (content.length > 1500) { APP.toast('Le contenu dépasse 1500 caractères', 'warning'); return; }

            const fd = new FormData();
            fd.append('action', 'add_post');
            fd.append('location_id', lid);
            fd.append('list_id', listId);
            fd.append('content', content);
            fd.append('image_url', document.getElementById('list-post-image').value.trim());

            const d = await APP.fetch('/api/post-lists.php', { method: 'POST', body: fd });
            if (d.success) {
                document.getElementById('list-add-post-form').style.display = 'none';
                this.viewList(listId, lid);
            } else {
                APP.toast(d.error || 'Erreur', 'error');
            }
        },

        async importCsv(listId, lid) {
            const fileInput = document.getElementById('list-csv-file');
            if (!fileInput.files.length) { APP.toast('Sélectionnez un fichier CSV', 'warning'); return; }

            const btn = document.getElementById('btn-list-import');
            btn.disabled = true;
            btn.innerHTML = '<svg viewBox="0 0 24 24" class="spin"><path d="M12 2v4m0 12v4m10-10h-4M6 12H2"/></svg> Import...';

            const fd = new FormData();
            fd.append('action', 'import_csv');
            fd.append('location_id', lid);
            fd.append('list_id', listId);
            fd.append('csv_file', fileInput.files[0]);

            const d = await APP.fetch('/api/post-lists.php', { method: 'POST', body: fd });
            btn.disabled = false;
            btn.innerHTML = 'Importer';

            if (d.success) {
                document.getElementById('list-import-result').textContent = '✅ ' + d.message;
                fileInput.value = '';
                setTimeout(() => {
                    document.getElementById('list-import-form').style.display = 'none';
                    this.viewList(listId, lid);
                }, 1500);
            } else {
                document.getElementById('list-import-result').textContent = '';
                APP.toast(d.error || 'Erreur d\'import', 'error');
            }
        },

        async movePost(listId, postId, direction, lid) {
            // Récupérer l'ordre actuel
            const data = await APP.fetch(`/api/post-lists.php?action=get&location_id=${lid}&list_id=${listId}`);
            if (!data.success) return;

            const posts = data.posts;
            const idx = posts.findIndex(p => parseInt(p.id) === postId);
            if (idx < 0) return;

            const swapIdx = direction === 'up' ? idx - 1 : idx + 1;
            if (swapIdx < 0 || swapIdx >= posts.length) return;

            // Swap
            const ids = posts.map(p => p.id);
            [ids[idx], ids[swapIdx]] = [ids[swapIdx], ids[idx]];

            const fd = new FormData();
            fd.append('action', 'reorder');
            fd.append('location_id', lid);
            fd.append('list_id', listId);
            fd.append('post_ids', JSON.stringify(ids));

            const d = await APP.fetch('/api/post-lists.php', { method: 'POST', body: fd });
            if (d.success) this.viewList(listId, lid);
        },

        async removePost(postId, listId, lid) {
            if (!await APP.modal.confirm('Retirer', 'Retirer ce post de la liste ? Il deviendra un brouillon indépendant.', 'Retirer', true)) return;
            const fd = new FormData();
            fd.append('action', 'remove_post');
            fd.append('location_id', lid);
            fd.append('list_id', listId);
            fd.append('post_id', postId);
            const d = await APP.fetch('/api/post-lists.php', { method: 'POST', body: fd });
            if (d.success) this.viewList(listId, lid);
            else APP.toast(d.error || 'Erreur', 'error');
        },

        async deleteAllPosts(listId, lid) {
            if (!await APP.modal.confirm('Tout supprimer', 'Supprimer TOUS les posts de cette liste ? Cette action est irréversible.', 'Tout supprimer', true)) return;
            const fd = new FormData();
            fd.append('action', 'delete_all_posts');
            fd.append('location_id', lid);
            fd.append('list_id', listId);
            const d = await APP.fetch('/api/post-lists.php', { method: 'POST', body: fd });
            if (d.success) this.viewList(listId, lid);
            else APP.toast(d.error || 'Erreur', 'error');
        }
    },

    // ====================================================================
    // MODULE : AVIS GLOBAL (cross-location)
    // ====================================================================
    reviewsAll: {
        _filter: 'unanswered',
        _filterLocation: '',
        _page: 1,
        _locations: [],

        async load(filter, filterLocation, page) {
            this._filter = filter || 'unanswered';
            this._filterLocation = filterLocation || '';
            this._page = page || 1;
            // Skeleton
            const c = document.getElementById('module-content');
            if (c && !filter) c.innerHTML = `<div class="sh"><div class="stit">AVIS GOOGLE — VUE GLOBALE</div></div>${APP.skeleton.rows(6)}`;
            const params = `action=list&filter=${this._filter}&filter_location=${this._filterLocation}&page=${this._page}`;
            const data = await APP.fetch(`/api/reviews-all.php?${params}`);
            if (data.error) return;
            this._locations = data.locations || [];
            this.render(data.reviews, data.stats, data.locations, data.pagination);
        },

        render(reviews, stats, locations, pagination) {
            const c = document.getElementById('module-content');
            if (!c) return;

            // Header + stats
            const deletedTotal = stats?.deleted_count || 0;
            let h = `<div class="sh" style="flex-wrap:wrap;gap:12px;">
                <div class="stit">AVIS GOOGLE — VUE GLOBALE</div>
                <div style="display:flex;gap:16px;align-items:center;">
                    <div class="post-stat"><div class="post-stat-n">${stats?.total||0}</div><div class="post-stat-l">Total</div></div>
                    <div class="post-stat"><div class="post-stat-n" style="color:var(--o)">${stats?.unanswered||0}</div><div class="post-stat-l">Sans rep.</div></div>
                    <div class="post-stat"><div class="post-stat-n" style="color:var(--o)">&#9733; ${stats?.avg_rating||'—'}</div><div class="post-stat-l">Moyenne</div></div>
                    ${deletedTotal > 0 ? `<div class="post-stat"><div class="post-stat-n" style="color:var(--r)">${deletedTotal}</div><div class="post-stat-l">Supprimes</div></div>` : ''}
                </div>
            </div>`;

            // Barre de filtres
            h += `<div class="filter-bar">
                <select onchange="APP.reviewsAll.load(APP.reviewsAll._filter, this.value, 1)">
                    <option value="">Toutes les fiches</option>`;
            for (const loc of (locations || [])) {
                const sel = this._filterLocation == loc.id ? 'selected' : '';
                h += `<option value="${loc.id}" ${sel}>${loc.name} — ${loc.city} (${loc.unanswered_count||0} sans rep.)</option>`;
            }
            h += `</select>`;

            const delCount = stats?.deleted_count || 0;
            const filters = [
                { val: 'all', label: 'Tous' },
                { val: 'unanswered', label: 'Sans réponse' },
                { val: 'deleted', label: `Supprimes (${delCount})` },
                { val: '5', label: '5 &#9733;' },
                { val: '4', label: '4 &#9733;' },
                { val: '3', label: '3 &#9733;' },
                { val: '2', label: '2 &#9733;' },
                { val: '1', label: '1 &#9733;' },
            ];
            for (const f of filters) {
                const extraStyle = f.val === 'deleted' ? ` style="${delCount > 0 ? 'color:var(--r);' : 'opacity:.5;'}"` : '';
                h += `<button class="filter-btn ${this._filter===f.val?'active':''}" onclick="APP.reviewsAll.load('${f.val}',APP.reviewsAll._filterLocation,1)"${extraStyle}>${f.label}</button>`;
            }
            h += `</div>`;

            // Liste des avis
            if (!reviews.length) {
                h += `<div style="padding:40px;text-align:center;color:var(--t2);"><p>Aucun avis trouve.</p></div>`;
            } else {
                h += `<div class="reviews-list">`;
                for (const r of reviews) {
                    const stars = '&#9733;'.repeat(r.rating) + '&#9734;'.repeat(5 - r.rating);
                    const isDeleted = r.deleted_by_google == 1;
                    const statusCls = isDeleted ? 'psd' : (r.is_replied == 1 ? 'psp' : 'psd');
                    const statusTxt = isDeleted ? 'Supprime' : (r.is_replied == 1 ? 'Répondu' : 'Sans réponse');
                    const initial = (r.author_name || r.reviewer_name || '?')[0].toUpperCase();
                    const date = r.review_date ? new Date(r.review_date).toLocaleDateString('fr-FR') : '';
                    const deletedBadge = isDeleted ? '<span style="display:inline-flex;align-items:center;gap:3px;padding:2px 8px;background:rgba(239,68,68,.12);color:var(--r);font-size:10px;font-weight:600;border-radius:4px;border:1px solid rgba(239,68,68,.2);">&#128465; Supprime par Google</span>' : '';
                    const deletedStyle = isDeleted ? ' style="opacity:.7;border-left:3px solid var(--r);"' : '';

                    h += `<div class="rev-card" id="review-${r.id}"${deletedStyle}>
                        <div class="rev-header">
                            <div class="rev-avatar">${initial}</div>
                            <div>
                                <div class="rev-author">${r.author_name||r.reviewer_name||'Anonyme'}</div>
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <span style="color:var(--o);font-size:14px;">${stars}</span>
                                    <span style="font-size:12px;color:var(--t3)">${date}</span>
                                    <span class="ps ${statusCls}">${statusTxt}</span>
                                    ${deletedBadge}
                                </div>
                            </div>
                            <span class="rev-location-badge">${r.location_name||''} — ${r.location_city||''}</span>
                        </div>`;

                    if (r.comment) {
                        h += `<div class="rev-comment">${r.comment}</div>`;
                    }

                    // Zone de réponse existante
                    if (r.reply_text) {
                        h += `<div class="rev-reply">
                            <div style="font-size:11px;color:var(--t3);margin-bottom:6px;">Réponse :</div>
                            <div style="font-size:13px;line-height:1.6;">${r.reply_text}</div>
                        </div>`;
                    }

                    // Zone de réponse / edition
                    h += `<div class="rev-actions">
                        <div id="reply-zone-${r.id}" style="display:none;margin-top:10px;">
                            <textarea id="reply-text-${r.id}" class="si" style="width:100%;height:100px;resize:vertical;" placeholder="Écrire une réponse...">${r.reply_text||''}</textarea>
                            <div style="display:flex;gap:8px;margin-top:8px;">
                                <button class="btn bp bsm" id="gen-btn-${r.id}" onclick="APP.reviewsAll.generateReply(${r.id})">
                                    <svg viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg> Générer avec IA
                                </button>
                                <button class="btn bp bsm" onclick="APP.reviewsAll.saveReply(${r.id}, true)">
                                    <svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M5 13l4 4L19 7"/></svg> Publier sur Google
                                </button>
                                <button class="btn bs bsm" onclick="APP.reviewsAll.saveReply(${r.id}, false)">Sauvegarder local</button>
                                <button class="btn bs bsm" onclick="document.getElementById('reply-zone-${r.id}').style.display='none'">Annuler</button>
                            </div>
                        </div>
                        <button class="btn bs bsm" style="margin-top:8px;" onclick="document.getElementById('reply-zone-${r.id}').style.display='block'">${r.reply_text ? 'Modifier la réponse' : 'Répondre'}</button>
                    </div>`;

                    h += `</div>`;
                }
                h += `</div>`;
            }

            // Pagination
            if (pagination && pagination.pages > 1) {
                h += `<div style="display:flex;justify-content:center;gap:8px;padding:16px;">`;
                for (let p = 1; p <= pagination.pages; p++) {
                    const cls = p === pagination.page ? 'bp' : 'bs';
                    h += `<button class="btn ${cls} bsm" onclick="APP.reviewsAll.load('${this._filter}','${this._filterLocation}',${p})">${p}</button>`;
                }
                h += `</div>`;
            }

            c.innerHTML = h;
        },

        async generateReply(reviewId) {
            const btn = document.getElementById(`gen-btn-${reviewId}`);
            if (btn) { btn.disabled = true; btn.innerHTML = '<svg class="spin" viewBox="0 0 24 24"><path d="M12 2v4m0 12v4m-7.07-3.93l2.83-2.83m8.48-8.48l2.83-2.83M2 12h4m12 0h4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83"/></svg> Generation...'; }

            const fd = new FormData();
            fd.append('action', 'generate_reply');
            fd.append('review_id', reviewId);

            const data = await APP.fetch('/api/reviews-all.php', { method: 'POST', body: fd });

            if (btn) { btn.disabled = false; btn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg> Générer avec IA'; }

            if (data.success && data.reply) {
                const ta = document.getElementById(`reply-text-${reviewId}`);
                if (ta) ta.value = data.reply;
            } else {
                APP.toast(data.error || 'Erreur lors de la génération', 'error');
            }
        },

        async saveReply(reviewId, postToGoogle = false) {
            const ta = document.getElementById(`reply-text-${reviewId}`);
            if (!ta || !ta.value.trim()) { APP.toast('Veuillez écrire une réponse', 'warning'); return; }

            const fd = new FormData();
            fd.append('action', 'save_reply');
            fd.append('review_id', reviewId);
            fd.append('reply_text', ta.value.trim());
            fd.append('post_to_google', postToGoogle ? '1' : '0');

            const data = await APP.fetch('/api/reviews-all.php', { method: 'POST', body: fd });

            if (data.success) {
                if (data.posted_to_google) {
                    APP.toast('Réponse publiée sur Google avec succès !', 'success');
                } else if (data.google_error) {
                    APP.toast('Réponse sauvegardée. Erreur Google : ' + data.google_error, 'warning');
                }
                this.load(this._filter, this._filterLocation, this._page);
            } else {
                APP.toast(data.error || 'Erreur lors de la sauvegarde', 'error');
            }
        },

        async syncReviews() {
            const btn = document.getElementById('btn-sync-reviews-global');
            if (btn) { btn.disabled = true; btn.innerHTML = '<svg class="spin" viewBox="0 0 24 24"><path d="M12 2v4m0 12v4m-7.07-3.93l2.83-2.83m8.48-8.48l2.83-2.83M2 12h4m12 0h4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83"/></svg> Synchronisation...'; }

            const fd = new FormData();
            fd.append('action', 'sync_reviews');
            const data = await APP.fetch('/api/locations.php', { method: 'POST', body: fd });

            if (btn) { btn.disabled = false; btn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Synchroniser les avis Google'; }

            if (data.success) {
                APP.toast(data.message || 'Synchronisation terminée', 'success');
                this.load(this._filter, this._filterLocation, this._page);
            } else {
                APP.toast(data.error || 'Erreur de synchronisation', 'error');
            }
        }
    },

    // ====================================================================
    // MODULE : RAPPORTS AUTOMATIQUES (cross-location) — PRO UI
    // ====================================================================
    reportsAll: {
        _templates: [],
        _locations: [],
        _editing: null,
        _expandedId: null,
        _expandedTab: null,

        async load() {
            const c = document.getElementById('module-content');
            if (c) c.innerHTML = `<div class="sh"><div class="stit">Rapports</div></div>${APP.skeleton.cards(2)}`;
            const data = await APP.fetch('/api/reports.php?action=list_templates');
            if (data.error) { this.renderEmpty(); return; }
            this._templates = data.templates || [];
            this._locations = data.locations || [];
            this.render();
        },

        renderEmpty() {
            const c = document.getElementById('module-content');
            if (!c) return;
            c.innerHTML = `<div class="sh"><div class="stit">Rapports</div></div>
            <div style="padding:80px 20px;text-align:center">
                <svg viewBox="0 0 24 24" style="width:52px;height:52px;stroke:var(--acc);fill:none;stroke-width:1.5;margin-bottom:20px;opacity:.4"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                <div style="font-size:16px;font-weight:700;margin-bottom:8px">Aucun rapport configuré</div>
                <div style="font-size:13px;color:var(--t2);max-width:380px;margin:0 auto 24px">Creez un template de rapport automatique pour envoyer des PDF professionnels a vos clients chaque mois.</div>
                <button class="btn bp" onclick="APP.reportsAll.showCreateForm()" style="padding:10px 24px">
                    <svg viewBox="0 0 24 24" style="width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;vertical-align:-2px"><path d="M12 4v16m8-8H4"/></svg> Creer un template
                </button>
            </div>`;
        },

        render() {
            const c = document.getElementById('module-content');
            if (!c) return;
            const R = this;
            let h = '';

            // ====== HEADER ======
            h += '<div class="sh" style="padding:14px 20px">';
            h += '<div style="display:flex;align-items:center;gap:10px">';
            h += '<div class="stit" style="font-size:15px;margin:0">Rapports</div>';
            if (this._templates.length) h += '<span class="rpt-count-badge">' + this._templates.length + '</span>';
            h += '</div>';
            h += '<button class="btn bp bsm" onclick="APP.reportsAll.showCreateForm()">';
            h += '<svg viewBox="0 0 24 24" style="width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2;vertical-align:-1px"><path d="M12 4v16m8-8H4"/></svg> Nouveau template</button>';
            h += '</div>';

            // ====== BANDEAU PERIODE ======
            const _m1 = new Date(new Date().getFullYear(), new Date().getMonth() - 1, 1);
            const _m2 = new Date(new Date().getFullYear(), new Date().getMonth() - 2, 1);
            const _fmt = d => d.toLocaleString('fr-FR', { month: 'long', year: 'numeric' }).replace(/^./, c => c.toUpperCase());
            h += '<div style="padding:10px 20px;border-bottom:1px solid var(--bdr);display:flex;align-items:center;gap:16px;font-size:12px;color:var(--t3);">';
            h += '<span>📅 Période par défaut :</span>';
            h += '<span style="font-weight:600;color:var(--t1);">M-1 ' + _fmt(_m1) + '</span>';
            h += '<span style="color:var(--t3);">vs</span>';
            h += '<span style="font-weight:600;color:var(--t2);">M-2 ' + _fmt(_m2) + '</span>';
            h += '</div>';

            // ====== TEMPLATE LIST ======
            if (!this._templates.length) {
                h += '<div style="padding:60px 20px;text-align:center">';
                h += '<div style="font-size:14px;color:var(--t2)">Aucun template. Cliquez sur "Nouveau template" pour commencer.</div>';
                h += '</div>';
            } else {
                h += '<div class="rpt-list">';
                for (const t of this._templates) {
                    h += this._renderCard(t);
                }
                h += '</div>';
            }

            c.innerHTML = h;

            // ====== MODAL FORM — attached to body (not inside .sec which has overflow:hidden + transform) ======
            let modal = document.getElementById('rpt-modal');
            if (!modal) {
                const wrapper = document.createElement('div');
                wrapper.innerHTML = this._renderFormModal();
                document.body.appendChild(wrapper.firstElementChild);
            }
        },

        /** Render a single template card */
        _renderCard(t) {
            const freqLabel = t.schedule_frequency === 'weekly' ? 'Hebdomadaire' : 'Mensuel';
            const dayLabel = t.schedule_frequency === 'weekly'
                ? ['','Lun','Mar','Mer','Jeu','Ven','Sam','Dim'][t.schedule_day] || t.schedule_day
                : 'le ' + t.schedule_day;
            const isActive = t.is_active == 1;
            const lastSent = t.last_sent_at ? new Date(t.last_sent_at).toLocaleDateString('fr-FR') : null;
            const sections = (() => { try { return JSON.parse(t.sections || '{}'); } catch(e) { return {}; } })();
            const isExpanded = this._expandedId === t.id;

            let h = '<div class="rpt-card' + (isExpanded ? ' expanded' : '') + '" data-id="' + t.id + '">';

            // ---- Row 1 : Header ----
            h += '<div class="rpt-card-top">';
            // Left: icon + info
            h += '<div class="rpt-card-left">';
            h += '<div class="rpt-card-icon' + (isActive ? ' active' : '') + '">';
            h += '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
            h += '</div>';
            h += '<div>';
            h += '<div class="rpt-card-name">' + this._esc(t.name) + '</div>';
            h += '<div class="rpt-card-meta">';
            h += '<span class="rpt-freq-badge">' + freqLabel + ' ' + dayLabel + '</span>';
            h += '<span class="rpt-meta-dot"></span>';
            h += '<span>' + (t.recipient_count||0) + ' destinataire' + ((t.recipient_count||0) > 1 ? 's' : '') + '</span>';
            if (lastSent) { h += '<span class="rpt-meta-dot"></span><span>Envoye le ' + lastSent + '</span>'; }
            h += '</div></div></div>';

            // Right: send mode + toggle + status
            const isAuto = t.send_mode === 'auto';
            h += '<div class="rpt-card-right">';
            h += '<span class="rpt-mode-pill ' + (isAuto ? 'auto' : 'manual') + '">' + (isAuto ? 'Auto' : 'Manuel') + '</span>';
            h += '<span class="rpt-status-pill ' + (isActive ? 'on' : 'off') + '">' + (isActive ? 'Actif' : 'Inactif') + '</span>';
            h += '<div class="list-toggle ' + (isActive ? 'active' : '') + '" onclick="event.stopPropagation();APP.reportsAll.toggleActive(' + t.id + ')" title="' + (isActive ? 'Desactiver' : 'Activer') + '"></div>';
            h += '</div>';
            h += '</div>';

            // ---- Row 2 : Section pills ----
            h += '<div class="rpt-card-sections">';
            const sectionDefs = [
                { key: 'google_stats', icon: '<svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>', label: 'Statistiques' },
                { key: 'keyword_positions', icon: '<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>', label: 'Mots-cles' },
                { key: 'reviews_summary', icon: '<svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>', label: 'Avis' },
                { key: 'posts_summary', icon: '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>', label: 'Posts' },
                { key: 'google_places', icon: '<svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>', label: 'Fiche Google' }
            ];
            for (const s of sectionDefs) {
                const on = sections[s.key];
                h += '<span class="rpt-section-pill' + (on ? ' on' : '') + '">' + s.icon + ' ' + s.label + '</span>';
            }
            h += '</div>';

            // ---- Row 3 : Actions ----
            h += '<div class="rpt-card-actions">';
            h += '<button class="rpt-act-btn" onclick="APP.reportsAll.editTemplate(' + t.id + ')" title="Modifier"><svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Modifier</button>';
            h += '<button class="rpt-act-btn" onclick="APP.reportsAll._toggle(' + t.id + ',\'recipients\')" title="Destinataires"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg> Destinataires <span class="rpt-act-count">' + (t.recipient_count||0) + '</span></button>';
            h += '<button class="rpt-act-btn" onclick="APP.reportsAll._toggle(' + t.id + ',\'history\')" title="Historique"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Historique</button>';
            h += '<button class="rpt-act-btn" onclick="APP.reportsAll.showPreview(' + t.id + ')" title="Aperçu"><svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> Aperçu</button>';
            h += '<div style="flex:1"></div>';
            h += '<button class="rpt-act-btn send" onclick="APP.reportsAll.triggerSend(' + t.id + ')"><svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Envoyer</button>';
            h += '<button class="rpt-act-btn" onclick="APP.reportsAll.sendTest(' + t.id + ')" title="Test"><svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg> Test</button>';
            h += '<button class="rpt-act-btn danger" onclick="APP.reportsAll.deleteTemplate(' + t.id + ')" title="Supprimer"><svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg></button>';
            h += '</div>';

            // ---- Expanded zone (recipients or history) ----
            if (isExpanded) {
                h += '<div class="rpt-expanded-zone" id="rpt-expand-' + t.id + '"></div>';
            }

            h += '</div>';
            return h;
        },

        /** Toggle expanded zone on a card */
        async _toggle(id, tab) {
            if (this._expandedId === id && this._expandedTab === tab) {
                this._expandedId = null;
                this._expandedTab = null;
                this.render();
                return;
            }
            this._expandedId = id;
            this._expandedTab = tab;
            this.render();
            if (tab === 'recipients') this._loadRecipients(id);
            else if (tab === 'history') this._loadHistory(id);
        },

        /** Render the modal form overlay */
        _renderFormModal() {
            let h = '<div class="rpt-modal-overlay" id="rpt-modal" style="display:none" onclick="if(event.target===this){APP.reportsAll._closeModal()}">';
            h += '<div class="rpt-modal">';

            // ── Header ──
            h += '<div class="rpt-modal-header">';
            h += '<div class="stit" style="font-size:16px;margin:0" id="rpt-modal-title">Nouveau template</div>';
            h += '<button class="rpt-modal-close" onclick="APP.reportsAll._closeModal()">';
            h += '<svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
            h += '</button>';
            h += '</div>';

            // ── Body ──
            h += '<div class="rpt-modal-body">';

            // ── FIELDSET 1 : Configuration ──
            h += '<div class="rpt-fieldset">';
            h += '<div class="rpt-fieldset-title"><svg viewBox="0 0 24 24"><path d="M12.22 2h-.44a2 2 0 00-2 2v.18a2 2 0 01-1 1.73l-.43.25a2 2 0 01-2 0l-.15-.08a2 2 0 00-2.73.73l-.22.38a2 2 0 00.73 2.73l.15.1a2 2 0 011 1.72v.51a2 2 0 01-1 1.74l-.15.09a2 2 0 00-.73 2.73l.22.38a2 2 0 002.73.73l.15-.08a2 2 0 012 0l.43.25a2 2 0 011 1.73V20a2 2 0 002 2h.44a2 2 0 002-2v-.18a2 2 0 011-1.73l.43-.25a2 2 0 012 0l.15.08a2 2 0 002.73-.73l.22-.39a2 2 0 00-.73-2.73l-.15-.08a2 2 0 01-1-1.74v-.5a2 2 0 011-1.74l.15-.09a2 2 0 00.73-2.73l-.22-.38a2 2 0 00-2.73-.73l-.15.08a2 2 0 01-2 0l-.43-.25a2 2 0 01-1-1.73V4a2 2 0 00-2-2z"/><circle cx="12" cy="12" r="3"/></svg> Configuration</div>';
            h += '<div class="rpt-form-group">';
            h += '<label class="rpt-label">Nom du template</label>';
            h += '<input type="text" id="rpt-name" class="si" placeholder="Ex: Rapport mensuel SEO">';
            h += '</div>';
            h += '<div class="rpt-form-row">';
            h += '<div style="flex:1">';
            h += '<label class="rpt-label">Frequence</label>';
            h += '<select id="rpt-frequency" class="si" style="width:100%" onchange="APP.reportsAll.updateDayOptions()"><option value="monthly">Mensuel</option><option value="weekly">Hebdomadaire</option></select>';
            h += '</div>';
            h += '<div style="flex:1">';
            h += '<label class="rpt-label">Jour d\'envoi</label>';
            h += '<select id="rpt-day" class="si" style="width:100%"><option value="1">Le 1er</option><option value="2">Le 2</option><option value="3">Le 3</option><option value="5">Le 5</option><option value="10">Le 10</option><option value="15">Le 15</option></select>';
            h += '</div>';
            h += '</div>';
            // Mode d'envoi
            h += '<div class="rpt-form-group" style="margin-top:12px">';
            h += '<label class="rpt-label">Mode d\'envoi</label>';
            h += '<div style="display:flex;gap:8px">';
            h += '<div class="rpt-mode-option selected" id="rpt-mode-manual" onclick="APP.reportsAll._setMode(\'manual\')">';
            h += '<svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>';
            h += '<div class="rpt-mode-texts"><span>Manuel</span><div class="rpt-mode-desc">Vous decidez quand envoyer</div></div></div>';
            h += '<div class="rpt-mode-option" id="rpt-mode-auto" onclick="APP.reportsAll._setMode(\'auto\')">';
            h += '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
            h += '<div class="rpt-mode-texts"><span>Automatique</span><div class="rpt-mode-desc">Envoi selon la frequence</div></div></div>';
            h += '</div>';
            h += '<input type="hidden" id="rpt-send-mode" value="manual">';
            h += '</div>';
            h += '</div>'; // end fieldset 1

            // ── FIELDSET 2 : Contenu du rapport ──
            h += '<div class="rpt-fieldset">';
            h += '<div class="rpt-fieldset-title"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> Contenu du rapport</div>';
            h += '<div class="report-section-grid" id="rpt-sections">';
            h += '<div class="report-section-check checked" data-val="google_stats" onclick="this.classList.toggle(\'checked\')">';
            h += '<svg viewBox="0 0 24 24" class="rpt-sec-icon"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg> Statistiques Google</div>';
            h += '<div class="report-section-check checked" data-val="keyword_positions" onclick="this.classList.toggle(\'checked\')">';
            h += '<svg viewBox="0 0 24 24" class="rpt-sec-icon"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg> Classement mots-cles</div>';
            h += '<div class="report-section-check checked" data-val="reviews_summary" onclick="this.classList.toggle(\'checked\')">';
            h += '<svg viewBox="0 0 24 24" class="rpt-sec-icon"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg> Resume des avis</div>';
            h += '<div class="report-section-check checked" data-val="posts_summary" onclick="this.classList.toggle(\'checked\')">';
            h += '<svg viewBox="0 0 24 24" class="rpt-sec-icon"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg> Publications Google</div>';
            h += '<div class="report-section-check checked" data-val="google_places" onclick="this.classList.toggle(\'checked\')">';
            h += '<svg viewBox="0 0 24 24" class="rpt-sec-icon"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg> Etat fiche Google</div>';
            h += '</div>';
            h += '</div>'; // end fieldset 2

            // ── FIELDSET 3 : Email ──
            h += '<div class="rpt-fieldset">';
            h += '<div class="rpt-fieldset-title"><svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22 6 12 13 2 6"/></svg> Personnalisation de l\'email</div>';
            h += '<div class="rpt-form-group">';
            h += '<label class="rpt-label">Objet</label>';
            h += '<input type="text" id="rpt-subject" class="si" placeholder="Rapport SEO - {client_name} - {period}" value="Rapport SEO - {client_name} - {period}">';
            h += '</div>';
            h += '<div class="rpt-form-group">';
            h += '<label class="rpt-label">Corps du message</label>';
            h += '<textarea id="rpt-body" class="si" style="height:120px;resize:vertical;line-height:1.6" placeholder="Bonjour {contact_name},...">Bonjour {contact_name},\n\nVeuillez trouver ci-joint le rapport de performance SEO local pour {client_name} couvrant la periode {period}.\n\nCordialement,\n{sender_name}</textarea>';
            h += '</div>';
            h += '<div class="rpt-hint">Variables : <code>{contact_name}</code> prenom du client &bull; <code>{client_name}</code> nom de la fiche &bull; <code>{period}</code> periode &bull; <code>{sender_name}</code> votre nom</div>';
            h += '</div>'; // end fieldset 3

            h += '</div>'; // end modal body

            // ── Footer ──
            h += '<div class="rpt-modal-footer">';
            h += '<button class="btn bs" onclick="APP.reportsAll._closeModal()">Annuler</button>';
            h += '<button class="btn bp" onclick="APP.reportsAll.saveTemplate()">';
            h += '<svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;vertical-align:-2px"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Sauvegarder</button>';
            h += '</div>';

            h += '</div></div>'; // end modal + overlay
            return h;
        },

        _closeModal() {
            const m = document.getElementById('rpt-modal');
            if (m) m.style.display = 'none';
            this._editing = null;
        },

        /** Ensure modal exists in body, create if needed */
        _ensureModal() {
            let m = document.getElementById('rpt-modal');
            if (!m) {
                const wrapper = document.createElement('div');
                wrapper.innerHTML = this._renderFormModal();
                document.body.appendChild(wrapper.firstElementChild);
                m = document.getElementById('rpt-modal');
            }
            return m;
        },

        _setMode(mode) {
            document.getElementById('rpt-send-mode').value = mode;
            document.getElementById('rpt-mode-manual').classList.toggle('selected', mode === 'manual');
            document.getElementById('rpt-mode-auto').classList.toggle('selected', mode === 'auto');
        },

        updateDayOptions() {
            const freq = document.getElementById('rpt-frequency')?.value;
            const daySelect = document.getElementById('rpt-day');
            if (!daySelect) return;
            if (freq === 'weekly') {
                daySelect.innerHTML = '<option value="1">Lundi</option><option value="2">Mardi</option><option value="3">Mercredi</option><option value="4">Jeudi</option><option value="5">Vendredi</option><option value="6">Samedi</option><option value="7">Dimanche</option>';
            } else {
                daySelect.innerHTML = '<option value="1">Le 1er</option><option value="2">Le 2</option><option value="3">Le 3</option><option value="5">Le 5</option><option value="10">Le 10</option><option value="15">Le 15</option><option value="20">Le 20</option>';
            }
        },

        showCreateForm() {
            this._editing = null;
            const m = this._ensureModal();
            if (!m) return;
            document.getElementById('rpt-modal-title').textContent = 'Nouveau template';
            document.getElementById('rpt-name').value = '';
            document.getElementById('rpt-frequency').value = 'monthly';
            this.updateDayOptions();
            document.getElementById('rpt-day').value = '1';
            document.getElementById('rpt-subject').value = 'Rapport SEO - {client_name} - {period}';
            document.getElementById('rpt-body').value = 'Bonjour {contact_name},\n\nVeuillez trouver ci-joint le rapport de performance SEO local pour {client_name} couvrant la periode {period}.\n\nCordialement,\n{sender_name}';
            document.querySelectorAll('#rpt-sections .report-section-check').forEach(el => {
                el.classList.add('checked');
            });
            this._setMode('manual');
            m.style.display = 'flex';
        },

        async editTemplate(id) {
            const data = await APP.fetch(`/api/reports.php?action=get_template&template_id=${id}`);
            if (!data.template) return;
            const t = data.template;
            this._editing = id;
            const m = this._ensureModal();
            if (!m) return;
            document.getElementById('rpt-modal-title').textContent = 'Modifier le template';
            document.getElementById('rpt-name').value = t.name;
            document.getElementById('rpt-frequency').value = t.schedule_frequency || 'monthly';
            this.updateDayOptions();
            document.getElementById('rpt-day').value = t.schedule_day || '1';
            document.getElementById('rpt-subject').value = t.email_subject || 'Rapport SEO - {client_name} - {period}';
            document.getElementById('rpt-body').value = t.email_body || '';
            const sections = (() => { try { return JSON.parse(t.sections || '{}'); } catch(e) { return {}; } })();
            document.querySelectorAll('#rpt-sections .report-section-check').forEach(el => {
                if (sections[el.dataset.val]) el.classList.add('checked');
                else el.classList.remove('checked');
            });
            this._setMode(t.send_mode || 'manual');
            m.style.display = 'flex';
        },

        async saveTemplate() {
            const name = document.getElementById('rpt-name').value.trim();
            if (!name) { APP.toast('Nom du template requis', 'warning'); document.getElementById('rpt-name').focus(); return; }
            if (name.length < 3) { APP.toast('Le nom doit faire au moins 3 caracteres', 'warning'); document.getElementById('rpt-name').focus(); return; }

            const sections = {};
            document.querySelectorAll('#rpt-sections .report-section-check.checked').forEach(el => {
                sections[el.dataset.val] = true;
            });

            const fd = new FormData();
            fd.append('action', 'save_template');
            fd.append('name', name);
            fd.append('schedule_frequency', document.getElementById('rpt-frequency').value);
            fd.append('schedule_day', document.getElementById('rpt-day').value);
            fd.append('sections', JSON.stringify(sections));
            fd.append('email_subject', document.getElementById('rpt-subject').value);
            fd.append('email_body', document.getElementById('rpt-body').value);
            fd.append('send_mode', document.getElementById('rpt-send-mode').value);
            if (this._editing) fd.append('template_id', this._editing);

            const data = await APP.fetch('/api/reports.php', { method: 'POST', body: fd });
            if (data.success) {
                this._editing = null;
                this._closeModal();
                this.load();
                APP.toast('Template sauvegarde', 'success');
            } else {
                APP.toast(data.error || 'Erreur', 'error');
            }
        },

        async toggleActive(id) {
            const fd = new FormData();
            fd.append('action', 'toggle_active');
            fd.append('template_id', id);
            const data = await APP.fetch('/api/reports.php', { method: 'POST', body: fd });
            if (data.success) this.load();
        },

        async deleteTemplate(id) {
            if (!await APP.modal.confirm('Supprimer', 'Supprimer ce template de rapport ?', 'Supprimer', true)) return;
            const fd = new FormData();
            fd.append('action', 'delete_template');
            fd.append('template_id', id);
            const data = await APP.fetch('/api/reports.php', { method: 'POST', body: fd });
            if (data.success) {
                if (this._expandedId === id) { this._expandedId = null; this._expandedTab = null; }
                this.load();
            }
            else APP.toast(data.error || 'Erreur', 'error');
        },

        // ---- Recipients ----
        _bulkMode: false,

        async _loadRecipients(templateId) {
            const zone = document.getElementById('rpt-expand-' + templateId);
            if (!zone) return;
            zone.innerHTML = '<div style="padding:20px;text-align:center;color:var(--t3)"><svg class="spin" viewBox="0 0 24 24" style="width:20px;height:20px;stroke:var(--acc);fill:none;stroke-width:2"><path d="M12 2v4m0 12v4m-7.07-3.93l2.83-2.83m8.48-8.48l2.83-2.83M2 12h4m12 0h4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83"/></svg></div>';

            const data = await APP.fetch(`/api/reports.php?action=get_template&template_id=${templateId}`);
            if (!data.template) return;
            const recipients = data.recipients || [];
            const existingLocIds = new Set(recipients.map(r => String(r.location_id)));

            let h = '<div class="rpt-expand-inner">';

            // Header with tabs
            h += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">';
            h += '<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--t3)">Destinataires <span style="color:var(--acc);font-weight:600">(' + recipients.length + ')</span></div>';
            h += '<div style="display:flex;gap:6px">';
            h += '<button class="rpt-act-btn' + (!this._bulkMode ? ' send' : '') + '" onclick="APP.reportsAll._bulkMode=false;APP.reportsAll._loadRecipients(' + templateId + ')">';
            h += '<svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> Individuel</button>';
            h += '<button class="rpt-act-btn' + (this._bulkMode ? ' send' : '') + '" onclick="APP.reportsAll._bulkMode=true;APP.reportsAll._loadRecipients(' + templateId + ')">';
            h += '<svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg> Ajout groupe</button>';
            h += '</div></div>';

            if (this._bulkMode) {
                // ====== BULK ADD MODE ======
                h += this._renderBulkAdd(templateId, existingLocIds);
            } else {
                // ====== INDIVIDUAL MODE ======

                // Add form
                h += '<div class="rpt-rcpt-form">';
                h += '<div class="rpt-form-group" style="flex:1.5">';
                h += '<label class="rpt-label-sm">Fiche client</label>';
                h += '<select id="rcpt-location" class="si" style="font-size:12px;padding:7px 10px">';
                for (const loc of this._locations) {
                    h += '<option value="' + loc.id + '">' + this._esc(loc.name) + ' - ' + this._esc(loc.city) + '</option>';
                }
                h += '</select></div>';
                h += '<div class="rpt-form-group" style="flex:1">';
                h += '<label class="rpt-label-sm">Email</label>';
                h += '<input type="email" id="rcpt-email" class="si" style="font-size:12px;padding:7px 10px" placeholder="client@email.com">';
                h += '</div>';
                h += '<div class="rpt-form-group" style="flex:.8">';
                h += '<label class="rpt-label-sm">Nom</label>';
                h += '<input type="text" id="rcpt-name" class="si" style="font-size:12px;padding:7px 10px" placeholder="Nom">';
                h += '</div>';
                h += '<button class="btn bp bsm" onclick="APP.reportsAll.addRecipient(' + templateId + ')" style="align-self:flex-end;margin-bottom:1px">';
                h += '<svg viewBox="0 0 24 24" style="width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2;vertical-align:-1px"><path d="M12 4v16m8-8H4"/></svg> Ajouter</button>';
                h += '</div>';

                // List
                if (!recipients.length) {
                    h += '<div style="padding:24px;text-align:center;color:var(--t3);font-size:13px">Aucun destinataire. Ajoutez-en individuellement ou via l\'ajout groupe.</div>';
                } else {
                    h += '<div class="rpt-rcpt-list">';
                    for (const r of recipients) {
                        h += '<div class="rpt-rcpt-row">';
                        h += '<div class="rpt-rcpt-avatar"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>';
                        h += '<div style="flex:1;min-width:0">';
                        h += '<div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + this._esc(r.location_name||'') + ' <span style="color:var(--t3);font-weight:400">- ' + this._esc(r.location_city||'') + '</span></div>';
                        h += '<div style="font-size:12px;color:var(--t2)">' + this._esc(r.recipient_email) + (r.recipient_name ? ' (' + this._esc(r.recipient_name) + ')' : '') + '</div>';
                        h += '</div>';
                        h += '<button class="rpt-act-btn danger" onclick="APP.reportsAll.removeRecipient(' + r.id + ',' + templateId + ')" style="padding:4px 8px"><svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg></button>';
                        h += '</div>';
                    }
                    h += '</div>';
                }
            }

            h += '</div>';
            zone.innerHTML = h;

            // Auto-update bulk count
            if (this._bulkMode) this._updateBulkCount(templateId);
        },

        /** Render bulk-add panel showing all locations as checkboxes */
        _renderBulkAdd(templateId, existingLocIds) {
            const available = this._locations.filter(l => !existingLocIds.has(String(l.id)));
            let h = '';

            if (!available.length) {
                h += '<div style="padding:24px;text-align:center;color:var(--t3);font-size:13px">';
                h += '<svg viewBox="0 0 24 24" style="width:28px;height:28px;stroke:var(--g);fill:none;stroke-width:2;margin-bottom:8px"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
                h += '<div>Toutes vos fiches sont deja ajoutees a ce template !</div>';
                h += '</div>';
                return h;
            }

            // Select all / deselect all
            h += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;padding:0 2px">';
            h += '<div style="font-size:12px;color:var(--t2)">' + available.length + ' fiche(s) disponible(s)</div>';
            h += '<div style="display:flex;gap:8px">';
            h += '<button class="rpt-act-btn" onclick="APP.reportsAll._bulkSelectAll(' + templateId + ', true)" style="padding:3px 8px;font-size:11px">';
            h += '<svg viewBox="0 0 24 24" style="width:11px;height:11px"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><polyline points="9 11 12 14 22 4"/></svg> Tout cocher</button>';
            h += '<button class="rpt-act-btn" onclick="APP.reportsAll._bulkSelectAll(' + templateId + ', false)" style="padding:3px 8px;font-size:11px">';
            h += '<svg viewBox="0 0 24 24" style="width:11px;height:11px"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/></svg> Tout decocher</button>';
            h += '</div></div>';

            // Location rows
            h += '<div class="rpt-bulk-list" id="rpt-bulk-list">';
            for (const loc of available) {
                h += '<div class="rpt-bulk-row" data-loc="' + loc.id + '">';
                h += '<label class="rpt-bulk-check" onclick="event.stopPropagation()">';
                h += '<input type="checkbox" class="rpt-bulk-cb" data-loc="' + loc.id + '" onchange="APP.reportsAll._updateBulkCount(' + templateId + ')" checked>';
                h += '<span class="rpt-bulk-checkmark"></span>';
                h += '</label>';
                h += '<div style="flex:1;min-width:0">';
                h += '<div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + this._esc(loc.name) + '</div>';
                h += '<div style="font-size:11px;color:var(--t3)">' + this._esc(loc.city || '') + '</div>';
                h += '</div>';
                const preEmail = loc.report_email || '';
                const preName = loc.report_contact_name || '';
                h += '<input type="email" class="si rpt-bulk-email" data-loc="' + loc.id + '" placeholder="email@client.com" value="' + this._esc(preEmail) + '" style="width:200px;font-size:12px;padding:6px 10px">';
                h += '<input type="text" class="si rpt-bulk-name" data-loc="' + loc.id + '" placeholder="Nom" value="' + this._esc(preName) + '" style="width:120px;font-size:12px;padding:6px 10px">';
                h += '</div>';
            }
            h += '</div>';

            // Footer with submit
            h += '<div style="display:flex;align-items:center;justify-content:space-between;margin-top:12px;padding-top:12px;border-top:1px solid var(--bdr)">';
            h += '<div id="rpt-bulk-status" style="font-size:12px;color:var(--t3)"></div>';
            h += '<button class="btn bp" id="rpt-bulk-submit" onclick="APP.reportsAll._submitBulk(' + templateId + ')" style="padding:8px 20px">';
            h += '<svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;vertical-align:-2px"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg> Ajouter les selectionnes</button>';
            h += '</div>';

            return h;
        },

        /** Update the count label for bulk add */
        _updateBulkCount(templateId) {
            const cbs = document.querySelectorAll('.rpt-bulk-cb:checked');
            const statusEl = document.getElementById('rpt-bulk-status');
            const btnEl = document.getElementById('rpt-bulk-submit');
            if (statusEl) statusEl.textContent = cbs.length + ' fiche(s) selectionnee(s)';
            if (btnEl) btnEl.disabled = cbs.length === 0;
        },

        /** Select/deselect all in bulk mode */
        _bulkSelectAll(templateId, checked) {
            document.querySelectorAll('.rpt-bulk-cb').forEach(cb => cb.checked = checked);
            this._updateBulkCount(templateId);
        },

        /** Submit bulk add */
        async _submitBulk(templateId) {
            const cbs = document.querySelectorAll('.rpt-bulk-cb:checked');
            if (!cbs.length) { APP.toast('Selectionnez au moins une fiche', 'warning'); return; }

            const items = [];
            let hasError = false;
            cbs.forEach(cb => {
                const locId = cb.dataset.loc;
                const emailInput = document.querySelector('.rpt-bulk-email[data-loc="' + locId + '"]');
                const nameInput = document.querySelector('.rpt-bulk-name[data-loc="' + locId + '"]');
                const email = emailInput ? emailInput.value.trim() : '';
                const name = nameInput ? nameInput.value.trim() : '';

                if (!email) {
                    if (emailInput) { emailInput.style.borderColor = 'var(--r)'; emailInput.focus(); }
                    hasError = true;
                    return;
                }
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    if (emailInput) { emailInput.style.borderColor = 'var(--r)'; emailInput.focus(); }
                    hasError = true;
                    return;
                }
                if (emailInput) emailInput.style.borderColor = '';

                items.push({ location_id: locId, email, name });
            });

            if (hasError) { APP.toast('Remplissez les emails de toutes les fiches cochees', 'warning'); return; }

            // Submit
            const btn = document.getElementById('rpt-bulk-submit');
            if (btn) { btn.disabled = true; btn.textContent = 'Ajout en cours...'; }

            const fd = new FormData();
            fd.append('action', 'bulk_add_recipients');
            fd.append('template_id', templateId);
            fd.append('recipients', JSON.stringify(items));

            const data = await APP.fetch('/api/reports.php', { method: 'POST', body: fd });
            if (data.success) {
                const msg = data.added + ' destinataire(s) ajoute(s)' + (data.skipped > 0 ? ', ' + data.skipped + ' ignore(s)' : '');
                APP.toast(msg, 'success');
                // Update count
                const tpl = this._templates.find(t => t.id == templateId);
                if (tpl) tpl.recipient_count = (parseInt(tpl.recipient_count)||0) + data.added;
                this._bulkMode = false;
                this.render();
                this._loadRecipients(templateId);
            } else {
                APP.toast(data.error || 'Erreur', 'error');
                if (btn) { btn.disabled = false; btn.textContent = 'Ajouter les selectionnes'; }
            }
        },

        async addRecipient(templateId) {
            const email = document.getElementById('rcpt-email').value.trim();
            const name = document.getElementById('rcpt-name').value.trim();
            const locationId = document.getElementById('rcpt-location').value;
            if (!email) { APP.toast('Email requis', 'warning'); document.getElementById('rcpt-email').focus(); return; }
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { APP.toast('Adresse email invalide', 'error'); document.getElementById('rcpt-email').focus(); return; }

            const fd = new FormData();
            fd.append('action', 'add_recipient');
            fd.append('template_id', templateId);
            fd.append('location_id', locationId);
            fd.append('recipient_email', email);
            fd.append('recipient_name', name);

            const data = await APP.fetch('/api/reports.php', { method: 'POST', body: fd });
            if (data.success) {
                APP.toast('Destinataire ajoute', 'success');
                const tpl = this._templates.find(t => t.id == templateId);
                if (tpl) tpl.recipient_count = (parseInt(tpl.recipient_count)||0) + 1;
                this.render();
                this._loadRecipients(templateId);
            }
            else APP.toast(data.error || 'Erreur', 'error');
        },

        async removeRecipient(recipientId, templateId) {
            const fd = new FormData();
            fd.append('action', 'remove_recipient');
            fd.append('recipient_id', recipientId);
            const data = await APP.fetch('/api/reports.php', { method: 'POST', body: fd });
            if (data.success) {
                const tpl = this._templates.find(t => t.id == templateId);
                if (tpl) tpl.recipient_count = Math.max(0, (parseInt(tpl.recipient_count)||0) - 1);
                this.render();
                this._loadRecipients(templateId);
            }
            else APP.toast(data.error || 'Erreur', 'error');
        },

        // ---- History ----
        async _loadHistory(templateId) {
            const zone = document.getElementById('rpt-expand-' + templateId);
            if (!zone) return;
            zone.innerHTML = '<div style="padding:20px;text-align:center;color:var(--t3)"><svg class="spin" viewBox="0 0 24 24" style="width:20px;height:20px;stroke:var(--acc);fill:none;stroke-width:2"><path d="M12 2v4m0 12v4m-7.07-3.93l2.83-2.83m8.48-8.48l2.83-2.83M2 12h4m12 0h4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83"/></svg></div>';

            const data = await APP.fetch(`/api/reports.php?action=list_history&template_id=${templateId}`);
            const history = data.history || [];

            let h = '<div class="rpt-expand-inner">';
            h += '<div class="rpt-expand-header">';
            h += '<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--t3)">Historique des envois</div>';
            h += '</div>';

            if (!history.length) {
                h += '<div style="padding:24px;text-align:center;color:var(--t3);font-size:13px">Aucun envoi pour le moment.</div>';
            } else {
                // Group by month
                const months = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
                const grouped = {};
                for (const entry of history) {
                    const d = entry.sent_at ? new Date(entry.sent_at) : null;
                    const key = d ? (d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0')) : 'unknown';
                    const label = d ? months[d.getMonth()] + ' ' + d.getFullYear() : 'Inconnu';
                    if (!grouped[key]) grouped[key] = { label, entries: [] };
                    grouped[key].entries.push(entry);
                }
                const sortedKeys = Object.keys(grouped).sort().reverse();

                for (const key of sortedKeys) {
                    const grp = grouped[key];
                    const sentCount = grp.entries.filter(e => e.status === 'sent').length;
                    const failedCount = grp.entries.filter(e => e.status === 'failed').length;
                    h += '<div style="margin-bottom:12px">';
                    h += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;padding:0 4px">';
                    h += '<div style="font-size:13px;font-weight:700;color:var(--t1)">' + grp.label + '</div>';
                    h += '<span style="font-size:10px;color:var(--g);font-weight:600">' + sentCount + ' envoye(s)</span>';
                    if (failedCount) h += '<span style="font-size:10px;color:var(--r);font-weight:600">' + failedCount + ' echoue(s)</span>';
                    h += '</div>';
                    h += '<div style="overflow-x:auto"><table class="stats-table" style="margin:0"><thead><tr>';
                    h += '<th>Date</th><th>Fiche</th><th>Destinataire</th><th style="text-align:center">Statut</th>';
                    h += '</tr></thead><tbody>';
                    for (const entry of grp.entries) {
                        const statusCls = entry.status === 'sent' ? 'rpt-st-sent' : (entry.status === 'failed' ? 'rpt-st-failed' : 'rpt-st-pending');
                        const statusLabel = entry.status === 'sent' ? 'Envoye' : (entry.status === 'failed' ? 'Echoue' : 'En attente');
                        const statusIcon = entry.status === 'sent'
                            ? '<svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>'
                            : (entry.status === 'failed'
                                ? '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>'
                                : '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>');
                        const date = entry.sent_at ? new Date(entry.sent_at).toLocaleDateString('fr-FR', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' }) : '—';
                        h += '<tr>';
                        h += '<td style="font-size:12px;color:var(--t3);white-space:nowrap;font-family:\'Space Mono\',monospace">' + date + '</td>';
                        h += '<td style="font-size:12px">' + this._esc(entry.location_name||'') + '</td>';
                        h += '<td style="font-size:12px;color:var(--t2)">' + this._esc(entry.recipient_email||'') + '</td>';
                        h += '<td style="text-align:center"><span class="' + statusCls + '">' + statusIcon + ' ' + statusLabel + '</span></td>';
                        h += '</tr>';
                    }
                    h += '</tbody></table></div></div>';
                }
            }

            h += '</div>';
            zone.innerHTML = h;
        },

        async viewHistory(templateId) {
            this._toggle(templateId, 'history');
        },

        async manageRecipients(templateId) {
            this._toggle(templateId, 'recipients');
        },

        /** Génère les options mois calendaires (6 derniers mois) */
        _getMonthOptions() {
            const months = [];
            const now = new Date();
            for (let i = 1; i <= 6; i++) {
                const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
                const val = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
                const label = d.toLocaleString('fr-FR', { month: 'long', year: 'numeric' });
                months.push({ value: val, label: label.charAt(0).toUpperCase() + label.slice(1) });
            }
            return months;
        },

        /** Affiche le dialogue de sélection du mois puis envoie */
        async triggerSend(templateId) {
            const months = this._getMonthOptions();
            let opts = months.map((m, i) => `<option value="${m.value}"${i === 0 ? ' selected' : ''}>${m.label}</option>`).join('');
            const html = `<div style="margin-bottom:12px;font-size:13px;color:var(--t2);">Sélectionnez le mois calendaire du rapport :</div>
                <select id="rpt-send-month" class="si" style="width:100%;margin-bottom:16px;">${opts}</select>
                <div style="font-size:12px;color:var(--t3);">Le rapport comparera automatiquement M vs M-1.</div>`;
            if (!await APP.modal.confirm('Envoyer les rapports', html, 'Envoyer')) return;
            const month = document.getElementById('rpt-send-month')?.value || '';
            const fd = new FormData();
            fd.append('action', 'trigger_send');
            fd.append('template_id', templateId);
            fd.append('month', month);
            const data = await APP.fetch('/api/reports.php', { method: 'POST', body: fd });
            if (data.success) {
                APP.toast((data.sent||0) + ' rapport(s) envoye(s)' + (data.failed > 0 ? ', ' + data.failed + ' echoue(s)' : ''), data.failed > 0 ? 'warning' : 'success');
                if (data.errors && data.errors.length) APP.toast('Erreurs : ' + data.errors.join(', '), 'error', 10000);
                this.load();
            } else {
                APP.toast(data.error || 'Erreur lors de l\'envoi', 'error');
            }
        },

        async sendTest(templateId) {
            const months = this._getMonthOptions();
            let opts = months.map((m, i) => `<option value="${m.value}"${i === 0 ? ' selected' : ''}>${m.label}</option>`).join('');
            const html = `<div style="margin-bottom:10px;">
                <label style="font-size:12px;color:var(--t3);display:block;margin-bottom:4px;text-transform:uppercase;font-weight:600;letter-spacing:.5px;">Adresse email test</label>
                <input type="email" id="rpt-test-email" class="si" placeholder="email@exemple.com" style="width:100%;">
            </div>
            <div>
                <label style="font-size:12px;color:var(--t3);display:block;margin-bottom:4px;text-transform:uppercase;font-weight:600;letter-spacing:.5px;">Mois du rapport</label>
                <select id="rpt-test-month" class="si" style="width:100%;">${opts}</select>
            </div>`;
            if (!await APP.modal.confirm('Envoyer un test', html, 'Envoyer')) return;
            const email = document.getElementById('rpt-test-email')?.value?.trim();
            const month = document.getElementById('rpt-test-month')?.value || '';
            if (!email) { APP.toast('Adresse email requise', 'warning'); return; }

            const fd = new FormData();
            fd.append('action', 'send_test');
            fd.append('template_id', templateId);
            fd.append('test_email', email);
            fd.append('month', month);

            const data = await APP.fetch('/api/reports.php', { method: 'POST', body: fd });
            if (data.success) APP.toast(data.message || 'Rapport test envoye !', 'success');
            else APP.toast(data.error || 'Erreur lors de l\'envoi du test', 'error');
        },

        // ====== APERÇU RAPPORT ======
        async showPreview(templateId) {
            const data = await APP.fetch('/api/reports.php?action=get_template&template_id=' + templateId);
            if (!data.template) { APP.toast('Template non trouvé', 'error'); return; }
            const recipients = data.recipients || [];
            if (!recipients.length) { APP.toast('Aucun destinataire configuré', 'warning'); return; }

            if (recipients.length === 1) {
                this._loadPreviewData(recipients[0].location_id, recipients[0].location_name || 'Fiche');
                return;
            }
            // Sélecteur de fiche
            const seen = new Set();
            let opts = '';
            for (const r of recipients) {
                if (seen.has(r.location_id)) continue;
                seen.add(r.location_id);
                opts += '<option value="' + r.location_id + '">' + this._esc(r.location_name || '') + ' — ' + this._esc(r.location_city || '') + '</option>';
            }
            const html = '<div style="margin-bottom:12px;font-size:13px;color:var(--t2);">Sélectionnez la fiche à prévisualiser :</div><select id="rpt-preview-loc" class="si" style="width:100%">' + opts + '</select>';
            if (!await APP.modal.confirm('Aperçu du rapport', html, 'Voir l\'aperçu')) return;
            const selId = document.getElementById('rpt-preview-loc')?.value;
            const selName = recipients.find(r => String(r.location_id) === selId)?.location_name || '';
            this._loadPreviewData(selId, selName);
        },

        async _loadPreviewData(locationId, locationName) {
            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            overlay.id = 'rpt-preview-overlay';
            overlay.innerHTML = '<div class="modal-card" style="max-width:900px;max-height:90vh;overflow-y:auto;">' +
                '<div class="modal-header" style="display:flex;align-items:center;justify-content:space-between;"><h3>Aperçu — ' + this._esc(locationName) + '</h3>' +
                '<button onclick="document.getElementById(\'rpt-preview-overlay\').classList.add(\'modal-out\');document.getElementById(\'rpt-preview-overlay\').addEventListener(\'animationend\',function(){this.remove()})" style="background:none;border:none;color:var(--t2);cursor:pointer;font-size:20px;">&times;</button></div>' +
                '<div class="modal-body" style="padding:20px;"><div style="text-align:center;padding:40px;">' +
                '<svg class="spin" viewBox="0 0 24 24" style="width:24px;height:24px;stroke:var(--acc);fill:none;stroke-width:2"><path d="M12 2v4m0 12v4m-7.07-3.93l2.83-2.83m8.48-8.48l2.83-2.83M2 12h4m12 0h4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83"/></svg>' +
                '<div style="margin-top:12px;font-size:13px;color:var(--t3)">Chargement des données...</div></div></div></div>';
            document.body.appendChild(overlay);
            overlay.addEventListener('click', function(e) { if (e.target === overlay) { overlay.classList.add('modal-out'); overlay.addEventListener('animationend', function() { overlay.remove(); }); } });

            const data = await APP.fetch('/api/reports.php?action=preview_report&location_id=' + locationId);
            if (data.error) { overlay.remove(); APP.toast(data.error, 'error'); return; }
            this._renderPreviewModal(overlay, data, locationId);
        },

        _renderPreviewModal(overlay, data, locationId) {
            const loc = data.location || {};
            const keywords = data.keywords || [];
            const rs = data.review_stats || {};
            const ps = data.post_stats || {};
            const ms = data.monthly_stats || [];
            const gd = data.grid_data || [];
            const E = this._esc.bind(this);

            let h = '';
            // Header fiche
            h += '<div style="display:flex;align-items:center;gap:12px;margin-bottom:24px;padding-bottom:16px;border-bottom:1px solid var(--bdr);">';
            h += '<div style="width:40px;height:40px;border-radius:10px;background:var(--acc);display:flex;align-items:center;justify-content:center;font-weight:800;color:#000;font-size:16px;">' + (E(loc.name) || '?')[0].toUpperCase() + '</div>';
            h += '<div><div style="font-size:16px;font-weight:700;">' + E(loc.name) + '</div>';
            h += '<div style="font-size:12px;color:var(--t3);">' + E(loc.city || '') + (loc.category ? ' — ' + E(loc.category) : '') + '</div></div></div>';

            // Mots-clés
            if (keywords.length) {
                h += '<div style="margin-bottom:24px;"><div style="font-size:14px;font-weight:700;margin-bottom:12px;">📍 Positions mots-clés (' + keywords.length + ')</div>';
                h += '<div style="overflow-x:auto"><table><thead><tr><th>Mot-clé</th><th style="text-align:center">Position</th><th style="text-align:center">Local Pack</th></tr></thead><tbody>';
                for (const kw of keywords) {
                    const pos = kw.position ? parseInt(kw.position) : null;
                    const pc = pos ? (pos <= 3 ? 'var(--g)' : pos <= 10 ? 'var(--o)' : 'var(--r)') : 'var(--t3)';
                    h += '<tr><td style="font-size:12px">' + E(kw.keyword) + '</td><td style="text-align:center;font-weight:700;color:' + pc + '">' + (pos || '—') + '</td>';
                    h += '<td style="text-align:center">' + (kw.in_local_pack == 1 ? '<span style="color:var(--g)">✓ Oui</span>' : '<span style="color:var(--t3)">Non</span>') + '</td></tr>';
                }
                h += '</tbody></table></div></div>';
            }

            // Avis
            h += '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px;">';
            h += '<div style="padding:16px;border-radius:10px;background:var(--bg2);border:1px solid var(--bdr);text-align:center;"><div style="font-size:11px;color:var(--t3);text-transform:uppercase;margin-bottom:4px;">Total avis</div><div style="font-size:22px;font-weight:800;">' + (rs.total || 0) + '</div></div>';
            h += '<div style="padding:16px;border-radius:10px;background:var(--bg2);border:1px solid var(--bdr);text-align:center;"><div style="font-size:11px;color:var(--t3);text-transform:uppercase;margin-bottom:4px;">Note moyenne</div><div style="font-size:22px;font-weight:800;color:var(--o);">' + (rs.avg_rating ? '★ ' + rs.avg_rating : '—') + '</div></div>';
            h += '<div style="padding:16px;border-radius:10px;background:var(--bg2);border:1px solid var(--bdr);text-align:center;"><div style="font-size:11px;color:var(--t3);text-transform:uppercase;margin-bottom:4px;">Sans réponse</div><div style="font-size:22px;font-weight:800;color:' + ((rs.unanswered > 0) ? 'var(--o)' : 'var(--g)') + ';">' + (rs.unanswered || 0) + '</div></div>';
            h += '</div>';

            // Stats mensuelles
            if (ms.length) {
                h += '<div style="margin-bottom:24px;"><div style="font-size:14px;font-weight:700;margin-bottom:12px;">📊 Statistiques mensuelles</div>';
                h += '<div style="overflow-x:auto"><table><thead><tr><th>Mois</th><th style="text-align:right">Search</th><th style="text-align:right">Maps</th><th style="text-align:right">Appels</th><th style="text-align:right">Site web</th><th style="text-align:right">Itinéraires</th></tr></thead><tbody>';
                for (const m of ms) {
                    h += '<tr><td style="font-size:12px;font-family:\'Space Mono\',monospace">' + m.month + '</td>';
                    h += '<td style="text-align:right">' + (parseInt(m.impressions_search)||0).toLocaleString('fr-FR') + '</td>';
                    h += '<td style="text-align:right">' + (parseInt(m.impressions_maps)||0).toLocaleString('fr-FR') + '</td>';
                    h += '<td style="text-align:right">' + (parseInt(m.call_clicks)||0) + '</td>';
                    h += '<td style="text-align:right">' + (parseInt(m.website_clicks)||0) + '</td>';
                    h += '<td style="text-align:right">' + (parseInt(m.direction_requests)||0) + '</td></tr>';
                }
                h += '</tbody></table></div></div>';
            }

            // Posts
            h += '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px;">';
            h += '<div style="padding:16px;border-radius:10px;background:var(--bg2);border:1px solid var(--bdr);text-align:center;"><div style="font-size:11px;color:var(--t3);text-transform:uppercase;margin-bottom:4px;">Total posts</div><div style="font-size:22px;font-weight:800;">' + (ps.total || 0) + '</div></div>';
            h += '<div style="padding:16px;border-radius:10px;background:var(--bg2);border:1px solid var(--bdr);text-align:center;"><div style="font-size:11px;color:var(--t3);text-transform:uppercase;margin-bottom:4px;">Publiés</div><div style="font-size:22px;font-weight:800;color:var(--g);">' + (ps.published || 0) + '</div></div>';
            h += '<div style="padding:16px;border-radius:10px;background:var(--bg2);border:1px solid var(--bdr);text-align:center;"><div style="font-size:11px;color:var(--t3);text-transform:uppercase;margin-bottom:4px;">Programmés</div><div style="font-size:22px;font-weight:800;color:var(--acc);">' + (ps.scheduled || 0) + '</div></div>';
            h += '</div>';

            // Grille visibilité
            if (gd.length) {
                h += '<div style="margin-bottom:24px;"><div style="font-size:14px;font-weight:700;margin-bottom:12px;">🗺️ Visibilité locale (grille)</div>';
                h += '<div style="overflow-x:auto"><table><thead><tr><th>Mot-clé</th><th style="text-align:center">Pos. moy.</th><th style="text-align:center">Visibilité</th><th style="text-align:center">Top 3</th><th style="text-align:center">Top 10</th><th style="text-align:center">Top 20</th></tr></thead><tbody>';
                for (const g of gd) {
                    const vis = parseFloat(g.visibility_score)||0;
                    const vc = vis >= 70 ? 'var(--g)' : vis >= 40 ? 'var(--o)' : 'var(--r)';
                    h += '<tr><td style="font-size:12px">' + E(g.keyword) + '</td>';
                    h += '<td style="text-align:center;font-weight:600">' + parseFloat(g.avg_position).toFixed(1) + '</td>';
                    h += '<td style="text-align:center;font-weight:700;color:' + vc + '">' + vis.toFixed(0) + '%</td>';
                    h += '<td style="text-align:center;color:var(--g)">' + (g.top3_count||0) + '</td>';
                    h += '<td style="text-align:center">' + (g.top10_count||0) + '</td>';
                    h += '<td style="text-align:center">' + (g.top20_count||0) + '</td></tr>';
                }
                h += '</tbody></table></div></div>';
            }

            // Footer
            h += '<div style="display:flex;gap:12px;justify-content:flex-end;padding-top:16px;border-top:1px solid var(--bdr);">';
            h += '<button class="btn bs" onclick="document.getElementById(\'rpt-preview-overlay\').classList.add(\'modal-out\');document.getElementById(\'rpt-preview-overlay\').addEventListener(\'animationend\',function(){this.remove()})">Fermer</button>';
            h += '<button class="btn bp" onclick="APP.reportsAll._generatePreviewPdf(' + locationId + ')" id="rpt-gen-pdf-btn"><svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;vertical-align:-2px"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> Générer le PDF</button>';
            h += '</div>';

            const card = overlay.querySelector('.modal-card');
            if (card) card.querySelector('.modal-body').innerHTML = h;
        },

        async _generatePreviewPdf(locationId) {
            const btn = document.getElementById('rpt-gen-pdf-btn');
            if (btn) { btn.disabled = true; btn.innerHTML = '<svg class="spin" viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2"><path d="M12 2v4m0 12v4m-7.07-3.93l2.83-2.83m8.48-8.48l2.83-2.83M2 12h4m12 0h4"/></svg> Génération...'; }
            const fd = new FormData();
            fd.append('action', 'generate_preview_pdf');
            fd.append('location_id', locationId);
            const data = await APP.fetch('/api/reports.php', { method: 'POST', body: fd });
            if (btn) { btn.disabled = false; btn.innerHTML = '<svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;vertical-align:-2px"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> Générer le PDF'; }
            if (data.success && data.pdf_url) { window.open(data.pdf_url, '_blank'); }
            else { APP.toast(data.error || 'Erreur génération PDF', 'error'); }
        },

        _esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    },

    // ====================================================
    // MODULE POST VISUALS — Génération de visuels
    // ====================================================
    postVisuals: {
        _locationId: null,
        _templates: [],
        _images: [],
        _stats: {},
        _selectedTemplateId: null,
        _view: 'gallery',     // 'gallery' (always)
        _filter: 'all',       // 'all' | 'draft' | 'generated'
        _editingId: null,
        _previewCache: {},
        _selectMode: false,
        _selectedIds: new Set(),
        _sortBy: 'date_desc', // 'date_desc' | 'date_asc' | 'alpha'
        _templateFilter: null, // template_id ou null

        _esc(s) {
            if (!s) return '';
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        },

        async load(locationId) {
            this._locationId = locationId;
            this._view = 'gallery';
            this._filter = 'all';
            this._editingId = null;
            this._previewCache = {};
            this._selectMode = false;
            this._selectedIds = new Set();
            this._sortBy = 'date_desc';
            this._templateFilter = null;

            // Charger templates + images en parallèle
            const [tplData, imgData] = await Promise.all([
                APP.fetch('/api/post-visuals.php?action=list_templates'),
                APP.fetch('/api/post-visuals.php?action=list_images&location_id=' + locationId)
            ]);

            this._templates = tplData.templates || [];
            this._images = imgData.images || [];
            this._stats = imgData.stats || {};

            if (!this._selectedTemplateId && this._templates.length) {
                this._selectedTemplateId = this._templates[0].id;
            }

            this.render();
        },

        render() {
            const c = document.getElementById('module-content');
            if (!c) return;

            let h = '';

            // Header
            h += `<div class="sh"><div class="stit">
                <svg viewBox="0 0 24 24" style="width:22px;height:22px;stroke:var(--acc);fill:none;stroke-width:2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                VISUELS GOOGLE POSTS
            </div></div>`;

            // Stats bar
            const st = this._stats;
            h += `<div style="padding:12px 20px;border-bottom:1px solid var(--bdr);display:flex;gap:12px;align-items:center;flex-wrap:wrap;">`;
            h += `<span style="font-size:12px;color:var(--t3);">Total : <b style="color:var(--t1)">${st.total || 0}</b></span>`;
            const drafts = (st.draft || 0) + (st.validated || 0);
            if (drafts) h += `<span style="font-size:11px;padding:2px 8px;border-radius:4px;background:var(--bg2);color:var(--t2);">${drafts} brouillon${drafts > 1 ? 's' : ''}</span>`;
            if (st.generated) h += `<span style="font-size:11px;padding:2px 8px;border-radius:4px;background:rgba(34,197,94,0.1);color:var(--g);">${st.generated} généré${st.generated > 1 ? 's' : ''}</span>`;
            // Publiés masqués de cette vue (visibles dans Listes Auto)

            h += `<div style="margin-left:auto;display:flex;gap:6px;">`;
            h += `<button class="btn bp bsm" onclick="APP.postVisuals.showImportModal()" style="font-size:12px;">
                <svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                Importer CSV</button>`;
            h += `<button class="btn bs bsm" onclick="APP.postVisuals.addSingle()" style="font-size:12px;">
                <svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Ajouter un visuel</button>`;
            h += `</div></div>`;

            // Toujours la galerie
            h += this.renderGallery();

            c.innerHTML = h;
        },

        // === IMPORT CSV (MODALE) ===
        showImportModal() {
            const esc = s => this._esc(s);

            let m = document.getElementById('pv-modal');
            if (!m) { m = document.createElement('div'); m.id = 'pv-modal'; document.body.appendChild(m); }

            let h = `<div style="position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;" onclick="if(event.target===this)APP.postVisuals.closeModal()">
                <div style="background:var(--card);border-radius:16px;width:100%;max-width:700px;max-height:90vh;overflow-y:auto;border:1px solid var(--bdr);">
                    <div style="padding:20px;border-bottom:1px solid var(--bdr);display:flex;justify-content:space-between;align-items:center;">
                        <h3 style="margin:0;color:var(--t1);font-family:'Anton',sans-serif;font-size:16px;">
                            <svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:var(--acc);fill:none;stroke-width:2;vertical-align:middle;margin-right:6px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            IMPORTER DES VISUELS
                        </h3>
                        <button onclick="APP.postVisuals.closeModal()" style="background:none;border:none;color:var(--t3);cursor:pointer;font-size:20px;">✕</button>
                    </div>
                    <div style="padding:20px;">

                        <!-- ÉTAPE 1 : Template -->
                        <div style="margin-bottom:20px;">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                                <span style="width:22px;height:22px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;">1</span>
                                <span style="font-size:13px;color:var(--t1);font-weight:600;">Choisir le template</span>
                            </div>
                            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                ${this._templates.map(t => {
                                    const sel = this._selectedTemplateId == t.id;
                                    return `<button class="btn ${sel ? 'bp' : 'bs'} bsm pv-import-tpl-btn" data-tpl="${t.id}" onclick="APP.postVisuals._selectImportTemplate(${t.id})" style="font-size:11px;">${esc(t.name)}</button>`;
                                }).join('')}
                            </div>
                            <!-- Mini aperçu -->
                            <div style="margin-top:10px;display:flex;gap:12px;align-items:center;">
                                <div style="width:160px;border:1px solid var(--bdr);border-radius:6px;overflow:hidden;">
                                    <div id="import-tpl-preview" style="aspect-ratio:4/3;background:var(--bg);display:flex;align-items:center;justify-content:center;">
                                        <span style="font-size:10px;color:var(--t3);">Cliquer un template</span>
                                    </div>
                                </div>
                                <div style="font-size:11px;color:var(--t3);line-height:1.5;">
                                    Les couleurs et le logo seront personnalisables après l'import via<br>le bouton <b style="color:var(--acc);">Personnaliser et Générer</b>.
                                </div>
                            </div>
                        </div>

                        <!-- ÉTAPE 2 : Upload -->
                        <div>
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                                <span style="width:22px;height:22px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;">2</span>
                                <span style="font-size:13px;color:var(--t1);font-weight:600;">Uploader le fichier CSV</span>
                            </div>
                            <div style="padding:8px 12px;background:var(--bg2);border-radius:6px;margin-bottom:10px;font-size:11px;color:var(--t2);line-height:1.5;">
                                Colonnes : <code style="background:var(--inp);padding:1px 3px;border-radius:3px;font-size:10px;">visual_text</code>
                                <code style="background:var(--inp);padding:1px 3px;border-radius:3px;font-size:10px;">description</code>
                                <code style="background:var(--inp);padding:1px 3px;border-radius:3px;font-size:10px;">cta_text</code> (opt.)
                                — séparateur <code>,</code> ou <code>;</code> auto-détecté
                            </div>
                            <div style="border:2px dashed var(--bdr);border-radius:10px;padding:28px;text-align:center;cursor:pointer;transition:border-color 0.2s;"
                                  id="csv-drop-zone"
                                  onclick="document.getElementById('csv-file-input').click()"
                                  ondragover="event.preventDefault();this.style.borderColor='var(--acc)'"
                                  ondragleave="this.style.borderColor='var(--bdr)'"
                                  ondrop="event.preventDefault();this.style.borderColor='var(--bdr)';APP.postVisuals.handleCsvDrop(event)">
                                <svg viewBox="0 0 24 24" style="width:32px;height:32px;stroke:var(--t3);fill:none;stroke-width:1.5;margin-bottom:4px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                <p style="color:var(--t2);font-size:13px;margin:0;">Glisser le CSV ici ou <span style="color:var(--acc);text-decoration:underline;">parcourir</span></p>
                                <input type="file" id="csv-file-input" accept=".csv,.txt" style="display:none" onchange="APP.postVisuals.uploadCsv(this.files[0])">
                            </div>
                            <div id="csv-upload-status" style="margin-top:8px;"></div>
                        </div>

                    </div>
                </div>
            </div>`;

            m.innerHTML = h;

            // Auto-preview du template sélectionné
            this._loadImportTemplatePreview();
        },

        _selectImportTemplate(tplId) {
            this._selectedTemplateId = tplId;
            document.querySelectorAll('.pv-import-tpl-btn').forEach(btn => {
                btn.className = 'btn ' + (btn.dataset.tpl == tplId ? 'bp' : 'bs') + ' bsm pv-import-tpl-btn';
            });
            this._loadImportTemplatePreview();
        },

        async _loadImportTemplatePreview() {
            const zone = document.getElementById('import-tpl-preview');
            if (!zone) return;
            zone.innerHTML = '<span style="font-size:10px;color:var(--acc);">⏳</span>';

            const params = new URLSearchParams({
                action: 'preview',
                template_id: this._selectedTemplateId,
                visual_text: 'Exemple de texte visuel',
                cta_text: 'boustacom.fr',
                location_id: this._locationId
            });

            const data = await APP.fetch('/api/post-visuals.php?' + params.toString());
            if (data.success && data.image) {
                zone.innerHTML = `<img src="${data.image}" style="width:100%;height:100%;object-fit:cover;">`;
            } else {
                zone.innerHTML = '<span style="font-size:10px;color:var(--r);">Erreur</span>';
            }
        },

        handleCsvDrop(event) {
            const file = event.dataTransfer?.files?.[0];
            if (file) this.uploadCsv(file);
        },

        async uploadCsv(file) {
            if (!file) return;
            const status = document.getElementById('csv-upload-status');
            if (status) status.innerHTML = '<p style="color:var(--acc);font-size:13px;">⏳ Import en cours...</p>';

            const fd = new FormData();
            fd.append('action', 'import_csv');
            fd.append('location_id', this._locationId);
            fd.append('template_id', this._selectedTemplateId);
            fd.append('csv_file', file);

            const data = await APP.fetch('/api/post-visuals.php', { method: 'POST', body: fd });

            if (data.success) {
                const nb = data.imported;
                this.closeModal();
                APP.toast(nb + ' visuel(s) importé(s) — personnalisez et générez !', 'success');

                // Recharger les données puis enchaîner avec la modale Personnaliser
                await this.load(this._locationId);

                // Auto-ouvrir la modale de personnalisation/génération
                if (nb > 0) {
                    setTimeout(() => this.generateAll(), 400);
                }
            } else {
                if (status) status.innerHTML = `<p style="color:var(--r);font-size:13px;">❌ ${data.error || 'Erreur'}</p>`;
            }
        },

        // === GALERIE ===
        renderGallery() {
            const esc = s => this._esc(s);
            let images = [...this._images];

            // Filtre par status
            if (this._filter === 'draft') {
                images = images.filter(i => i.status === 'draft' || i.status === 'preview' || i.status === 'validated');
            } else if (this._filter !== 'all') {
                images = images.filter(i => i.status === this._filter);
            }

            // Filtre par template
            if (this._templateFilter) {
                images = images.filter(i => i.template_id == this._templateFilter);
            }

            // Tri
            if (this._sortBy === 'date_asc') images.sort((a,b) => new Date(a.created_at) - new Date(b.created_at));
            else if (this._sortBy === 'alpha') images.sort((a,b) => (a.visual_text||'').localeCompare(b.visual_text||''));
            else images.sort((a,b) => new Date(b.created_at) - new Date(a.created_at)); // date_desc default

            let h = '';
            const f = this._filter;
            const selCount = this._selectedIds.size;

            // === LIGNE 1 : Filtres status + Template filter + Tri + Toggle sélection ===
            h += `<div style="padding:10px 20px;border-bottom:1px solid var(--bdr);display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                <button class="btn ${f==='all'?'bp':'bs'} bsm" onclick="APP.postVisuals._filter='all';APP.postVisuals.render()" style="font-size:11px;">Tous (${this._images.length})</button>
                <button class="btn ${f==='draft'?'bp':'bs'} bsm" onclick="APP.postVisuals._filter='draft';APP.postVisuals.render()" style="font-size:11px;">Brouillons</button>
                <button class="btn ${f==='generated'?'bp':'bs'} bsm" onclick="APP.postVisuals._filter='generated';APP.postVisuals.render()" style="font-size:11px;">Générés</button>`;

            // Séparateur
            h += `<span style="width:1px;height:20px;background:var(--bdr);margin:0 4px;"></span>`;

            // Template filter
            h += `<select class="si" style="font-size:11px;padding:4px 8px;height:28px;" onchange="APP.postVisuals._templateFilter=this.value||null;APP.postVisuals.render()">
                <option value="">Tous les templates</option>
                ${this._templates.map(t => `<option value="${t.id}" ${this._templateFilter == t.id ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}
            </select>`;

            // Tri
            h += `<select class="si" style="font-size:11px;padding:4px 8px;height:28px;" onchange="APP.postVisuals._sortBy=this.value;APP.postVisuals.render()">
                <option value="date_desc" ${this._sortBy==='date_desc'?'selected':''}>Plus récent</option>
                <option value="date_asc" ${this._sortBy==='date_asc'?'selected':''}>Plus ancien</option>
                <option value="alpha" ${this._sortBy==='alpha'?'selected':''}>Alphabétique</option>
            </select>`;

            // Spacer + Actions droite
            h += `<div style="margin-left:auto;display:flex;gap:6px;align-items:center;">`;

            // Toggle sélection
            h += `<button class="btn ${this._selectMode ? 'bp' : 'bs'} bsm" onclick="APP.postVisuals.toggleSelectMode()" style="font-size:11px;">
                ${this._selectMode ? '✕ Annuler' : '☐ Sélectionner'}
            </button>`;

            // Actions batch classiques (si pas en mode sélection)
            if (!this._selectMode) {
                const pendingTotal = this._images.filter(i => i.status === 'draft' || i.status === 'preview' || i.status === 'validated').length;

                if (pendingTotal > 0) {
                    h += `<button class="btn bp bsm" onclick="APP.postVisuals.generateAll()" style="font-size:11px;">
                        <svg viewBox="0 0 24 24" style="width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                        Personnaliser et Générer (${pendingTotal})</button>`;
                }
                const schedulable = this._images.filter(i => i.status === 'generated' && !i.google_post_id).length;
                if (schedulable > 0) {
                    h += `<button class="btn bp bsm" onclick="APP.postVisuals.showScheduleModal()" style="font-size:11px;background:var(--g);border-color:var(--g);">
                        <svg viewBox="0 0 24 24" style="width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        Programmer (${schedulable})</button>`;
                }
            }
            h += `</div></div>`;

            // === LIGNE 2 : Barre d'actions bulk (visible en mode sélection) ===
            if (this._selectMode) {
                h += `<div style="padding:8px 20px;border-bottom:1px solid var(--bdr);background:rgba(0,212,255,0.05);display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;color:var(--t2);">
                        <input type="checkbox" ${selCount === images.length && images.length > 0 ? 'checked' : ''} onchange="APP.postVisuals.${selCount === images.length && images.length > 0 ? 'deselectAll' : 'selectAll'}()" style="accent-color:var(--acc);width:16px;height:16px;">
                        Tout sélectionner
                    </label>
                    <span style="font-size:12px;color:var(--acc);font-weight:600;">${selCount} sélectionné${selCount > 1 ? 's' : ''}</span>`;

                if (selCount > 0) {
                    h += `<span style="width:1px;height:20px;background:var(--bdr);margin:0 4px;"></span>
                        <button class="btn bs bsm" onclick="APP.postVisuals.bulkChangeTemplate()" style="font-size:11px;">Changer template</button>
                        <button class="btn bs bsm" onclick="APP.postVisuals.bulkDelete()" style="font-size:11px;color:var(--r);border-color:var(--r);">
                            <svg viewBox="0 0 24 24" style="width:12px;height:12px;stroke:var(--r);fill:none;stroke-width:2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                            Supprimer</button>`;
                }
                h += `</div>`;
            }

            if (images.length === 0) {
                h += `<div style="padding:60px 20px;text-align:center;">
                    <svg viewBox="0 0 24 24" style="width:48px;height:48px;stroke:var(--t3);fill:none;stroke-width:1;margin-bottom:12px;opacity:0.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                    <p style="color:var(--t2);font-size:15px;margin:0 0 6px;">Aucun visuel pour le moment</p>
                    <p style="color:var(--t3);font-size:12px;margin:0 0 16px;">Commencez par importer vos textes depuis un fichier CSV</p>
                    <button class="btn bp" onclick="APP.postVisuals.showImportModal()" style="font-size:13px;padding:10px 24px;">
                        <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        Importer un CSV
                    </button>
                </div>`;
                return h;
            }

            // Grille de visuels
            h += `<div style="padding:16px;display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;">`;

            for (const img of images) {
                h += this.renderImageCard(img);
            }

            h += `</div>`;
            return h;
        },

        renderImageCard(img) {
            const esc = s => this._esc(s);
            const statusColors = {
                draft: 'var(--t3)', validated: 'var(--t3)', generated: 'var(--g)', published: '#a855f7', preview: 'var(--t3)'
            };
            const statusLabels = {
                draft: 'Brouillon', validated: 'Brouillon', generated: 'Généré', published: 'Publié', preview: 'Brouillon'
            };

            const isSelected = this._selectedIds.has(img.id);
            const borderColor = isSelected ? 'var(--acc)' : 'var(--bdr)';
            const cardClick = this._selectMode ? `onclick="APP.postVisuals.toggleSelect(${img.id})"` : '';

            let h = `<div style="background:var(--bg2);border-radius:12px;overflow:hidden;border:2px solid ${borderColor};transition:border-color 0.2s;position:relative;${this._selectMode ? 'cursor:pointer;' : ''}"
                      ${this._selectMode ? cardClick : `onmouseover="this.style.borderColor='var(--acc)'" onmouseout="this.style.borderColor='${borderColor}'"`}>`;

            // Checkbox en mode sélection
            if (this._selectMode) {
                h += `<div style="position:absolute;top:8px;left:8px;z-index:2;">
                    <input type="checkbox" ${isSelected ? 'checked' : ''} onchange="APP.postVisuals.toggleSelect(${img.id})" onclick="event.stopPropagation()"
                        style="width:20px;height:20px;accent-color:var(--acc);cursor:pointer;border-radius:4px;">
                </div>`;
            }

            // Image preview ou placeholder
            if (img.file_url && (img.status === 'generated' || img.status === 'published')) {
                h += `<div style="aspect-ratio:4/3;background:#0a0a0a;position:relative;cursor:pointer;" onclick="window.open('${esc(img.file_url)}','_blank')">
                    <img src="${esc(img.file_url)}" style="width:100%;height:100%;object-fit:cover;" loading="lazy">
                </div>`;
            } else {
                // Preview dynamique
                h += `<div style="aspect-ratio:4/3;background:var(--bg);display:flex;align-items:center;justify-content:center;position:relative;cursor:pointer;"
                      id="preview-zone-${img.id}" onclick="APP.postVisuals.loadPreview(${img.id},${img.template_id})">
                    <div style="text-align:center;padding:20px;">
                        <svg viewBox="0 0 24 24" style="width:32px;height:32px;stroke:var(--t3);fill:none;stroke-width:1.5;opacity:0.5;margin-bottom:8px;"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                        <p style="font-size:11px;color:var(--t3);margin:0;">Cliquer pour prévisualiser</p>
                    </div>
                </div>`;
            }

            // Infos
            h += `<div style="padding:12px;">`;

            // Status badge
            h += `<div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${statusColors[img.status] || 'var(--t3)'};"></span>
                <span style="font-size:11px;color:${statusColors[img.status] || 'var(--t3)'};font-weight:600;">${statusLabels[img.status] || img.status}</span>
                <span style="font-size:10px;color:var(--t3);margin-left:auto;">${esc(img.template_name || '')}</span>
            </div>`;

            // Texte visuel
            const shortText = (img.visual_text || '').substring(0, 80);
            h += `<p style="font-size:13px;color:var(--t1);margin:0 0 4px;font-weight:600;line-height:1.3;">${esc(shortText)}${(img.visual_text || '').length > 80 ? '...' : ''}</p>`;

            // Description (tronquée)
            if (img.description) {
                const shortDesc = img.description.substring(0, 100);
                h += `<p style="font-size:11px;color:var(--t3);margin:0 0 8px;line-height:1.4;">${esc(shortDesc)}${img.description.length > 100 ? '...' : ''}</p>`;
            }

            // Actions
            h += `<div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:8px;">`;

            if (img.status === 'draft' || img.status === 'preview' || img.status === 'validated') {
                h += `<button class="btn bs bsm" onclick="APP.postVisuals.editImage(${img.id})" style="font-size:10px;">✏️ Éditer</button>`;
                h += `<button class="btn bp bsm" onclick="APP.postVisuals.generateOne(${img.id})" style="font-size:10px;">🖼️ Générer</button>`;
            }
            if (img.status === 'generated') {
                h += `<button class="btn bs bsm" onclick="window.open('${esc(img.file_url || '')}','_blank')" style="font-size:10px;">👁️ Voir</button>`;
                h += `<button class="btn bs bsm" onclick="APP.postVisuals.generateOne(${img.id})" style="font-size:10px;">🔄 Regénérer</button>`;
            }

            h += `<button class="btn bs bsm" onclick="APP.postVisuals.deleteImage(${img.id})" style="font-size:10px;color:var(--r);margin-left:auto;">🗑️</button>`;
            h += `</div>`;

            h += `</div></div>`;
            return h;
        },

        // === ACTIONS ===

        async loadPreview(imageId, templateId) {
            const zone = document.getElementById('preview-zone-' + imageId);
            if (!zone) return;

            const img = this._images.find(i => i.id == imageId);
            if (!img) return;

            zone.innerHTML = '<div style="text-align:center;padding:20px;"><p style="font-size:11px;color:var(--acc);">⏳ Génération preview...</p></div>';

            const params = new URLSearchParams({
                action: 'preview',
                template_id: templateId || this._selectedTemplateId,
                visual_text: img.visual_text || 'Texte d\'exemple',
                cta_text: img.cta_text || '',
                location_id: this._locationId
            });

            // Couleurs custom depuis variables JSON
            if (img.variables) {
                try {
                    const vars = typeof img.variables === 'string' ? JSON.parse(img.variables) : img.variables;
                    if (vars.bg_color) params.append('bg_color', vars.bg_color);
                    if (vars.text_color) params.append('text_color', vars.text_color);
                } catch(e) {}
            }

            const data = await APP.fetch('/api/post-visuals.php?' + params.toString());

            if (data.success && data.image) {
                zone.innerHTML = `<img src="${data.image}" style="width:100%;height:100%;object-fit:cover;">`;
            } else {
                zone.innerHTML = '<div style="text-align:center;padding:20px;"><p style="font-size:11px;color:var(--r);">❌ Erreur preview</p></div>';
            }
        },

        addSingle() {
            this._editingId = null;
            this.showEditModal();
        },

        editImage(id) {
            this._editingId = id;
            this.showEditModal();
        },

        async showEditModal() {
            const img = this._editingId ? this._images.find(i => i.id == this._editingId) : null;
            const isEdit = !!img;

            // Récupérer les couleurs custom si édition
            let curBg = '', curText = '';
            if (img && img.variables) {
                try {
                    const vars = typeof img.variables === 'string' ? JSON.parse(img.variables) : img.variables;
                    curBg = vars.bg_color || '';
                    curText = vars.text_color || '';
                } catch(e) {}
            }

            // Récupérer le logo du client
            const logoData = await APP.fetch('/api/post-visuals.php?action=get_logo&location_id=' + this._locationId);

            // Modal overlay
            let m = document.getElementById('pv-modal');
            if (!m) {
                m = document.createElement('div');
                m.id = 'pv-modal';
                document.body.appendChild(m);
            }

            const esc = s => this._esc(s);

            let h = `<div style="position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;" onclick="if(event.target===this)APP.postVisuals.closeModal()">
                <div style="background:var(--card);border-radius:16px;width:100%;max-width:750px;max-height:90vh;overflow-y:auto;border:1px solid var(--bdr);">
                    <div style="padding:20px;border-bottom:1px solid var(--bdr);display:flex;justify-content:space-between;align-items:center;">
                        <h3 style="margin:0;color:var(--t1);font-family:'Anton',sans-serif;font-size:16px;">${isEdit ? 'ÉDITER LE VISUEL' : 'NOUVEAU VISUEL'}</h3>
                        <button onclick="APP.postVisuals.closeModal()" style="background:none;border:none;color:var(--t3);cursor:pointer;font-size:20px;">✕</button>
                    </div>
                    <div style="padding:20px;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                            <div style="grid-column:1/3;">
                                <label style="display:block;font-size:12px;color:var(--t3);margin-bottom:4px;">Template</label>
                                <select id="pv-edit-template" style="width:100%;padding:8px 12px;background:var(--bg);border:1px solid var(--bdr);border-radius:8px;color:var(--t1);font-size:13px;">
                                    ${this._templates.map(t => `<option value="${t.id}" ${(img?.template_id || this._selectedTemplateId) == t.id ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}
                                </select>
                            </div>
                            <div style="grid-column:1/3;">
                                <label style="display:block;font-size:12px;color:var(--t3);margin-bottom:4px;">Texte du visuel <span style="color:var(--r);">*</span></label>
                                <textarea id="pv-edit-visual" rows="3" style="width:100%;padding:8px 12px;background:var(--bg);border:1px solid var(--bdr);border-radius:8px;color:var(--t1);font-size:13px;resize:vertical;font-family:inherit;">${esc(img?.visual_text || '')}</textarea>
                            </div>
                            <div style="grid-column:1/3;">
                                <label style="display:block;font-size:12px;color:var(--t3);margin-bottom:4px;">Description Google Post</label>
                                <textarea id="pv-edit-desc" rows="3" style="width:100%;padding:8px 12px;background:var(--bg);border:1px solid var(--bdr);border-radius:8px;color:var(--t1);font-size:13px;resize:vertical;font-family:inherit;">${esc(img?.description || '')}</textarea>
                            </div>
                            <div>
                                <label style="display:block;font-size:12px;color:var(--t3);margin-bottom:4px;">Texte CTA (optionnel)</label>
                                <input id="pv-edit-cta" value="${esc(img?.cta_text || '')}" style="width:100%;padding:8px 12px;background:var(--bg);border:1px solid var(--bdr);border-radius:8px;color:var(--t1);font-size:13px;">
                            </div>
                            <div>
                                <label style="display:block;font-size:12px;color:var(--t3);margin-bottom:4px;">
                                    <svg viewBox="0 0 24 24" style="width:12px;height:12px;stroke:var(--g);fill:none;stroke-width:2;vertical-align:middle;margin-right:2px;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                    Mot-clé SEO (URL image)
                                </label>
                                <input id="pv-edit-seo-kw" value="${esc(img?.seo_keyword || '')}" placeholder="Ex: boulangerie artisanale" style="width:100%;padding:8px 12px;background:var(--bg);border:1px solid var(--bdr);border-radius:8px;color:var(--t1);font-size:13px;">
                                <div style="font-size:10px;color:var(--t3);margin-top:3px;">Utilisé dans l'URL du fichier pour le SEO image</div>
                            </div>
                            <div>
                                <label style="display:block;font-size:12px;color:var(--t3);margin-bottom:4px;">Ordre</label>
                                <input id="pv-edit-order" type="number" value="${img?.sort_order || 0}" style="width:100%;padding:8px 12px;background:var(--bg);border:1px solid var(--bdr);border-radius:8px;color:var(--t1);font-size:13px;">
                            </div>
                        </div>

                        <!-- Couleurs personnalisées -->
                        <div style="margin-top:16px;padding:14px;background:var(--bg2);border-radius:10px;border:1px solid var(--bdr);">
                            <div style="font-size:12px;color:var(--t2);font-weight:600;margin-bottom:10px;">
                                <svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:var(--acc);fill:none;stroke-width:2;vertical-align:middle;margin-right:4px;"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/><circle cx="12" cy="12" r="3"/></svg>
                                Personnalisation couleurs
                            </div>
                            <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;">
                                <div>
                                    <label style="display:block;font-size:11px;color:var(--t3);margin-bottom:4px;">Couleur de fond</label>
                                    <div style="display:flex;align-items:center;gap:6px;">
                                        <input type="color" id="pv-edit-bg-color" value="${curBg || '#1a1a2e'}" style="width:36px;height:36px;border:1px solid var(--bdr);border-radius:6px;cursor:pointer;padding:2px;background:var(--bg);">
                                        <input type="text" id="pv-edit-bg-hex" value="${curBg}" placeholder="Défaut template" style="width:110px;padding:6px 8px;background:var(--bg);border:1px solid var(--bdr);border-radius:6px;color:var(--t1);font-size:11px;font-family:'Space Mono',monospace;"
                                            oninput="const v=this.value;if(/^#[0-9a-fA-F]{6}$/.test(v))document.getElementById('pv-edit-bg-color').value=v">
                                    </div>
                                </div>
                                <div>
                                    <label style="display:block;font-size:11px;color:var(--t3);margin-bottom:4px;">Couleur du texte</label>
                                    <div style="display:flex;align-items:center;gap:6px;">
                                        <input type="color" id="pv-edit-text-color" value="${curText || '#FFFFFF'}" style="width:36px;height:36px;border:1px solid var(--bdr);border-radius:6px;cursor:pointer;padding:2px;background:var(--bg);">
                                        <input type="text" id="pv-edit-text-hex" value="${curText}" placeholder="Défaut template" style="width:110px;padding:6px 8px;background:var(--bg);border:1px solid var(--bdr);border-radius:6px;color:var(--t1);font-size:11px;font-family:'Space Mono',monospace;"
                                            oninput="const v=this.value;if(/^#[0-9a-fA-F]{6}$/.test(v))document.getElementById('pv-edit-text-color').value=v">
                                    </div>
                                </div>
                                <button class="btn bs bsm" onclick="document.getElementById('pv-edit-bg-hex').value='';document.getElementById('pv-edit-text-hex').value='';document.getElementById('pv-edit-bg-color').value='#1a1a2e';document.getElementById('pv-edit-text-color').value='#FFFFFF';" style="font-size:10px;color:var(--t3);">Réinitialiser</button>
                            </div>
                            <p style="font-size:10px;color:var(--t3);margin:8px 0 0;font-style:italic;">Laissez vide pour utiliser les couleurs par défaut du template</p>
                        </div>

                        <!-- Logo info -->
                        <div style="margin-top:12px;padding:10px 14px;background:var(--bg2);border-radius:8px;font-size:11px;color:var(--t2);display:flex;align-items:center;gap:8px;">
                            ${logoData.has_logo
                                ? `<img src="${esc(logoData.logo_url)}" style="width:32px;height:32px;object-fit:contain;border-radius:4px;background:var(--bg);border:1px solid var(--bdr);">
                                   <span style="color:var(--g);">Logo actif — sera intégré au visuel</span>`
                                : `<span style="color:var(--t3);">Aucun logo — <a href="?view=client&location=${this._locationId}&tab=settings" style="color:var(--acc);">configurer dans Paramètres</a></span>`
                            }
                        </div>

                        <!-- Preview zone -->
                        <div style="margin-top:16px;border:1px solid var(--bdr);border-radius:8px;overflow:hidden;">
                            <div style="padding:8px 12px;background:var(--bg2);border-bottom:1px solid var(--bdr);display:flex;justify-content:space-between;align-items:center;">
                                <span style="font-size:11px;color:var(--t3);">Prévisualisation</span>
                                <button class="btn bs bsm" onclick="APP.postVisuals.refreshModalPreview()" style="font-size:10px;">🔄 Actualiser</button>
                            </div>
                            <div id="pv-modal-preview" style="aspect-ratio:4/3;background:var(--bg);display:flex;align-items:center;justify-content:center;">
                                <p style="font-size:12px;color:var(--t3);">Cliquer sur "Actualiser" pour prévisualiser</p>
                            </div>
                        </div>

                        <div style="display:flex;gap:8px;margin-top:16px;justify-content:flex-end;">
                            <button class="btn bs bsm" onclick="APP.postVisuals.closeModal()" style="font-size:12px;">Annuler</button>
                            <button class="btn bp bsm" onclick="APP.postVisuals.saveEdit()" style="font-size:12px;">
                                ${isEdit ? 'Enregistrer' : 'Créer le visuel'}
                            </button>
                        </div>
                    </div>
                </div>
            </div>`;

            m.innerHTML = h;

            // Sync color picker → hex input
            document.getElementById('pv-edit-bg-color')?.addEventListener('input', function() {
                document.getElementById('pv-edit-bg-hex').value = this.value;
            });
            document.getElementById('pv-edit-text-color')?.addEventListener('input', function() {
                document.getElementById('pv-edit-text-hex').value = this.value;
            });
        },

        // === SÉLECTION ET ACTIONS GROUPÉES ===
        toggleSelectMode() {
            this._selectMode = !this._selectMode;
            this._selectedIds = new Set();
            this.render();
        },

        toggleSelect(id) {
            if (this._selectedIds.has(id)) {
                this._selectedIds.delete(id);
            } else {
                this._selectedIds.add(id);
            }
            this.render();
        },

        selectAll() {
            // Sélectionner toutes les images VISIBLES (après filtres)
            let images = [...this._images];
            if (this._filter === 'draft') images = images.filter(i => i.status === 'draft' || i.status === 'preview' || i.status === 'validated');
            else if (this._filter !== 'all') images = images.filter(i => i.status === this._filter);
            if (this._templateFilter) images = images.filter(i => i.template_id == this._templateFilter);
            this._selectedIds = new Set(images.map(i => i.id));
            this.render();
        },

        deselectAll() {
            this._selectedIds = new Set();
            this.render();
        },

        async bulkDelete() {
            const count = this._selectedIds.size;
            if (!count) return;
            if (!confirm(`Supprimer ${count} visuel${count > 1 ? 's' : ''} ? Cette action est irréversible.`)) return;

            const fd = new FormData();
            fd.append('action', 'bulk_delete');
            fd.append('location_id', this._locationId);
            for (const id of this._selectedIds) fd.append('image_ids[]', id);

            const data = await APP.fetch('/api/post-visuals.php', { method: 'POST', body: fd });

            if (data.success) {
                APP.toast(`${data.deleted} visuel${data.deleted > 1 ? 's' : ''} supprimé${data.deleted > 1 ? 's' : ''}`, 'success');
                this._selectMode = false;
                this._selectedIds = new Set();
                this.load(this._locationId);
            } else {
                APP.toast(data.error || 'Erreur suppression', 'error');
            }
        },

        async bulkValidate() {
            const count = this._selectedIds.size;
            if (!count) return;

            const fd = new FormData();
            fd.append('action', 'bulk_validate');
            fd.append('location_id', this._locationId);
            for (const id of this._selectedIds) fd.append('image_ids[]', id);

            const data = await APP.fetch('/api/post-visuals.php', { method: 'POST', body: fd });

            if (data.success) {
                APP.toast(`${data.validated} visuel${data.validated > 1 ? 's' : ''} validé${data.validated > 1 ? 's' : ''}`, 'success');
                this._selectMode = false;
                this._selectedIds = new Set();
                this.load(this._locationId);
            } else {
                APP.toast(data.error || 'Erreur validation', 'error');
            }
        },

        async bulkChangeTemplate() {
            const count = this._selectedIds.size;
            if (!count) return;

            const esc = s => this._esc(s);
            let m = document.getElementById('pv-modal');
            if (!m) { m = document.createElement('div'); m.id = 'pv-modal'; document.body.appendChild(m); }

            let h = `<div style="position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;" onclick="if(event.target===this)APP.postVisuals.closeModal()">
                <div style="background:var(--card);border-radius:12px;width:100%;max-width:400px;border:1px solid var(--bdr);">
                    <div style="padding:16px 20px;border-bottom:1px solid var(--bdr);">
                        <h3 style="margin:0;font-size:14px;color:var(--t1);">Changer le template (${count} visuel${count > 1 ? 's' : ''})</h3>
                    </div>
                    <div style="padding:20px;">
                        <select id="pv-bulk-tpl-select" class="si" style="width:100%;font-size:13px;padding:10px 12px;margin-bottom:16px;">
                            ${this._templates.map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('')}
                        </select>
                        <div style="display:flex;gap:8px;justify-content:flex-end;">
                            <button class="btn bs bsm" onclick="APP.postVisuals.closeModal()" style="font-size:12px;">Annuler</button>
                            <button class="btn bp bsm" onclick="APP.postVisuals._executeBulkChangeTemplate()" style="font-size:12px;">Appliquer</button>
                        </div>
                    </div>
                </div>
            </div>`;
            m.innerHTML = h;
        },

        async _executeBulkChangeTemplate() {
            const tplId = document.getElementById('pv-bulk-tpl-select')?.value;
            if (!tplId) return;

            const fd = new FormData();
            fd.append('action', 'bulk_update_template');
            fd.append('location_id', this._locationId);
            fd.append('template_id', tplId);
            for (const id of this._selectedIds) fd.append('image_ids[]', id);

            const data = await APP.fetch('/api/post-visuals.php', { method: 'POST', body: fd });

            if (data.success) {
                APP.toast(`Template mis à jour pour ${data.updated} visuel${data.updated > 1 ? 's' : ''}`, 'success');
                this.closeModal();
                this._selectMode = false;
                this._selectedIds = new Set();
                this.load(this._locationId);
            } else {
                APP.toast(data.error || 'Erreur', 'error');
            }
        },

        closeModal() {
            const m = document.getElementById('pv-modal');
            if (m) m.innerHTML = '';
        },

        async refreshModalPreview() {
            const zone = document.getElementById('pv-modal-preview');
            if (!zone) return;

            const tplId = document.getElementById('pv-edit-template')?.value;
            const text = document.getElementById('pv-edit-visual')?.value?.trim() || 'Texte d\'exemple';
            const cta = document.getElementById('pv-edit-cta')?.value?.trim() || '';
            const bgHex = document.getElementById('pv-edit-bg-hex')?.value?.trim() || '';
            const textHex = document.getElementById('pv-edit-text-hex')?.value?.trim() || '';

            zone.innerHTML = '<p style="font-size:12px;color:var(--acc);text-align:center;">⏳ Génération...</p>';

            const params = new URLSearchParams({
                action: 'preview',
                template_id: tplId,
                visual_text: text,
                cta_text: cta,
                location_id: this._locationId
            });
            if (bgHex && /^#[0-9a-fA-F]{6}$/.test(bgHex)) params.append('bg_color', bgHex);
            if (textHex && /^#[0-9a-fA-F]{6}$/.test(textHex)) params.append('text_color', textHex);

            const data = await APP.fetch('/api/post-visuals.php?' + params.toString());

            if (data.success && data.image) {
                zone.innerHTML = `<img src="${data.image}" style="width:100%;height:100%;object-fit:cover;">`;
            } else {
                zone.innerHTML = `<p style="font-size:12px;color:var(--r);text-align:center;">❌ ${data.error || 'Erreur'}</p>`;
            }
        },

        async saveEdit() {
            const fd = new FormData();
            fd.append('action', 'save_image');
            fd.append('location_id', this._locationId);
            fd.append('template_id', document.getElementById('pv-edit-template')?.value || '');
            fd.append('visual_text', document.getElementById('pv-edit-visual')?.value?.trim() || '');
            fd.append('description', document.getElementById('pv-edit-desc')?.value?.trim() || '');
            fd.append('cta_text', document.getElementById('pv-edit-cta')?.value?.trim() || '');
            fd.append('seo_keyword', document.getElementById('pv-edit-seo-kw')?.value?.trim() || '');
            fd.append('sort_order', document.getElementById('pv-edit-order')?.value || '0');

            // Couleurs custom
            const bgHex = document.getElementById('pv-edit-bg-hex')?.value?.trim() || '';
            const textHex = document.getElementById('pv-edit-text-hex')?.value?.trim() || '';
            if (bgHex && /^#[0-9a-fA-F]{6}$/.test(bgHex)) fd.append('bg_color', bgHex);
            if (textHex && /^#[0-9a-fA-F]{6}$/.test(textHex)) fd.append('text_color', textHex);

            if (this._editingId) {
                fd.append('id', this._editingId);
            }

            const data = await APP.fetch('/api/post-visuals.php', { method: 'POST', body: fd });

            if (data.success) {
                APP.toast(this._editingId ? 'Visuel mis à jour' : 'Visuel créé', 'success');
                this.closeModal();
                await this.load(this._locationId);
            } else {
                APP.toast(data.error || 'Erreur', 'error');
            }
        },

        async validateOne(id) {
            const fd = new FormData();
            fd.append('action', 'validate');
            fd.append('image_id', id);
            const data = await APP.fetch('/api/post-visuals.php', { method: 'POST', body: fd });
            if (data.success) {
                APP.toast('Visuel validé', 'success');
                await this.load(this._locationId);
            }
        },

        async validateAll() {
            const fd = new FormData();
            fd.append('action', 'validate_all');
            fd.append('location_id', this._locationId);
            const data = await APP.fetch('/api/post-visuals.php', { method: 'POST', body: fd });
            if (data.success) {
                APP.toast(data.count + ' visuel(s) validé(s)', 'success');
                await this.load(this._locationId);
            }
        },

        async generateOne(id) {
            // Feedback immédiat
            const card = document.querySelector(`[onclick*="generateOne(${id})"]`);
            if (card) {
                card.disabled = true;
                card.innerHTML = '⏳ Génération...';
            }

            const fd = new FormData();
            fd.append('action', 'generate');
            fd.append('image_id', id);
            const data = await APP.fetch('/api/post-visuals.php', { method: 'POST', body: fd });

            if (data.success) {
                const sizeKo = data.size ? Math.round(data.size / 1024) : '?';
                APP.toast(`Image générée (${sizeKo} Ko)`, 'success');
                await this.load(this._locationId);
            } else {
                APP.toast(data.error || 'Erreur de génération', 'error');
                if (card) { card.disabled = false; card.innerHTML = '🖼️ Générer'; }
            }
        },

        async generateAll() {
            // Ouvre la modale de personnalisation avant génération
            const pendingImages = this._images.filter(i => i.status === 'draft' || i.status === 'preview' || i.status === 'validated');
            if (!pendingImages.length) { APP.toast('Aucun visuel à générer', 'warning'); return; }
            const generable = pendingImages.length;

            // Charger logo info
            const logoData = await APP.fetch('/api/post-visuals.php?action=get_logo&location_id=' + this._locationId);

            // Chercher le premier visuel pour preview
            const firstImg = pendingImages[0];
            const sampleText = firstImg?.visual_text || 'Texte d\'exemple';
            const sampleCta = firstImg?.cta_text || '';

            let m = document.getElementById('pv-modal');
            if (!m) { m = document.createElement('div'); m.id = 'pv-modal'; document.body.appendChild(m); }

            const esc = s => this._esc(s);

            let h = `<div style="position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;" onclick="if(event.target===this)APP.postVisuals.closeModal()">
                <div style="background:var(--card);border-radius:16px;width:100%;max-width:750px;max-height:90vh;overflow-y:auto;border:1px solid var(--bdr);">
                    <div style="padding:20px;border-bottom:1px solid var(--bdr);display:flex;justify-content:space-between;align-items:center;">
                        <h3 style="margin:0;color:var(--t1);font-family:'Anton',sans-serif;font-size:16px;">
                            <svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:var(--acc);fill:none;stroke-width:2;vertical-align:middle;margin-right:6px;"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                            PERSONNALISER ET GÉNÉRER
                        </h3>
                        <button onclick="APP.postVisuals.closeModal()" style="background:none;border:none;color:var(--t3);cursor:pointer;font-size:20px;">✕</button>
                    </div>
                    <div style="padding:20px;">

                        <!-- Résumé -->
                        <div style="padding:12px 16px;background:rgba(0,229,204,0.06);border:1px solid rgba(0,229,204,0.15);border-radius:10px;margin-bottom:20px;">
                            <p style="margin:0;font-size:13px;color:var(--t1);"><b>${generable}</b> visuel${generable > 1 ? 's' : ''} — personnalisez le template et les couleurs avant la génération</p>
                            <p style="margin:4px 0 0;font-size:11px;color:var(--t2);">Les brouillons seront directement générés en images finales.</p>
                        </div>

                        <!-- Template -->
                        <div style="margin-bottom:16px;">
                            <label style="display:block;font-size:12px;color:var(--t3);margin-bottom:6px;font-weight:600;">Template à appliquer à tous les visuels</label>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                ${this._templates.map(t => {
                                    const isCurrent = this._selectedTemplateId == t.id;
                                    return `<button class="btn ${isCurrent ? 'bp' : 'bs'} bsm pv-batch-tpl-btn" data-tpl="${t.id}" onclick="APP.postVisuals._selectBatchTemplate(${t.id})" style="font-size:11px;">${esc(t.name)}</button>`;
                                }).join('')}
                            </div>
                        </div>

                        <!-- Police + Couleurs + Éléments déco -->
                        <div style="margin-bottom:16px;padding:14px;background:var(--bg2);border-radius:10px;border:1px solid var(--bdr);">
                            <div style="font-size:12px;color:var(--t2);font-weight:600;margin-bottom:10px;">Personnalisation visuelle</div>

                            <!-- Police -->
                            <div style="margin-bottom:12px;">
                                <label style="display:block;font-size:11px;color:var(--t3);margin-bottom:4px;">Police</label>
                                <select id="pv-batch-font" class="si" style="width:200px;font-size:12px;padding:6px 10px;">
                                    <option value="">Défaut du template</option>
                                    <option value="montserrat">Montserrat</option>
                                    <option value="inter">Inter</option>
                                    <option value="playfair">Playfair Display</option>
                                    <option value="space-mono">Space Mono</option>
                                    <option value="anton">Anton</option>
                                    <option value="raleway">Raleway</option>
                                    <option value="poppins">Poppins</option>
                                    <option value="poppins-bold">Poppins Bold</option>
                                </select>
                            </div>

                            <!-- Couleurs -->
                            <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;">
                                <div>
                                    <label style="display:block;font-size:11px;color:var(--t3);margin-bottom:4px;">Couleur de fond</label>
                                    <div style="display:flex;align-items:center;gap:6px;">
                                        <input type="color" id="pv-batch-bg-color" value="#1a1a2e" style="width:36px;height:36px;border:1px solid var(--bdr);border-radius:6px;cursor:pointer;padding:2px;background:var(--bg);">
                                        <input type="text" id="pv-batch-bg-hex" value="" placeholder="Défaut" style="width:100px;padding:6px 8px;background:var(--bg);border:1px solid var(--bdr);border-radius:6px;color:var(--t1);font-size:11px;font-family:'Space Mono',monospace;"
                                            oninput="const v=this.value;if(/^#[0-9a-fA-F]{6}$/.test(v))document.getElementById('pv-batch-bg-color').value=v">
                                    </div>
                                </div>
                                <div>
                                    <label style="display:block;font-size:11px;color:var(--t3);margin-bottom:4px;">Couleur du texte</label>
                                    <div style="display:flex;align-items:center;gap:6px;">
                                        <input type="color" id="pv-batch-text-color" value="#FFFFFF" style="width:36px;height:36px;border:1px solid var(--bdr);border-radius:6px;cursor:pointer;padding:2px;background:var(--bg);">
                                        <input type="text" id="pv-batch-text-hex" value="" placeholder="Défaut" style="width:100px;padding:6px 8px;background:var(--bg);border:1px solid var(--bdr);border-radius:6px;color:var(--t1);font-size:11px;font-family:'Space Mono',monospace;"
                                            oninput="const v=this.value;if(/^#[0-9a-fA-F]{6}$/.test(v))document.getElementById('pv-batch-text-color').value=v">
                                    </div>
                                </div>
                                <div>
                                    <label style="display:block;font-size:11px;color:var(--t3);margin-bottom:4px;">Couleur des éléments</label>
                                    <div style="display:flex;align-items:center;gap:6px;">
                                        <input type="color" id="pv-batch-deco-color" value="#00d4ff" style="width:36px;height:36px;border:1px solid var(--bdr);border-radius:6px;cursor:pointer;padding:2px;background:var(--bg);">
                                        <input type="text" id="pv-batch-deco-hex" value="" placeholder="Défaut" style="width:100px;padding:6px 8px;background:var(--bg);border:1px solid var(--bdr);border-radius:6px;color:var(--t1);font-size:11px;font-family:'Space Mono',monospace;"
                                            oninput="const v=this.value;if(/^#[0-9a-fA-F]{6}$/.test(v))document.getElementById('pv-batch-deco-color').value=v">
                                    </div>
                                </div>
                                <button class="btn bs bsm" onclick="document.getElementById('pv-batch-bg-hex').value='';document.getElementById('pv-batch-text-hex').value='';document.getElementById('pv-batch-deco-hex').value='';document.getElementById('pv-batch-font').value='';" style="font-size:10px;color:var(--t3);">Réinitialiser</button>
                            </div>
                            <p style="font-size:10px;color:var(--t3);margin:8px 0 0;font-style:italic;">Laissez vide pour utiliser les réglages par défaut du template</p>
                        </div>

                        <!-- Mot-clé SEO pour l'URL -->
                        <div style="margin-bottom:16px;padding:14px;background:var(--bg2);border-radius:10px;border:1px solid var(--bdr);">
                            <div style="font-size:12px;color:var(--t2);font-weight:600;margin-bottom:8px;">
                                <svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:var(--g);fill:none;stroke-width:2;vertical-align:middle;margin-right:4px;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                Mot-clé SEO (URL de l'image)
                            </div>
                            <input type="text" id="pv-batch-seo-kw" class="si" placeholder="Ex: boulangerie artisanale, plombier chauffagiste..." style="width:100%;font-size:12px;" value="">
                            <p style="font-size:10px;color:var(--t3);margin:6px 0 0;">Ce mot-clé + la ville du client seront utilisés dans l'URL du fichier généré pour le référencement image Google. Laissez vide pour utiliser le texte du visuel.</p>
                        </div>

                        <!-- Logo info -->
                        <div style="margin-bottom:16px;padding:10px 14px;background:var(--bg2);border-radius:8px;font-size:11px;color:var(--t2);display:flex;align-items:center;gap:8px;">
                            ${logoData.has_logo
                                ? `<img src="${esc(logoData.logo_url)}" style="width:32px;height:32px;object-fit:contain;border-radius:4px;background:var(--bg);border:1px solid var(--bdr);">
                                   <span>Logo actif — sera intégré dans tous les visuels</span>`
                                : `<span style="color:var(--t3);">Aucun logo — <a href="?view=client&location=${this._locationId}&tab=settings" style="color:var(--acc);">configurer dans Paramètres</a></span>`
                            }
                        </div>

                        <!-- Preview zone -->
                        <div style="border:1px solid var(--bdr);border-radius:8px;overflow:hidden;">
                            <div style="padding:8px 12px;background:var(--bg2);border-bottom:1px solid var(--bdr);display:flex;justify-content:space-between;align-items:center;">
                                <span style="font-size:11px;color:var(--t3);">Aperçu (premier visuel)</span>
                                <button class="btn bs bsm" onclick="APP.postVisuals._refreshBatchPreview()" style="font-size:10px;">🔄 Actualiser l'aperçu</button>
                            </div>
                            <div id="pv-batch-preview" style="aspect-ratio:4/3;background:var(--bg);display:flex;align-items:center;justify-content:center;">
                                <p style="font-size:12px;color:var(--t3);">Cliquer "Actualiser" pour prévisualiser avec ces réglages</p>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div style="display:flex;gap:8px;margin-top:20px;justify-content:flex-end;">
                            <button class="btn bs bsm" onclick="APP.postVisuals.closeModal()" style="font-size:12px;">Annuler</button>
                            <button class="btn bp bsm" id="pv-batch-generate-btn" onclick="APP.postVisuals._executeBatchGenerate()" style="font-size:12px;">
                                <svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                                Appliquer et générer (${generable})
                            </button>
                        </div>
                    </div>
                </div>
            </div>`;

            m.innerHTML = h;

            // Sync color pickers → hex inputs
            document.getElementById('pv-batch-bg-color')?.addEventListener('input', function() {
                document.getElementById('pv-batch-bg-hex').value = this.value;
            });
            document.getElementById('pv-batch-text-color')?.addEventListener('input', function() {
                document.getElementById('pv-batch-text-hex').value = this.value;
            });
            document.getElementById('pv-batch-deco-color')?.addEventListener('input', function() {
                document.getElementById('pv-batch-deco-hex').value = this.value;
            });
        },

        _selectBatchTemplate(tplId) {
            this._selectedTemplateId = tplId;
            document.querySelectorAll('.pv-batch-tpl-btn').forEach(btn => {
                btn.className = 'btn ' + (btn.dataset.tpl == tplId ? 'bp' : 'bs') + ' bsm pv-batch-tpl-btn';
            });
        },

        async _refreshBatchPreview() {
            const zone = document.getElementById('pv-batch-preview');
            if (!zone) return;

            const firstPending = this._images.find(i => i.status === 'draft' || i.status === 'preview' || i.status === 'validated');
            const sampleText = firstPending?.visual_text || 'Texte d\'exemple';
            const sampleCta = firstPending?.cta_text || '';
            const bgHex = document.getElementById('pv-batch-bg-hex')?.value?.trim() || '';
            const textHex = document.getElementById('pv-batch-text-hex')?.value?.trim() || '';
            const font = document.getElementById('pv-batch-font')?.value || '';
            const decoHex = document.getElementById('pv-batch-deco-hex')?.value?.trim() || '';

            zone.innerHTML = '<p style="font-size:12px;color:var(--acc);text-align:center;">⏳ Génération aperçu...</p>';

            const params = new URLSearchParams({
                action: 'preview',
                template_id: this._selectedTemplateId,
                visual_text: sampleText,
                cta_text: sampleCta,
                location_id: this._locationId
            });
            if (bgHex && /^#[0-9a-fA-F]{6}$/.test(bgHex)) params.append('bg_color', bgHex);
            if (textHex && /^#[0-9a-fA-F]{6}$/.test(textHex)) params.append('text_color', textHex);
            if (font) params.append('font', font);
            if (decoHex && /^#[0-9a-fA-F]{6}$/.test(decoHex)) params.append('deco_color', decoHex);

            const data = await APP.fetch('/api/post-visuals.php?' + params.toString());

            if (data.success && data.image) {
                zone.innerHTML = `<img src="${data.image}" style="width:100%;height:100%;object-fit:cover;">`;
            } else {
                zone.innerHTML = `<p style="font-size:12px;color:var(--r);text-align:center;">❌ ${data.error || 'Erreur'}</p>`;
            }
        },

        async _executeBatchGenerate() {
            const btn = document.getElementById('pv-batch-generate-btn');
            if (btn) { btn.disabled = true; btn.innerHTML = '⏳ Application en cours...'; }

            const bgHex = document.getElementById('pv-batch-bg-hex')?.value?.trim() || '';
            const textHex = document.getElementById('pv-batch-text-hex')?.value?.trim() || '';
            const font = document.getElementById('pv-batch-font')?.value || '';
            const decoHex = document.getElementById('pv-batch-deco-hex')?.value?.trim() || '';
            const seoKw = document.getElementById('pv-batch-seo-kw')?.value?.trim() || '';

            // 1. Appliquer template + couleurs + police + déco + SEO keyword à TOUS les visuels
            if (btn) btn.innerHTML = '⏳ Application du template...';

            const fdCustomize = new FormData();
            fdCustomize.append('action', 'batch_customize');
            fdCustomize.append('location_id', this._locationId);
            fdCustomize.append('template_id', this._selectedTemplateId);
            fdCustomize.append('target_status', 'all_pending');
            if (bgHex && /^#[0-9a-fA-F]{6}$/.test(bgHex)) fdCustomize.append('bg_color', bgHex);
            if (textHex && /^#[0-9a-fA-F]{6}$/.test(textHex)) fdCustomize.append('text_color', textHex);
            if (font) fdCustomize.append('font', font);
            if (decoHex && /^#[0-9a-fA-F]{6}$/.test(decoHex)) fdCustomize.append('deco_color', decoHex);
            if (seoKw) fdCustomize.append('seo_keyword', seoKw);

            const customizeResult = await APP.fetch('/api/post-visuals.php', { method: 'POST', body: fdCustomize });

            if (!customizeResult.success) {
                APP.toast(customizeResult.error || 'Erreur personnalisation', 'error');
                if (btn) { btn.disabled = false; btn.innerHTML = 'Appliquer et générer'; }
                return;
            }

            // 2. Générer toutes les images en attente (draft/preview/validated → generated)
            if (btn) btn.innerHTML = '⏳ Génération en cours...';

            const fdGen = new FormData();
            fdGen.append('action', 'generate_all');
            fdGen.append('location_id', this._locationId);
            const data = await APP.fetch('/api/post-visuals.php', { method: 'POST', body: fdGen });

            if (data.success) {
                const r = data.results;
                APP.toast(`${r.success}/${r.total} images générées !`, r.errors.length ? 'warning' : 'success');
                this.closeModal();
                await this.load(this._locationId);
            } else {
                APP.toast(data.error || 'Erreur de génération', 'error');
                if (btn) { btn.disabled = false; btn.innerHTML = 'Appliquer et générer'; }
            }
        },

        async deleteImage(id) {
            if (!confirm('Supprimer ce visuel ?')) return;

            const fd = new FormData();
            fd.append('action', 'delete_image');
            fd.append('id', id);
            const data = await APP.fetch('/api/post-visuals.php', { method: 'POST', body: fd });

            if (data.success) {
                APP.toast('Visuel supprimé', 'success');
                await this.load(this._locationId);
            }
        },

        async showScheduleModal() {
            const schedulable = this._images.filter(i => i.status === 'generated' && !i.google_post_id).length;
            if (!schedulable) { APP.toast('Aucune image générée à programmer', 'warning'); return; }

            // Charger les listes existantes
            const listsData = await APP.fetch('/api/post-visuals.php?action=list_existing_lists&location_id=' + this._locationId);
            const existingLists = listsData.lists || [];

            let m = document.getElementById('pv-modal');
            if (!m) { m = document.createElement('div'); m.id = 'pv-modal'; document.body.appendChild(m); }

            const dayNames = ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
            const defaultDays = [1,3,5];

            let h = `<div style="position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;" onclick="if(event.target===this)APP.postVisuals.closeModal()">
                <div style="background:var(--card);border-radius:16px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;border:1px solid var(--bdr);">
                    <div style="padding:20px;border-bottom:1px solid var(--bdr);display:flex;justify-content:space-between;align-items:center;">
                        <h3 style="margin:0;color:var(--t1);font-family:'Anton',sans-serif;font-size:16px;">
                            <svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:var(--g);fill:none;stroke-width:2;vertical-align:middle;margin-right:6px;"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            PROGRAMMER LES VISUELS
                        </h3>
                        <button onclick="APP.postVisuals.closeModal()" style="background:none;border:none;color:var(--t3);cursor:pointer;font-size:20px;">✕</button>
                    </div>
                    <div style="padding:20px;">

                        <!-- Résumé -->
                        <div style="padding:12px 16px;background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.2);border-radius:10px;margin-bottom:20px;">
                            <p style="margin:0;font-size:13px;color:var(--t1);"><b>${schedulable}</b> visuel${schedulable > 1 ? 's' : ''} prêt${schedulable > 1 ? 's' : ''} à programmer</p>
                            <p style="margin:4px 0 0;font-size:11px;color:var(--t2);">Chaque visuel sera créé comme Google Post dans la liste auto choisie.</p>
                        </div>

                        <!-- Mode : Nouvelle liste ou existante -->
                        <div style="margin-bottom:16px;">
                            <label style="display:block;font-size:12px;color:var(--t3);margin-bottom:8px;">Destination</label>
                            <div style="display:flex;gap:6px;">
                                <button class="btn bp bsm" id="sched-mode-new" onclick="APP.postVisuals._toggleScheduleMode('new')" style="font-size:12px;">Nouvelle liste</button>
                                <button class="btn bs bsm" id="sched-mode-existing" onclick="APP.postVisuals._toggleScheduleMode('existing')" style="font-size:12px;" ${existingLists.length === 0 ? 'disabled' : ''}>Liste existante (${existingLists.length})</button>
                            </div>
                        </div>

                        <!-- Nouvelle liste -->
                        <div id="sched-new-fields">
                            <div style="margin-bottom:14px;">
                                <label style="display:block;font-size:12px;color:var(--t3);margin-bottom:4px;">Nom de la liste</label>
                                <input id="sched-name" value="Visuels auto" style="width:100%;padding:8px 12px;background:var(--bg);border:1px solid var(--bdr);border-radius:8px;color:var(--t1);font-size:13px;">
                            </div>

                            <div style="margin-bottom:14px;">
                                <label style="display:block;font-size:12px;color:var(--t3);margin-bottom:8px;">Jours de publication</label>
                                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                    ${dayNames.map((d, i) => {
                                        const dayNum = i + 1;
                                        const checked = defaultDays.includes(dayNum);
                                        return `<label style="display:flex;align-items:center;gap:4px;padding:6px 10px;background:var(--bg);border:1px solid var(--bdr);border-radius:6px;cursor:pointer;font-size:12px;color:var(--t1);">
                                            <input type="checkbox" class="sched-day-cb" value="${dayNum}" ${checked ? 'checked' : ''} onchange="APP.postVisuals._updateScheduleEstimate()" style="accent-color:var(--g);">
                                            ${d}
                                        </label>`;
                                    }).join('')}
                                </div>
                            </div>

                            <div style="margin-bottom:14px;display:flex;gap:12px;">
                                <div style="flex:1;">
                                    <label style="display:block;font-size:12px;color:var(--t3);margin-bottom:4px;">Horaire</label>
                                    <input id="sched-time" type="time" value="09:00" style="width:100%;padding:8px 12px;background:var(--bg);border:1px solid var(--bdr);border-radius:8px;color:var(--t1);font-size:13px;">
                                </div>
                                <div style="flex:1;">
                                    <label style="display:block;font-size:12px;color:var(--t3);margin-bottom:4px;">Mode boucle</label>
                                    <label style="display:flex;align-items:center;gap:6px;padding:8px 12px;background:var(--bg);border:1px solid var(--bdr);border-radius:8px;cursor:pointer;font-size:12px;color:var(--t1);">
                                        <input type="checkbox" id="sched-repeat" style="accent-color:var(--g);">
                                        Répéter en boucle
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Liste existante -->
                        <div id="sched-existing-fields" style="display:none;">
                            <div style="margin-bottom:14px;">
                                <label style="display:block;font-size:12px;color:var(--t3);margin-bottom:4px;">Choisir une liste</label>
                                <select id="sched-existing-list" style="width:100%;padding:8px 12px;background:var(--bg);border:1px solid var(--bdr);border-radius:8px;color:var(--t1);font-size:13px;">
                                    ${existingLists.map(l => `<option value="${l.id}">${this._esc(l.name)} (${l.post_count} posts)</option>`).join('')}
                                </select>
                            </div>
                            <div style="padding:10px 14px;background:var(--bg2);border-radius:8px;font-size:11px;color:var(--t2);line-height:1.5;">
                                ⚠️ Les ${schedulable} visuels seront ajoutés <b>à la suite</b> des posts existants dans cette liste.
                            </div>
                        </div>

                        <!-- Estimation -->
                        <div id="sched-estimate" style="margin-top:16px;padding:12px 16px;background:var(--bg2);border-radius:8px;font-size:12px;color:var(--t2);line-height:1.5;">
                        </div>

                        <!-- Actions -->
                        <div style="display:flex;gap:8px;margin-top:20px;justify-content:flex-end;">
                            <button class="btn bs bsm" onclick="APP.postVisuals.closeModal()" style="font-size:12px;">Annuler</button>
                            <button class="btn bp bsm" id="sched-submit-btn" onclick="APP.postVisuals.submitSchedule()" style="font-size:12px;background:var(--g);border-color:var(--g);">
                                <svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                Programmer ${schedulable} post${schedulable > 1 ? 's' : ''}
                            </button>
                        </div>
                    </div>
                </div>
            </div>`;

            m.innerHTML = h;
            this._updateScheduleEstimate();
        },

        _toggleScheduleMode(mode) {
            const newF = document.getElementById('sched-new-fields');
            const existF = document.getElementById('sched-existing-fields');
            const btnNew = document.getElementById('sched-mode-new');
            const btnExist = document.getElementById('sched-mode-existing');
            if (!newF || !existF) return;

            if (mode === 'new') {
                newF.style.display = 'block';
                existF.style.display = 'none';
                btnNew.className = 'btn bp bsm';
                btnExist.className = 'btn bs bsm';
            } else {
                newF.style.display = 'none';
                existF.style.display = 'block';
                btnNew.className = 'btn bs bsm';
                btnExist.className = 'btn bp bsm';
            }
            this._scheduleMode = mode;
            this._updateScheduleEstimate();
        },

        _scheduleMode: 'new',

        _updateScheduleEstimate() {
            const el = document.getElementById('sched-estimate');
            if (!el) return;

            const schedulable = this._images.filter(i => i.status === 'generated' && !i.google_post_id).length;

            if (this._scheduleMode === 'existing') {
                el.innerHTML = `📋 <b>${schedulable}</b> posts seront ajoutés à la liste existante.`;
                return;
            }

            const checkedDays = document.querySelectorAll('.sched-day-cb:checked');
            const daysPerWeek = checkedDays.length || 1;
            const weeks = Math.ceil(schedulable / daysPerWeek);
            const months = (weeks / 4.33).toFixed(1);

            el.innerHTML = `📅 <b>${daysPerWeek}x/semaine</b> → ${schedulable} posts couvrent environ <b>${weeks} semaines</b> (~${months} mois)`;
        },

        async submitSchedule() {
            const btn = document.getElementById('sched-submit-btn');
            if (btn) { btn.disabled = true; btn.innerHTML = '⏳ Création en cours...'; }

            const fd = new FormData();
            fd.append('action', 'push_to_list');
            fd.append('location_id', this._locationId);

            if (this._scheduleMode === 'existing') {
                fd.append('mode', 'existing');
                fd.append('list_id', document.getElementById('sched-existing-list')?.value || '');
            } else {
                fd.append('mode', 'new');
                fd.append('list_name', document.getElementById('sched-name')?.value?.trim() || 'Visuels auto');

                const checkedDays = [...document.querySelectorAll('.sched-day-cb:checked')].map(c => c.value);
                fd.append('schedule_days', checkedDays.join(',') || '1,3,5');
                fd.append('schedule_times', document.getElementById('sched-time')?.value || '09:00');
                fd.append('is_repeat', document.getElementById('sched-repeat')?.checked ? '1' : '0');
            }

            const data = await APP.fetch('/api/post-visuals.php', { method: 'POST', body: fd });

            if (data.success) {
                APP.toast(`${data.posts_created} post${data.posts_created > 1 ? 's' : ''} programmé${data.posts_created > 1 ? 's' : ''} dans la liste auto !`, 'success');
                this.closeModal();
                await this.load(this._locationId);
            } else {
                APP.toast(data.error || 'Erreur', 'error');
                if (btn) { btn.disabled = false; btn.innerHTML = 'Programmer'; }
            }
        }
    },

    // ============================================
    // MODULE ACQUISITION DE CLIENTS
    // ============================================
    acquisition: {
        _results: [],
        _prospects: [],
        _view: 'search', // 'search' | 'prospects' | 'detail'
        _detailId: null,
        _detailData: null,
        _scanTimer: null,
        _searchMode: 'keyword', // 'keyword' | 'name'
        _prospectFilter: 'all', // 'all' | 'contacted' | 'not_contacted' | 'audited' | 'not_audited'
        _periodFilter: '90d', // '90d' | '6m' | 'all'
        _auditsRunning: {}, // { auditId: true } — audits en cours d'exécution
        _acqCityData: null, // {name, lat, lng} from autocomplete
        _acqAcTimer: null,
        _acqAcAbort: null,
        _acqBizData: null, // {place_id, name, address, lat, lng, category, rating, reviews_count, ...}
        _acqBizAcTimer: null,
        _acqBizAcAbort: null,

        init() {
            this.render();
        },

        render() {
            if (this._view === 'detail') { this.renderDétail(); return; }
            const c = document.getElementById('module-content');
            if (!c) return;

            let h = `<div class="sh" style="flex-wrap:wrap;gap:12px;">
                <div class="stit">AUDIT & ACQUISITION</div>
                <div style="display:flex;gap:8px;">
                    <button class="btn ${this._view==='search'?'bp':'bs'} bsm" onclick="APP.acquisition.switchView('search')">Rechercher</button>
                    <button class="btn ${this._view==='prospects'?'bp':'bs'} bsm" onclick="APP.acquisition.switchView('prospects')">Mes Prospects</button>
                </div>
            </div>`;

            if (this._view === 'search') {
                const isName = this._searchMode === 'name';
                h += `<div style="padding:20px;border-bottom:1px solid var(--bdr);">
                    <div style="display:flex;gap:6px;margin-bottom:14px;">
                        <button class="btn ${!isName?'bp':'bs'} bsm" onclick="APP.acquisition._setSearchMode('keyword')" style="font-size:12px;">
                            <svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            Par mot-clé
                        </button>
                        <button class="btn ${isName?'bp':'bs'} bsm" onclick="APP.acquisition._setSearchMode('name')" style="font-size:12px;">
                            <svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2m-16 0H3m5-10h4m-4 4h4"/></svg>
                            Par nom de fiche
                        </button>
                    </div>
                    <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                        ${isName ? `<div style="flex:2;min-width:250px;position:relative;">
                            <label style="font-size:12px;color:var(--t3);display:block;margin-bottom:4px;text-transform:uppercase;font-weight:600;letter-spacing:.5px;">Nom de la fiche Google</label>
                            <input type="text" id="acq-target-name" class="si" placeholder="Tapez le nom (ex: Restaurant Le Bistrot)..." style="width:100%;" autocomplete="off" oninput="APP.acquisition._acqBizAutocomplete(this.value)" onblur="setTimeout(function(){var l=document.getElementById('acq-biz-ac-list');if(l)l.style.display='none';},200)">
                            <div id="acq-biz-ac-list" class="ac-dropdown" style="display:none;"></div>
                            <div id="acq-biz-selected">${this._acqBizData ? this._renderBizChip(this._acqBizData) : ''}</div>
                        </div>
                        <div style="flex:1;min-width:150px;">
                            <label style="font-size:12px;color:var(--t3);display:block;margin-bottom:4px;text-transform:uppercase;font-weight:600;letter-spacing:.5px;">Mot-clé / Activité</label>
                            <input type="text" id="acq-keyword" class="si" placeholder="Ex: restaurant, plombier..." style="width:100%;">
                        </div>` : `<div style="flex:1;min-width:200px;">
                            <label style="font-size:12px;color:var(--t3);display:block;margin-bottom:4px;text-transform:uppercase;font-weight:600;letter-spacing:.5px;">Mot-clé / Activité</label>
                            <input type="text" id="acq-keyword" class="si" placeholder="Ex: plombier, pizzeria, dentiste..." style="width:100%;">
                        </div>`}
                        <div style="flex:1;min-width:200px;position:relative;">
                            <label style="font-size:12px;color:var(--t3);display:block;margin-bottom:4px;text-transform:uppercase;font-weight:600;letter-spacing:.5px;">Ville ciblée</label>
                            <input type="text" id="acq-city" class="si" placeholder="Ex: Lyon, Paris, Brive..." style="width:100%;" autocomplete="off" oninput="APP.acquisition._acqCityAutocomplete(this.value)" onblur="setTimeout(function(){var l=document.getElementById('acq-city-ac-list');if(l)l.style.display='none';},200)">
                            <div id="acq-city-ac-list" class="ac-dropdown" style="display:none;"></div>
                        </div>
                        <button class="btn bp" onclick="APP.acquisition.search()" id="btn-acq-search" ${isName && !this._acqBizData ? 'disabled' : ''}>
                            <svg viewBox="0 0 24 24"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            ${isName ? 'Sauvegarder le prospect' : 'Scanner (1 crédit)'}
                        </button>
                    </div>
                </div>
                <div id="acq-results">${this._results.length ? this._renderResults(this._results) : `<div style="padding:50px;text-align:center;">
                    <svg viewBox="0 0 24 24" style="width:48px;height:48px;stroke:var(--t3);fill:none;stroke-width:1.5;margin-bottom:16px;opacity:.4;">
                        ${isName ? '<path d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2m-16 0H3m5-10h4m-4 4h4"/>' : '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>'}
                    </svg>
                    <div style="font-size:15px;font-weight:600;color:var(--t2);margin-bottom:6px;">${isName ? 'Trouvez une fiche Google' : 'Recherchez des prospects'}</div>
                    <div style="font-size:13px;color:var(--t3);">${isName ? 'Tapez le nom de l\'entreprise pour voir les suggestions, sélectionnez la fiche puis ajoutez un mot-clé' : 'Entrez un mot-clé et une ville pour scanner les résultats Google Maps'}</div>
                </div>`}</div>`;
            }

            c.innerHTML = h;
            if (this._view === 'prospects') this.loadProspects();
        },

        _setSearchMode(mode) {
            this._searchMode = mode;
            this._results = [];
            this._acqBizData = null;
            this.render();
        },

        _setProspectFilter(filter) {
            this._prospectFilter = filter;
            this.renderProspects();
        },

        // === SEARCH ===
        async search() {
            const keyword = document.getElementById('acq-keyword')?.value.trim();
            const isName = this._searchMode === 'name';

            // === MODE NAME : sauvegarde directe via autocomplete ===
            if (isName) {
                if (!this._acqBizData || !this._acqBizData.place_id) {
                    APP.toast('Sélectionnez une fiche dans les suggestions', 'warning'); return;
                }
                if (!keyword) { APP.toast('Mot-clé / activité requis', 'warning'); return; }

                const btn = document.getElementById('btn-acq-search');
                btn.disabled = true;
                btn.innerHTML = '<svg viewBox="0 0 24 24" class="spin"><path d="M12 2v4m0 12v4m10-10h-4M6 12H2"/></svg> Sauvegarde...';

                const biz = this._acqBizData;
                const userCity = document.getElementById('acq-city')?.value.trim();
                const fd = new FormData();
                fd.append('action', 'save_prospect');
                fd.append('business_name', biz.name || '');
                fd.append('city', userCity || biz.city || '');
                fd.append('search_keyword', keyword);
                fd.append('address', biz.address || '');
                fd.append('category', biz.category || '');
                fd.append('phone', biz.phone || '');
                fd.append('domain', biz.domain || '');
                fd.append('url', biz.website || '');
                fd.append('rating', biz.rating || '0');
                fd.append('reviews_count', biz.reviews_count || '0');
                fd.append('latitude', biz.lat || '');
                fd.append('longitude', biz.lng || '');
                fd.append('place_id', biz.place_id);
                fd.append('data_cid', '');
                fd.append('score', '0');
                fd.append('position', '0');
                fd.append('total_photos', '0');

                const data = await APP.fetch('/api/prospects.php', { method: 'POST', body: fd });
                btn.disabled = false;
                btn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg> Sauvegarder le prospect';

                if (data.success) {
                    APP.toast('Prospect "' + biz.name + '" sauvegardé ! Lancez le scan grille depuis Mes Prospects.', 'success');
                    this._acqBizData = null;
                    this.switchView('prospects');
                } else {
                    APP.toast(data.error || 'Erreur lors de la sauvegarde', 'error');
                }
                return;
            }

            // === MODE KEYWORD : scan DataForSEO (inchangé) ===
            const city = document.getElementById('acq-city')?.value.trim();
            if (!keyword || !city) { APP.toast('Mot-clé et ville requis', 'warning'); return; }
            if (!this._acqCityData || !this._acqCityData.lat) {
                APP.toast('Sélectionnez une ville dans la liste pour obtenir les coordonnées GPS', 'warning'); return;
            }

            const btn = document.getElementById('btn-acq-search');
            btn.disabled = true;
            btn.innerHTML = '<svg viewBox="0 0 24 24" class="spin"><path d="M12 2v4m0 12v4m10-10h-4M6 12H2"/></svg> Scan en cours...';

            const area = document.getElementById('acq-results');
            if (area) area.innerHTML = `<div style="padding:60px 20px;text-align:center;">
                <div style="display:inline-block;position:relative;width:48px;height:48px;margin-bottom:16px;">
                    <div style="position:absolute;inset:0;border:3px solid rgba(0,212,255,.15);border-top-color:var(--acc);border-radius:50%;animation:spin .8s linear infinite;"></div>
                </div>
                <div style="font-size:15px;font-weight:600;color:var(--t1);margin-bottom:6px;">Scan Google Maps en cours...</div>
                <div style="font-size:13px;color:var(--t3);">Analyse des résultats pour "<strong style="color:var(--t2);">${keyword}</strong>" à <strong style="color:var(--t2);">${city}</strong></div>
                <div style="font-size:11px;color:var(--t3);margin-top:12px;opacity:.6;">Cela peut prendre quelques secondes</div>
            </div>`;

            const fd = new FormData();
            fd.append('action', 'search');
            fd.append('keyword', keyword);
            fd.append('city', city);
            fd.append('lat', this._acqCityData.lat);
            fd.append('lng', this._acqCityData.lng);

            const data = await APP.fetch('/api/prospects.php', { method: 'POST', body: fd });
            btn.disabled = false;
            btn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg> Scanner (1 crédit)';

            if (data.success) {
                this._results = data.results || [];
                const area = document.getElementById('acq-results');
                if (area) area.innerHTML = this._renderResults(this._results, keyword, city);
                const cr = document.getElementById('credits-count');
                if (cr && data.credits_remaining != null) cr.textContent = data.credits_remaining;
            } else {
                APP.toast(data.error || 'Erreur lors de la recherche', 'error');
            }
        },

        _renderResults(results, keyword, city) {
            if (!results.length) return '<div style="padding:40px;text-align:center;color:var(--t2);">Aucun résultat trouvé.</div>';
            let h = '';
            if (keyword) {
                h += `<div style="padding:12px 20px;border-bottom:1px solid var(--bdr);font-size:13px;color:var(--t2);">
                    ${results.length} résultat(s) pour "<strong style="color:var(--t1);">${keyword}</strong>" à <strong style="color:var(--t1);">${city}</strong>
                </div>`;
            }
            h += '<table><thead><tr><th style="width:50px">#</th><th>Entreprise</th><th style="text-align:center">Score</th><th style="text-align:center">Note</th><th style="text-align:center">Avis</th><th>Site web</th><th></th></tr></thead><tbody>';
            for (const r of results) {
                const scoreColor = r.score >= 70 ? 'var(--g)' : r.score >= 40 ? 'var(--o)' : 'var(--r)';
                const posClass = r.position <= 3 ? 'p3' : r.position <= 10 ? 'p10' : r.position <= 20 ? 'p20' : 'po';
                const isTarget = !!r.is_target;
                const rData = btoa(unescape(encodeURIComponent(JSON.stringify(r))));
                h += `<tr${isTarget ? ' style="background:rgba(0,212,255,.08);border-left:3px solid var(--acc);"' : ''}>
                    <td><span class="pb ${posClass}">${r.position}</span></td>
                    <td>
                        <div class="kn">${r.business_name}${isTarget ? ' <span style="font-size:10px;background:var(--primary);color:#fff;padding:1px 6px;border-radius:4px;font-weight:600;margin-left:6px;">CIBLE</span>' : ''}</div>
                        <div style="font-size:11px;color:var(--t3);margin-top:2px;">${r.address || ''}</div>
                    </td>
                    <td style="text-align:center"><span style="font-weight:700;font-family:'Space Mono',monospace;font-size:15px;color:${scoreColor}">${r.score}</span></td>
                    <td style="text-align:center"><span style="color:var(--o);">${r.rating ? r.rating + ' &#9733;' : '\u2014'}</span></td>
                    <td style="text-align:center" class="kv">${r.reviews_count || 0}</td>
                    <td class="kv">${r.domain ? '<a href="https://'+r.domain+'" target="_blank" style="color:var(--acc);">'+r.domain+'</a>' : '\u2014'}</td>
                    <td><button class="btn bs bsm" onclick="APP.acquisition.saveProspect('${rData}')">
                        <svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>
                        Sauvegarder
                    </button></td>
                </tr>`;
            }
            h += '</tbody></table>';
            return h;
        },

        async saveProspect(b64) {
            const r = JSON.parse(decodeURIComponent(escape(atob(b64))));
            const fd = new FormData();
            fd.append('action', 'save_prospect');
            fd.append('business_name', r.business_name);
            fd.append('city', r.city || '');
            fd.append('search_keyword', r.search_keyword || '');
            fd.append('address', r.address || '');
            fd.append('category', r.category || '');
            fd.append('phone', r.phone || '');
            fd.append('domain', r.domain || '');
            fd.append('url', r.url || '');
            fd.append('score', r.score || 0);
            fd.append('position', r.position || 0);
            fd.append('rating', r.rating || 0);
            fd.append('reviews_count', r.reviews_count || 0);
            fd.append('latitude', r.latitude || '');
            fd.append('longitude', r.longitude || '');
            fd.append('place_id', r.place_id || '');
            fd.append('data_cid', r.data_cid || '');
            fd.append('total_photos', r.total_photos || 0);

            const data = await APP.fetch('/api/prospects.php', { method: 'POST', body: fd });
            if (data.success) {
                APP.toast('Prospect sauvegarde !', 'success');
            } else {
                APP.toast(data.error || 'Erreur', 'error');
            }
        },

        // === BUSINESS AUTOCOMPLETE (Google Places API) ===
        _acqBizAutocomplete(query) {
            var list = document.getElementById('acq-biz-ac-list');
            if (!list) return;
            clearTimeout(this._acqBizAcTimer);
            if (this._acqBizAcAbort) { this._acqBizAcAbort.abort(); this._acqBizAcAbort = null; }
            // Si l'utilisateur tape après sélection, on reset
            if (this._acqBizData) {
                this._acqBizData = null;
                var sel = document.getElementById('acq-biz-selected');
                if (sel) sel.innerHTML = '';
                var btn = document.getElementById('btn-acq-search');
                if (btn) btn.disabled = true;
            }
            if (query.length < 3) { list.style.display = 'none'; return; }
            this._acqBizAcTimer = setTimeout(async () => {
                try {
                    this._acqBizAcAbort = new AbortController();
                    var url = APP.url + '/api/places-autocomplete.php?q=' + encodeURIComponent(query);
                    // Biais géographique si ville sélectionnée
                    if (this._acqCityData && this._acqCityData.lat) {
                        url += '&lat=' + this._acqCityData.lat + '&lng=' + this._acqCityData.lng;
                    }
                    var resp = await fetch(url, { signal: this._acqBizAcAbort.signal });
                    var data = await resp.json();
                    this._acqBizAcAbort = null;
                    if (!data.results || !data.results.length) { list.style.display = 'none'; return; }
                    var html = '';
                    for (var i = 0; i < data.results.length; i++) {
                        var r = data.results[i];
                        var b64 = btoa(unescape(encodeURIComponent(JSON.stringify(r))));
                        var nameDisplay = r.name;
                        var idx = nameDisplay.toLowerCase().indexOf(query.toLowerCase());
                        if (idx >= 0) {
                            nameDisplay = nameDisplay.substring(0, idx) + '<b>' + nameDisplay.substring(idx, idx + query.length) + '</b>' + nameDisplay.substring(idx + query.length);
                        }
                        html += '<div class="ac-item" onmousedown="APP.acquisition._acqSelectBiz(\'' + b64 + '\')">';
                        html += '<div style="display:flex;align-items:center;gap:6px;">';
                        html += '<svg viewBox="0 0 24 24" style="width:14px;height:14px;flex-shrink:0;stroke:var(--acc);fill:none;stroke-width:2;"><path d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2m-16 0H3"/></svg>';
                        html += '<div><div style="font-weight:600;color:var(--t1);">' + nameDisplay + '</div>';
                        if (r.address) html += '<div style="font-size:11px;color:var(--t3);margin-top:1px;">' + r.address + '</div>';
                        html += '</div></div></div>';
                    }
                    list.innerHTML = html;
                    list.style.display = 'block';
                } catch (e) {
                    if (e.name !== 'AbortError') console.error('biz autocomplete:', e);
                }
            }, 300);
        },

        async _acqSelectBiz(b64) {
            var suggestion = JSON.parse(decodeURIComponent(escape(atob(b64))));
            var input = document.getElementById('acq-target-name');
            var list = document.getElementById('acq-biz-ac-list');
            if (input) input.value = suggestion.name;
            if (list) list.style.display = 'none';

            // Charger les détails complets via Places Details
            var sel = document.getElementById('acq-biz-selected');
            if (sel) sel.innerHTML = '<div style="padding:6px 10px;margin-top:6px;font-size:12px;color:var(--t3);"><svg viewBox="0 0 24 24" class="spin" style="width:14px;height:14px;display:inline;vertical-align:middle;stroke:var(--acc);fill:none;stroke-width:2;"><path d="M12 2v4m0 12v4m10-10h-4M6 12H2"/></svg> Chargement des détails...</div>';

            try {
                var resp = await fetch(APP.url + '/api/places-details.php?place_id=' + encodeURIComponent(suggestion.place_id));
                var data = await resp.json();
                if (data.success && data.place) {
                    this._acqBizData = data.place;
                } else {
                    // Fallback : utiliser les données de l'autocomplete
                    this._acqBizData = {
                        place_id: suggestion.place_id,
                        name: suggestion.name,
                        address: suggestion.address || suggestion.full_text || '',
                        city: '',
                        lat: null,
                        lng: null,
                        category: (suggestion.types || []).join(', '),
                        rating: null,
                        reviews_count: 0,
                        website: '',
                        domain: '',
                        phone: '',
                    };
                }
            } catch (e) {
                console.error('places details error:', e);
                this._acqBizData = {
                    place_id: suggestion.place_id,
                    name: suggestion.name,
                    address: suggestion.address || '',
                    city: '', lat: null, lng: null, category: '',
                    rating: null, reviews_count: 0, website: '', domain: '', phone: '',
                };
            }

            // Afficher le chip de confirmation
            if (sel) sel.innerHTML = this._renderBizChip(this._acqBizData);
            // Activer le bouton
            var btn = document.getElementById('btn-acq-search');
            if (btn) btn.disabled = false;
        },

        _renderBizChip(biz) {
            if (!biz) return '';
            var info = [];
            if (biz.category) info.push(biz.category);
            if (biz.short_address || biz.address) info.push(biz.short_address || biz.address);
            var ratingHtml = '';
            if (biz.rating) {
                ratingHtml = ' <span style="color:var(--o);">&#9733; ' + biz.rating + '</span>';
                if (biz.reviews_count) ratingHtml += ' <span style="color:var(--t3);">(' + biz.reviews_count + ' avis)</span>';
            }
            return '<div style="display:flex;align-items:center;gap:8px;padding:8px 10px;background:rgba(0,212,255,.08);border:1px solid rgba(0,212,255,.2);border-radius:6px;margin-top:6px;">'
                + '<svg viewBox="0 0 24 24" style="width:16px;height:16px;flex-shrink:0;stroke:var(--g);fill:none;stroke-width:2;"><polyline points="20 6 9 17 4 12"/></svg>'
                + '<div style="flex:1;min-width:0;">'
                + '<div style="font-weight:700;color:var(--t1);font-size:13px;">' + (biz.name || '') + ratingHtml + '</div>'
                + (info.length ? '<div style="font-size:11px;color:var(--t3);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + info.join(' — ') + '</div>' : '')
                + '</div>'
                + '<button onclick="APP.acquisition._clearBizSelection()" style="background:none;border:none;cursor:pointer;padding:2px;color:var(--t3);" title="Supprimer la sélection">'
                + '<svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;"><path d="M18 6L6 18M6 6l12 12"/></svg>'
                + '</button></div>';
        },

        _clearBizSelection() {
            this._acqBizData = null;
            var sel = document.getElementById('acq-biz-selected');
            if (sel) sel.innerHTML = '';
            var input = document.getElementById('acq-target-name');
            if (input) { input.value = ''; input.focus(); }
            var btn = document.getElementById('btn-acq-search');
            if (btn) btn.disabled = true;
        },

        // === CITY AUTOCOMPLETE (meme pattern que keywords) ===
        _acqCityAutocomplete(query) {
            var list = document.getElementById('acq-city-ac-list');
            if (!list) return;
            clearTimeout(this._acqAcTimer);
            if (this._acqAcAbort) { this._acqAcAbort.abort(); this._acqAcAbort = null; }
            // Reset city data si l'utilisateur tape
            this._acqCityData = null;
            if (query.length < 2) { list.style.display = 'none'; return; }
            this._acqAcTimer = setTimeout(async () => {
                try {
                    this._acqAcAbort = new AbortController();
                    var resp = await fetch(APP.url + '/api/dataforseo-locations.php?q=' + encodeURIComponent(query), { signal: this._acqAcAbort.signal });
                    var data = await resp.json();
                    this._acqAcAbort = null;
                    if (!data.results || !data.results.length) { list.style.display = 'none'; return; }
                    var html = '';
                    for (var i = 0; i < data.results.length; i++) {
                        var r = data.results[i];
                        var city = r.city || r.name;
                        var dept = r.department || '';
                        var cp = r.postal || '';
                        var pop = r.population || 0;
                        var lat = r.lat || 0;
                        var lng = r.lng || 0;
                        var label = r.name;
                        var idx = city.toLowerCase().indexOf(query.toLowerCase());
                        var displayCity = idx >= 0 ? city.substring(0, idx) + '<b>' + city.substring(idx, idx + query.length) + '</b>' + city.substring(idx + query.length) : city;
                        var popStr = pop > 0 ? (pop > 1000 ? Math.round(pop/1000) + 'k hab.' : pop + ' hab.') : '';
                        html += '<div class="ac-item" onmousedown="APP.acquisition._acqSelectCity(\'' + label.replace(/'/g, "\\'") + '\',' + lat + ',' + lng + ')">';
                        html += '<div><span class="ac-city">' + displayCity + '</span>';
                        if (dept) html += '<span class="ac-dept">' + dept + (cp ? ' (' + cp + ')' : '') + '</span>';
                        html += '</div>';
                        if (popStr) html += '<span class="ac-type">' + popStr + '</span>';
                        html += '</div>';
                    }
                    list.innerHTML = html;
                    list.style.display = 'block';
                } catch (e) {
                    if (e.name !== 'AbortError') console.error('acq city autocomplete:', e);
                }
            }, 250);
        },

        _acqSelectCity(name, lat, lng) {
            var input = document.getElementById('acq-city');
            var list = document.getElementById('acq-city-ac-list');
            if (input) input.value = name;
            if (list) list.style.display = 'none';
            this._acqCityData = { name: name, lat: lat, lng: lng };
        },

        switchView(v) {
            this._view = v;
            this._detailId = null;
            this.render();
        },

        // === PROSPECTS LIST ===
        async loadProspects() {
            const data = await APP.fetch('/api/prospects.php?action=list_prospects&period=' + this._periodFilter);
            if (data.error) return;
            this._prospects = data.prospects || [];
            this.renderProspects();
        },

        _setPeriodFilter(period) {
            this._periodFilter = period;
            this.loadProspects();
        },

        renderProspects() {
            const c = document.getElementById('module-content');
            if (!c) return;

            let h = `<div class="sh" style="flex-wrap:wrap;gap:12px;">
                <div class="stit">AUDIT & ACQUISITION</div>
                <div style="display:flex;gap:8px;">
                    <button class="btn ${this._view==='search'?'bp':'bs'} bsm" onclick="APP.acquisition.switchView('search')">Rechercher</button>
                    <button class="btn ${this._view==='prospects'?'bp':'bs'} bsm" onclick="APP.acquisition.switchView('prospects')">Mes Prospects</button>
                </div>
            </div>`;

            // Période selector (toujours visible)
            const pf0 = this._periodFilter;
            h += `<div style="padding:10px 20px;border-bottom:1px solid var(--bdr);display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <span style="font-size:12px;color:var(--t3);margin-right:2px;">Période :</span>
                <div class="dash-period-selector" style="margin:0;">
                    <button class="dash-period-btn${pf0==='90d'?' active':''}" onclick="APP.acquisition._setPeriodFilter('90d')">90 jours</button>
                    <button class="dash-period-btn${pf0==='6m'?' active':''}" onclick="APP.acquisition._setPeriodFilter('6m')">6 mois</button>
                    <button class="dash-period-btn${pf0==='all'?' active':''}" onclick="APP.acquisition._setPeriodFilter('all')">Tous</button>
                </div>
            </div>`;

            if (!this._prospects.length) {
                h += `<div style="padding:50px;text-align:center;">
                    <svg viewBox="0 0 24 24" style="width:48px;height:48px;stroke:var(--t3);fill:none;stroke-width:1.5;margin-bottom:16px;opacity:.4;">
                        <path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4-4v2"/><circle cx="9" cy="7" r="4"/><path d="M20 8v6M23 11h-6"/>
                    </svg>
                    <div style="font-size:15px;font-weight:600;color:var(--t2);margin-bottom:6px;">Aucun prospect sur cette période</div>
                    <div style="font-size:13px;color:var(--t3);">Lancez une recherche ou changez la période</div>
                </div>`;
            } else {
                // Compteurs pour les filtres
                const totalCount = this._prospects.length;
                const contactedCount = this._prospects.filter(p => !!p.sent_at).length;
                const notContactedCount = totalCount - contactedCount;
                const auditedCount = this._prospects.filter(p => p.audit_status === 'audited').length;
                const f = this._prospectFilter;

                // Barre de filtres statut
                h += `<div style="padding:12px 20px;border-bottom:1px solid var(--bdr);display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                    <span style="font-size:12px;color:var(--t3);margin-right:4px;">Filtrer :</span>
                    <button class="btn ${f==='all'?'bp':'bs'} bsm" onclick="APP.acquisition._setProspectFilter('all')" style="font-size:11px;">Tous (${totalCount})</button>
                    <button class="btn ${f==='contacted'?'bp':'bs'} bsm" onclick="APP.acquisition._setProspectFilter('contacted')" style="font-size:11px;">
                        <svg viewBox="0 0 24 24" style="width:12px;height:12px;stroke:var(--g);fill:none;stroke-width:2.5;"><path d="M20 6L9 17l-5-5"/></svg>
                        Contactés (${contactedCount})
                    </button>
                    <button class="btn ${f==='not_contacted'?'bp':'bs'} bsm" onclick="APP.acquisition._setProspectFilter('not_contacted')" style="font-size:11px;">Non contactés (${notContactedCount})</button>
                    <button class="btn ${f==='audited'?'bp':'bs'} bsm" onclick="APP.acquisition._setProspectFilter('audited')" style="font-size:11px;">Audités (${auditedCount})</button>
                </div>`;

                // Filtrer les prospects
                let filtered = this._prospects;
                if (f === 'contacted') filtered = filtered.filter(p => !!p.sent_at);
                else if (f === 'not_contacted') filtered = filtered.filter(p => !p.sent_at);
                else if (f === 'audited') filtered = filtered.filter(p => p.audit_status === 'audited');

                if (!filtered.length) {
                    h += `<div style="padding:30px;text-align:center;color:var(--t3);font-size:13px;">Aucun prospect dans ce filtre</div>`;
                } else {
                    h += '<table><thead><tr><th>Entreprise</th><th>Ville</th><th style="text-align:center">Statut</th><th style="text-align:center">Score</th><th style="text-align:center">Visibilité</th><th style="text-align:center">Note</th><th style="text-align:center">Contacté</th><th></th></tr></thead><tbody>';
                    for (const p of filtered) {
                        const scoreColor = p.score >= 70 ? 'var(--g)' : p.score >= 40 ? 'var(--o)' : 'var(--r)';
                        const st = p.audit_status || 'search_only';
                        const statusBadge = st === 'audited' ? '<span style="background:rgba(34,197,94,.15);color:var(--g);padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600;">Audité</span>'
                            : st === 'scanned' ? '<span style="background:rgba(245,158,11,.15);color:var(--o);padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600;">Scanné</span>'
                            : '<span style="background:rgba(120,130,150,.15);color:var(--t3);padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600;">Nouveau</span>';
                        const vis = p.grid_visibility != null ? p.grid_visibility + '%' : '\u2014';
                        const visColor = p.grid_visibility >= 60 ? 'var(--g)' : p.grid_visibility >= 30 ? 'var(--o)' : 'var(--r)';
                        const contacted = !!p.sent_at;
                        const contactedHtml = contacted
                            ? `<svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:var(--g);fill:none;stroke-width:2.5;"><path d="M20 6L9 17l-5-5"/></svg>`
                            : `<span style="color:var(--t3);">\u2014</span>`;

                        // Actions contextuelles — bouton unifie "Analyser" (scan + audit)
                        const analysisRunning = !!this._analysisRunning?.[p.id];
                        let actions = `<button class="btn bs bsm" onclick="APP.acquisition.showDétail(${p.id})" title="Voir">Voir</button>`;
                        if (analysisRunning) {
                            actions = `<div style="display:inline-flex;align-items:center;gap:6px;"><svg viewBox="0 0 24 24" class="spin" style="width:16px;height:16px;stroke:var(--acc);fill:none;stroke-width:2;"><path d="M12 2v4m0 12v4m10-10h-4M6 12H2"/></svg><span style="font-size:12px;color:var(--acc);font-weight:600;" id="analysis-list-phase-${p.id}">Analyse...</span></div> ` + actions;
                        } else if (st !== 'audited') {
                            actions = `<button class="btn bp bsm" id="btn-analyze-list-${p.id}" onclick="APP.acquisition.analyzeProspect(${p.id})" title="Analyser">Analyser</button> ` + actions;
                        }

                        h += `<tr id="prospect-row-${p.id}">
                            <td><div class="kn" style="cursor:pointer;" onclick="APP.acquisition.showDétail(${p.id})">${p.business_name}</div><div style="font-size:11px;color:var(--t3);">${p.category || ''}</div></td>
                            <td class="kv">${p.city || '\u2014'}</td>
                            <td style="text-align:center" id="acq-status-${p.id}">${statusBadge}</td>
                            <td style="text-align:center"><span style="font-weight:700;font-family:'Space Mono',monospace;color:${scoreColor}">${p.score || '\u2014'}</span></td>
                            <td style="text-align:center" id="acq-vis-${p.id}"><span style="font-weight:600;font-family:'Space Mono',monospace;color:${p.grid_visibility != null ? visColor : 'var(--t3)'}">${vis}</span></td>
                            <td style="text-align:center"><span style="color:var(--o);">${p.rating ? p.rating + ' \u2605' : '\u2014'}</span></td>
                            <td style="text-align:center">${contactedHtml}</td>
                            <td style="white-space:nowrap;" id="acq-actions-${p.id}">${actions}</td>
                        </tr>`;
                    }
                    h += '</tbody></table>';
                }
            }
            c.innerHTML = h;
        },

        // === DETAIL VIEW ===
        async showDétail(auditId) {
            this._view = 'detail';
            this._detailId = auditId;
            const data = await APP.fetch('/api/prospects.php?action=get_audit&audit_id=' + auditId);
            if (!data.success) { APP.toast(data.error || 'Erreur', 'error'); return; }
            this._detailData = data;
            this.renderDétail();
        },

        renderDétail() {
            const c = document.getElementById('module-content');
            if (!c || !this._detailData) return;
            const a = this._detailData.audit;
            this._detailAudit = a;
            const pts = this._detailData.grid_points || [];
            const bd = a.audit_data?.score_breakdown || {};
            const score = parseInt(a.score) || 0;
            const scoreColor = score >= 70 ? 'var(--g)' : score >= 40 ? 'var(--o)' : 'var(--r)';
            const st = a.audit_status || 'search_only';

            let h = `<div class="sh" style="flex-wrap:wrap;gap:12px;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <button class="btn bs bsm" onclick="APP.acquisition.switchView('prospects')" title="Retour">
                        <svg viewBox="0 0 24 24" style="width:16px;height:16px;"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                    </button>
                    <div>
                        <div class="stit">${a.business_name}</div>
                        <div style="font-size:12px;color:var(--t3);">${a.city || ''}${a.category ? ' \u2014 ' + a.category : ''}</div>
                    </div>
                </div>
                <div style="display:flex;gap:8px;align-items:center;">`;

            // Boutons d'action — bouton unifie "Analyser" (scan grille + audit chaine)
            const analysisRunning = !!this._analysisRunning?.[a.id];
            if (analysisRunning) {
                h += `<button class="btn bp bsm" disabled style="opacity:.7;" id="btn-analyze-${a.id}"><svg viewBox="0 0 24 24" class="spin" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;"><path d="M12 2v4m0 12v4m10-10h-4M6 12H2"/></svg> Analyse en cours...</button>`;
            } else if (st === 'audited') {
                h += `<div style="position:relative;display:inline-flex;" id="pdf-split-${a.id}">
                    <button class="btn bp bsm" onclick="APP.acquisition.generatePdf(${a.id})" id="btn-pdf-${a.id}" style="border-radius:8px 0 0 8px;border-right:1px solid rgba(255,255,255,.2);">Générer PDF</button>
                    <button class="btn bp bsm" onclick="APP.acquisition._togglePdfMenu(${a.id})" id="btn-pdf-arrow-${a.id}" style="border-radius:0 8px 8px 0;padding:6px 8px;"><svg viewBox="0 0 24 24" style="width:12px;height:12px;fill:currentColor;"><path d="M7 10l5 5 5-5z"/></svg></button>
                    <div id="pdf-menu-${a.id}" style="display:none;position:absolute;top:100%;left:0;margin-top:4px;background:var(--card);border:1px solid var(--bdr);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.25);min-width:200px;z-index:50;overflow:hidden;">
                        <div onclick="APP.acquisition.generatePdf(${a.id});APP.acquisition._closePdfMenu(${a.id})" style="padding:10px 14px;cursor:pointer;font-size:12px;color:var(--t1);display:flex;align-items:center;gap:8px;transition:background .15s;" onmouseenter="this.style.background='var(--subtle-bg)'" onmouseleave="this.style.background=''">
                            <svg viewBox="0 0 24 24" style="width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;flex-shrink:0;"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            Générer le PDF
                        </div>
                        <div style="height:1px;background:var(--bdr);margin:0 8px;"></div>
                        <div onclick="APP.acquisition.copyPdfLink(${a.id});APP.acquisition._closePdfMenu(${a.id})" style="padding:10px 14px;cursor:pointer;font-size:12px;color:var(--t1);display:flex;align-items:center;gap:8px;transition:background .15s;" onmouseenter="this.style.background='var(--subtle-bg)'" onmouseleave="this.style.background=''">
                            <svg viewBox="0 0 24 24" style="width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;flex-shrink:0;"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
                            Copier le lien de l'audit
                        </div>
                    </div>
                </div>`;
                h += `<button class="btn bs bsm" onclick="APP.acquisition.analyzeProspect(${a.id})" id="btn-analyze-${a.id}">Re-analyser</button>`;
            } else {
                h += `<button class="btn bp bsm" onclick="APP.acquisition.analyzeProspect(${a.id})" id="btn-analyze-${a.id}">Lancer l'analyse</button>`;
            }
            h += `<button class="btn bs bsm" style="color:var(--r);border-color:rgba(239,68,68,.3);" onclick="APP.acquisition.deleteProspect(${a.id})">Supprimer</button>`;
            h += `</div></div>`;

            // Score global + 4 sections
            h += `<div style="padding:20px;">`;

            // Score global
            if (st === 'audited' && score > 0) {
                h += `<div style="display:flex;align-items:center;gap:24px;margin-bottom:24px;padding:20px;background:var(--card);border:1px solid var(--bdr);border-radius:12px;">
                    <div style="font-size:52px;font-weight:700;font-family:'Space Mono',monospace;color:${scoreColor};letter-spacing:-3px;">${score}<span style="font-size:20px;color:var(--t3);">/100</span></div>
                    <div style="flex:1;">
                        <div style="font-size:16px;font-weight:600;color:var(--t1);margin-bottom:4px;">Score d'audit global</div>
                        <div style="background:rgba(255,255,255,.06);border-radius:6px;height:8px;overflow:hidden;">
                            <div style="height:100%;width:${score}%;background:${scoreColor};border-radius:6px;transition:width .5s;"></div>
                        </div>
                    </div>
                </div>`;

                // 4 cards sections
                const sections = [
                    { key: 'visibility', label: 'Visibilité locale', max: 35, icon: 'M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5' },
                    { key: 'reputation', label: 'E-réputation', max: 25, icon: 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z' },
                    { key: 'presence', label: 'Présence digitale', max: 25, icon: 'M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9' },
                    { key: 'activity', label: 'Activité', max: 15, icon: 'M13 10V3L4 14h7v7l9-11h-7z' },
                ];

                h += `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:24px;">`;
                for (const s of sections) {
                    const sScore = bd[s.key]?.score || 0;
                    const pct = Math.round((sScore / s.max) * 100);
                    const sColor = pct >= 70 ? 'var(--g)' : pct >= 40 ? 'var(--o)' : 'var(--r)';
                    h += `<div style="background:var(--card);border:1px solid var(--bdr);border-radius:10px;padding:16px;">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                            <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:${sColor};fill:none;stroke-width:2;"><path d="${s.icon}"/></svg>
                            <span style="font-size:12px;color:var(--t3);text-transform:uppercase;font-weight:600;letter-spacing:.5px;">${s.label}</span>
                        </div>
                        <div style="font-size:24px;font-weight:700;font-family:'Space Mono',monospace;color:${sColor};">${sScore}<span style="font-size:13px;color:var(--t3);">/${s.max}</span></div>
                        <div style="background:rgba(255,255,255,.06);border-radius:4px;height:4px;margin-top:8px;overflow:hidden;">
                            <div style="height:100%;width:${pct}%;background:${sColor};border-radius:4px;"></div>
                        </div>
                    </div>`;
                }
                h += `</div>`;
            }

            // Rang global + infos cles (target_rank = rang compétitif grid, a.position = rang single-point)
            const pos = parseInt(this._detailData.target_rank) || parseInt(a.position) || 0;
            const posColor = pos && pos <= 3 ? 'var(--g)' : pos && pos <= 7 ? 'var(--o)' : 'var(--r)';
            const nbAbove = pos > 1 ? pos - 1 : 0;

            h += `<div style="background:var(--card);border:1px solid var(--bdr);border-radius:12px;padding:20px;margin-bottom:24px;">
                <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:center;">
                    <div style="text-align:center;">
                        <div style="font-size:42px;font-weight:700;font-family:'Space Mono',monospace;color:${posColor};">${pos ? '#' + pos : '#?'}</div>
                        <div style="font-size:11px;color:var(--t3);text-transform:uppercase;font-weight:600;">Rang local</div>
                    </div>
                    <div style="flex:1;min-width:200px;">
                        <div style="font-size:13px;color:var(--t2);line-height:1.6;">
                            ${!pos ? '<span style="color:var(--r);font-weight:600;">Position non détectée</span> — la fiche n\'apparaît pas dans les résultats locaux pour ce mot-clé.' :
                              pos <= 3 ? '<span style="color:var(--g);font-weight:600;">Top 3 dans votre zone</span> — vous dominez la visibilité locale face à vos concurrents.' :
                              pos <= 7 ? '<span style="color:var(--o);font-weight:600;">' + nbAbove + ' concurrent(s) mieux classé(s)</span> — bonne présence mais pas encore dans le top 3 local.' :
                              pos <= 20 ? '<span style="color:var(--r);font-weight:600;">' + nbAbove + ' concurrent(s) mieux classé(s)</span> — visibilité faible, les prospects voient d\'abord vos concurrents.' :
                              '<span style="color:var(--r);font-weight:600;">Position très basse ou non détectée</span> — la fiche est quasi invisible face à la concurrence.'}
                        </div>
                    </div>
                </div>
            </div>`;

            h += `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:24px;">`;
            const photoCount = parseInt(a.total_photos) || 0;
            const photoColor = photoCount >= 30 ? 'var(--g)' : photoCount >= 15 ? 'var(--o)' : 'var(--r)';
            const infos = [
                ['Note Google', a.rating ? a.rating + '/5 \u2605' : 'N/A', a.rating >= 4.5 ? 'var(--g)' : a.rating >= 4.0 ? 'var(--o)' : 'var(--r)'],
                ['Avis clients', (a.reviews_count || 0) + ' avis', parseInt(a.reviews_count) >= 50 ? 'var(--g)' : parseInt(a.reviews_count) >= 20 ? 'var(--o)' : 'var(--r)'],
                ['Photos', photoCount ? photoCount + ' photos' : 'Aucune', photoColor],
                ['Site web', a.domain || 'Non renseigné', a.domain ? 'var(--acc)' : 'var(--r)'],
                ['Description', a.description ? 'Oui' : 'Absente', a.description ? 'var(--g)' : 'var(--r)'],
                ['Mot-clé', a.search_keyword || a.category || '\u2014', 'var(--t2)'],
            ];
            for (const [label, val, color] of infos) {
                h += `<div style="background:var(--card);border:1px solid var(--bdr);border-radius:10px;padding:14px;">
                    <div style="font-size:11px;color:var(--t3);text-transform:uppercase;font-weight:600;letter-spacing:.5px;margin-bottom:6px;">${label}</div>
                    <div style="font-size:16px;font-weight:600;color:${color};">${val}</div>
                </div>`;
            }
            h += `</div>`;

            // Grille visibility
            if (a.grid_scan_id) {
                const gv = parseInt(a.grid_visibility) || 0;
                const gvColor = gv >= 50 ? 'var(--g)' : gv >= 20 ? 'var(--o)' : 'var(--r)';
                const gt3 = parseInt(a.grid_top3) || 0;
                const gt10 = parseInt(a.grid_top10) || 0;
                const gt20 = parseInt(a.grid_top20) || 0;
                const gOut = 49 - gt20;
                h += `<div style="background:var(--card);border:1px solid var(--bdr);border-radius:12px;padding:20px;margin-bottom:24px;">
                    <div style="font-size:14px;font-weight:600;color:var(--t1);margin-bottom:4px;">Visibilité géographique (grille 49 points GPS)</div>
                    <div style="font-size:12px;color:var(--t3);margin-bottom:16px;">Analyse de la présence dans les résultats locaux sur un rayon de 15 km</div>
                    <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:center;">
                        <div style="text-align:center;">
                            <div style="font-size:36px;font-weight:700;font-family:'Space Mono',monospace;color:${gvColor};">${gv}%</div>
                            <div style="font-size:11px;color:var(--t3);">Visibilité</div>
                        </div>
                        <div style="display:flex;gap:16px;flex-wrap:wrap;">
                            <div style="text-align:center;"><div style="font-size:20px;font-weight:700;color:var(--g);">${gt3}</div><div style="font-size:10px;color:var(--t3);">Top 3</div></div>
                            <div style="text-align:center;"><div style="font-size:20px;font-weight:700;color:var(--o);">${gt10}</div><div style="font-size:10px;color:var(--t3);">Top 10</div></div>
                            <div style="text-align:center;"><div style="font-size:20px;font-weight:700;color:var(--acc);">${gt20}</div><div style="font-size:10px;color:var(--t3);">Top 20</div></div>
                            <div style="text-align:center;"><div style="font-size:20px;font-weight:700;color:var(--r);">${gOut}</div><div style="font-size:10px;color:var(--t3);">Hors top 20</div></div>
                        </div>
                    </div>
                    <div id="audit-grid-map" style="width:100%;height:400px;border-radius:10px;margin-top:16px;overflow:hidden;"></div>
                    <div style="display:flex;gap:16px;justify-content:center;margin-top:10px;flex-wrap:wrap;">
                        <div style="display:flex;align-items:center;gap:4px;"><div style="width:12px;height:12px;border-radius:50%;background:rgba(34,197,94,.85);"></div><span style="font-size:10px;color:var(--t3);">Top 3</span></div>
                        <div style="display:flex;align-items:center;gap:4px;"><div style="width:12px;height:12px;border-radius:50%;background:rgba(245,158,11,.85);"></div><span style="font-size:10px;color:var(--t3);">Top 4-10</span></div>
                        <div style="display:flex;align-items:center;gap:4px;"><div style="width:12px;height:12px;border-radius:50%;background:rgba(236,72,153,.7);"></div><span style="font-size:10px;color:var(--t3);">Top 11-20</span></div>
                        <div style="display:flex;align-items:center;gap:4px;"><div style="width:12px;height:12px;border-radius:50%;background:rgba(239,68,68,.85);"></div><span style="font-size:10px;color:var(--t3);">20+</span></div>
                    </div>
                    <div id="audit-competitors-zone" style="margin-top:16px;"></div>
                </div>`;
            }

            // Zone scan en cours
            h += `<div id="scan-progress-${a.id}"></div>`;

            // Envoi email (si audite)
            if (st === 'audited') {
                const scrapedEmails = a.audit_data?.business_info?.website_scrape?.emails || [];
                const alreadySent = !!a.sent_at;
                const sentDate = a.sent_at ? new Date(a.sent_at).toLocaleDateString('fr-FR', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' }) : '';
                h += `<div style="background:var(--card);border:1px solid var(--bdr);border-radius:12px;padding:20px;margin-bottom:24px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                        <div style="font-size:14px;font-weight:600;color:var(--t1);">
                            <svg viewBox="0 0 24 24" style="width:16px;height:16px;display:inline;vertical-align:-2px;stroke:var(--acc);fill:none;stroke-width:2;margin-right:6px;"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
                            Contacter le prospect
                        </div>
                        ${alreadySent ? `<div style="font-size:11px;color:var(--g);display:flex;align-items:center;gap:4px;">
                            <svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:var(--g);fill:none;stroke-width:2.5;"><path d="M20 6L9 17l-5-5"/></svg>
                            Envoyé le ${sentDate}${a.prospect_email ? ' à ' + a.prospect_email : ''}
                        </div>` : ''}
                    </div>
                    <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px;">
                        <div style="flex:1;min-width:250px;">
                            <label style="font-size:12px;color:var(--t3);display:block;margin-bottom:4px;">Email(s) du prospect${a.prospect_email && scrapedEmails.length ? ' (détecté automatiquement)' : ''} <span style="font-weight:400;opacity:.7;">— séparez par des virgules pour plusieurs destinataires</span></label>
                            <input type="text" id="audit-email-${a.id}" class="si" placeholder="contact@entreprise.fr, info@entreprise.fr" value="${a.prospect_email || ''}" style="width:100%;">
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                        <div style="flex:1;min-width:200px;">
                            <label style="font-size:12px;color:var(--t3);display:block;margin-bottom:4px;">Modèle d'email</label>
                            <select id="audit-template-${a.id}" class="si" style="width:100%;" onchange="APP.acquisition._previewTemplate(${a.id})">
                                <option value="prospection_froid">Prospection à froid</option>
                                <option value="contact_existant">Contact existant</option>
                                <option value="relance">Relance</option>
                            </select>
                        </div>
                        <button class="btn bp bsm" onclick="APP.acquisition.sendAudit(${a.id})" id="btn-send-${a.id}">
                            <svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
                            ${alreadySent ? 'Renvoyer' : 'Envoyer'}
                        </button>
                    </div>
                    <div id="template-preview-${a.id}" style="margin-top:12px;border:1px solid var(--bdr);border-radius:12px;overflow:hidden;display:none;max-height:500px;overflow-y:auto;"></div>
                    <div style="margin-top:8px;text-align:right;">
                        <span style="font-size:11px;color:var(--acc);cursor:pointer;text-decoration:underline;" onclick="APP.acquisition._previewTemplate(${a.id})">Aperçu du message</span>
                    </div>
                    ${scrapedEmails.length > 1 ? '<div style="font-size:11px;color:var(--t3);margin-top:8px;">Autres emails trouvés : ' + scrapedEmails.slice(1).map(e => '<span style="cursor:pointer;color:var(--acc);text-decoration:underline;" onclick="document.getElementById(\'audit-email-' + a.id + '\').value += (document.getElementById(\'audit-email-' + a.id + '\').value ? \', \' : \'\') + \'' + e + '\'">' + e + '</span>').join(', ') + '</div>' : ''}
                    ${!scrapedEmails.length && !a.prospect_email ? '<div style="font-size:11px;color:var(--t3);margin-top:8px;">Aucun email détecté sur le site web. Entrez l\'email manuellement.</div>' : ''}
                </div>`;
            }

            h += `</div>`;
            c.innerHTML = h;

            // Initialiser la carte Mapbox si des points de grille existent
            if (a.grid_scan_id && pts.length) {
                this._renderAuditMap(pts, { lat: parseFloat(a.latitude), lng: parseFloat(a.longitude) });
            }

            // Afficher les concurrents s'ils existent
            const competitors = this._detailData.competitors || [];
            if (competitors.length) {
                this._renderAuditCompetitors(competitors, this._detailData.target_rank, this._detailData.total_competitors);
            }
        },

        // === CARTE MAPBOX POUR LA GRILLE D'AUDIT ===
        _renderAuditMap(points, center) {
            const loadMapbox = (cb) => {
                if (window.mapboxgl) { cb(); return; }
                const css = document.createElement('link'); css.rel = 'stylesheet'; css.href = 'https://api.mapbox.com/mapbox-gl-js/v3.4.0/mapbox-gl.css'; document.head.appendChild(css);
                const js = document.createElement('script'); js.src = 'https://api.mapbox.com/mapbox-gl-js/v3.4.0/mapbox-gl.js'; js.onload = cb; document.head.appendChild(js);
            };

            loadMapbox(() => {
                const mapEl = document.getElementById('audit-grid-map');
                if (!mapEl) return;

                const token = document.querySelector('meta[name="mapbox-token"]')?.content;
                if (!token) { console.error('Mapbox token missing'); return; }

                // Clean up old map
                if (this._auditMap) { this._auditMap.remove(); this._auditMap = null; }
                if (this._auditMarkers) { this._auditMarkers.forEach(m => m.remove()); this._auditMarkers = []; }

                mapboxgl.accessToken = token;
                const map = new mapboxgl.Map({
                    container: mapEl,
                    style: 'mapbox://styles/mapbox/dark-v11',
                    center: [center.lng, center.lat],
                    zoom: 10,
                    attributionControl: false
                });
                map.addControl(new mapboxgl.NavigationControl(), 'top-right');
                this._auditMap = map;
                this._auditMarkers = [];

                const bounds = new mapboxgl.LngLatBounds();

                map.on('load', () => {
                    // Centre (emplacement du business)
                    const ce = document.createElement('div');
                    ce.style.cssText = 'width:14px;height:14px;background:#EF4444;border:3px solid #fff;border-radius:50%;box-shadow:0 2px 8px rgba(0,0,0,.4);';
                    const cm = new mapboxgl.Marker({ element: ce }).setLngLat([center.lng, center.lat]).addTo(map);
                    this._auditMarkers.push(cm);
                    bounds.extend([center.lng, center.lat]);

                    // Points de grille
                    for (const pt of points) {
                        const lat = parseFloat(pt.latitude), lng = parseFloat(pt.longitude);
                        if (!lat || !lng) continue;
                        bounds.extend([lng, lat]);

                        const pos = pt.position !== null && pt.position !== '' ? parseInt(pt.position) : null;
                        let bgColor, size, label, tooltip;
                        if (pos === null) {
                            bgColor = 'rgba(107,114,128,.4)'; size = 26; label = '?'; tooltip = 'Pas de données';
                        } else if (pos <= 3) {
                            bgColor = 'rgba(34,197,94,.85)'; size = 36; label = pos; tooltip = 'Position ' + pos;
                        } else if (pos <= 10) {
                            bgColor = 'rgba(245,158,11,.85)'; size = 32; label = pos; tooltip = 'Position ' + pos;
                        } else if (pos <= 20) {
                            bgColor = 'rgba(236,72,153,.7)'; size = 30; label = pos; tooltip = 'Position ' + pos;
                        } else {
                            bgColor = 'rgba(239,68,68,.85)'; size = 28; label = '20+'; tooltip = 'Position ' + pos;
                        }

                        const el = document.createElement('div');
                        el.style.cssText = `width:${size}px;height:${size}px;background:${bgColor};border:2px solid rgba(255,255,255,.5);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:${pos !== null && pos <= 20 ? '11' : '9'}px;font-weight:700;font-family:'Space Mono',monospace;box-shadow:0 2px 6px rgba(0,0,0,.25);cursor:pointer;`;
                        el.textContent = label;

                        // Popup enrichi avec top 3 concurrents du point
                        let popupHtml = `<div style="font-size:12px;font-weight:700;margin-bottom:4px;">${tooltip}</div>`;
                        if (pt.business_name_found) popupHtml += `<div style="font-size:11px;color:#00d4ff;margin-bottom:6px;">${pt.business_name_found}</div>`;
                        if (pt.top_competitors && pt.top_competitors.length) {
                            popupHtml += `<div style="font-size:10px;color:#999;margin-bottom:3px;text-transform:uppercase;">Top 3 à ce point :</div>`;
                            for (const tc of pt.top_competitors) {
                                const tcColor = tc.pos <= 3 ? '#22c55e' : tc.pos <= 10 ? '#f59e0b' : '#ef4444';
                                popupHtml += `<div style="font-size:11px;display:flex;gap:6px;align-items:center;margin-bottom:2px;">
                                    <span style="color:${tcColor};font-weight:700;min-width:18px;">#${tc.pos}</span>
                                    <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px;">${tc.title}</span>
                                    ${tc.rating ? `<span style="color:#999;font-size:9px;">★${parseFloat(tc.rating).toFixed(1)}</span>` : ''}
                                </div>`;
                            }
                        }
                        const popup = new mapboxgl.Popup({ offset: 25, closeButton: false, closeOnClick: false, maxWidth: '280px' }).setHTML(popupHtml);
                        const marker = new mapboxgl.Marker({ element: el }).setLngLat([lng, lat]).setPopup(popup).addTo(map);
                        el.addEventListener('mouseenter', () => marker.togglePopup());
                        el.addEventListener('mouseleave', () => marker.togglePopup());
                        this._auditMarkers.push(marker);
                    }

                    if (!bounds.isEmpty()) map.fitBounds(bounds, { padding: 50, maxZoom: 12 });
                });
            });
        },

        // === CLASSEMENT CONCURRENTS DANS L'AUDIT ===
        _renderAuditCompetitors(competitors, targetRank, totalCompetitors) {
            const zone = document.getElementById('audit-competitors-zone');
            if (!zone) return;
            if (!competitors || !competitors.length) { zone.innerHTML = ''; return; }

            const totalPts = 49;
            let h = '';

            // En-tete avec rang si disponible
            h += `<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">`;
            h += `<div style="font-size:12px;font-weight:600;color:var(--t2);text-transform:uppercase;letter-spacing:.5px;">Classement concurrents</div>`;
            if (targetRank) {
                const rkColor = targetRank <= 3 ? 'var(--g)' : targetRank <= 10 ? 'var(--o)' : targetRank <= 20 ? 'var(--p)' : 'var(--r)';
                h += `<div style="display:flex;align-items:center;gap:6px;">
                    <span style="font-size:11px;color:var(--t3);">Votre rang :</span>
                    <span style="font-size:16px;font-weight:700;color:${rkColor};font-family:'Space Mono',monospace;">#${targetRank}</span>
                    <span style="font-size:11px;color:var(--t3);">/ ${totalCompetitors || '?'}</span>
                </div>`;
            }
            h += `</div>`;

            // Grille de concurrents
            h += `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:8px;">`;
            const top = competitors.slice(0, 15);
            for (const c of top) {
                const isTarget = parseInt(c.is_target) === 1;
                const avg = parseFloat(c.avg_position) || 101;
                const rank = c.rank || '—';
                const avgColor = avg <= 3 ? 'rgba(34,197,94,.85)' : avg <= 10 ? 'rgba(245,158,11,.85)' : avg <= 20 ? 'rgba(236,72,153,.7)' : 'rgba(239,68,68,.85)';
                const rankColor = rank <= 3 ? 'var(--g)' : rank <= 10 ? 'var(--o)' : rank <= 20 ? 'var(--p)' : 'var(--r)';
                const rating = c.rating ? parseFloat(c.rating).toFixed(1) : '—';
                const avgLabel = avg <= 20 ? avg.toFixed(1) : '20+';
                const avgFontSize = avg <= 20 ? '11' : '9';
                const mapsUrl = c.place_id ? `https://www.google.com/maps/place/?q=place_id:${c.place_id}` : '#';

                h += `<a href="${mapsUrl}" target="_blank" style="display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:8px;background:${isTarget?'rgba(0,212,255,.08)':'var(--card)'};border:1px solid ${isTarget?'rgba(0,212,255,.25)':'var(--bdr)'};text-decoration:none;color:inherit;transition:border-color .2s;" onmouseover="this.style.borderColor='var(--acc)'" onmouseout="this.style.borderColor='${isTarget?'rgba(0,212,255,.25)':'var(--bdr)'}'">`
                + `<div style="min-width:28px;text-align:center;font-size:14px;font-weight:700;color:${rankColor};font-family:'Space Mono',monospace;">#${rank}</div>`
                + `<div style="min-width:36px;height:36px;border-radius:50%;background:${avgColor};display:flex;align-items:center;justify-content:center;color:#fff;font-size:${avgFontSize}px;font-weight:700;font-family:'Space Mono',monospace;" title="Pos. moyenne: ${avg.toFixed(1)}">${avgLabel}</div>`
                + `<div style="flex:1;min-width:0;">`
                + `<div style="font-size:13px;font-weight:${isTarget?'700':'500'};white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${isTarget?'⭐ ':''}${c.title}</div>`
                + `<div style="font-size:11px;color:var(--t3);">${rating !== '—' ? '★ ' + rating : ''} ${c.reviews_count ? '· ' + c.reviews_count + ' avis' : ''} · Vu ${c.appearances}/${totalPts} pts</div>`
                + `</div></a>`;
            }
            h += `</div>`;

            zone.innerHTML = h;
        },

        // === ANALYSE UNIFIEE (scan grille + audit chaines) ===
        async analyzeProspect(auditId) {
            // Track running state
            if (!this._analysisRunning) this._analysisRunning = {};
            this._analysisRunning[auditId] = true;

            // Disable detail button
            const btn = document.getElementById('btn-analyze-' + auditId);
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<svg viewBox="0 0 24 24" class="spin" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;"><path d="M12 2v4m0 12v4m10-10h-4M6 12H2"/></svg> Lancement...';
            }

            // List view spinner
            const listBtn = document.getElementById('btn-analyze-list-' + auditId);
            const actionsCell = document.getElementById('acq-actions-' + auditId);
            if (listBtn && actionsCell) {
                actionsCell.innerHTML = `<div style="display:inline-flex;align-items:center;gap:6px;">
                    <svg viewBox="0 0 24 24" class="spin" style="width:16px;height:16px;stroke:var(--acc);fill:none;stroke-width:2;"><path d="M12 2v4m0 12v4m10-10h-4M6 12H2"/></svg>
                    <span style="font-size:12px;color:var(--acc);font-weight:600;" id="analysis-list-phase-${auditId}">Lancement...</span>
                </div>`;
            }

            // Status badge
            const statusCell = document.getElementById('acq-status-' + auditId);
            if (statusCell) {
                statusCell.innerHTML = '<span style="background:rgba(0,209,178,.15);color:var(--acc);padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600;">Analyse...</span>';
            }

            // Show progress bar in detail view
            this._updateAnalysisProgress(auditId, 1, 'Lancement du scan grille...');

            // Step 1: Start scan_grid
            const fd = new FormData();
            fd.append('action', 'scan_grid');
            fd.append('audit_id', auditId);
            const data = await APP.fetch('/api/prospects.php', { method: 'POST', body: fd });

            if (data.success) {
                if (data.credits_remaining != null) {
                    const cr = document.getElementById('credits-count');
                    if (cr) cr.textContent = data.credits_remaining;
                }
                // Poll scan → then auto-chain to audit
                this._pollAnalysis(auditId);
            } else {
                delete this._analysisRunning[auditId];
                APP.toast(data.error || 'Erreur', 'error');
                if (btn) { btn.disabled = false; btn.textContent = 'Lancer l\'analyse'; }
                if (actionsCell) {
                    actionsCell.innerHTML = `<button class="btn bp bsm" id="btn-analyze-list-${auditId}" onclick="APP.acquisition.analyzeProspect(${auditId})" title="Analyser">Analyser</button> <button class="btn bs bsm" onclick="APP.acquisition.showDétail(${auditId})" title="Voir">Voir</button>`;
                }
                if (statusCell) {
                    statusCell.innerHTML = '<span style="background:rgba(120,130,150,.15);color:var(--t3);padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600;">Nouveau</span>';
                }
            }
        },

        _pollAnalysis(auditId) {
            if (this._scanTimer) clearInterval(this._scanTimer);

            this._scanTimer = setInterval(async () => {
                const data = await APP.fetch('/api/prospects.php?action=scan_status&audit_id=' + auditId);
                if (!data.success) return;

                const phaseTexts = {
                    starting: 'Démarrage du scan...',
                    grid: 'Génération de la grille GPS...',
                    scanning: 'Scan des 49 points GPS...',
                    processing: 'Traitement des résultats...'
                };
                const shortPhases = {
                    starting: 'Démarrage...',
                    grid: 'Grille...',
                    scanning: 'Scan GPS...',
                    processing: 'Traitement...'
                };

                if (data.phase) {
                    this._updateAnalysisProgress(auditId, 1, phaseTexts[data.phase] || data.phase);
                    const lp = document.getElementById('analysis-list-phase-' + auditId);
                    if (lp) lp.textContent = shortPhases[data.phase] || data.phase;
                }

                if (data.status === 'completed') {
                    clearInterval(this._scanTimer);
                    this._scanTimer = null;

                    // Step 2/2: Audit
                    this._updateAnalysisProgress(auditId, 2, 'Audit de la fiche en cours...');
                    const lp = document.getElementById('analysis-list-phase-' + auditId);
                    if (lp) lp.textContent = 'Audit...';

                    // Chain to run_audit
                    const fd = new FormData();
                    fd.append('action', 'run_audit');
                    fd.append('audit_id', auditId);

                    try {
                        const auditData = await APP.fetch('/api/prospects.php', { method: 'POST', body: fd });
                        delete this._analysisRunning[auditId];

                        if (auditData.success) {
                            APP.toast('Analyse terminée ! Score : ' + (auditData.score || 0) + '/100', 'success');
                        } else {
                            APP.toast(auditData.error || 'Erreur lors de l\'audit', 'error');
                        }
                    } catch (e) {
                        delete this._analysisRunning[auditId];
                        APP.toast('Erreur réseau lors de l\'audit', 'error');
                    }

                    // Refresh view
                    if (this._view === 'detail' && this._detailId == auditId) {
                        this.showDétail(auditId);
                    } else if (this._view === 'prospects') {
                        this.loadProspects();
                    }

                } else if (data.status === 'failed') {
                    clearInterval(this._scanTimer);
                    this._scanTimer = null;
                    delete this._analysisRunning[auditId];
                    APP.toast(data.error || 'Scan échoué', 'error');

                    const zone = document.getElementById('scan-progress-' + auditId);
                    if (zone) zone.innerHTML = `<div style="padding:16px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);border-radius:10px;color:var(--r);margin-bottom:24px;">${data.error || 'Erreur lors du scan'}</div>`;

                    if (this._view === 'detail' && this._detailId == auditId) {
                        this.showDétail(auditId);
                    }
                    const actionsCell = document.getElementById('acq-actions-' + auditId);
                    if (actionsCell) {
                        actionsCell.innerHTML = `<button class="btn bp bsm" id="btn-analyze-list-${auditId}" onclick="APP.acquisition.analyzeProspect(${auditId})" title="Analyser">Analyser</button> <button class="btn bs bsm" onclick="APP.acquisition.showDétail(${auditId})" title="Voir">Voir</button>`;
                    }

                } else if (data.status === 'idle') {
                    clearInterval(this._scanTimer);
                    this._scanTimer = null;
                    delete this._analysisRunning[auditId];
                }
            }, 4000);
        },

        _updateAnalysisProgress(auditId, step, text) {
            const zone = document.getElementById('scan-progress-' + auditId);
            if (!zone) return;

            const pct = step === 1 ? 40 : 80;
            const stepLabel = step === 1 ? 'Scan de la grille GPS' : 'Audit de la fiche';
            zone.innerHTML = `<div style="background:var(--card);border:1px solid var(--bdr);border-radius:12px;padding:20px;margin-bottom:24px;">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
                    <svg viewBox="0 0 24 24" class="spin" style="width:20px;height:20px;stroke:var(--acc);fill:none;stroke-width:2;"><path d="M12 2v4m0 12v4m10-10h-4M6 12H2"/></svg>
                    <div>
                        <div style="font-size:14px;font-weight:600;color:var(--t1);">Analyse en cours</div>
                        <div style="font-size:12px;color:var(--t3);">Étape ${step}/2 — ${stepLabel}</div>
                    </div>
                </div>
                <div style="background:rgba(255,255,255,.06);border-radius:6px;height:8px;overflow:hidden;margin-bottom:8px;">
                    <div style="height:100%;width:${pct}%;background:var(--acc);border-radius:6px;transition:width .8s ease;"></div>
                </div>
                <div style="font-size:12px;color:var(--t3);">${text}</div>
            </div>`;
        },

        // === PDF SPLIT BUTTON ===
        _togglePdfMenu(auditId) {
            const menu = document.getElementById('pdf-menu-' + auditId);
            if (!menu) return;
            const open = menu.style.display !== 'none';
            menu.style.display = open ? 'none' : 'block';
            if (!open) {
                // Fermer au clic extérieur
                const close = (e) => {
                    if (!e.target.closest('#pdf-split-' + auditId)) {
                        menu.style.display = 'none';
                        document.removeEventListener('click', close);
                    }
                };
                setTimeout(() => document.addEventListener('click', close), 0);
            }
        },
        _closePdfMenu(auditId) {
            const menu = document.getElementById('pdf-menu-' + auditId);
            if (menu) menu.style.display = 'none';
        },

        async generatePdf(auditId) {
            const btn = document.getElementById('btn-pdf-' + auditId);
            if (btn) { btn.disabled = true; btn.textContent = 'Génération...'; }

            const fd = new FormData();
            fd.append('action', 'generate_pdf');
            fd.append('audit_id', auditId);
            const data = await APP.fetch('/api/prospects.php', { method: 'POST', body: fd });

            if (data.success && data.pdf_url) {
                APP.toast('PDF généré !', 'success');
                if (this._detailData) this._detailData.pdf_url = data.pdf_url;
                window.open(data.pdf_url, '_blank');
            } else {
                APP.toast(data.error || 'Erreur', 'error');
            }
            if (btn) { btn.disabled = false; btn.textContent = 'Générer PDF'; }
        },

        async copyPdfLink(auditId) {
            let pdfUrl = this._detailData?.pdf_url;

            // Si pas de PDF, en générer un d'abord
            if (!pdfUrl) {
                const fd = new FormData();
                fd.append('action', 'generate_pdf');
                fd.append('audit_id', auditId);
                const data = await APP.fetch('/api/prospects.php', { method: 'POST', body: fd });

                if (data.success && data.pdf_url) {
                    pdfUrl = data.pdf_url;
                    if (this._detailData) this._detailData.pdf_url = pdfUrl;
                } else {
                    APP.toast(data.error || 'Erreur de génération', 'error');
                    return;
                }
            }

            // Copier le lien
            try {
                await navigator.clipboard.writeText(pdfUrl);
                APP.toast('Lien copié dans le presse-papier !', 'success');
            } catch (e) {
                const ta = document.createElement('textarea');
                ta.value = pdfUrl;
                ta.style.position = 'fixed'; ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select(); document.execCommand('copy');
                document.body.removeChild(ta);
                APP.toast('Lien copié !', 'success');
            }
        },

        // === EMAIL TEMPLATE PREVIEW ===
        _templateBodies: {
            prospection_froid: "Bonjour,\n\nJe suis Mathieu, expert en référencement Google Business Profile. Je me permets de vous contacter car j'ai réalisé un audit de visibilité de votre établissement {business_name}{city_text}.\n\nVotre score actuel est de {score}/100, ce qui signifie qu'il y a des axes d'amélioration concrets pour rendre votre fiche plus visible.\n\nMon objectif au quotidien, c'est justement de positionner mes clients dans le top 3 local sur Google, pour que ce soit eux que les internautes choisissent en premier.\n\nVous trouverez le rapport détaillé en pièce jointe. Et si vous souhaitez en savoir plus sur mes services, c'est par ici : www.boustacom.fr\n\nN'hésitez pas à me contacter si vous avez des questions.",
            contact_existant: "Bonjour,\n\nSuite à notre échange, je vous envoie comme convenu l'audit de visibilité en ligne de votre établissement {business_name}{city_text}.\n\nVotre score actuel est de {score}/100.\n\nLe rapport en pièce jointe détaille votre positionnement sur Google, votre e-réputation et votre présence digitale, avec des recommandations personnalisées.\n\nN'hésitez pas si vous avez des questions, je suis disponible pour en discuter.",
            relance: "Bonjour,\n\nJe me permets de revenir vers vous suite à l'audit de visibilité que je vous avais transmis concernant {business_name}{city_text}.\n\nPour rappel, votre score actuel est de {score}/100, ce qui signifie qu'il existe des opportunités concrètes pour améliorer votre visibilité en ligne.\n\nLe rapport détaillé est de nouveau en pièce jointe. Je reste disponible pour échanger si vous le souhaitez.",
        },
        _previewTemplate(auditId) {
            const sel = document.getElementById('audit-template-' + auditId);
            const preview = document.getElementById('template-preview-' + auditId);
            if (!sel || !preview) return;
            // Toggle
            if (preview.style.display === 'block') { preview.style.display = 'none'; return; }
            const key = sel.value;
            const body = this._templateBodies[key] || '';
            const a = this._detailAudit;
            if (!a) return;
            const cityText = a.city ? ' \u00e0 ' + a.city : '';
            const filled = body.replace(/\{business_name\}/g, a.business_name || '').replace(/\{city_text\}/g, cityText).replace(/\{score\}/g, a.score || '0').replace(/\{city\}/g, a.city || '');
            const score = parseInt(a.score) || 0;
            const scoreColor = score >= 70 ? '#22c55e' : score >= 40 ? '#f59e0b' : '#ef4444';
            const subject = 'Audit SEO Local \u2014 ' + (a.business_name || '') + (a.city ? ' (' + a.city + ')' : '');
            // Paragraphes HTML
            const bodyHtml = filled.split('\n\n').filter(p => p.trim()).map(p => '<p style="font-size:14px;color:#0a1628;line-height:1.8;margin:0 0 16px;">' + p.trim().replace(/\n/g, '<br>') + '</p>').join('');
            preview.innerHTML = `
                <div style="background:#1a1a2e;padding:10px 16px;border-radius:12px 12px 0 0;">
                    <div style="font-size:10px;color:rgba(255,255,255,.4);margin-bottom:4px;">OBJET</div>
                    <div style="font-size:13px;color:#fff;font-weight:600;">${subject}</div>
                </div>
                <div style="background:#0a1628;padding:18px 28px;"><div style="font-size:20px;color:#fff;font-weight:700;">NEURA</div><div style="font-size:11px;color:rgba(255,255,255,.45);margin-top:4px;">Audit de visibilit\u00e9 SEO local</div></div>
                <div style="background:#fff;padding:28px 28px;border-left:1px solid #e5e7eb;border-right:1px solid #e5e7eb;">
                    ${bodyHtml}
                    <div style="text-align:center;margin:24px 0;">
                        <div style="display:inline-block;background:#f8fafc;border:1px solid #e5e7eb;border-radius:16px;padding:20px 40px;">
                            <div style="font-size:42px;font-weight:700;color:${scoreColor};letter-spacing:-2px;">${score}<span style="font-size:18px;color:#94a3b8;">/100</span></div>
                            <div style="font-size:11px;color:#94a3b8;margin-top:4px;text-transform:uppercase;letter-spacing:1px;">Score de visibilit\u00e9</div>
                        </div>
                    </div>
                    <p style="font-size:13px;color:#64748b;line-height:1.7;margin:20px 0 0;padding:12px 16px;background:#f8fafc;border-radius:8px;border-left:3px solid #0a1628;">\uD83D\uDCCE Le rapport d\u00e9taill\u00e9 est en pi\u00e8ce jointe de cet email (PDF).</p>
                </div>
                <div style="padding:10px 28px;background:#f8fafc;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 12px 12px;font-size:10px;color:#94a3b8;text-align:center;">Rapport g\u00e9n\u00e9r\u00e9 par Neura \u2014 une solution d\u00e9velopp\u00e9e par BOUS'TACOM</div>`;
            preview.style.display = 'block';
        },

        // === SEND EMAIL ===
        async sendAudit(auditId) {
            const emailInput = document.getElementById('audit-email-' + auditId);
            const emailRaw = emailInput?.value.trim();
            if (!emailRaw) { APP.toast('Email requis', 'warning'); return; }

            const templateSel = document.getElementById('audit-template-' + auditId);
            const template = templateSel?.value || 'prospection_froid';

            const btn = document.getElementById('btn-send-' + auditId);
            if (btn) { btn.disabled = true; btn.innerHTML = '<svg viewBox="0 0 24 24" class="spin" style="width:14px;height:14px;"><path d="M12 2v4m0 12v4m10-10h-4M6 12H2"/></svg> Envoi en cours...'; }

            const fd = new FormData();
            fd.append('action', 'send_audit');
            fd.append('audit_id', auditId);
            fd.append('email', emailRaw);
            fd.append('template', template);
            const data = await APP.fetch('/api/prospects.php', { method: 'POST', body: fd });

            if (data.success) {
                APP.toast(data.message || 'Audit envoyé !', 'success');
                // Rafraîchir la vue détail pour afficher "Envoyé le..."
                this.showDétail(auditId);
            } else {
                APP.toast(data.error || 'Erreur d\'envoi', 'error');
                if (btn) { btn.disabled = false; btn.innerHTML = '<svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg> Envoyer'; }
            }
        },

        // === DELETE ===
        async deleteProspect(id) {
            if (!await APP.modal.confirm('Retirer', 'Retirer ce prospect ?', 'Retirer', true)) return;
            const fd = new FormData();
            fd.append('action', 'delete_prospect');
            fd.append('prospect_id', id);
            const data = await APP.fetch('/api/prospects.php', { method: 'POST', body: fd });
            if (data.success) {
                APP.toast('Prospect supprime', 'success');
                if (this._view === 'detail') this.switchView('prospects');
                else this.loadProspects();
            } else APP.toast(data.error || 'Erreur', 'error');
        }
    },

    // ====================================================================
    // MODULE : GESTION DES FICHES GBP
    // ====================================================================
    locations: {
        _data: [],

        async load() {
            const c = document.getElementById('module-content');
            if (c) c.innerHTML = `<div class="sh"><div class="stit">TOUTES LES FICHES</div></div>${APP.skeleton.table(6, 7)}`;
            const data = await APP.fetch('/api/locations.php?action=list&show_all=1');
            if (data.error) return;
            this._data = data.locations || [];
            this.render();
        },

        render() {
            const c = document.getElementById('module-content');
            if (!c) return;

            const locs = this._data;

            // Detecter les doublons (meme google_location_id)
            const seen = {};
            let dupCount = 0;
            for (const loc of locs) {
                const gid = loc.google_location_id || '';
                if (gid && seen[gid]) dupCount++;
                if (gid) seen[gid] = true;
            }

            let h = `<div class="sh" style="justify-content:space-between;">
                <div class="stit">TOUTES LES FICHES (${locs.length})</div>
                <div style="display:flex;gap:8px;">
                    <button class="btn bs bsm" style="color:var(--dng);border-color:rgba(239,68,68,.3);" onclick="APP.locations.resetAllGpsAndResync()">Reinitialiser tous les GPS</button>
                    ${dupCount > 0 ? `<button class="btn bs bsm" style="color:var(--o);border-color:rgba(245,158,11,.3);" onclick="APP.locations.removeDuplicates()"><svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg> Supprimer les doublons (${dupCount})</button>` : ''}
                </div>
            </div>`;

            if (!locs.length) {
                h += `<div style="padding:40px;text-align:center;color:var(--t2);">
                    <p>Aucune fiche Google Business Profile trouvee.</p>
                    <p style="font-size:13px;margin-top:8px;">Cliquez sur "Connecter compte Google" pour importer vos fiches.</p>
                </div>`;
            } else {
                h += `<table><thead><tr>
                    <th>Nom</th>
                    <th>Ville</th>
                    <th>Catégorie</th>
                    <th style="text-align:center">Mots-cles</th>
                    <th style="text-align:center">Avis</th>
                    <th style="text-align:center">Note</th>
                    <th style="text-align:center">Posts</th>
                    <th style="text-align:center">Statut</th>
                    <th style="text-align:center">Actions</th>
                </tr></thead><tbody>`;

                for (const loc of locs) {
                    const rating = loc.avg_rating ? `<span style="color:var(--o);">${loc.avg_rating} &#9733;</span>` : '<span class="kv">—</span>';
                    const statusCls = loc.is_active == 1 ? 'psp' : 'psd';
                    const statusTxt = loc.is_active == 1 ? 'Actif' : 'Inactif';
                    const toggleTxt = loc.is_active == 1 ? 'Desactiver' : 'Activer';
                    const toggleColor = loc.is_active == 1 ? 'color:var(--o);border-color:rgba(245,158,11,.3);' : 'color:var(--g);border-color:rgba(34,197,94,.3);';
                    const unanswered = (loc.unanswered_count || 0) > 0 ? ` <span class="nb or" style="font-size:10px;">${loc.unanswered_count}</span>` : '';

                    h += `<tr style="opacity:${loc.is_active == 1 ? '1' : '.5'};">
                        <td>
                            <div class="kn">${loc.name}</div>
                            ${loc.phone ? '<div class="kv" style="font-size:11px;">' + loc.phone + '</div>' : ''}
                        </td>
                        <td class="kv">${loc.city || '—'}</td>
                        <td class="kv">${loc.category || '—'}</td>
                        <td style="text-align:center"><span class="pb p10">${loc.keyword_count || 0}</span></td>
                        <td style="text-align:center">${loc.review_count || 0}${unanswered}</td>
                        <td style="text-align:center">${rating}</td>
                        <td style="text-align:center" class="kv">${loc.post_count || 0}</td>
                        <td style="text-align:center"><span class="ps ${statusCls}">${statusTxt}</span></td>
                        <td style="text-align:center">
                            <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
                                <button class="btn bs bsm" style="${toggleColor}" onclick="APP.locations.toggle(${loc.id})">${toggleTxt}</button>
                                <button class="btn bs bsm" onclick="APP.locations.syncReviews(${loc.id})" title="Synchroniser les avis">
                                    <svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                </button>
                                <a href="?view=client&location=${loc.id}&tab=keywords" class="btn bs bsm" title="Voir le profil">
                                    <svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                </a>
                                <button class="btn bs bsm" style="color:var(--r);border-color:rgba(239,68,68,.3);" onclick="APP.locations.deleteLocation(${loc.id},'${loc.name.replace(/'/g, "\\'")}')" title="Supprimer cette fiche">
                                    <svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>`;
                }
                h += '</tbody></table>';
            }

            c.innerHTML = h;
        },

        async toggle(locationId) {
            const fd = new FormData();
            fd.append('action', 'toggle');
            fd.append('location_id', locationId);
            const data = await APP.fetch('/api/locations.php', { method: 'POST', body: fd });
            if (data.success) {
                this.load();
            } else {
                APP.toast(data.error || 'Erreur', 'error');
            }
        },

        async confirmSync() {
            if (!await APP.modal.confirm(
                'Importer des fiches Google',
                'Rechercher les fiches disponibles sur votre compte Google Business Profile ?\n\nVous pourrez choisir quelles fiches importer. Les fiches deja presentes seront marquees.',
                'Rechercher les fiches'
            )) return;
            this.syncLocations();
        },

        async confirmConnect() {
            if (!await APP.modal.confirm(
                'Connecter Google',
                'Connecter votre compte Google Business Profile ?\n\nCette action va :\n• Importer toutes vos fiches GBP\n• Synchroniser les avis Google\n• Permettre la publication de posts et réponses\n\nSi vous etes deja connecte, vos fiches seront mises a jour.',
                'Continuer vers Google'
            )) return;
            var appUrl = (document.querySelector('meta[name=app-url]') || {}).content || '';
            window.location.href = appUrl + '/../oauth-callback.php?auth=google';
        },

        async forceRefresh() {
            if (!await APP.modal.confirm(
                'Actualiser les fiches',
                'Mettre a jour les informations de vos ' + (this._data ? this._data.length : '') + ' fiches depuis Google ?\n\nNom, adresse, catégorie, téléphone et coordonnées seront actualises.\nAucune nouvelle fiche ne sera importee.',
                'Actualiser'
            )) return;
            const btn = document.getElementById('btn-refresh-locations');
            if (btn) { btn.disabled = true; btn.innerHTML = '<svg class="spin" viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2"><path d="M12 2v4m0 12v4m-7.07-3.93l2.83-2.83m8.48-8.48l2.83-2.83M2 12h4m12 0h4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83"/></svg> Actualisation...'; }
            const fd = new FormData();
            fd.append('action', 'refresh_locations');
            const data = await APP.fetch('/api/locations.php', { method: 'POST', body: fd });
            if (btn) { btn.disabled = false; btn.innerHTML = '<svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Actualiser les fiches'; }
            if (data.success) {
                APP.toast(data.message || data.refreshed + ' fiche(s) actualisee(s)', 'success');
                this.load();
            } else {
                APP.toast(data.error || 'Erreur lors de l\'actualisation', 'error');
            }
        },

        async syncLocations() {
            const btn = document.getElementById('btn-sync-locations');
            if (btn) { btn.disabled = true; btn.innerHTML = '<svg class="spin" viewBox="0 0 24 24"><path d="M12 2v4m0 12v4m-7.07-3.93l2.83-2.83m8.48-8.48l2.83-2.83M2 12h4m12 0h4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83"/></svg> Chargement...'; }

            const fd = new FormData();
            fd.append('action', 'preview_locations');
            const data = await APP.fetch('/api/locations.php', { method: 'POST', body: fd });

            if (btn) { btn.disabled = false; btn.innerHTML = '<svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4m4-5l5 5 5-5m-5 5V3"/></svg> Importer des fiches'; }

            if (!data.success) {
                APP.toast(data.error || 'Erreur', 'error');
                return;
            }

            if (!data.locations || data.locations.length === 0) {
                APP.toast('Aucune fiche trouvée sur votre compte Google Business.', 'info');
                return;
            }

            // Afficher le panneau de selection
            this.showLocationPicker(data.locations);
        },

        showLocationPicker(googleLocations) {
            const c = document.getElementById('module-content');
            if (!c) return;

            let h = `<div class="sh" style="justify-content:space-between;">
                <div class="stit">SELECTIONNER LES FICHES A IMPORTER (${googleLocations.length} disponibles)</div>
                <div style="display:flex;gap:8px;">
                    <button class="btn bs bsm" onclick="APP.locations.pickerSelectAll(true)">Tout selectionner</button>
                    <button class="btn bs bsm" onclick="APP.locations.pickerSelectAll(false)">Tout deselectionner</button>
                    <button class="btn bs bsm" onclick="APP.locations.load()">Annuler</button>
                </div>
            </div>`;

            h += `<div style="padding:12px 20px;background:rgba(0,212,255,.03);border-bottom:1px solid var(--bdr);font-size:13px;color:var(--t2);">
                Cochez les fiches que vous souhaitez ajouter a votre dashboard. Les fiches deja importees sont marquees.
            </div>`;

            h += `<table><thead><tr>
                <th style="width:40px;text-align:center;"><input type="checkbox" id="picker-check-all" checked onchange="APP.locations.pickerSelectAll(this.checked)"></th>
                <th>Nom</th>
                <th>Ville</th>
                <th>Catégorie</th>
                <th>Téléphone</th>
                <th style="text-align:center">Statut</th>
            </tr></thead><tbody>`;

            for (const loc of googleLocations) {
                const imported = loc.already_imported;
                const badge = imported
                    ? '<span class="ps psp" style="font-size:11px;">Deja importee</span>'
                    : '<span class="ps" style="font-size:11px;background:rgba(0,212,255,.1);color:var(--acc);">Nouvelle</span>';

                h += `<tr style="opacity:${imported ? '.6' : '1'};">
                    <td style="text-align:center;">
                        <input type="checkbox" class="picker-cb" value="${loc.google_location_id}" ${imported ? '' : 'checked'}>
                    </td>
                    <td><div class="kn">${loc.name}</div></td>
                    <td class="kv">${loc.city || '—'}</td>
                    <td class="kv">${loc.category || '—'}</td>
                    <td class="kv">${loc.phone || '—'}</td>
                    <td style="text-align:center">${badge}</td>
                </tr>`;
            }

            h += `</tbody></table>`;

            h += `<div style="padding:16px 20px;border-top:1px solid var(--bdr);display:flex;gap:12px;align-items:center;">
                <button class="btn bp" onclick="APP.locations.importSelected()" id="btn-import-selected">
                    <svg viewBox="0 0 24 24" style="width:16px;height:16px;"><path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    Importer la selection
                </button>
                <span id="picker-count" style="font-size:13px;color:var(--t2);"></span>
            </div>`;

            c.innerHTML = h;
            this.updatePickerCount();

            // Mettre a jour le compteur quand on coche/decoche
            c.querySelectorAll('.picker-cb').forEach(cb => {
                cb.addEventListener('change', () => this.updatePickerCount());
            });
        },

        updatePickerCount() {
            const checked = document.querySelectorAll('.picker-cb:checked').length;
            const total = document.querySelectorAll('.picker-cb').length;
            const el = document.getElementById('picker-count');
            if (el) el.textContent = `${checked} / ${total} fiche(s) selectionnee(s)`;
        },

        pickerSelectAll(checked) {
            document.querySelectorAll('.picker-cb').forEach(cb => { cb.checked = checked; });
            const checkAll = document.getElementById('picker-check-all');
            if (checkAll) checkAll.checked = checked;
            this.updatePickerCount();
        },

        async importSelected() {
            const checkboxes = document.querySelectorAll('.picker-cb:checked');
            const selectedIds = Array.from(checkboxes).map(cb => cb.value);

            if (selectedIds.length === 0) {
                APP.toast('Sélectionnez au moins une fiche', 'warning');
                return;
            }

            const btn = document.getElementById('btn-import-selected');
            if (btn) { btn.disabled = true; btn.innerHTML = '<svg class="spin" viewBox="0 0 24 24"><path d="M12 2v4m0 12v4m-7.07-3.93l2.83-2.83m8.48-8.48l2.83-2.83M2 12h4m12 0h4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83"/></svg> Import en cours...'; }

            const fd = new FormData();
            fd.append('action', 'import_selected');
            for (const id of selectedIds) {
                fd.append('google_location_ids[]', id);
            }

            const data = await APP.fetch('/api/locations.php', { method: 'POST', body: fd });

            if (data.success) {
                APP.toast(data.message || 'Import terminé', 'success');
                this.load();
            } else {
                if (btn) { btn.disabled = false; btn.innerHTML = 'Importer la selection'; }
                APP.toast(data.error || 'Erreur', 'error');
            }
        },

        async syncAllReviews() {
            const btn = document.getElementById('btn-sync-reviews');
            if (btn) { btn.disabled = true; btn.innerHTML = '<svg class="spin" viewBox="0 0 24 24"><path d="M12 2v4m0 12v4m-7.07-3.93l2.83-2.83m8.48-8.48l2.83-2.83M2 12h4m12 0h4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83"/></svg> Synchronisation...'; }

            const fd = new FormData();
            fd.append('action', 'sync_reviews');
            const data = await APP.fetch('/api/locations.php', { method: 'POST', body: fd });

            if (btn) { btn.disabled = false; btn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg> Synchroniser les avis'; }

            if (data.success) {
                APP.toast(data.message || 'Synchronisation terminée', 'success');
                this.load();
            } else {
                APP.toast(data.error || 'Erreur de synchronisation', 'error');
            }
        },

        async syncReviews(locationId) {
            const fd = new FormData();
            fd.append('action', 'sync_reviews');
            fd.append('location_id', locationId);
            const data = await APP.fetch('/api/locations.php', { method: 'POST', body: fd });
            if (data.success) {
                APP.toast(data.message || 'Avis synchronisés', 'success');
                this.load();
            } else {
                APP.toast(data.error || 'Erreur', 'error');
            }
        },

        async deleteLocation(locationId, locationName) {
            if (!await APP.modal.confirm('Supprimer', `Supprimer la fiche "${locationName}" et toutes ses données (mots-clés, avis, posts) ?\n\nCette action est irréversible.`, 'Supprimer', true)) return;

            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('location_id', locationId);
            const data = await APP.fetch('/api/locations.php', { method: 'POST', body: fd });
            if (data.success) {
                APP.toast(data.message || 'Fiche supprimée', 'success');
                this.load();
            } else {
                APP.toast(data.error || 'Erreur', 'error');
            }
        },

        async removeDuplicates() {
            if (!await APP.modal.confirm('Supprimer les doublons', 'Supprimer automatiquement les fiches en double ?\n\nPour chaque doublon, la fiche la plus ancienne (avec le moins de données) sera supprimée.', 'Supprimer les doublons', true)) return;

            const fd = new FormData();
            fd.append('action', 'remove_duplicates');
            const data = await APP.fetch('/api/locations.php', { method: 'POST', body: fd });
            if (data.success) {
                APP.toast(data.message || 'Doublons supprimés', 'success');
                this.load();
            } else {
                APP.toast(data.error || 'Erreur', 'error');
            }
        },

        // ====== SELECTION EN MASSE ======
        toggleSelectAll(checked) {
            document.querySelectorAll('.loc-cb').forEach(function(cb) {
                cb.checked = checked;
                const id = parseInt(cb.dataset.id);
                if (checked) APP.locations._selected.add(id);
                else APP.locations._selected.delete(id);
            });
            APP.locations._updateBulkBar();
        },

        toggleSelect(id, checked) {
            if (checked) this._selected.add(id);
            else this._selected.delete(id);
            // Mettre a jour "select all"
            const all = document.querySelectorAll('.loc-cb');
            const selAll = document.getElementById('loc-select-all');
            if (selAll) selAll.checked = (all.length > 0 && this._selected.size === all.length);
            this._updateBulkBar();
        },

        clearSelection() {
            this._selected.clear();
            document.querySelectorAll('.loc-cb').forEach(function(cb) { cb.checked = false; });
            const selAll = document.getElementById('loc-select-all');
            if (selAll) selAll.checked = false;
            this._updateBulkBar();
        },

        _updateBulkBar() {
            const bar = document.getElementById('loc-bulk-bar');
            const count = document.getElementById('loc-bulk-count');
            if (!bar) return;
            const n = this._selected.size;
            if (n > 0) {
                bar.style.display = 'flex';
                count.textContent = n + ' fiche' + (n > 1 ? 's' : '') + ' selectionnee' + (n > 1 ? 's' : '');
            } else {
                bar.style.display = 'none';
            }
        },

        async bulkDeactivate() {
            const n = this._selected.size;
            if (!n) return;
            if (!await APP.modal.confirm('Desactiver', 'Desactiver ' + n + ' fiche' + (n > 1 ? 's' : '') + ' ?\n\nElles ne seront plus suivies par les scans mais resteront en base.', 'Desactiver', false)) return;

            const fd = new FormData();
            fd.append('action', 'bulk_deactivate');
            fd.append('location_ids', Array.from(this._selected).join(','));
            const data = await APP.fetch('/api/locations.php', { method: 'POST', body: fd });
            if (data.success) {
                APP.toast(data.message, 'success');
                this._selected.clear();
                this.load();
            } else {
                APP.toast(data.error || 'Erreur', 'error');
            }
        },

        async bulkDelete() {
            const n = this._selected.size;
            if (!n) return;
            if (!await APP.modal.confirm('Supprimer', 'Supprimer définitivement ' + n + ' fiche' + (n > 1 ? 's' : '') + ' et toutes leurs données (mots-cles, avis, scans, posts) ?\n\nCette action est IRREVERSIBLE.', 'Supprimer', true)) return;

            const fd = new FormData();
            fd.append('action', 'bulk_delete');
            fd.append('location_ids', Array.from(this._selected).join(','));
            const data = await APP.fetch('/api/locations.php', { method: 'POST', body: fd });
            if (data.success) {
                APP.toast(data.message, 'success');
                this._selected.clear();
                this.load();
            } else {
                APP.toast(data.error || 'Erreur', 'error');
            }
        },

        async cleanupTestData() {
            if (!await APP.modal.confirm('Nettoyage', 'Supprimer toutes les données de test ?\n\n- Avis manuels (sans ID Google)\n- Posts non publiés sur Google\n\nCette action est irréversible.', 'Nettoyer', true)) return;

            const fd = new FormData();
            fd.append('action', 'cleanup_test_data');
            const data = await APP.fetch('/api/locations.php', { method: 'POST', body: fd });

            if (data.success) {
                APP.toast(data.message || 'Nettoyage effectué', 'success');
                this.load();
            } else {
                APP.toast(data.error || 'Erreur', 'error');
            }
        },

        async resetAllGpsAndResync() {
            if (!await APP.modal.confirm('Réinitialiser GPS', 'RÉINITIALISER TOUTES les coordonnées GPS de toutes vos fiches ?\n\nCette action va :\n1. Supprimer toutes les coordonnées GPS en base\n2. Re-synchroniser depuis Google\n3. Géocoder les adresses postales\n4. Seules les fiches SAB resteront sans GPS\n\nContinuer ?', 'Réinitialiser', true)) return;

            const fd = new FormData();
            fd.append('action', 'reset_all_gps_and_resync');
            const data = await APP.fetch('/api/locations.php', { method: 'POST', body: fd });

            if (data.success) {
                APP.toast(data.message, 'success');
                this.load();
            } else {
                APP.toast(data.error || 'Erreur', 'error');
            }
        }
    },

    // ====================================================================
    // MODULE : PARAMETRES PAR FICHE (onglet client)
    // ====================================================================
    clientSettings: {
        _locationId: null,
        _location: null,
        _settings: null,
        // Places API association
        _placesAcTimer: null,
        _placesAcAbort: null,
        _selectedPlace: null,

        async load(locationId) {
            this._locationId = locationId;
            this._selectedPlace = null;
            const data = await APP.fetch(`/api/reviews.php?action=get_location_settings&location_id=${locationId}`);
            if (!data.success) { console.error('clientSettings error:', data.error); return; }
            this._location = data.location;
            this._settings = data.settings;
            this.render();
        },

        esc(s) { if(!s)return''; const d=document.createElement('div');d.textContent=s;return d.innerHTML; },

        render() {
            const c = document.getElementById('module-content');
            if (!c) return;
            const loc = this._location;
            const s = this._settings;
            const lid = this._locationId;

            let h = `<div class="sh"><div class="stit">PARAMÈTRES</div></div>`;

            // ===== SECTION 0 : ASSOCIATION FICHE GOOGLE (Places API) =====
            const isLinked = loc.places_api_linked == 1;
            const hasPlaceId = !!loc.place_id;
            const linkedAt = loc.places_api_linked_at ? new Date(loc.places_api_linked_at).toLocaleDateString('fr-FR') : '';

            h += `<div style="border-bottom:1px solid var(--bdr);">
                <div style="padding:14px 20px;display:flex;justify-content:space-between;align-items:center;cursor:pointer;background:rgba(255,255,255,.03);transition:background .15s;" onmouseenter="this.style.background='rgba(255,255,255,.06)'" onmouseleave="this.style.background='rgba(255,255,255,.03)'" onclick="const b=this.parentElement.querySelector('.cs-body');const o=b.style.display!=='none';b.style.display=o?'none':'block';this.querySelector('.cs-chev').style.transform=o?'rotate(-90deg)':''">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <svg class="cs-chev" viewBox="0 0 24 24" style="width:16px;height:16px;stroke:var(--t3);fill:none;stroke-width:2;transition:transform .2s;flex-shrink:0;${isLinked || hasPlaceId ? 'transform:rotate(-90deg)' : ''}"><polyline points="6 9 12 15 18 9"/></svg>
                        <span style="font-weight:600;font-size:14px;">Association fiche Google</span>
                        ${isLinked || hasPlaceId
                            ? '<span style="font-size:11px;padding:2px 8px;border-radius:4px;background:rgba(34,197,94,.15);color:#22c55e;">✓ Associée automatiquement</span>'
                            : '<span style="font-size:11px;padding:2px 8px;border-radius:4px;background:rgba(234,179,8,.15);color:#eab308;">Non associée — aucun Place ID</span>'}
                    </div>
                    <span style="color:var(--t3);font-size:12px;">Google Places API</span>
                </div>
                <div class="cs-body" style="padding:0 20px 20px;${isLinked || hasPlaceId ? 'display:none' : ''}">`;

            // Cas 1 : place_id existe → association automatique, juste afficher le résumé
            if (hasPlaceId) {
                h += `<div style="padding:14px 16px;background:rgba(0,255,209,.06);border:1px solid rgba(0,255,209,.2);border-radius:10px;margin-bottom:14px;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                        <div>
                            <div style="font-size:14px;font-weight:600;color:var(--t1);margin-bottom:4px;">${this.esc(loc.name)}</div>
                            <div style="font-size:12px;color:var(--t3);margin-bottom:2px;">${this.esc(loc.category || '')}</div>
                            <div style="font-size:12px;color:var(--t3);">${this.esc(loc.address || '')}</div>
                            <div style="font-size:11px;color:var(--t3);margin-top:6px;">Place ID : <code style="font-size:10px;background:var(--overlay);padding:2px 6px;border-radius:4px;">${this.esc(loc.place_id)}</code></div>
                            <div style="font-size:11px;color:var(--acc);margin-top:4px;">L'association est automatique via la synchronisation Google Business Profile.</div>
                        </div>
                    </div>
                </div>`;
            } else {
                // Cas 2 : pas de place_id → afficher la recherche manuelle (cas rare)
                h += `<div style="padding:10px 14px;background:rgba(234,179,8,.08);border:1px solid rgba(234,179,8,.2);border-radius:10px;margin-bottom:14px;">
                    <div style="font-size:12px;color:#eab308;">Aucun Place ID détecté depuis Google Business Profile. Vous pouvez associer manuellement la fiche ci-dessous.</div>
                </div>`;

                // Autocomplete recherche
                h += `<div style="margin-bottom:12px;">
                    <label style="font-size:12px;font-weight:600;color:var(--t2);display:block;margin-bottom:6px;">Rechercher la fiche Google</label>
                    <div style="position:relative;">
                        <input id="cs-places-search" class="si" autocomplete="off"
                            placeholder="Tapez le nom de l'établissement (ex: Restaurant Le Bistrot, Brive)..."
                            oninput="APP.clientSettings._placesAutocomplete(this.value)"
                            onblur="setTimeout(()=>{const el=document.getElementById('cs-places-ac-list');if(el)el.style.display='none'},200)">
                        <div id="cs-places-ac-list" class="ac-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:100;max-height:300px;overflow-y:auto;background:var(--card);border:1px solid var(--bdr);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.3);"></div>
                    </div>
                    <div id="cs-places-chip"></div>
                </div>`;

                // Bouton confirmer
                h += `<div id="cs-places-confirm" style="display:none;margin-bottom:14px;">
                    <button class="btn ba" style="width:100%;padding:10px;font-size:13px;font-weight:600;" onclick="APP.clientSettings.confirmPlaceAssociation(${lid})">
                        <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;vertical-align:-3px;margin-right:6px;"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        Confirmer l'association
                    </button>
                </div>`;

                // Fallbacks
                h += `<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:6px;">
                    <div>
                        <label style="font-size:11px;color:var(--t3);display:block;margin-bottom:4px;">Place ID manuel</label>
                        <div style="display:flex;gap:6px;">
                            <input id="cs-manual-placeid" class="si" style="font-size:12px;flex:1;" placeholder="ChIJ...">
                            <button class="btn bp" style="font-size:11px;padding:6px 10px;white-space:nowrap;" onclick="APP.clientSettings.linkFromPlaceId(${lid})">Vérifier</button>
                        </div>
                    </div>
                    <div>
                        <label style="font-size:11px;color:var(--t3);display:block;margin-bottom:4px;">URL Google Maps</label>
                        <div style="display:flex;gap:6px;">
                            <input id="cs-gmaps-url" class="si" style="font-size:12px;flex:1;" placeholder="https://maps.google.com/...">
                            <button class="btn bp" style="font-size:11px;padding:6px 10px;white-space:nowrap;" onclick="APP.clientSettings.linkFromUrl(${lid})">Extraire</button>
                        </div>
                    </div>
                </div>`;
            }

            h += `</div></div>`;

            // ===== SECTION 1 : INFORMATIONS DE LA FICHE =====
            h += `<div style="border-bottom:1px solid var(--bdr);">
                <div style="padding:14px 20px;display:flex;justify-content:space-between;align-items:center;cursor:pointer;background:rgba(255,255,255,.03);transition:background .15s;" onmouseenter="this.style.background='rgba(255,255,255,.06)'" onmouseleave="this.style.background='rgba(255,255,255,.03)'" onclick="const b=this.parentElement.querySelector('.cs-body');const o=b.style.display!=='none';b.style.display=o?'none':'block';this.querySelector('.cs-chev').style.transform=o?'rotate(-90deg)':''">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <svg class="cs-chev" viewBox="0 0 24 24" style="width:16px;height:16px;stroke:var(--t3);fill:none;stroke-width:2;transition:transform .2s;flex-shrink:0;"><polyline points="6 9 12 15 18 9"/></svg>
                        <span style="font-weight:600;font-size:14px;">Informations de la fiche</span>
                    </div>
                    <span style="color:var(--t3);font-size:12px;">Données Google Business Profile</span>
                </div>
                <div class="cs-body" style="padding:0 20px 20px;">`;

            const managedDate = loc.created_at ? new Date(loc.created_at).toLocaleDateString('fr-FR') : null;
            const infoFields = [
                ['Nom', loc.name],
                ['Adresse', loc.address],
                ['Ville', loc.city],
                ['Code postal', loc.postal_code],
                ['Téléphone', loc.phone],
                ['Site internet', loc.website],
                ['Catégorie', loc.category],
                ['Place ID', loc.place_id],
                ['Suivi depuis', managedDate],
            ];

            h += `<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">`;
            for (const [label, value] of infoFields) {
                const displayVal = value ? this.esc(String(value)) : '<span style="color:var(--t3);font-style:italic;">Non renseigne</span>';
                let extra = '';
                if (label === 'Site internet' && value) {
                    extra = ` <a href="${this.esc(value)}" target="_blank" style="color:var(--acc);font-size:11px;margin-left:6px;">Ouvrir</a>`;
                }
                if (label === 'Téléphone' && value) {
                    extra = ` <a href="tel:${this.esc(value)}" style="color:var(--acc);font-size:11px;margin-left:6px;">Appeler</a>`;
                }
                h += `<div style="padding:10px 12px;background:var(--card);border-radius:8px;border:1px solid var(--bdr);">
                    <div style="font-size:11px;color:var(--t3);margin-bottom:4px;">${label}</div>
                    <div style="font-size:13px;color:var(--t1);word-break:break-all;">${displayVal}${extra}</div>
                </div>`;
            }
            h += `</div>`;

            // ===== BLOC GPS EDITABLE =====
            const hasGps = loc.latitude && loc.longitude;
            const gpsDisplay = hasGps ? `${loc.latitude}, ${loc.longitude}` : '';
            h += `<div style="margin-top:14px;padding:14px 16px;background:var(--card);border-radius:10px;border:1px solid ${hasGps ? 'var(--suc)' : 'var(--wrn)'};border-left:4px solid ${hasGps ? 'var(--suc)' : 'var(--wrn)'};">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                    <div style="font-size:12px;font-weight:600;color:var(--t1);">Coordonnées GPS</div>
                    <span style="font-size:11px;padding:2px 8px;border-radius:4px;background:${hasGps ? 'rgba(34,197,94,.15);color:#22c55e' : 'rgba(234,179,8,.15);color:#eab308'};">${hasGps ? 'OK' : 'Manquant'}</span>
                </div>`;

            if (hasGps) {
                h += `<div style="font-size:14px;color:var(--t1);font-weight:500;margin-bottom:8px;">${gpsDisplay}</div>`;
            }

            // Input adresse pour geocoder
            h += `<div style="display:flex;gap:8px;margin-bottom:8px;">
                <input type="text" id="cs-gps-address" class="si" placeholder="Tapez une adresse pour geocoder (ex: 12 rue du Parc, Le Chastang 19190)" style="flex:1;" value="${this.esc(loc.address ? (loc.address + (loc.city ? ', ' + loc.city : '') + (loc.postal_code ? ' ' + loc.postal_code : '')) : '')}">
                <button class="btn bp bsm" onclick="APP.clientSettings.geocodeAddress(${lid})" style="white-space:nowrap;">Géocoder</button>
            </div>`;

            // Input lat/lng direct
            h += `<div style="display:flex;gap:8px;margin-bottom:8px;">
                <input type="text" id="cs-gps-lat" class="si" placeholder="Latitude (ex: 45.1234)" style="width:140px;" value="${loc.latitude || ''}">
                <input type="text" id="cs-gps-lng" class="si" placeholder="Longitude (ex: 1.5678)" style="width:140px;" value="${loc.longitude || ''}">
                <button class="btn bp bsm" onclick="APP.clientSettings.saveGps(${lid})" style="white-space:nowrap;">Enregistrer GPS</button>
            </div>`;

            // Boutons actions
            h += `<div style="display:flex;gap:8px;flex-wrap:wrap;">`;
            if (loc.place_id) {
                h += `<a href="https://www.google.com/maps/place/?q=place_id:${this.esc(loc.place_id)}" target="_blank" class="btn bs bsm" style="font-size:11px;">Voir sur Google Maps</a>`;
            }
            if (hasGps) {
                h += `<a href="https://www.google.com/maps?q=${loc.latitude},${loc.longitude}" target="_blank" class="btn bs bsm" style="font-size:11px;">Verifier sur la carte</a>`;
                h += `<button class="btn bs bsm" style="font-size:11px;color:var(--dng);" onclick="APP.clientSettings.resetGps(${lid})">Reinitialiser</button>`;
            }
            h += `</div>`;
            h += `<div style="font-size:10px;color:var(--t3);margin-top:6px;">Tapez l'adresse du client puis cliquez "Géocoder", ou saisissez directement les coordonnées GPS.</div>`;
            h += `</div>`;
            h += `<div style="font-size:11px;color:var(--t3);margin-top:10px;">Ces informations sont synchronisées automatiquement depuis Google Business Profile.</div>`;
            h += `</div></div>`;

            // ===== SECTION 1b : GRILLE DE POSITION (sunburst 49 pts fixe) =====
            h += `<div style="border-bottom:1px solid var(--bdr);">
                <div style="padding:14px 20px;display:flex;justify-content:space-between;align-items:center;cursor:pointer;background:rgba(255,255,255,.03);transition:background .15s;" onmouseenter="this.style.background='rgba(255,255,255,.06)'" onmouseleave="this.style.background='rgba(255,255,255,.03)'" onclick="const b=this.parentElement.querySelector('.cs-body');const o=b.style.display!=='none';b.style.display=o?'none':'block';this.querySelector('.cs-chev').style.transform=o?'rotate(-90deg)':''">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <svg class="cs-chev" viewBox="0 0 24 24" style="width:16px;height:16px;stroke:var(--t3);fill:none;stroke-width:2;transition:transform .2s;flex-shrink:0;transform:rotate(-90deg)"><polyline points="6 9 12 15 18 9"/></svg>
                        <span style="font-weight:600;font-size:14px;">Zone de scan des positions</span>
                    </div>
                    <span style="color:var(--t3);font-size:12px;">49 points · 15 km</span>
                </div>
                <div class="cs-body" style="padding:0 20px 20px;display:none;">
                    <div style="font-size:12px;color:var(--t2);margin-bottom:12px;">Grille 7×7 alignee sur les axes cardinaux, couvrant uniformement toute la zone autour de votre fiche.</div>
                    <div style="display:flex;gap:16px;margin-bottom:8px;">
                        <div style="flex:1;background:var(--subtle-bg);border-radius:8px;padding:10px 14px;text-align:center;">
                            <div style="font-size:20px;font-weight:700;color:var(--acc);">49</div>
                            <div style="font-size:10px;color:var(--t3);text-transform:uppercase;">Points GPS</div>
                        </div>
                        <div style="flex:1;background:var(--subtle-bg);border-radius:8px;padding:10px 14px;text-align:center;">
                            <div style="font-size:20px;font-weight:700;color:var(--t1);">15 km</div>
                            <div style="font-size:10px;color:var(--t3);text-transform:uppercase;">Rayon max</div>
                        </div>
                        <div style="flex:1;background:var(--subtle-bg);border-radius:8px;padding:10px 14px;text-align:center;">
                            <div style="font-size:20px;font-weight:700;color:var(--suc);">5 km</div>
                            <div style="font-size:10px;color:var(--t3);text-transform:uppercase;">Espacement</div>
                        </div>
                    </div>
                    <div style="font-size:10px;color:var(--t3);">Grille 7×7 · axes cardinaux · jitter ±60m</div>
                </div>
            </div>`;

            // ===== SECTION 1c : LOGO DU CLIENT =====
            h += `<div style="border-bottom:1px solid var(--bdr);">
                <div style="padding:14px 20px;display:flex;justify-content:space-between;align-items:center;cursor:pointer;background:rgba(255,255,255,.03);transition:background .15s;" onmouseenter="this.style.background='rgba(255,255,255,.06)'" onmouseleave="this.style.background='rgba(255,255,255,.03)'" onclick="const b=this.parentElement.querySelector('.cs-body');const o=b.style.display!=='none';b.style.display=o?'none':'block';this.querySelector('.cs-chev').style.transform=o?'rotate(-90deg)':''">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <svg class="cs-chev" viewBox="0 0 24 24" style="width:16px;height:16px;stroke:var(--t3);fill:none;stroke-width:2;transition:transform .2s;flex-shrink:0;transform:rotate(-90deg)"><polyline points="6 9 12 15 18 9"/></svg>
                        <span style="font-weight:600;font-size:14px;">Logo du client</span>
                    </div>
                    <span id="cs-logo-status" style="color:var(--t3);font-size:12px;">${loc.logo_path ? 'Configuré' : 'Non configuré'}</span>
                </div>
                <div class="cs-body" style="padding:0 20px 20px;display:none;">
                    <div style="font-size:12px;color:var(--t2);margin-bottom:12px;">Le logo sera automatiquement intégré à tous les visuels Google Posts générés pour ce client.</div>
                    <div id="cs-logo-zone" style="display:flex;align-items:center;gap:16px;">
                        ${loc.logo_path
                            ? `<img src="${APP.url}/media/logos/${this.esc(loc.logo_path)}" style="width:80px;height:80px;object-fit:contain;border-radius:10px;background:var(--bg);border:1px solid var(--bdr);padding:6px;" id="cs-logo-thumb">
                               <div>
                                   <p style="font-size:12px;color:var(--g);margin:0 0 6px;font-weight:500;">✓ Logo actif</p>
                                   <div style="display:flex;gap:8px;">
                                       <label class="btn bs bsm" style="font-size:11px;cursor:pointer;">
                                           Changer
                                           <input type="file" accept="image/png,image/jpeg,image/webp" style="display:none" onchange="APP.clientSettings.uploadLogo(this.files[0])">
                                       </label>
                                       <button class="btn bs bsm" onclick="APP.clientSettings.deleteLogo()" style="font-size:11px;color:var(--r);">Supprimer</button>
                                   </div>
                               </div>`
                            : `<div style="width:80px;height:80px;border-radius:10px;background:var(--bg);border:2px dashed var(--bdr);display:flex;align-items:center;justify-content:center;">
                                   <svg viewBox="0 0 24 24" style="width:24px;height:24px;stroke:var(--t3);fill:none;stroke-width:1.5;opacity:0.5;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                               </div>
                               <div>
                                   <p style="font-size:12px;color:var(--t3);margin:0 0 6px;">Aucun logo configuré</p>
                                   <label class="btn bp bsm" style="font-size:11px;cursor:pointer;">
                                       <svg viewBox="0 0 24 24" style="width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                       Uploader un logo
                                       <input type="file" accept="image/png,image/jpeg,image/webp" style="display:none" onchange="APP.clientSettings.uploadLogo(this.files[0])">
                                   </label>
                                   <p style="font-size:10px;color:var(--t3);margin:6px 0 0;">PNG, JPG ou WebP — max 2 Mo</p>
                               </div>`
                        }
                    </div>
                </div>
            </div>`;

            // ===== SECTION 1d : SIGNATURE VISUELS =====
            const sigEnabled = loc.signature_enabled !== undefined ? loc.signature_enabled : 1;
            const sigText = loc.signature_text || '';
            const defaultSig = "Gérée par Neura · BOUS'TACOM — Expert Google Business · boustacom.fr";

            h += `<div style="border-bottom:1px solid var(--bdr);">
                <div style="padding:14px 20px;display:flex;justify-content:space-between;align-items:center;cursor:pointer;background:rgba(255,255,255,.03);transition:background .15s;" onmouseenter="this.style.background='rgba(255,255,255,.06)'" onmouseleave="this.style.background='rgba(255,255,255,.03)'" onclick="const b=this.parentElement.querySelector('.cs-body');const o=b.style.display!=='none';b.style.display=o?'none':'block';this.querySelector('.cs-chev').style.transform=o?'rotate(-90deg)':''">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <svg class="cs-chev" viewBox="0 0 24 24" style="width:16px;height:16px;stroke:var(--t3);fill:none;stroke-width:2;transition:transform .2s;flex-shrink:0;transform:rotate(-90deg)"><polyline points="6 9 12 15 18 9"/></svg>
                        <span style="font-weight:600;font-size:14px;">Signature sur les visuels</span>
                    </div>
                    <span style="color:var(--t3);font-size:12px;">${sigEnabled ? 'Activée' : 'Désactivée'}</span>
                </div>
                <div class="cs-body" style="padding:0 20px 20px;display:none;">
                    <div style="font-size:12px;color:var(--t2);margin-bottom:12px;">Une bande discrète en bas de chaque visuel généré, pour montrer que la fiche est gérée professionnellement.</div>

                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--t1);">
                            <input type="checkbox" id="cs-sig-enabled" ${sigEnabled ? 'checked' : ''} style="accent-color:var(--acc);width:18px;height:18px;">
                            Afficher la signature
                        </label>
                    </div>

                    <div>
                        <label style="display:block;font-size:12px;color:var(--t2);margin-bottom:4px;">Texte de la signature</label>
                        <input type="text" id="cs-sig-text" class="si" placeholder="${this.esc(defaultSig)}" style="width:100%;font-size:12px;" value="${this.esc(sigText)}"
                            oninput="document.getElementById('cs-sig-preview').textContent=this.value||this.placeholder">
                        <div style="font-size:10px;color:var(--t3);margin-top:4px;">Laissez vide pour le texte par défaut. Utilisez · ou — pour séparer les éléments.</div>
                    </div>

                    <div style="margin-top:12px;padding:10px 16px;background:var(--bg);border-radius:8px;border:1px solid var(--bdr);position:relative;overflow:hidden;">
                        <div style="font-size:10px;color:var(--t3);margin-bottom:4px;">Aperçu :</div>
                        <div style="background:rgba(0,0,0,0.55);border-radius:4px;padding:6px 12px;text-align:center;">
                            <span id="cs-sig-preview" style="font-size:9px;color:rgba(255,255,255,0.75);font-family:'Inter',sans-serif;">${this.esc(sigText || defaultSig)}</span>
                        </div>
                    </div>

                    <button class="btn bp bsm" onclick="APP.clientSettings.saveSignature(${lid})" style="margin-top:12px;">Enregistrer la signature</button>
                </div>
            </div>`;

            // ===== SECTION 2 : PROFIL IA — REPONSES AUX AVIS =====
            const tone = s.default_tone || 'professional';
            const gender = s.gender || 'neutral';
            const speech = s.review_speech || 'vous';

            h += `<div style="border-bottom:1px solid var(--bdr);">
                <div style="padding:14px 20px;display:flex;justify-content:space-between;align-items:center;cursor:pointer;background:rgba(255,255,255,.03);transition:background .15s;" onmouseenter="this.style.background='rgba(255,255,255,.06)'" onmouseleave="this.style.background='rgba(255,255,255,.03)'" onclick="const b=this.parentElement.querySelector('.cs-body');const o=b.style.display!=='none';b.style.display=o?'none':'block';this.querySelector('.cs-chev').style.transform=o?'rotate(-90deg)':''">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <svg class="cs-chev" viewBox="0 0 24 24" style="width:16px;height:16px;stroke:var(--t3);fill:none;stroke-width:2;transition:transform .2s;flex-shrink:0;transform:rotate(-90deg)"><polyline points="6 9 12 15 18 9"/></svg>
                        <span style="font-weight:600;font-size:14px;">Profil IA — Réponses aux avis</span>
                    </div>
                    <span style="color:var(--t3);font-size:12px;">Personnaliser le comportement de l'IA</span>
                </div>
                <div class="cs-body" style="padding:0 20px 20px;display:none;">
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px;">
                        <div><label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Ton</label><select id="cs-tone" class="si" style="width:100%;"><option value="professional"${tone==='professional'?' selected':''}>Professionnel</option><option value="friendly"${tone==='friendly'?' selected':''}>Amical</option><option value="empathetic"${tone==='empathetic'?' selected':''}>Empathique</option></select></div>
                        <div><label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Je parle en tant que</label><select id="cs-gender" class="si" style="width:100%;"><option value="male"${gender==='male'?' selected':''}>Homme</option><option value="female"${gender==='female'?' selected':''}>Femme</option><option value="neutral"${gender==='neutral'?' selected':''}>Neutre / Entreprise</option></select></div>
                        <div><label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Forme d'adresse</label><select id="cs-speech" class="si" style="width:100%;"><option value="vous"${speech==='vous'?' selected':''}>Vouvoiement (vous)</option><option value="tu"${speech==='tu'?' selected':''}>Tutoiement (tu)</option></select></div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px;">
                        <div><label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Formule d'introduction</label><input type="text" id="cs-review-intro" class="si" placeholder="Bonjour {prenom}," style="width:100%;" value="${this.esc(s.review_intro || '')}"></div>
                        <div><label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Formule de conclusion</label><input type="text" id="cs-review-closing" class="si" placeholder="A bientot," style="width:100%;" value="${this.esc(s.review_closing || '')}"></div>
                        <div><label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Signature</label><input type="text" id="cs-review-signature" class="si" placeholder="${this.esc(loc.name || 'Nom de la fiche')}" style="width:100%;" value="${this.esc(s.review_signature || '')}"></div>
                    </div>
                    <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Instructions personnalisees pour l'IA</label>
                    <textarea id="cs-instructions" class="si" placeholder="Ex: Toujours mentionner notre engagement qualite..." style="width:100%;height:80px;resize:vertical;">${this.esc(s.custom_instructions || '')}</textarea>
                    <div style="font-size:11px;color:var(--t3);margin-top:6px;margin-bottom:12px;">Format des réponses : "{intro}" → réponse → "{closing}" + retour a la ligne + "{signature}"</div>
                    <button class="btn bp bsm" onclick="APP.clientSettings.saveReviewProfile(${lid})">Enregistrer le profil Avis</button>
                </div>
            </div>`;

            // ===== SECTION 3 : PROFIL IA — GOOGLE POSTS =====
            const ptone = s.posts_tone || '';
            const pgender = s.posts_gender || '';
            const pspeech = s.posts_speech || '';

            h += `<div style="border-bottom:1px solid var(--bdr);">
                <div style="padding:14px 20px;display:flex;justify-content:space-between;align-items:center;cursor:pointer;background:rgba(255,255,255,.03);transition:background .15s;" onmouseenter="this.style.background='rgba(255,255,255,.06)'" onmouseleave="this.style.background='rgba(255,255,255,.03)'" onclick="const b=this.parentElement.querySelector('.cs-body');const o=b.style.display!=='none';b.style.display=o?'none':'block';this.querySelector('.cs-chev').style.transform=o?'rotate(-90deg)':''">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <svg class="cs-chev" viewBox="0 0 24 24" style="width:16px;height:16px;stroke:var(--t3);fill:none;stroke-width:2;transition:transform .2s;flex-shrink:0;transform:rotate(-90deg)"><polyline points="6 9 12 15 18 9"/></svg>
                        <span style="font-weight:600;font-size:14px;">Profil IA — Google Posts</span>
                    </div>
                    <span style="color:var(--t3);font-size:12px;">Style article, SEO-optimisé</span>
                </div>
                <div class="cs-body" style="padding:0 20px 20px;display:none;">
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px;margin-bottom:12px;">
                        <div><label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Ton</label><select id="cs-posts-tone" class="si" style="width:100%;"><option value="professional"${ptone==='professional'||ptone===''?' selected':''}>Professionnel</option><option value="friendly"${ptone==='friendly'?' selected':''}>Amical</option><option value="empathetic"${ptone==='empathetic'?' selected':''}>Empathique</option></select></div>
                        <div><label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Je parle en tant que</label><select id="cs-posts-gender" class="si" style="width:100%;"><option value="male"${pgender==='male'?' selected':''}>Homme</option><option value="female"${pgender==='female'?' selected':''}>Femme</option><option value="neutral"${pgender==='neutral'||pgender===''?' selected':''}>Neutre / Entreprise</option></select></div>
                        <div><label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Forme d'adresse</label><select id="cs-posts-speech" class="si" style="width:100%;"><option value="vous"${pspeech==='vous'||pspeech===''?' selected':''}>Vouvoiement (vous)</option><option value="tu"${pspeech==='tu'?' selected':''}>Tutoiement (tu)</option></select></div>
                        <div><label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Signature</label><input type="text" id="cs-posts-signature" class="si" placeholder="Nom de l'entreprise" style="width:100%;" value="${this.esc(s.posts_signature || '')}"></div>
                    </div>
                    <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Instructions personnalisees pour l'IA</label>
                    <textarea id="cs-posts-instructions" class="si" placeholder="Ex: Redige de vrais articles SEO, commence par un titre accrocheur..." style="width:100%;height:100px;resize:vertical;">${this.esc(s.posts_instructions || '')}</textarea>
                    <div style="font-size:11px;color:var(--t3);margin-top:6px;margin-bottom:16px;">Ces instructions sont utilisees pour chaque generation de post.</div>

                    <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">📋 Contexte metier de l'entreprise</label>
                    <textarea id="cs-business-context" class="si" placeholder="Collez ici toutes les infos de l'entreprise : services, tarifs, specialites, zone d'intervention, annees d'experience, certifications, types de clients...&#10;&#10;L'IA utilisera UNIQUEMENT ces informations pour générer des posts precis et realistes." style="width:100%;height:180px;resize:vertical;font-size:12px;line-height:1.5;">${this.esc(s.business_context || '')}</textarea>
                    <div style="font-size:11px;color:var(--t3);margin-top:6px;margin-bottom:6px;">Informations reelles de l'entreprise injectees dans chaque prompt IA. L'IA ne pourra PAS inventer de tarifs ou services non listes ici.</div>
                    <div style="margin-bottom:12px;"><button class="btn bsm bgh" onclick="APP.clientSettings.importBusinessContext(${lid})" style="font-size:11px;">📄 Importer depuis un CSV</button></div>
                    <button class="btn bp bsm" onclick="APP.clientSettings.savePostsProfile(${lid})">Enregistrer le profil Posts</button>
                </div>
            </div>`;

            // ===== SECTION 4 : RAPPORT CLIENT =====
            const rptEmail = loc.report_email || '';
            const rptContact = loc.report_contact_name || '';
            h += `<div style="border-bottom:1px solid var(--bdr);">
                <div style="padding:14px 20px;display:flex;justify-content:space-between;align-items:center;cursor:pointer;background:rgba(255,255,255,.03);transition:background .15s;" onmouseenter="this.style.background='rgba(255,255,255,.06)'" onmouseleave="this.style.background='rgba(255,255,255,.03)'" onclick="const b=this.parentElement.querySelector('.cs-body');const o=b.style.display!=='none';b.style.display=o?'none':'block';this.querySelector('.cs-chev').style.transform=o?'rotate(-90deg)':''">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <svg class="cs-chev" viewBox="0 0 24 24" style="width:16px;height:16px;stroke:var(--t3);fill:none;stroke-width:2;transition:transform .2s;flex-shrink:0;transform:rotate(-90deg)"><polyline points="6 9 12 15 18 9"/></svg>
                        <span style="font-weight:600;font-size:14px;">Rapport client</span>
                    </div>
                    <span style="color:var(--t3);font-size:12px;">${rptEmail ? 'Configuré' : 'Non configuré'}</span>
                </div>
                <div class="cs-body" style="padding:0 20px 20px;display:none;">
                    <div style="font-size:12px;color:var(--t2);margin-bottom:12px;">Configurez l'email et le nom de contact du client pour les rapports automatiques. Ces informations seront pre-remplies automatiquement dans l'onglet Rapports.</div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                        <div>
                            <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Email du client (rapport)</label>
                            <input type="email" id="cs-report-email" class="si" placeholder="client@email.com" style="width:100%;" value="${this.esc(rptEmail)}">
                        </div>
                        <div>
                            <label style="font-size:12px;color:var(--t2);display:block;margin-bottom:4px;">Nom du contact</label>
                            <input type="text" id="cs-report-contact" class="si" placeholder="Jean Dupont" style="width:100%;" value="${this.esc(rptContact)}">
                        </div>
                    </div>
                    <div style="font-size:11px;color:var(--t3);margin-bottom:12px;">Ces informations seront utilisees pour pre-remplir automatiquement les destinataires dans l'ajout groupe de l'onglet Rapports.</div>
                    <button class="btn bp bsm" onclick="APP.clientSettings.saveReportContact(${lid})">Enregistrer</button>
                </div>
            </div>`;

            c.innerHTML = h;
        },

        async saveReviewProfile(lid) {
            const fd = new FormData();
            fd.append('action', 'save_settings');
            fd.append('location_id', lid);
            fd.append('default_tone', document.getElementById('cs-tone').value);
            fd.append('gender', document.getElementById('cs-gender').value);
            fd.append('review_speech', document.getElementById('cs-speech').value);
            fd.append('review_intro', document.getElementById('cs-review-intro').value.trim());
            fd.append('review_closing', document.getElementById('cs-review-closing').value.trim());
            fd.append('review_signature', document.getElementById('cs-review-signature').value.trim());
            fd.append('custom_instructions', document.getElementById('cs-instructions').value.trim());
            const d = await APP.fetch('/api/reviews.php', { method: 'POST', body: fd });
            if (d.success) {
                APP.toast('Profil IA Avis sauvegardé !', 'success');
            } else {
                APP.toast(d.error || 'Erreur de sauvegarde', 'error');
            }
        },

        async savePostsProfile(lid) {
            const fd = new FormData();
            fd.append('action', 'save_posts_settings');
            fd.append('location_id', lid);
            fd.append('posts_tone', document.getElementById('cs-posts-tone').value);
            fd.append('posts_gender', document.getElementById('cs-posts-gender').value);
            fd.append('posts_speech', document.getElementById('cs-posts-speech').value);
            fd.append('posts_signature', document.getElementById('cs-posts-signature').value.trim());
            fd.append('posts_instructions', document.getElementById('cs-posts-instructions').value.trim());
            fd.append('business_context', document.getElementById('cs-business-context').value.trim());
            const d = await APP.fetch('/api/reviews.php', { method: 'POST', body: fd });
            if (d.success) {
                APP.toast('Profil IA Posts sauvegardé !', 'success');
            } else {
                APP.toast(d.error || 'Erreur de sauvegarde', 'error');
            }
        },

        importBusinessContext(lid) {
            const input = document.createElement('input');
            input.type = 'file';
            input.accept = '.csv,.tsv,.txt';
            input.onchange = (e) => {
                const file = e.target.files[0];
                if (!file) return;
                const reader = new FileReader();
                reader.onload = (ev) => {
                    let text = ev.target.result;
                    // Supprimer BOM UTF-8 si present
                    if (text.charCodeAt(0) === 0xFEFF) text = text.slice(1);
                    // Detecter le separateur (point-virgule courant en locale FR)
                    const firstLine = text.split('\n')[0];
                    const sep = (firstLine.split(';').length > firstLine.split(',').length) ? ';' : ',';
                    // Parser une ligne CSV en respectant les guillemets
                    function parseLine(line) {
                        const fields = [];
                        let cur = '', inQ = false;
                        for (let i = 0; i < line.length; i++) {
                            const ch = line[i];
                            if (ch === '"') {
                                if (inQ && i + 1 < line.length && line[i + 1] === '"') { cur += '"'; i++; }
                                else { inQ = !inQ; }
                            } else if (ch === sep && !inQ) { fields.push(cur.trim()); cur = ''; }
                            else if (ch !== '\r') { cur += ch; }
                        }
                        fields.push(cur.trim());
                        return fields;
                    }
                    // Decouper en enregistrements (gere les valeurs multi-lignes entre guillemets)
                    const records = [];
                    let rec = '', rQ = false;
                    for (let i = 0; i < text.length; i++) {
                        const ch = text[i];
                        if (ch === '"') { rQ = !rQ; rec += ch; }
                        else if ((ch === '\n' || ch === '\r') && !rQ) {
                            if (ch === '\r' && text[i + 1] === '\n') i++;
                            if (rec.trim()) records.push(rec);
                            rec = '';
                        } else { rec += ch; }
                    }
                    if (rec.trim()) records.push(rec);
                    if (records.length < 2) { APP.toast('CSV vide ou invalide', 'error'); return; }
                    const headers = parseLine(records[0]);
                    const values = parseLine(records[records.length - 1]);
                    // Construire le texte contexte
                    let context = '';
                    for (let i = 0; i < headers.length; i++) {
                        const h = headers[i];
                        const v = values[i] || '';
                        if (!v || h.toLowerCase().includes('horodateur') || h.toLowerCase().includes('timestamp') || h.toLowerCase().includes('email')) continue;
                        context += h + ' : ' + v + '\n';
                    }
                    const ta = document.getElementById('cs-business-context');
                    if (ta) {
                        ta.value = context.trim();
                        APP.toast('CSV importé ! Vérifiez le contenu puis enregistrez.', 'success');
                    }
                };
                reader.readAsText(file, 'UTF-8');
            };
            input.click();
        },

        async saveReportContact(lid) {
            const fd = new FormData();
            fd.append('action', 'save_report_contact');
            fd.append('location_id', lid);
            fd.append('report_email', document.getElementById('cs-report-email').value.trim());
            fd.append('report_contact_name', document.getElementById('cs-report-contact').value.trim());
            const d = await APP.fetch('/api/reviews.php', { method: 'POST', body: fd });
            if (d.success) {
                APP.toast('Contact rapport sauvegarde !', 'success');
                if (this._location) {
                    this._location.report_email = document.getElementById('cs-report-email').value.trim();
                    this._location.report_contact_name = document.getElementById('cs-report-contact').value.trim();
                }
            } else {
                APP.toast(d.error || 'Erreur de sauvegarde', 'error');
            }
        },

        async uploadLogo(file) {
            if (!file) return;
            const fd = new FormData();
            fd.append('action', 'upload_logo');
            fd.append('location_id', this._locationId);
            fd.append('logo', file);
            const data = await APP.fetch('/api/post-visuals.php', { method: 'POST', body: fd });
            if (data.success) {
                APP.toast('Logo uploadé avec succès', 'success');
                if (this._location) this._location.logo_path = data.logo_path;
                this.render(); // Re-render pour afficher le nouveau logo
            } else {
                APP.toast(data.error || 'Erreur upload logo', 'error');
            }
        },

        async deleteLogo() {
            if (!confirm('Supprimer le logo du client ?')) return;
            const fd = new FormData();
            fd.append('action', 'delete_logo');
            fd.append('location_id', this._locationId);
            const data = await APP.fetch('/api/post-visuals.php', { method: 'POST', body: fd });
            if (data.success) {
                APP.toast('Logo supprimé', 'success');
                if (this._location) this._location.logo_path = null;
                this.render();
            } else {
                APP.toast(data.error || 'Erreur suppression logo', 'error');
            }
        },

        async saveSignature(lid) {
            const enabled = document.getElementById('cs-sig-enabled')?.checked ? 1 : 0;
            const text = document.getElementById('cs-sig-text')?.value?.trim() || '';

            const fd = new FormData();
            fd.append('action', 'save_signature');
            fd.append('location_id', lid);
            fd.append('signature_enabled', enabled);
            fd.append('signature_text', text);
            const d = await APP.fetch('/api/reviews.php', { method: 'POST', body: fd });
            if (d.success) {
                APP.toast('Signature enregistrée !', 'success');
                if (this._location) {
                    this._location.signature_enabled = enabled;
                    this._location.signature_text = text;
                }
            } else {
                APP.toast(d.error || 'Erreur de sauvegarde', 'error');
            }
        },

        async geocodeAddress(lid) {
            const address = document.getElementById('cs-gps-address')?.value?.trim();
            if (!address) { APP.toast('Veuillez saisir une adresse.', 'warning'); return; }
            try {
                const resp = await fetch(`https://nominatim.openstreetmap.org/search?q=${encodeURIComponent(address)}&format=json&limit=1&countrycodes=fr`);
                const results = await resp.json();
                if (results && results.length > 0) {
                    const lat = parseFloat(results[0].lat).toFixed(7);
                    const lng = parseFloat(results[0].lon).toFixed(7);
                    document.getElementById('cs-gps-lat').value = lat;
                    document.getElementById('cs-gps-lng').value = lng;
                    // Sauvegarder directement
                    const fd = new FormData();
                    fd.append('action', 'save_gps');
                    fd.append('location_id', lid);
                    fd.append('latitude', lat);
                    fd.append('longitude', lng);
                    const d = await APP.fetch('/api/reviews.php', { method: 'POST', body: fd });
                    if (d.success) {
                        APP.toast(`Coordonnées trouvées : ${lat}, ${lng} — ${results[0].display_name}`, 'success', 6000);
                        this.load(lid);
                    }
                } else {
                    APP.toast('Adresse introuvable. Essayez avec plus de détails (ville, code postal, pays).', 'warning');
                }
            } catch (e) {
                APP.toast('Erreur de géocodage : ' + e.message, 'error');
            }
        },

        async saveGps(lid) {
            const lat = document.getElementById('cs-gps-lat')?.value?.trim();
            const lng = document.getElementById('cs-gps-lng')?.value?.trim();
            if (!lat || !lng || isNaN(lat) || isNaN(lng)) {
                APP.toast('Veuillez saisir des coordonnées GPS valides (nombres).', 'warning'); return;
            }
            const latNum = parseFloat(lat), lngNum = parseFloat(lng);
            if (latNum < -90 || latNum > 90) { APP.toast('Latitude doit être entre -90 et 90', 'error'); return; }
            if (lngNum < -180 || lngNum > 180) { APP.toast('Longitude doit être entre -180 et 180', 'error'); return; }
            const fd = new FormData();
            fd.append('action', 'save_gps');
            fd.append('location_id', lid);
            fd.append('latitude', lat);
            fd.append('longitude', lng);
            const d = await APP.fetch('/api/reviews.php', { method: 'POST', body: fd });
            if (d.success) {
                APP.toast(`Coordonnées GPS sauvegardées : ${lat}, ${lng}`, 'success');
                this.load(lid);
            } else {
                APP.toast(d.error || 'Erreur de sauvegarde', 'error');
            }
        },

        // saveGridSettings() — no-op : grille sunburst 49 pts fixe, plus de paramètres
        async saveGridSettings(lid) { /* no-op */ },

        async resetGps(lid) {
            if (!await APP.modal.confirm('Réinitialiser', 'Réinitialiser les coordonnées GPS de cette fiche ?\nLes coordonnées actuelles seront supprimées.', 'Réinitialiser', true)) return;
            const fd = new FormData();
            fd.append('action', 'reset_gps');
            fd.append('location_id', lid);
            const d = await APP.fetch('/api/reviews.php', { method: 'POST', body: fd });
            if (d.success) {
                APP.toast('Coordonnées GPS réinitialisées.', 'success');
                this.load(lid);
            } else {
                APP.toast(d.error || 'Erreur', 'error');
            }
        },

        async forceGeocode(lid) {
            const btn = document.getElementById('btn-force-geocode');
            if (btn) { btn.disabled = true; btn.textContent = 'Recherche en cours...'; }
            try {
                const fd = new FormData();
                fd.append('action', 'force_geocode');
                fd.append('location_id', lid);
                const d = await APP.fetch('/api/reviews.php', { method: 'POST', body: fd });
                if (d.success && d.latitude && d.longitude) {
                    APP.toast(`Coordonnées GPS récupérées : ${d.latitude}, ${d.longitude} (via ${d.method})`, 'success');
                    this.load(lid);
                } else {
                    APP.toast('Impossible de récupérer les coordonnées GPS. ' + (d.error || ''), 'error');
                    if (btn) { btn.disabled = false; btn.textContent = 'Récupérer les coordonnées GPS'; }
                }
            } catch (e) {
                APP.toast('Erreur : ' + e.message, 'error');
                if (btn) { btn.disabled = false; btn.textContent = 'Récupérer les coordonnées GPS'; }
            }
        },

        // ===== PLACES API — Autocomplete association =====
        _placesAutocomplete(query) {
            clearTimeout(this._placesAcTimer);
            if (this._placesAcAbort) this._placesAcAbort.abort();
            const list = document.getElementById('cs-places-ac-list');
            if (!list) return;
            if (query.length < 3) { list.style.display = 'none'; return; }
            // Si c'est une URL Google Maps, traiter directement
            if (query.startsWith('https://maps.google') || query.startsWith('https://www.google.com/maps') || query.startsWith('https://goo.gl/maps')) {
                list.style.display = 'none';
                document.getElementById('cs-gmaps-url').value = query;
                document.getElementById('cs-places-search').value = '';
                APP.toast('URL Google Maps détectée — cliquez "Extraire"', 'info');
                return;
            }
            this._placesAcTimer = setTimeout(async () => {
                const ctrl = new AbortController();
                this._placesAcAbort = ctrl;
                try {
                    const loc = this._location;
                    let url = `/api/places-autocomplete.php?q=${encodeURIComponent(query)}`;
                    if (loc.latitude && loc.longitude) url += `&lat=${loc.latitude}&lng=${loc.longitude}`;
                    const data = await APP.fetch(url, { signal: ctrl.signal });
                    if (ctrl.signal.aborted) return;
                    if (!data.results || data.results.length === 0) { list.style.display = 'none'; return; }
                    let html = '';
                    for (const r of data.results) {
                        const b64 = btoa(unescape(encodeURIComponent(JSON.stringify(r))));
                        const nameHtml = this.esc(r.name).replace(new RegExp('(' + query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi'), '<strong>$1</strong>');
                        html += `<div style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--bdr);display:flex;gap:10px;align-items:center;" onmousedown="APP.clientSettings._selectPlace('${b64}')" onmouseover="this.style.background='var(--overlay)'" onmouseout="this.style.background='transparent'">
                            <div style="width:32px;height:32px;border-radius:8px;background:var(--overlay);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:var(--acc);fill:none;stroke-width:2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            </div>
                            <div style="min-width:0;">
                                <div style="font-size:13px;color:var(--t1);">${nameHtml}</div>
                                <div style="font-size:11px;color:var(--t3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${this.esc(r.address || '')}</div>
                            </div>
                        </div>`;
                    }
                    list.innerHTML = html;
                    list.style.display = 'block';
                } catch (e) {
                    if (e.name !== 'AbortError') console.error('Places autocomplete error:', e);
                }
            }, 300);
        },

        async _selectPlace(b64) {
            const list = document.getElementById('cs-places-ac-list');
            if (list) list.style.display = 'none';
            try {
                const suggestion = JSON.parse(decodeURIComponent(escape(atob(b64))));
                const input = document.getElementById('cs-places-search');
                if (input) input.value = suggestion.name || '';
                // Afficher un loading chip
                const chip = document.getElementById('cs-places-chip');
                if (chip) chip.innerHTML = '<div style="padding:10px;color:var(--t3);font-size:12px;">Chargement des détails...</div>';
                // Fetch les détails complets
                const data = await APP.fetch(`/api/places-details.php?place_id=${encodeURIComponent(suggestion.place_id)}&mode=basic`);
                if (data.success && data.place) {
                    this._selectedPlace = data.place;
                    this._renderPlaceChip(data.place);
                    const confirm = document.getElementById('cs-places-confirm');
                    if (confirm) confirm.style.display = 'block';
                } else {
                    // Fallback avec les données de l'autocomplete
                    this._selectedPlace = { place_id: suggestion.place_id, name: suggestion.name, address: suggestion.address };
                    this._renderPlaceChip(this._selectedPlace);
                    const confirm = document.getElementById('cs-places-confirm');
                    if (confirm) confirm.style.display = 'block';
                }
            } catch (e) {
                console.error('_selectPlace error:', e);
                APP.toast('Erreur lors de la sélection', 'error');
            }
        },

        _renderPlaceChip(place) {
            const chip = document.getElementById('cs-places-chip');
            if (!chip) return;
            const ratingHtml = place.rating ? `<span style="color:var(--o);">★ ${place.rating}</span>` : '';
            const reviewsHtml = place.reviews_count ? `<span style="color:var(--t3);">(${place.reviews_count} avis)</span>` : '';
            const catHtml = place.category ? `<span style="color:var(--t3);">${this.esc(place.category)}</span>` : '';
            const isSab = place.is_sab == 1;
            const addrHtml = isSab
                ? '<span style="color:var(--o);font-style:italic;">Adresse masquée (fiche SAB)</span>'
                : `<span style="color:var(--t3);">${this.esc(place.address || '')}</span>`;
            chip.innerHTML = `<div style="margin-top:8px;padding:12px 14px;background:rgba(0,255,209,.06);border:1px solid rgba(0,255,209,.25);border-radius:10px;display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <div style="font-size:13px;font-weight:600;color:var(--t1);margin-bottom:4px;">
                        <svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:var(--g);fill:none;stroke-width:2;vertical-align:-2px;margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg>
                        ${this.esc(place.name || '')}
                    </div>
                    <div style="font-size:12px;display:flex;gap:8px;flex-wrap:wrap;">${ratingHtml} ${reviewsHtml} ${catHtml}</div>
                    <div style="font-size:11px;margin-top:4px;">${addrHtml}</div>
                    <div style="font-size:10px;color:var(--t3);margin-top:4px;">Place ID : ${this.esc(place.place_id || '')}</div>
                </div>
                <button onclick="APP.clientSettings._clearPlaceSelection()" style="background:none;border:none;color:var(--t3);cursor:pointer;padding:4px;font-size:16px;" title="Annuler">✕</button>
            </div>`;
        },

        _clearPlaceSelection() {
            this._selectedPlace = null;
            const chip = document.getElementById('cs-places-chip');
            if (chip) chip.innerHTML = '';
            const confirm = document.getElementById('cs-places-confirm');
            if (confirm) confirm.style.display = 'none';
            const input = document.getElementById('cs-places-search');
            if (input) input.value = '';
        },

        async confirmPlaceAssociation(lid) {
            if (!this._selectedPlace?.place_id) { APP.toast('Sélectionnez une fiche d\'abord', 'warning'); return; }
            const fd = new FormData();
            fd.append('action', 'link_place');
            fd.append('location_id', lid);
            fd.append('place_id', this._selectedPlace.place_id);
            const data = await APP.fetch('/api/places-data.php', { method: 'POST', body: fd });
            if (data.success) {
                APP.toast(data.message || 'Fiche associée !', 'success');
                this._selectedPlace = null;
                this.load(lid);
            } else {
                APP.toast(data.error || 'Erreur d\'association', 'error');
            }
        },

        async linkFromUrl(lid) {
            const url = document.getElementById('cs-gmaps-url')?.value?.trim();
            if (!url) { APP.toast('Collez une URL Google Maps', 'warning'); return; }
            const fd = new FormData();
            fd.append('action', 'link_from_url');
            fd.append('location_id', lid);
            fd.append('url', url);
            const data = await APP.fetch('/api/places-data.php', { method: 'POST', body: fd });
            if (data.success) {
                APP.toast(data.message || 'Fiche associée !', 'success');
                this.load(lid);
            } else {
                APP.toast(data.error || 'Erreur d\'extraction', 'error');
            }
        },

        async linkFromPlaceId(lid) {
            const pid = document.getElementById('cs-manual-placeid')?.value?.trim();
            if (!pid) { APP.toast('Saisissez un Place ID', 'warning'); return; }
            const fd = new FormData();
            fd.append('action', 'link_place');
            fd.append('location_id', lid);
            fd.append('place_id', pid);
            const data = await APP.fetch('/api/places-data.php', { method: 'POST', body: fd });
            if (data.success) {
                APP.toast(data.message || 'Fiche associée !', 'success');
                this.load(lid);
            } else {
                APP.toast(data.error || 'Place ID invalide', 'error');
            }
        },

        async _unlinkPlace(lid) {
            if (!confirm('Dissocier la fiche Google de ce client ?')) return;
            const fd = new FormData();
            fd.append('action', 'save_gps'); // Réutilise l'endpoint existant juste pour trigger un update
            fd.append('location_id', lid);
            // On remet places_api_linked à 0 via un appel direct
            try {
                const stmt = await APP.fetch('/api/places-data.php', {
                    method: 'POST',
                    body: (() => { const f = new FormData(); f.append('action', 'link_place'); f.append('location_id', lid); f.append('place_id', ''); return f; })(),
                });
                APP.toast('Fiche dissociée', 'success');
                this.load(lid);
            } catch (e) {
                APP.toast('Erreur', 'error');
            }
        }
    },

    // ====================================================================
    // MODULE : PARAMETRES — PROFILS IA (PRESETS)
    // ====================================================================
    settings: {
        async load() {
            const c = document.getElementById('settings-content');
            if (!c) return;
            const isAdmin = (document.querySelector('meta[name="user-role"]')?.content === 'admin');
            c.innerHTML = `<div class="sh"><div class="stit">PARAMETRES</div></div>
                <div style="padding:30px 20px;">
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;">
                        <a href="?view=locations" style="text-decoration:none;display:block;padding:20px;background:var(--overlay);border:1px solid var(--bdr);border-radius:12px;transition:border-color .2s;" onmouseover="this.style.borderColor='var(--acc)'" onmouseout="this.style.borderColor='var(--bdr)'">
                            <div style="font-weight:600;color:var(--t1);margin-bottom:6px;">Fiches Google</div>
                            <div style="font-size:13px;color:var(--t3);line-height:1.5;">Gérez vos fiches GBP, paramétrez les profils IA individuellement par fiche, et configuréz les réponses automatiques.</div>
                        </a>
                        <a href="?view=reports" style="text-decoration:none;display:block;padding:20px;background:var(--overlay);border:1px solid var(--bdr);border-radius:12px;transition:border-color .2s;" onmouseover="this.style.borderColor='var(--acc)'" onmouseout="this.style.borderColor='var(--bdr)'">
                            <div style="font-weight:600;color:var(--t1);margin-bottom:6px;">Rapports automatiques</div>
                            <div style="font-size:13px;color:var(--t3);line-height:1.5;">Configurez l'envoi automatique de rapports de performance par email.</div>
                        </a>
                        <a href="?view=dashboard" style="text-decoration:none;display:block;padding:20px;background:var(--overlay);border:1px solid var(--bdr);border-radius:12px;transition:border-color .2s;" onmouseover="this.style.borderColor='var(--acc)'" onmouseout="this.style.borderColor='var(--bdr)'">
                            <div style="font-weight:600;color:var(--t1);margin-bottom:6px;">Compte Google</div>
                            <div style="font-size:13px;color:var(--t3);line-height:1.5;">Connectez ou deconnectez votre compte Google Business Profile.</div>
                        </a>
                    </div>
                    <div style="margin-top:24px;padding:16px;background:rgba(0,212,255,.05);border:1px solid rgba(0,212,255,.15);border-radius:8px;">
                        <div style="font-size:13px;color:var(--t2);line-height:1.6;">
                            <strong style="color:var(--acc);">Profils IA :</strong> Les profils IA sont désormais configurés individuellement sur chaque fiche. Rendez-vous dans <a href="?view=locations" style="color:var(--acc);">Fiches Google</a>, selectionnez une fiche, puis ouvrez l'onglet <em>Paramètres</em> pour configurer le ton, le vouvoiement, la signature, etc.
                        </div>
                    </div>
                </div>` + (isAdmin ? '<div id="admin-maintenance-section"></div><div id="admin-users-section"></div>' : '');
            if (isAdmin) { APP.settings.loadMaintenance(); APP.settings.loadUsers(); }
        },

        // ====== MODE MAINTENANCE (ADMIN) ======
        async loadMaintenance() {
            const sec = document.getElementById('admin-maintenance-section');
            if (!sec) return;
            try {
                const d = await APP.fetch('/api/users.php?action=maintenance_status');
                const active = d.maintenance || false;
                const color = active ? '#ef4444' : '#22c55e';
                const label = active ? 'ACTIVE' : 'DESACTIVE';
                const btnText = active ? 'Desactiver la maintenance' : 'Activer la maintenance';
                const btnColor = active ? 'rgba(34,197,94,.15)' : 'rgba(239,68,68,.1)';
                const btnTextColor = active ? '#22c55e' : '#ef4444';

                sec.innerHTML = '<div class="sh"><div class="stit">MODE MAINTENANCE</div></div>'
                    + '<div style="padding:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">'
                    + '<div style="display:flex;align-items:center;gap:12px;">'
                    + '<div style="width:10px;height:10px;border-radius:50%;background:' + color + ';box-shadow:0 0 8px ' + color + ';"></div>'
                    + '<div>'
                    + '<div style="font-size:14px;font-weight:600;color:var(--t1);">Maintenance : <span style="color:' + color + ';">' + label + '</span></div>'
                    + '<div style="font-size:12px;color:var(--t3);margin-top:2px;">' + (active ? 'Les utilisateurs voient une page de maintenance. Vous seul avez acces.' : 'Le site est accessible a tous les utilisateurs.') + '</div>'
                    + '</div>'
                    + '</div>'
                    + '<button onclick="APP.settings.toggleMaintenance(' + (active ? 'false' : 'true') + ',this)" style="padding:8px 20px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;background:' + btnColor + ';color:' + btnTextColor + ';white-space:nowrap;">' + btnText + '</button>'
                    + '</div>';
            } catch (e) {
                sec.innerHTML = '';
            }
        },

        async toggleMaintenance(activate, btn) {
            const msg = activate
                ? 'Activer le mode maintenance ? Les utilisateurs ne pourront plus acceder au site.'
                : 'Desactiver le mode maintenance ? Le site sera a nouveau accessible.';
            if (!confirm(msg)) return;
            if (btn) { btn.disabled = true; btn.textContent = '...'; }
            try {
                const action = activate ? 'maintenance_on' : 'maintenance_off';
                const fd = new FormData();
                fd.append('action', action);
                const d = await APP.fetch('/api/users.php', { method: 'POST', body: fd });
                if (d.error) { APP.toast(d.error, 'error'); return; }
                APP.toast(d.message, 'success');
                APP.settings.loadMaintenance();
            } catch (e) {
                APP.toast('Erreur : ' + e.message, 'error');
                if (btn) { btn.disabled = false; }
            }
        },

        // ====== GESTION DES UTILISATEURS (ADMIN) ======
        async loadUsers() {
            const sec = document.getElementById('admin-users-section');
            if (!sec) return;
            sec.innerHTML = '<div class="sh"><div class="stit">GESTION DES UTILISATEURS</div></div><div style="padding:20px;color:var(--t3);font-size:13px;">Chargement...</div>';
            try {
                const d = await APP.fetch('/api/users.php?action=list');
                if (!d.success || !d.users) { sec.innerHTML = ''; return; }
                const users = d.users;
                const statusBadge = (s) => {
                    if (s === 'active') return '<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:rgba(34,197,94,.12);color:#22c55e;">Actif</span>';
                    if (s === 'pending') return '<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:rgba(251,191,36,.12);color:#fbbf24;">En attente</span>';
                    if (s === 'suspended') return '<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:rgba(239,68,68,.12);color:#ef4444;">Suspendu</span>';
                    return '<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:rgba(255,255,255,.08);color:var(--t3);">' + APP.escHtml(s) + '</span>';
                };
                const roleBadge = (r) => {
                    if (r === 'admin') return '<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:rgba(0,212,255,.12);color:var(--acc);">Admin</span>';
                    return '<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:rgba(255,255,255,.06);color:var(--t3);">User</span>';
                };
                const fmtDate = (d) => { if (!d) return '—'; const dt = new Date(d); return dt.toLocaleDateString('fr-FR', {day:'2-digit',month:'short',year:'numeric'}); };

                let rows = '';
                users.forEach(u => {
                    let actions = '';
                    if (u.role !== 'admin') {
                        if (u.status === 'pending') {
                            actions += '<button onclick="APP.settings.userAction(\'validate\',' + u.id + ',this)" style="padding:5px 12px;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;background:rgba(34,197,94,.15);color:#22c55e;margin-right:6px;" title="Valider le compte">Valider</button>';
                        }
                        if (u.status === 'active') {
                            actions += '<button onclick="APP.settings.userAction(\'suspend\',' + u.id + ',this)" style="padding:5px 12px;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;background:rgba(251,191,36,.12);color:#fbbf24;margin-right:6px;" title="Suspendre le compte">Suspendre</button>';
                        }
                        if (u.status === 'suspended') {
                            actions += '<button onclick="APP.settings.userAction(\'activate\',' + u.id + ',this)" style="padding:5px 12px;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;background:rgba(34,197,94,.15);color:#22c55e;margin-right:6px;" title="Reactiver le compte">Reactiver</button>';
                        }
                        actions += '<button onclick="APP.settings.userAction(\'delete\',' + u.id + ',this)" style="padding:5px 12px;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;background:rgba(239,68,68,.1);color:#ef4444;" title="Supprimer le compte">Supprimer</button>';
                    } else {
                        actions = '<span style="font-size:12px;color:var(--t3);font-style:italic;">—</span>';
                    }
                    rows += '<tr style="border-bottom:1px solid var(--bdr);">'
                        + '<td style="padding:12px 16px;font-size:13px;color:var(--t1);font-weight:500;">' + APP.escHtml(u.name) + '</td>'
                        + '<td style="padding:12px 16px;font-size:13px;color:var(--t2);">' + APP.escHtml(u.email) + '</td>'
                        + '<td style="padding:12px 16px;">' + roleBadge(u.role) + '</td>'
                        + '<td style="padding:12px 16px;">' + statusBadge(u.status) + '</td>'
                        + '<td style="padding:12px 16px;font-size:12px;color:var(--t3);">' + fmtDate(u.created_at) + '</td>'
                        + '<td style="padding:12px 16px;white-space:nowrap;">' + actions + '</td>'
                        + '</tr>';
                });

                const pendingCount = users.filter(u => u.status === 'pending').length;
                const pendingNotice = pendingCount > 0
                    ? '<div style="margin-bottom:16px;padding:12px 16px;background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.2);border-radius:8px;font-size:13px;color:#fbbf24;display:flex;align-items:center;gap:8px;">' + APP.icon('alert-triangle', 16) + ' <strong>' + pendingCount + ' compte' + (pendingCount > 1 ? 's' : '') + '</strong> en attente de validation</div>'
                    : '';

                sec.innerHTML = '<div class="sh"><div class="stit">GESTION DES UTILISATEURS</div></div>'
                    + '<div style="padding:20px;">'
                    + pendingNotice
                    + '<div style="overflow-x:auto;">'
                    + '<table style="width:100%;border-collapse:collapse;">'
                    + '<thead><tr style="border-bottom:2px solid var(--bdr);">'
                    + '<th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.8px;">Nom</th>'
                    + '<th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.8px;">Email</th>'
                    + '<th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.8px;">Role</th>'
                    + '<th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.8px;">Statut</th>'
                    + '<th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.8px;">Inscription</th>'
                    + '<th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.8px;">Actions</th>'
                    + '</tr></thead>'
                    + '<tbody>' + rows + '</tbody>'
                    + '</table></div>'
                    + '<div style="margin-top:16px;font-size:12px;color:var(--t3);">' + users.length + ' utilisateur' + (users.length > 1 ? 's' : '') + ' au total</div>'
                    + '</div>';
            } catch (e) {
                sec.innerHTML = '<div class="sh"><div class="stit">GESTION DES UTILISATEURS</div></div><div style="padding:20px;color:var(--r);font-size:13px;">Erreur : ' + APP.escHtml(e.message) + '</div>';
            }
        },

        async userAction(action, userId, btn) {
            const labels = {validate: 'Valider', suspend: 'Suspendre', activate: 'Reactiver', delete: 'Supprimer'};
            const confirmMsgs = {
                validate: 'Valider ce compte ? L\'utilisateur recevra un email de confirmation.',
                suspend: 'Suspendre ce compte ? L\'utilisateur ne pourra plus se connecter.',
                activate: 'Reactiver ce compte ?',
                delete: 'Supprimer définitivement ce compte ? Cette action est irreversible.'
            };
            if (!confirm(confirmMsgs[action] || ('Confirmer l\'action : ' + action + ' ?'))) return;
            if (btn) { btn.disabled = true; btn.textContent = '...'; }
            try {
                const fd = new FormData();
                fd.append('action', action);
                fd.append('user_id', userId);
                const d = await APP.fetch('/api/users.php', { method: 'POST', body: fd });
                if (d.error) {
                    APP.toast(d.error, 'error');
                    if (btn) { btn.disabled = false; btn.textContent = labels[action] || action; }
                    return;
                }
                APP.toast(d.message || 'Action effectuee', 'success');
                APP.settings.loadUsers();
            } catch (e) {
                APP.toast('Erreur : ' + e.message, 'error');
                if (btn) { btn.disabled = false; btn.textContent = labels[action] || action; }
            }
        }
    }
};
/* --- presets code removed (start marker) --- */
const style=document.createElement('style');style.textContent='@keyframes spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}.spin{animation:spin 1s linear infinite;width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2}';document.head.appendChild(style);

// ====================================================================
// UTILITAIRE GLOBAL — Echappement HTML (utilise par les IIFEs)
// ====================================================================
APP.escHtml = function(s) { if(!s)return''; var d=document.createElement('div');d.textContent=s;return d.innerHTML; };

// ====================================================================
// ICONES MODERNES (Lucide style — traits fins, coherents)
// ====================================================================
APP.icon = function(name, size) {
    size = size || 20;
    var icons = {
        // Navigation
        'dashboard': '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'map-pin': '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0116 0z"/><circle cx="12" cy="10" r="3"/>',
        'message-circle': '<path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/>',
        'bar-chart': '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>',
        'file-text': '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
        'users': '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4-4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>',
        'user-plus': '<path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4-4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/>',
        'settings': '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/>',
        // Actions
        'plus': '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'search': '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'check': '<polyline points="20 6 9 17 4 12"/>',
        'x': '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'trash': '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/>',
        'edit': '<path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        'copy': '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>',
        'refresh-cw': '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>',
        'send': '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'download': '<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'external-link': '<path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
        // UI
        'chevron-right': '<polyline points="9 18 15 12 9 6"/>',
        'chevron-down': '<polyline points="6 9 12 15 18 9"/>',
        'menu': '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'star': '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
        'zap': '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        'calendar': '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'clock': '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'eye': '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'filter': '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
        'list': '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
        'map': '<polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/>',
        'globe': '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/>',
        'sun': '<circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>',
        'moon': '<path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/>',
        'info': '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        'alert-triangle': '<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'image': '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
        'link': '<path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/>',
        'mail': '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22 6 12 13 2 6"/>',
        'google': '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c0 2.73-1.85 4.67-4.58 4.67-.18 0-.35-.01-.53-.03l-.09.12c.24.19.58.41.79.58.72.58 1.54 1.25 1.54 2.53 0 1.69-1.63 3.4-4.71 3.4-2.56 0-4.78-1.12-4.78-3.1 0-.96.52-2.35 2.34-3.24 1.02-.51 2.41-.78 3.44-.83-.25-.34-.47-.7-.47-1.29 0-.3.08-.5.18-.72h-.56C7.37 11.89 6 10.14 6 8.38 6 6.17 7.73 4 10.73 4h4.42l-1.06 1.06h-1.34c.93.53 1.89 1.47 1.89 3.13z"/>',
    };
    var path = icons[name] || icons['info'];
    return '<svg viewBox="0 0 24 24" width="' + size + '" height="' + size + '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + path + '</svg>';
};

// ====================================================================
// TOAST NOTIFICATIONS
// ====================================================================
(function() {
    const ICONS = { success: '✓', error: '✗', warning: '!', info: 'i' };
    const TITLES = { success: 'Succès', error: 'Erreur', warning: 'Attention', info: 'Info' };
    let container = null;

    function getContainer() {
        if (!container || !container.isConnected) {
            container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }
        return container;
    }

    APP.toast = function(msg, type = 'success', duration) {
        if (!duration) duration = (type === 'error') ? 8000 : 4000;
        const el = document.createElement('div');
        el.className = `toast toast--${type}`;
        el.innerHTML = `<div class="toast-icon">${ICONS[type] || ICONS.info}</div><div class="toast-body"><div class="toast-title">${TITLES[type] || TITLES.info}</div><div class="toast-msg">${msg}</div></div><button class="toast-close" aria-label="Fermer">&times;</button>`;
        el.querySelector('.toast-close').onclick = () => dismiss(el);
        getContainer().appendChild(el);
        const timer = setTimeout(() => dismiss(el), duration);
        el._timer = timer;
    };

    function dismiss(el) {
        if (el._dismissed) return;
        el._dismissed = true;
        clearTimeout(el._timer);
        el.classList.add('toast-out');
        el.addEventListener('animationend', () => el.remove());
    }
})();

// ====================================================================
// MODAL CONFIRM / PROMPT
// ====================================================================
(function() {
    APP.modal = {
        confirm(title, msg, confirmLabel = 'Confirmer', danger = false) {
            return new Promise(resolve => {
                const overlay = document.createElement('div');
                overlay.className = 'modal-overlay';
                overlay.innerHTML = `<div class="modal-card"><div class="modal-header"><h3>${title}</h3></div><div class="modal-body">${msg}</div><div class="modal-footer"><button class="modal-btn modal-btn--cancel">Annuler</button><button class="modal-btn ${danger ? 'modal-btn--danger' : 'modal-btn--confirm'}">${confirmLabel}</button></div></div>`;
                document.body.appendChild(overlay);
                const close = (val) => {
                    overlay.classList.add('modal-out');
                    overlay.addEventListener('animationend', () => overlay.remove());
                    resolve(val);
                };
                overlay.querySelector('.modal-btn--cancel').onclick = () => close(false);
                overlay.querySelector('.modal-btn:last-child').onclick = () => close(true);
                overlay.addEventListener('click', e => { if (e.target === overlay) close(false); });
                document.addEventListener('keydown', function esc(e) {
                    if (e.key === 'Escape') { document.removeEventListener('keydown', esc); close(false); }
                });
            });
        },
        prompt(title, msg, placeholder = '') {
            return new Promise(resolve => {
                const overlay = document.createElement('div');
                overlay.className = 'modal-overlay';
                overlay.innerHTML = `<div class="modal-card"><div class="modal-header"><h3>${title}</h3></div><div class="modal-body">${msg}<input class="modal-input" type="text" placeholder="${placeholder}" autofocus></div><div class="modal-footer"><button class="modal-btn modal-btn--cancel">Annuler</button><button class="modal-btn modal-btn--confirm">Valider</button></div></div>`;
                document.body.appendChild(overlay);
                const input = overlay.querySelector('.modal-input');
                const close = (val) => {
                    overlay.classList.add('modal-out');
                    overlay.addEventListener('animationend', () => overlay.remove());
                    resolve(val);
                };
                overlay.querySelector('.modal-btn--cancel').onclick = () => close(null);
                overlay.querySelector('.modal-btn--confirm').onclick = () => close(input.value);
                input.addEventListener('keydown', e => { if (e.key === 'Enter') close(input.value); });
                overlay.addEventListener('click', e => { if (e.target === overlay) close(null); });
                document.addEventListener('keydown', function esc(e) {
                    if (e.key === 'Escape') { document.removeEventListener('keydown', esc); close(null); }
                });
                setTimeout(() => input.focus(), 50);
            });
        }
    };
})();

// ====================================================================
// BUTTON LOADING STATE HELPER
// ====================================================================
APP.btnLoading = function(btn, loading, originalHTML) {
    if (!btn) return;
    if (loading) {
        btn._originalHTML = btn.innerHTML;
        btn.classList.add('btn-loading');
        btn.disabled = true;
    } else {
        btn.classList.remove('btn-loading');
        btn.disabled = false;
        if (originalHTML) btn.innerHTML = originalHTML;
        else if (btn._originalHTML) btn.innerHTML = btn._originalHTML;
    }
};

// ====================================================================
// INLINE FORM VALIDATION HELPER
// ====================================================================
APP.validate = {
    /**
     * Validate a single field
     * @param {string} id - Input element ID
     * @param {object} rules - { required, minLength, maxLength, pattern, email, url, custom }
     * @returns {boolean}
     */
    field(id, rules = {}) {
        const input = document.getElementById(id);
        if (!input) return true;

        const group = input.closest('.field-group');
        const hint = group ? group.querySelector('.field-hint') : null;
        const val = input.value.trim();
        let error = '';

        if (rules.required && !val) {
            error = 'Ce champ est requis';
        } else if (rules.minLength && val.length < rules.minLength) {
            error = `Minimum ${rules.minLength} caractères`;
        } else if (rules.maxLength && val.length > rules.maxLength) {
            error = `Maximum ${rules.maxLength} caractères`;
        } else if (rules.email && val && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
            error = 'Adresse email invalide';
        } else if (rules.url && val && !/^https?:\/\/.+/.test(val)) {
            error = 'URL invalide (commencez par http:// ou https://)';
        } else if (rules.pattern && val && !rules.pattern.test(val)) {
            error = rules.patternMsg || 'Format invalide';
        } else if (rules.custom && val) {
            error = rules.custom(val) || '';
        }

        if (group) {
            group.classList.remove('valid', 'error', 'warning');
            if (error) {
                group.classList.add('error');
                if (hint) { hint.textContent = error; hint.style.display = 'block'; }
            } else if (val) {
                group.classList.add('valid');
                if (hint) hint.style.display = 'none';
            }
        }

        return !error;
    },

    /**
     * Validate multiple fields at once
     * @param {Array<[string, object]>} fieldRules - Array of [id, rules]
     * @returns {boolean} All valid
     */
    form(fieldRules) {
        let allValid = true;
        for (const [id, rules] of fieldRules) {
            if (!this.field(id, rules)) allValid = false;
        }
        if (!allValid) APP.toast('Veuillez corriger les champs en erreur', 'warning');
        return allValid;
    },

    /** Clear validation states for a field */
    clear(id) {
        const input = document.getElementById(id);
        if (!input) return;
        const group = input.closest('.field-group');
        if (group) {
            group.classList.remove('valid', 'error', 'warning');
            const hint = group.querySelector('.field-hint');
            if (hint) hint.style.display = 'none';
        }
    }
};

// ====================================================================
// SKELETON LOADER HELPERS
// ====================================================================
APP.skeleton = {
    /** Generate skeleton rows for tables */
    table(rows = 5, cols = 4) {
        let h = '<table><thead><tr>';
        for (let c = 0; c < cols; c++) h += '<th><div class="skeleton skeleton-text w-3-4" style="height:10px"></div></th>';
        h += '</tr></thead><tbody>';
        for (let r = 0; r < rows; r++) {
            h += '<tr>';
            for (let c = 0; c < cols; c++) {
                const w = c === 0 ? 'w-full' : (c === cols - 1 ? 'w-1-4' : 'w-1-2');
                h += `<td><div class="skeleton skeleton-text ${w}"></div></td>`;
            }
            h += '</tr>';
        }
        h += '</tbody></table>';
        return h;
    },

    /** Generate skeleton cards */
    cards(count = 3) {
        let h = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;padding:20px;">';
        for (let i = 0; i < count; i++) {
            h += `<div class="skeleton-card"><div class="skeleton skeleton-text w-3-4 h-lg" style="margin-bottom:12px;"></div><div class="skeleton skeleton-text w-full"></div><div class="skeleton skeleton-text w-1-2"></div></div>`;
        }
        h += '</div>';
        return h;
    },

    /** Generate stat bar skeleton */
    stats(count = 4) {
        let h = '<div class="skeleton-stat">';
        for (let i = 0; i < count; i++) {
            h += '<div class="skeleton skeleton-stat-card"></div>';
        }
        h += '</div>';
        return h;
    },

    /** Generate list rows skeleton */
    rows(count = 5) {
        let h = '';
        for (let i = 0; i < count; i++) {
            h += `<div class="skeleton-row">
                <div class="skeleton skeleton-avatar"></div>
                <div class="skeleton-block">
                    <div class="skeleton skeleton-text w-3-4"></div>
                    <div class="skeleton skeleton-text w-1-2"></div>
                </div>
            </div>`;
        }
        return h;
    }
};

// ====================================================================
// DASHBOARD V4 — Centre de pilotage avec sélecteur de période
// ====================================================================
APP.dashboard = {
    _period: 30,
    _data: null,
    _loading: false,

    init() {
        this._period = 30;
        this.load();
    },

    setPeriod(days) {
        if (this._loading || this._period === days) return;
        this._period = days;
        // Update active button
        document.querySelectorAll('.dash-period-btn').forEach(btn => {
            btn.classList.toggle('active', parseInt(btn.dataset.period) === days);
        });
        this.load();
    },

    async load() {
        if (this._loading) return;
        this._loading = true;
        this._renderSkeleton();
        document.getElementById('dash-error').style.display = 'none';

        try {
            const data = await APP.fetch('/api/dashboard.php?period=' + this._period);
            if (data.error) throw new Error(data.error);
            this._data = data;
            this._loading = false;
            this.render();
        } catch (e) {
            console.error('Dashboard load error:', e);
            this._loading = false;
            this._renderError();
        }
    },

    render() {
        var d = this._data;
        if (!d) return;
        if (d.empty) { this._renderEmpty(); return; }
        this._renderAlerts(d.alerts);
        this._renderKPIs(d.kpis, d.period);
        this._renderMonitor(d);
        this._renderReviews(d.recent_reviews);
        this._renderOverview(d.overview);
    },

    // ====== ALERTES (temps réel, pas de période) ======
    _renderAlerts(alerts) {
        var el = document.getElementById('dash-alerts');
        if (!alerts || !alerts.length) { el.innerHTML = ''; return; }
        var h = '<div class="dash-alerts">';
        h += '<div class="dash-section-title">';
        h += '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
        h += ' Actions requises <span class="dash-alert-count">' + alerts.length + '</span>';
        h += this._info('Actions prioritaires détectées automatiquement. Rouge = urgent, orange = important, bleu = recommandé.');
        h += '</div>';
        alerts.forEach(function(a) {
            h += '<div class="dash-alert dash-alert-' + a.type + '">';
            h += '<div class="dash-alert-icon">' + a.icon + '</div>';
            h += '<div class="dash-alert-body">';
            h += '<div class="dash-alert-text">' + a.text + '</div>';
            if (a.sub) h += '<div class="dash-alert-sub">' + a.sub + '</div>';
            h += '</div>';
            h += '<a href="' + a.action + '" class="dash-alert-action">' + a.action_label + ' &rarr;</a>';
            h += '</div>';
        });
        h += '</div>';
        el.innerHTML = h;
    },

    // ====== KPIs (4 cards, affectées par la période) ======
    _renderKPIs(kpis, period) {
        var el = document.getElementById('dash-kpis');
        var pLabel = this._periodLabel(period);
        var h = '';

        // 1. Position moyenne (inverse : baisse de position = amélioration = vert)
        var pos = kpis.avg_position || {};
        var posVal = pos.value || 0;
        var posColor = !posVal ? 'var(--t3)' : posVal <= 3 ? 'var(--g)' : posVal <= 10 ? 'var(--o)' : 'var(--r)';
        h += '<div class="dash-kpi">';
        h += '<div class="dash-kpi-header"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="var(--acc)" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
        h += '<span class="dash-kpi-label">Position moyenne</span>';
        h += this._info('Position moyenne de toutes vos fiches dans le Local Pack Google. Plus le chiffre est bas, mieux c\'est. Top 3 = visible sans cliquer "Plus de résultats".');
        h += '</div>';
        h += '<div class="dash-kpi-value" style="color:' + posColor + '">' + (posVal || '—') + '</div>';
        h += '<div class="dash-kpi-trend">' + this._deltaAbs(pos.delta, false) + '<span class="dash-kpi-period">vs ' + pLabel + '</span></div>';
        h += '</div>';

        // 2. Mots-clés Top 3
        var top3 = kpis.top3_count || {};
        h += '<div class="dash-kpi">';
        h += '<div class="dash-kpi-header"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="var(--g)" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>';
        h += '<span class="dash-kpi-label">Mots-clés Top 3</span>';
        h += this._info('Nombre de mots-clés classés dans les 3 premières positions du Local Pack Google. Ces mots-clés sont directement visibles par les internautes.');
        h += '</div>';
        h += '<div class="dash-kpi-value" style="color:var(--g)">' + (top3.value || 0) + '<span class="dash-kpi-total"> / ' + (top3.total || 0) + '</span></div>';
        h += '<div class="dash-kpi-trend">' + this._deltaAbs(top3.delta) + '<span class="dash-kpi-period">vs ' + pLabel + '</span></div>';
        h += '</div>';

        // 3. Note moyenne
        var rating = kpis.avg_rating || {};
        var ratVal = rating.value || 0;
        var ratColor = !ratVal ? 'var(--t3)' : ratVal >= 4.5 ? 'var(--g)' : ratVal >= 3.5 ? 'var(--o)' : 'var(--r)';
        h += '<div class="dash-kpi">';
        h += '<div class="dash-kpi-header"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="var(--o)" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
        h += '<span class="dash-kpi-label">Note moyenne</span>';
        h += this._info('Note moyenne de toutes vos fiches Google. Au-dessus de 4.5 = excellent. En dessous de 4.0 = impact négatif sur le classement local.');
        h += '</div>';
        h += '<div class="dash-kpi-value" style="color:' + ratColor + '">' + (ratVal || '—') + '<span class="dash-kpi-total"> / 5</span></div>';
        h += '<div class="dash-kpi-trend">' + this._deltaAbs(rating.delta) + '<span class="dash-kpi-period">tendance ' + pLabel + '</span></div>';
        h += '</div>';

        // 4. Avis sans réponse (action KPI)
        var unans = kpis.unanswered_reviews || {};
        var unVal = unans.value || 0;
        var unColor = unVal > 0 ? 'var(--o)' : 'var(--g)';
        var unStroke = unVal > 0 ? 'var(--o)' : 'var(--g)';
        h += '<a href="?view=reviews-all" class="dash-kpi dash-kpi-link">';
        h += '<div class="dash-kpi-header"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="' + unStroke + '" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>';
        h += '<span class="dash-kpi-label">Avis sans réponse</span>';
        h += this._info('Nombre d\'avis Google auxquels vous n\'avez pas encore répondu. Google recommande de répondre à tous les avis sous 24h pour améliorer le classement local.');
        h += '</div>';
        h += '<div class="dash-kpi-value" style="color:' + unColor + '">' + unVal + '</div>';
        h += '<div class="dash-kpi-trend"><span class="dash-kpi-sub">' + (unans.new_period || 0) + ' nouveaux avis (' + pLabel + ')</span></div>';
        h += '</a>';

        el.innerHTML = h;
    },

    // ====== MONITORING (3 cards: Performance, Santé, Publications) ======
    _renderMonitor(d) {
        var el = document.getElementById('dash-monitor');
        var pLabel = this._periodLabel(d.period);
        var h = '';

        // Card 1: Performance Google
        h += '<div class="dash-card">';
        h += '<div class="dash-card-title"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="var(--acc)" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg> Performance Google';
        h += this._info('Données issues de l\'API Google Business Profile Performance. Google a un délai de 2-3 jours dans la remontée des statistiques. Les chiffres comparent 2 périodes équivalentes (hors 3 derniers jours).');
        if (d.performance && d.performance.last_date) {
            h += '<span class="dash-period">dernières données : ' + this._formatDate(d.performance.last_date) + '</span>';
        } else {
            h += '<span class="dash-period">' + pLabel + '</span>';
        }
        h += '</div>';

        var perfItems = [
            { key: 'impressions', label: 'Impressions', info: 'Nombre de fois où votre fiche est apparue dans les résultats Google Search et Google Maps.' },
            { key: 'direction_requests', label: 'Itinéraires', info: 'Nombre de demandes d\'itinéraire vers votre établissement depuis Google Maps.' },
            { key: 'call_clicks', label: 'Appels', info: 'Nombre de clics sur le bouton "Appeler" de votre fiche Google.' },
            { key: 'website_clicks', label: 'Clics site web', info: 'Nombre de clics vers votre site web depuis votre fiche Google.' },
            { key: 'conversations', label: 'Messages', info: 'Nombre de conversations initiées via la messagerie Google Business.' },
            { key: 'bookings', label: 'Réservations', info: 'Nombre de réservations effectuées via votre fiche Google.' }
        ];
        var hasPerf = false;
        if (d.performance) {
            perfItems.forEach(function(item) {
                var m = d.performance[item.key];
                if (m && (m.value > 0 || m.prev > 0)) hasPerf = true;
            });
        }

        if (hasPerf) {
            h += '<div class="dash-perf-list">';
            perfItems.forEach(function(item) {
                var m = d.performance[item.key];
                if (!m || (m.value === 0 && m.prev === 0)) return;
                h += '<div class="dash-perf-row">';
                h += '<span class="dash-perf-label">' + item.label + ' ' + APP.dashboard._info(item.info) + '</span>';
                h += '<span class="dash-perf-value">' + APP.dashboard._formatNum(m.value) + '</span>';
                h += APP.dashboard._deltaPct(m.value, m.prev);
                h += '</div>';
            });
            h += '</div>';
        } else {
            h += '<div class="dash-empty-hint"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="var(--t3)" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
            h += '<div>Pas encore de données de performance.</div></div>';
        }
        h += '</div>';

        // Card 2: Santé & Positions
        h += '<div class="dash-card">';
        h += '<div class="dash-card-title"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="var(--g)" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg> Santé &amp; Positions';
        h += this._info('Score de santé basé sur 3 critères : position SEO (Top 10 = 1 pt), avis répondus (0 en attente = 1 pt), activité posts (1 post/sem = 1 pt). Max = 3 points.');
        h += '</div>';

        var hth = d.health || {};
        var totalH = Math.max(1, (hth.good || 0) + (hth.mid || 0) + (hth.low || 0));
        var pGood = Math.round((hth.good || 0) / totalH * 100);
        var pMid = Math.round((hth.mid || 0) / totalH * 100);
        var pLow = Math.round((hth.low || 0) / totalH * 100);

        h += '<div class="dash-health-summary">';
        h += '<div class="dash-health-bar">';
        h += '<div class="dash-health-seg good" style="width:' + pGood + '%"></div>';
        h += '<div class="dash-health-seg mid" style="width:' + pMid + '%"></div>';
        h += '<div class="dash-health-seg low" style="width:' + pLow + '%"></div>';
        h += '</div>';
        h += '<div class="dash-health-legend">';
        h += '<span class="dash-hl-item"><span class="dash-hl-dot" style="background:var(--g)"></span> ' + (hth.good || 0) + ' bon' + ((hth.good || 0) > 1 ? 's' : '') + '</span>';
        h += '<span class="dash-hl-item"><span class="dash-hl-dot" style="background:var(--o)"></span> ' + (hth.mid || 0) + ' moyen' + ((hth.mid || 0) > 1 ? 's' : '') + '</span>';
        h += '<span class="dash-hl-item"><span class="dash-hl-dot" style="background:var(--r)"></span> ' + (hth.low || 0) + ' faible' + ((hth.low || 0) > 1 ? 's' : '') + '</span>';
        h += '</div></div>';

        // Position distribution
        var pd = d.position_distrib || {};
        var totalKw = Math.max(1, pd.total || 0);
        var segments = [
            { label: 'Top 3', val: pd.top3 || 0, color: 'var(--g)' },
            { label: '4–10', val: pd.top10 || 0, color: 'var(--acc)' },
            { label: '11–20', val: pd.top20 || 0, color: 'var(--o)' },
            { label: 'Hors 20', val: pd.out20 || 0, color: 'var(--r)' }
        ];
        h += '<div class="dash-pos-distrib"><div class="dash-pos-title">Répartition des ' + (pd.total || 0) + ' mots-clés ' + this._info('Distribution de vos mots-clés par tranche de position dans le Local Pack Google. Top 3 = visibles directement. 4-10 = page 1. 11-20 = page 2. Hors 20 = invisible.') + '</div>';
        h += '<div class="dash-pos-bars">';
        segments.forEach(function(seg) {
            var pct = Math.max(0, Math.round(seg.val / totalKw * 100));
            h += '<div class="dash-pos-bar-row"><span class="dash-pos-label">' + seg.label + '</span>';
            h += '<div class="dash-pos-bar-track"><div class="dash-pos-bar-fill" style="width:' + pct + '%;background:' + seg.color + '"></div></div>';
            h += '<span class="dash-pos-count">' + seg.val + '</span></div>';
        });
        h += '</div></div>';
        h += '</div>';

        // Card 3: Publications
        h += '<div class="dash-card">';
        h += '<div class="dash-card-title"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="var(--primary)" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> Publications';
        h += this._info('Activité de publication Google Posts sur l\'ensemble de vos fiches. Publier 1 post/semaine minimum par fiche améliore la visibilité dans le Local Pack.');
        h += '</div>';

        var pub = d.publications || {};
        h += '<div class="dash-pub-stats">';
        h += '<div class="dash-pub-item"><div class="dash-pub-value" style="color:var(--g)">' + (pub.published_period || 0) + '</div><div class="dash-pub-label">Publiés (' + pLabel + ')</div></div>';
        h += '<div class="dash-pub-item"><div class="dash-pub-value" style="color:var(--acc)">' + (pub.scheduled || 0) + '</div><div class="dash-pub-label">Programmés</div></div>';
        var failedColor = (pub.failed || 0) > 0 ? 'var(--r)' : 'var(--t3)';
        h += '<div class="dash-pub-item"><div class="dash-pub-value" style="color:' + failedColor + '">' + (pub.failed || 0) + '</div><div class="dash-pub-label">En erreur</div></div>';
        h += '</div>';

        // Delta publications
        if (pub.published_prev > 0 || pub.published_period > 0) {
            h += '<div style="font-size:12px;color:var(--t3);margin-bottom:12px;">' + this._deltaPct(pub.published_period, pub.published_prev) + ' vs période précédente</div>';
        }

        // Coverage
        var covPct = pub.coverage_pct || 0;
        var covColor = covPct >= 80 ? 'var(--g)' : covPct >= 50 ? 'var(--o)' : 'var(--r)';
        h += '<div class="dash-pub-coverage">';
        h += '<div class="dash-pub-cov-header"><span>Couverture publications ' + this._info('Pourcentage de fiches ayant au moins 1 post publié ou programmé sur la période. Objectif : 100%.') + '</span><span style="font-weight:700;color:' + covColor + '">' + covPct + '%</span></div>';
        h += '<div class="dash-pub-cov-bar"><div style="width:' + covPct + '%;background:' + covColor + '"></div></div>';
        h += '<div class="dash-pub-cov-sub">' + (pub.active_locations || 0) + ' fiche' + ((pub.active_locations || 0) > 1 ? 's' : '') + ' active' + ((pub.active_locations || 0) > 1 ? 's' : '') + ' / ' + ((pub.total_locations || 0) - (pub.active_locations || 0)) + ' inactive' + (((pub.total_locations || 0) - (pub.active_locations || 0)) > 1 ? 's' : '') + '</div>';
        h += '</div>';
        h += '</div>';

        el.innerHTML = h;
    },

    // ====== DERNIERS AVIS (temps réel) ======
    _renderReviews(reviews) {
        var el = document.getElementById('dash-reviews');
        if (!reviews || !reviews.length) { el.innerHTML = ''; return; }

        var h = '<div class="dash-card dash-recent-reviews">';
        h += '<div class="dash-card-title"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="var(--o)" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
        h += ' Derniers avis reçus ';
        h += this._info('Les 5 derniers avis Google reçus sur l\'ensemble de vos fiches. Répondez rapidement aux avis pour améliorer votre e-réputation.');
        h += '<a href="?view=reviews-all" class="dash-card-link-all">Tout voir &rarr;</a></div>';
        h += '<div class="dash-review-list">';

        reviews.forEach(function(rev) {
            h += '<div class="dash-review-item">';
            // Stars
            h += '<div class="dash-review-stars">';
            for (var i = 1; i <= 5; i++) {
                h += '<span style="color:' + (i <= rev.rating ? 'var(--o)' : 'var(--bdr)') + '">★</span>';
            }
            h += '</div>';
            // Body
            h += '<div class="dash-review-body">';
            h += '<span class="dash-review-name">' + APP.dashboard._esc(rev.reviewer_name || 'Anonyme') + '</span>';
            if (rev.comment) {
                var txt = rev.comment.length > 80 ? rev.comment.substring(0, 80) + '…' : rev.comment;
                h += '<span class="dash-review-text">' + APP.dashboard._esc(txt) + '</span>';
            }
            h += '</div>';
            // Meta
            h += '<div class="dash-review-meta">';
            h += '<span class="dash-review-loc">' + APP.dashboard._esc(rev.location_name) + '</span>';
            if (!rev.is_replied) {
                h += '<a href="?view=client&location=' + rev.location_id + '&tab=reviews" class="dash-review-reply">Répondre</a>';
            } else {
                h += '<span class="dash-review-replied">✓ Répondu</span>';
            }
            h += '</div></div>';
        });

        h += '</div></div>';
        el.innerHTML = h;
    },

    // ====== OVERVIEW BAR ======
    _renderOverview(ov) {
        var el = document.getElementById('dash-overview');
        if (!ov) { el.style.display = 'none'; return; }
        el.style.display = '';
        el.innerHTML = '<div class="dash-ov-item"><span class="dash-ov-num">' + (ov.total_locations || 0) + '</span><span class="dash-ov-label">Fiches actives</span></div>'
            + '<div class="dash-ov-sep"></div>'
            + '<div class="dash-ov-item"><span class="dash-ov-num">' + (ov.total_keywords || 0) + '</span><span class="dash-ov-label">Mots-clés suivis</span></div>'
            + '<div class="dash-ov-sep"></div>'
            + '<div class="dash-ov-item"><span class="dash-ov-num">' + (ov.total_reviews || 0) + '</span><span class="dash-ov-label">Avis au total</span></div>'
            + '<div class="dash-ov-sep"></div>'
            + '<div class="dash-ov-item"><a href="?view=locations" style="color:var(--acc);text-decoration:none;font-size:13px;font-weight:600;">Gérer les fiches &rarr;</a></div>';
    },

    // ====== SKELETON LOADING ======
    _renderSkeleton() {
        var skel = '<div class="dash-skeleton-bar" style="width:60%"></div><div class="dash-skeleton-bar" style="width:40%"></div>';

        // KPI skeleton
        var kpiH = '';
        for (var i = 0; i < 4; i++) {
            kpiH += '<div class="dash-kpi dash-kpi-skeleton"><div class="dash-skeleton-bar" style="width:70%;height:12px;margin-bottom:12px;"></div><div class="dash-skeleton-bar" style="width:40%;height:28px;margin-bottom:8px;"></div><div class="dash-skeleton-bar" style="width:50%;height:10px;"></div></div>';
        }
        document.getElementById('dash-kpis').innerHTML = kpiH;

        // Monitor skeleton
        var monH = '';
        for (var j = 0; j < 3; j++) {
            monH += '<div class="dash-card dash-card-skeleton"><div class="dash-skeleton-bar" style="width:50%;height:14px;margin-bottom:16px;"></div>';
            for (var k = 0; k < 4; k++) {
                monH += '<div class="dash-skeleton-bar" style="width:' + (60 + Math.random() * 30) + '%;height:10px;margin-bottom:8px;"></div>';
            }
            monH += '</div>';
        }
        document.getElementById('dash-monitor').innerHTML = monH;
    },

    // ====== ÉTATS ======
    _renderEmpty() {
        document.getElementById('dash-kpis').innerHTML = '<div class="dash-empty-state"><svg viewBox="0 0 24 24" style="width:48px;height:48px;stroke:var(--acc);fill:none;stroke-width:1.5;opacity:.4"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0116 0z"/><circle cx="12" cy="10" r="3"/></svg><div style="font-size:16px;font-weight:700;color:var(--t1);margin-top:12px;">Aucune donnée disponible</div><div style="color:var(--t2);font-size:13px;margin-top:6px;">Connectez vos fiches pour alimenter le dashboard.</div></div>';
        document.getElementById('dash-monitor').innerHTML = '';
        document.getElementById('dash-reviews').innerHTML = '';
        document.getElementById('dash-overview').style.display = 'none';
        document.getElementById('dash-alerts').innerHTML = '';
    },

    _renderError() {
        document.getElementById('dash-error').style.display = '';
        document.getElementById('dash-kpis').innerHTML = '';
        document.getElementById('dash-monitor').innerHTML = '';
        document.getElementById('dash-reviews').innerHTML = '';
        document.getElementById('dash-overview').style.display = 'none';
        document.getElementById('dash-alerts').innerHTML = '';
    },

    // ====== HELPERS ======
    _periodLabel(p) {
        var labels = { 7: '7j', 30: '30j', 90: '90j', 180: '6 mois' };
        return labels[p] || p + 'j';
    },

    _deltaAbs(diff, inverse) {
        if (diff === null || diff === undefined || diff === 0) return '<span class="dash-delta neutral">&rarr; stable</span>';
        var effective = inverse ? -diff : diff;
        var cls = effective > 0 ? 'up' : 'down';
        var arrow = effective > 0 ? '↑' : '↓';
        var sign = diff > 0 ? '+' : '';
        return '<span class="dash-delta ' + cls + '">' + arrow + ' ' + sign + diff + '</span>';
    },

    _deltaPct(val, prev) {
        if (!prev && !val) return '<span class="dash-delta neutral">&mdash;</span>';
        if (!prev) return '<span class="dash-delta neutral">nouveau</span>';
        var diff = val - prev;
        var pct = prev > 0 ? Math.round((diff / prev) * 100) : 0;
        var cls = diff > 0 ? 'up' : (diff < 0 ? 'down' : 'neutral');
        var arrow = diff > 0 ? '↑' : (diff < 0 ? '↓' : '→');
        var sign = pct > 0 ? '+' : '';
        return '<span class="dash-delta ' + cls + '">' + arrow + ' ' + sign + pct + '%</span>';
    },

    _formatNum(n) {
        if (n === null || n === undefined) return '0';
        return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    },

    _formatDate(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr);
        return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
    },

    _esc(str) {
        if (!str) return '';
        var el = document.createElement('span');
        el.textContent = str;
        return el.innerHTML;
    },

    _info(text) {
        return '<span class="dash-info" tabindex="0"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg><span class="dash-info-tip">' + text + '</span></span>';
    }
};

// ====================================================================
// PHASE 1 — DASHBOARD SEARCH / SORT / FILTER (locations view)
// ====================================================================
APP.dashboardFilter = function() {
    const search = (document.getElementById('dashboard-search')?.value || '').toLowerCase();
    const filterStatus = document.getElementById('dashboard-filter-status')?.value || 'all';
    const cards = document.querySelectorAll('#client-grid .client-card');
    cards.forEach(card => {
        const name = card.dataset.name || '';
        const unanswered = parseInt(card.dataset.unanswered) || 0;
        const health = parseFloat(card.dataset.health) || 0;
        const hasPosts = card.dataset.hasPosts === '1';
        let show = true;
        if (search && !name.includes(search)) show = false;
        if (filterStatus === 'unanswered' && unanswered === 0) show = false;
        if (filterStatus === 'low-health' && health >= 2) show = false;
        if (filterStatus === 'no-posts' && hasPosts) show = false;
        card.style.display = show ? '' : 'none';
    });
};

APP.dashboardSort = function() {
    const sortBy = document.getElementById('dashboard-sort')?.value || 'name';
    const grid = document.getElementById('client-grid');
    if (!grid) return;
    const cards = Array.from(grid.querySelectorAll('.client-card'));
    cards.sort((a, b) => {
        switch (sortBy) {
            case 'name': return (a.dataset.name || '').localeCompare(b.dataset.name || '');
            case 'health': return (parseFloat(b.dataset.health) || 0) - (parseFloat(a.dataset.health) || 0);
            case 'position': return (parseFloat(a.dataset.position) || 99) - (parseFloat(b.dataset.position) || 99);
            case 'unanswered': return (parseInt(b.dataset.unanswered) || 0) - (parseInt(a.dataset.unanswered) || 0);
            case 'rating': return (parseFloat(b.dataset.rating) || 0) - (parseFloat(a.dataset.rating) || 0);
            default: return 0;
        }
    });
    cards.forEach(card => grid.appendChild(card));
};

// ====================================================================
// PHASE 1 — SIDE PANEL (generic)
// ====================================================================
APP.sidePanel = {
    _overlay: null,
    _panel: null,

    open(title, bodyHtml, footerHtml, linksHtml) {
        this.close();
        const overlay = document.createElement('div');
        overlay.className = 'side-panel-overlay active';
        overlay.onclick = () => this.close();
        document.body.appendChild(overlay);
        this._overlay = overlay;

        const panel = document.createElement('div');
        panel.className = 'side-panel';
        let h = `<div class="side-panel-header"><div style="font-weight:700;font-size:16px;">${title}</div><button class="side-panel-close" onclick="APP.sidePanel.close()">&times;</button></div>`;
        h += `<div class="side-panel-body">${bodyHtml}</div>`;
        if (linksHtml) h += `<div class="side-panel-links">${linksHtml}</div>`;
        if (footerHtml) h += `<div class="side-panel-footer">${footerHtml}</div>`;
        panel.innerHTML = h;
        document.body.appendChild(panel);
        this._panel = panel;
        requestAnimationFrame(() => { panel.classList.add('open'); });
        this._escHandler = (e) => { if (e.key === 'Escape') this.close(); };
        document.addEventListener('keydown', this._escHandler);
    },

    close() {
        if (this._panel) { this._panel.classList.remove('open'); setTimeout(() => { this._panel?.remove(); }, 300); this._panel = null; }
        if (this._overlay) { this._overlay.classList.remove('active'); setTimeout(() => { this._overlay?.remove(); }, 300); this._overlay = null; }
        if (this._escHandler) { document.removeEventListener('keydown', this._escHandler); this._escHandler = null; }
    }
};

// ====================================================================
// PREMIUM UX — Command Palette (Cmd+K / Ctrl+K)
// ====================================================================
APP.commandPalette = {
    _visible: false,
    _activeIndex: 0,
    _filtered: [],

    init() {
        document.addEventListener('keydown', e => {
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                this.toggle();
            }
        });
    },

    _getCommands() {
        const cmds = [];
        const params = new URLSearchParams(window.location.search);
        const view = params.get('view') || 'dashboard';
        const locationId = params.get('location');

        // Global navigation
        cmds.push({ group: 'Navigation', label: 'Dashboard', icon: '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>', action: () => { window.location.href = '?view=dashboard'; } });
        cmds.push({ group: 'Navigation', label: 'Fiches GBP', icon: '<svg viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0116 0z"/><circle cx="12" cy="10" r="3"/></svg>', action: () => { window.location.href = '?view=locations'; } });
        cmds.push({ group: 'Navigation', label: 'Tous les avis', icon: '<svg viewBox="0 0 24 24"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>', action: () => { window.location.href = '?view=reviews-all'; } });
        cmds.push({ group: 'Navigation', label: 'Rapports', icon: '<svg viewBox="0 0 24 24"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg>', action: () => { window.location.href = '?view=reports'; } });
        cmds.push({ group: 'Navigation', label: 'Paramètres', icon: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09"/></svg>', action: () => { window.location.href = '?view=settings'; } });

        // Client tabs (if in client view)
        if (locationId) {
            const tabs = [
                { label: 'Mots-cles', tab: 'keywords', icon: '<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' },
                { label: 'Carte de position', tab: 'position-map', icon: '<svg viewBox="0 0 24 24"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/></svg>' },
                { label: 'Concurrents', tab: 'competitors', icon: '<svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4-4v2"/><circle cx="9" cy="7" r="4"/></svg>' },
                { label: 'Avis client', tab: 'reviews', icon: '<svg viewBox="0 0 24 24"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7"/></svg>' },
                { label: 'Statistiques', tab: 'stats', icon: '<svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>' },
                { label: 'Posts', tab: 'posts', icon: '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>' },
                { label: 'Listes auto', tab: 'post-lists', icon: '<svg viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/></svg>' },
                { label: 'Visuels', tab: 'post-visuals', icon: '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>' },
            ];
            tabs.forEach(t => {
                cmds.push({ group: 'Onglets client', label: t.label, icon: t.icon, action: () => { window.location.href = `?view=client&location=${locationId}&tab=${t.tab}`; } });
            });
        }

        // Theme toggle
        cmds.push({ group: 'Actions', label: 'Basculer theme sombre / clair', icon: '<svg viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>', action: () => { APP.toggleTheme(); } });

        // Clients quick-switch
        const selector = document.querySelector('.sb-client-selector, .csel');
        if (selector) {
            Array.from(selector.options).forEach(opt => {
                if (opt.value) {
                    cmds.push({ group: 'Clients', label: opt.text, icon: '<svg viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0116 0z"/><circle cx="12" cy="10" r="3"/></svg>', action: () => { window.location.href = `?view=client&location=${opt.value}&tab=keywords`; } });
                }
            });
        }

        return cmds;
    },

    toggle() { this._visible ? this.close() : this.open(); },

    open() {
        if (this._visible) return;
        this._visible = true;
        this._activeIndex = 0;
        const commands = this._getCommands();
        this._filtered = commands;

        const overlay = document.createElement('div');
        overlay.className = 'cmd-palette-overlay';
        overlay.onclick = (e) => { if (e.target === overlay) this.close(); };

        let h = '<div class="cmd-palette">';
        h += '<div class="cmd-palette-search"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
        h += '<input class="cmd-palette-input" placeholder="Rechercher une action..." autofocus>';
        h += '<span class="cmd-palette-kbd">ESC</span></div>';
        h += '<div class="cmd-palette-list"></div>';
        h += '<div class="cmd-palette-footer"><span><kbd>&uarr;&darr;</kbd> Naviguer</span><span><kbd>Enter</kbd> Ouvrir</span><span><kbd>Esc</kbd> Fermer</span></div>';
        h += '</div>';
        overlay.innerHTML = h;
        document.body.appendChild(overlay);
        this._overlay = overlay;

        this._renderList(commands);

        const input = overlay.querySelector('.cmd-palette-input');
        input.focus();
        input.addEventListener('input', () => {
            const q = input.value.toLowerCase().trim();
            this._filtered = q ? commands.filter(c => c.label.toLowerCase().includes(q) || c.group.toLowerCase().includes(q)) : commands;
            this._activeIndex = 0;
            this._renderList(this._filtered);
        });

        this._keyHandler = (e) => {
            if (e.key === 'Escape') { this.close(); return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); this._activeIndex = Math.min(this._activeIndex + 1, this._filtered.length - 1); this._highlightActive(); }
            if (e.key === 'ArrowUp') { e.preventDefault(); this._activeIndex = Math.max(this._activeIndex - 1, 0); this._highlightActive(); }
            if (e.key === 'Enter' && this._filtered[this._activeIndex]) { e.preventDefault(); this.close(); this._filtered[this._activeIndex].action(); }
        };
        document.addEventListener('keydown', this._keyHandler);
    },

    _renderList(cmds) {
        const list = this._overlay?.querySelector('.cmd-palette-list');
        if (!list) return;
        if (!cmds.length) { list.innerHTML = '<div class="cmd-palette-empty">Aucun resultat</div>'; return; }
        let h = '', lastGroup = '';
        cmds.forEach((c, i) => {
            if (c.group !== lastGroup) { h += `<div class="cmd-palette-group">${c.group}</div>`; lastGroup = c.group; }
            h += `<div class="cmd-palette-item${i === this._activeIndex ? ' active' : ''}" data-idx="${i}">${c.icon}<span>${c.label}</span></div>`;
        });
        list.innerHTML = h;
        list.querySelectorAll('.cmd-palette-item').forEach(el => {
            el.addEventListener('click', () => { const idx = parseInt(el.dataset.idx); this.close(); cmds[idx].action(); });
            el.addEventListener('mouseenter', () => { this._activeIndex = parseInt(el.dataset.idx); this._highlightActive(); });
        });
    },

    _highlightActive() {
        const items = this._overlay?.querySelectorAll('.cmd-palette-item');
        if (!items) return;
        items.forEach((el, i) => { el.classList.toggle('active', i === this._activeIndex); });
        items[this._activeIndex]?.scrollIntoView({ block: 'nearest' });
    },

    close() {
        if (!this._visible) return;
        this._visible = false;
        if (this._overlay) { this._overlay.remove(); this._overlay = null; }
        if (this._keyHandler) { document.removeEventListener('keydown', this._keyHandler); this._keyHandler = null; }
    }
};

// Init command palette on DOM ready
document.addEventListener('DOMContentLoaded', () => { APP.commandPalette.init(); });

// ====================================================================
// MODULE : PHOTOS GBP
// ====================================================================
APP.photos = {
    _locationId: null,
    _photos: [],
    _selectMode: false,
    _selectedIds: new Set(),
    _filter: '',

    async load(locationId) {
        this._locationId = locationId;
        const c = document.getElementById('module-content'); if (!c) return;
        c.innerHTML = '<div style="text-align:center;padding:60px;"><div class="loader"></div></div>';
        try {
            const r = await fetch(`api/photos.php?action=list&location_id=${locationId}`);
            const d = await r.json();
            if (d.error) { c.innerHTML = `<div class="empty-state">${d.error}</div>`; return; }
            this._photos = d.photos || [];
            this.render(d.photos, d.stats, locationId);
        } catch (e) {
            c.innerHTML = '<div class="empty-state">Erreur chargement photos</div>';
        }
    },

    render(photos, stats, locationId) {
        const c = document.getElementById('module-content'); if (!c) return;
        if (stats) this._stats = stats;
        const s = this._stats || {};
        const total = parseInt(s.total || 0);
        const published = parseInt(s.published || 0);
        const draft = parseInt(s.draft || 0);
        const failed = parseInt(s.failed || 0);
        const selCount = this._selectedIds.size;

        const catégories = [
            {v:'',l:'Toutes'},{v:'COVER',l:'Couverture'},{v:'PROFILE',l:'Profil'},
            {v:'EXTERIOR',l:'Extérieur'},{v:'INTERIOR',l:'Intérieur'},
            {v:'PRODUCT',l:'Produits'},{v:'AT_WORK',l:'En action'},{v:'FOOD_AND_DRINK',l:'Plats'},
            {v:'TEAMS',l:'Équipe'},{v:'ADDITIONAL',l:'Autres'}
        ];
        const catLabels = {COVER:'Couverture',PROFILE:'Profil',EXTERIOR:'Extérieur',INTERIOR:'Intérieur',PRODUCT:'Produits',AT_WORK:'En action',FOOD_AND_DRINK:'Plats',TEAMS:'Équipe',ADDITIONAL:'Autres',MENU:'Menu',COMMON_AREA:'Espaces',ROOMS:'Chambres'};

        let filtered = photos;
        if (this._filter) filtered = photos.filter(p => p.category === this._filter);

        // Header
        let h = `<div class="sh" style="flex-wrap:wrap;gap:12px;">
            <div class="stit">PHOTOS GBP</div>
            <div style="display:flex;gap:10px;align-items:center;">
                <button class="btn bp bsm" onclick="APP.photos.showUploadForm(${locationId})">
                    <svg viewBox="0 0 24 24"><path d="M12 4v16m8-8H4"/></svg> Ajouter des photos
                </button>`;
        if (total > 0 && !this._selectMode) {
            h += `<button class="btn bs bsm" onclick="APP.photos.toggleSelectMode()">☐ Sélectionner</button>`;
        }
        if (this._selectMode) {
            h += `<button class="btn bs bsm" onclick="APP.photos.toggleSelectMode()">✕ Annuler</button>`;
        }
        h += `</div></div>`;

        // Stats bar
        h += `<div class="photos-stats">
            <div class="photos-stat"><span class="photos-stat-n">${total}</span><span class="photos-stat-l">Total</span></div>
            <div class="photos-stat"><span class="photos-stat-n" style="color:var(--g);">${published}</span><span class="photos-stat-l">Publiées</span></div>
            <div class="photos-stat"><span class="photos-stat-n" style="color:var(--o);">${draft}</span><span class="photos-stat-l">Brouillons</span></div>
            ${failed > 0 ? `<div class="photos-stat"><span class="photos-stat-n" style="color:var(--r);">${failed}</span><span class="photos-stat-l">Échouées</span></div>` : ''}
        </div>`;

        // Filter pills
        h += `<div class="photos-filters">`;
        for (const cat of catégories) {
            const active = this._filter === cat.v;
            h += `<button class="photos-filter-btn${active ? ' act' : ''}" onclick="APP.photos._filter='${cat.v}';APP.photos.render(APP.photos._photos,null,${locationId})">${cat.l}</button>`;
        }
        h += `</div>`;

        // Bulk bar (select mode)
        if (this._selectMode) {
            h += `<div class="photos-bulk-bar">
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;color:var(--t2);">
                    <input type="checkbox" ${selCount === filtered.length && filtered.length > 0 ? 'checked' : ''} onchange="APP.photos.${selCount === filtered.length && filtered.length > 0 ? 'deselectAll' : 'selectAll'}()" style="accent-color:var(--primary);width:16px;height:16px;">
                    Tout sélectionner
                </label>
                <span style="font-size:12px;color:var(--primary);font-weight:600;">${selCount} sélectionné${selCount > 1 ? 's' : ''}</span>`;
            if (selCount > 0) {
                h += `<span style="width:1px;height:20px;background:var(--bdr);margin:0 4px;"></span>
                    <button class="btn bs bsm" onclick="APP.photos.bulkDelete(${locationId})" style="font-size:11px;color:var(--r);border-color:var(--r);">
                        <svg viewBox="0 0 24 24" style="width:12px;height:12px;stroke:var(--r);fill:none;stroke-width:2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                        Supprimer (${selCount})</button>`;
            }
            h += `</div>`;
        }

        // Grid
        if (filtered.length === 0) {
            if (total === 0) {
                h += `<div class="photos-empty">
                    <svg class="photos-empty-icon" viewBox="0 0 24 24"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    <h3>Aucune photo</h3>
                    <p>Ajoutez des photos pour enrichir votre fiche Google Business et améliorer votre visibilité SEO locale.</p>
                    <button class="btn bp" onclick="APP.photos.showUploadForm(${locationId})"><svg viewBox="0 0 24 24"><path d="M12 4v16m8-8H4"/></svg> Ajouter des photos</button>
                </div>`;
            } else {
                h += `<div style="padding:60px 20px;text-align:center;color:var(--t3);font-size:14px;">Aucune photo dans cette catégorie</div>`;
            }
        } else {
            h += `<div class="photos-grid">`;
            for (const p of filtered) {
                const selected = this._selectedIds.has(p.id);
                const catLabel = catLabels[p.category] || p.category;
                const statusBadge = p.status === 'published' ? '<span class="photo-badge pub">Publiée</span>'
                    : p.status === 'failed' ? '<span class="photo-badge fail">Échec</span>'
                    : '<span class="photo-badge draft">Brouillon</span>';
                const seoTag = p.seo_keyword ? `<div class="photo-seo">${this._esc(p.seo_keyword)}</div>` : '';

                h += `<div class="photo-card${selected ? ' selected' : ''}" data-id="${p.id}"
                    ${this._selectMode ? `onclick="APP.photos.toggleSelect(${p.id})" style="cursor:pointer;"` : ''}>
                    <div class="photo-img" style="background-image:url('${p.file_url}')">
                        ${this._selectMode ? `<div class="photo-check">${selected ? '✓' : ''}</div>` : ''}
                        ${statusBadge}
                    </div>
                    <div class="photo-body">
                        <div class="photo-cat">${catLabel}</div>
                        ${seoTag}
                        ${p.caption ? `<div class="photo-caption">${this._esc(p.caption)}</div>` : ''}
                        <div class="photo-meta">${this._formatSize(p.file_size)}${p.width ? ` · ${p.width}×${p.height}` : ''}</div>
                    </div>
                    ${!this._selectMode ? `<div class="photo-actions">
                        ${p.status !== 'published' ? `<button class="pa-btn pa-pub" onclick="event.stopPropagation();APP.photos.publish(${p.id},${locationId})">
                            <svg viewBox="0 0 24 24"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></svg> Publier
                        </button>` : ''}
                        <button class="pa-btn" onclick="event.stopPropagation();APP.photos.showEditForm(${p.id},${locationId})">
                            <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Modifier
                        </button>
                        <button class="pa-btn pa-del" onclick="event.stopPropagation();APP.photos.deletePhoto(${p.id},${locationId})">
                            <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                        </button>
                    </div>` : ''}
                </div>`;
            }
            h += `</div>`;
        }

        // SEO URL info box
        if (filtered.length > 0) {
            const sample = filtered[0];
            h += `<div class="photos-seo-box">
                <svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
                <div>
                    <strong style="color:var(--primary);">URLs SEO optimisées</strong> — Chaque photo est servie avec une URL structurée pour le référencement.
                    <code>${sample.file_url}</code>
                </div>
            </div>`;
        }

        c.innerHTML = h;
    },

    selectAll() {
        this._photos.forEach(p => { if (!this._filter || p.category === this._filter) this._selectedIds.add(p.id); });
        this.render(this._photos, null, this._locationId);
    },
    deselectAll() {
        this._selectedIds.clear();
        this.render(this._photos, null, this._locationId);
    },

    showUploadForm(locationId) {
        const catégories = [
            {v:'EXTERIOR',l:'Extérieur'},{v:'INTERIOR',l:'Intérieur'},{v:'PRODUCT',l:'Produits'},
            {v:'AT_WORK',l:'En action'},{v:'FOOD_AND_DRINK',l:'Plats & boissons'},{v:'TEAMS',l:'Équipe'},
            {v:'ADDITIONAL',l:'Autres'}
        ];
        let catOpts = catégories.map(c => `<option value="${c.v}">${c.l}</option>`).join('');

        const bodyHtml = `
            <div class="fg">
                <label class="fl">Photos</label>
                <div id="photo-dropzone" class="photo-dropzone">
                    <svg class="dz-icon" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <div style="font-size:14px;font-weight:500;color:var(--t1);">Glissez vos photos ici</div>
                    <div style="font-size:12px;color:var(--t3);margin-top:4px;">ou <span style="color:var(--primary);font-weight:500;cursor:pointer;">parcourez</span> vos fichiers</div>
                    <div style="font-size:11px;color:var(--t3);margin-top:8px;opacity:.7;">JPG, PNG, WEBP — max 10 Mo par fichier</div>
                    <input type="file" id="photo-files" multiple accept="image/jpeg,image/png,image/webp" style="display:none">
                </div>
                <div id="photo-preview-list" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;"></div>
            </div>
            <div style="border-top:1px solid var(--bdr);margin:4px 0 18px;"></div>
            <div class="fg">
                <label class="fl">Mot-clé SEO <span style="font-weight:400;color:var(--t3);">(pour l'URL)</span></label>
                <input type="text" id="photo-seo-kw" class="fi" placeholder="ex: plombier-brive, restaurant-terrasse...">
                <span class="fl-hint">L'URL sera : /media/votre-fiche/<strong style="color:var(--primary);">mot-clé</strong>/photo.jpg</span>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div class="fg" style="margin-bottom:0;">
                    <label class="fl">Catégorie GBP</label>
                    <select id="photo-category" class="fi">${catOpts}</select>
                </div>
                <div class="fg" style="margin-bottom:0;">
                    <label class="fl">Légende <span style="font-weight:400;color:var(--t3);">(opt.)</span></label>
                    <input type="text" id="photo-caption" class="fi" placeholder="Description courte...">
                </div>
            </div>
            <div id="photo-upload-progress" style="display:none;margin-top:20px;">
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                    <span style="font-size:12px;font-weight:500;color:var(--t2);">Upload en cours</span>
                    <span id="photo-progress-text" style="font-size:12px;color:var(--primary);font-weight:600;font-family:'Space Mono',monospace;">0%</span>
                </div>
                <div style="height:6px;background:var(--bdr);border-radius:3px;overflow:hidden;">
                    <div id="photo-progress-bar" style="height:100%;background:var(--primary);width:0%;border-radius:3px;transition:width .3s ease;"></div>
                </div>
            </div>`;

        const footerHtml = `<button class="btn bp" id="photo-upload-btn" onclick="APP.photos.doUpload(${locationId})" style="flex:1;justify-content:center;">
            <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            Uploader les photos</button>`;
        APP.sidePanel.open('Ajouter des photos', bodyHtml, footerHtml);
        this._initDropzone();
    },

    _initDropzone() {
        const dz = document.getElementById('photo-dropzone');
        const input = document.getElementById('photo-files');
        if (!dz || !input) return;

        dz.onclick = () => input.click();
        dz.ondragover = (e) => { e.preventDefault(); dz.classList.add('dragover'); };
        dz.ondragleave = () => dz.classList.remove('dragover');
        dz.ondrop = (e) => {
            e.preventDefault(); dz.classList.remove('dragover');
            input.files = e.dataTransfer.files;
            this._showPreviews(input.files);
        };
        input.onchange = () => this._showPreviews(input.files);
    },

    _showPreviews(files) {
        const list = document.getElementById('photo-preview-list');
        if (!list) return;
        list.innerHTML = '';
        for (const f of files) {
            const url = URL.createObjectURL(f);
            const size = f.size < 1048576 ? Math.round(f.size / 1024) + ' Ko' : (f.size / 1048576).toFixed(1) + ' Mo';
            list.innerHTML += `<div style="width:72px;flex-shrink:0;text-align:center;">
                <div style="width:72px;height:72px;border-radius:var(--rd-sm);overflow:hidden;background:var(--inp);border:1px solid var(--bdr);">
                    <img src="${url}" style="width:100%;height:100%;object-fit:cover;">
                </div>
                <div style="font-size:10px;color:var(--t3);margin-top:3px;font-family:'Space Mono',monospace;">${size}</div>
            </div>`;
        }
        // Update the upload button text
        const btn = document.getElementById('photo-upload-btn');
        if (btn) btn.innerHTML = `<svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Uploader ${files.length} photo${files.length > 1 ? 's' : ''}`;
    },

    async doUpload(locationId) {
        const input = document.getElementById('photo-files');
        const seoKw = document.getElementById('photo-seo-kw')?.value?.trim() || '';
        const category = document.getElementById('photo-category')?.value || 'ADDITIONAL';
        const caption = document.getElementById('photo-caption')?.value?.trim() || '';
        const btn = document.getElementById('photo-upload-btn');
        const progress = document.getElementById('photo-upload-progress');
        const bar = document.getElementById('photo-progress-bar');
        const text = document.getElementById('photo-progress-text');

        if (!input?.files?.length) { APP.toast('Sélectionnez au moins une photo', 'error'); return; }

        APP.btnLoading(btn, true);
        if (progress) progress.style.display = 'block';

        const fd = new FormData();
        fd.append('action', 'upload');
        fd.append('location_id', locationId);
        fd.append('seo_keyword', seoKw);
        fd.append('category', category);
        fd.append('caption', caption);
        for (const f of input.files) fd.append('photos[]', f);

        try {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'api/photos.php');
            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable && bar) {
                    const pct = Math.round((e.loaded / e.total) * 100);
                    bar.style.width = pct + '%';
                    if (text) text.textContent = pct + '%';
                }
            };
            xhr.onload = () => {
                try {
                    const d = JSON.parse(xhr.responseText);
                    if (d.success) {
                        APP.toast(`${d.count} photo${d.count > 1 ? 's' : ''} uploadée${d.count > 1 ? 's' : ''} avec succès`, 'success');
                        APP.sidePanel.close();
                        this.load(locationId);
                    } else {
                        APP.toast(d.error || 'Erreur upload', 'error');
                        APP.btnLoading(btn, false);
                    }
                } catch (e) {
                    APP.toast('Erreur parsing réponse', 'error');
                    APP.btnLoading(btn, false);
                }
            };
            xhr.onerror = () => {
                APP.toast('Erreur réseau', 'error');
                APP.btnLoading(btn, false);
            };
            xhr.send(fd);
        } catch (e) {
            APP.toast('Erreur upload', 'error');
            APP.btnLoading(btn, false);
        }
    },

    async publish(photoId, locationId) {
        if (!await APP.modal.confirm(
            'Publier sur Google',
            'Cette photo sera publiée sur votre fiche Google Business Profile et sera visible publiquement.',
            'Publier'
        )) return;
        const btn = event?.target;
        if (btn) APP.btnLoading(btn, true);
        try {
            const fd = new FormData();
            fd.append('action', 'publish');
            fd.append('id', photoId);
            fd.append('location_id', locationId);
            const r = await fetch('api/photos.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (d.success) {
                APP.toast('Photo publiée sur Google !', 'success');
                this.load(locationId);
            } else {
                APP.toast(d.error || 'Erreur publication', 'error');
                if (btn) APP.btnLoading(btn, false);
            }
        } catch (e) {
            APP.toast('Erreur réseau', 'error');
            if (btn) APP.btnLoading(btn, false);
        }
    },

    showEditForm(photoId, locationId) {
        const p = this._photos.find(x => x.id == photoId);
        if (!p) return;
        const catégories = [
            {v:'COVER',l:'Couverture'},{v:'PROFILE',l:'Profil'},
            {v:'EXTERIOR',l:'Extérieur'},{v:'INTERIOR',l:'Intérieur'},{v:'PRODUCT',l:'Produits'},
            {v:'AT_WORK',l:'En action'},{v:'FOOD_AND_DRINK',l:'Plats & boissons'},{v:'TEAMS',l:'Équipe'},
            {v:'ADDITIONAL',l:'Autres'}
        ];
        const catLabels = {COVER:'Couverture',PROFILE:'Profil',EXTERIOR:'Extérieur',INTERIOR:'Intérieur',PRODUCT:'Produits',AT_WORK:'En action',FOOD_AND_DRINK:'Plats',TEAMS:'Équipe',ADDITIONAL:'Autres'};
        let catOpts = catégories.map(c => `<option value="${c.v}" ${p.category===c.v?'selected':''}>${c.l}</option>`).join('');

        const statusLabel = p.status === 'published' ? '<span style="color:var(--g);font-weight:600;">Publiée</span>'
            : p.status === 'failed' ? '<span style="color:var(--r);font-weight:600;">Échec</span>'
            : '<span style="color:var(--o);font-weight:600;">Brouillon</span>';

        const bodyHtml = `
            <div style="border-radius:var(--rd);overflow:hidden;margin-bottom:20px;background:var(--inp);position:relative;">
                <img src="${p.file_url}" style="width:100%;display:block;object-fit:contain;max-height:280px;">
                <div style="position:absolute;bottom:0;left:0;right:0;padding:10px 14px;background:linear-gradient(to top,rgba(0,0,0,.6),transparent);display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-size:11px;color:rgba(255,255,255,.8);font-family:'Space Mono',monospace;">${this._formatSize(p.file_size)}${p.width ? ` · ${p.width}×${p.height}` : ''}</span>
                    ${statusLabel}
                </div>
            </div>
            <div class="fg">
                <label class="fl">Mot-clé SEO</label>
                <input type="text" id="edit-photo-seo" class="fi" value="${this._esc(p.seo_keyword||'')}" placeholder="ex: plombier-brive">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div class="fg" style="margin-bottom:0;">
                    <label class="fl">Catégorie</label>
                    <select id="edit-photo-cat" class="fi">${catOpts}</select>
                </div>
                <div class="fg" style="margin-bottom:0;">
                    <label class="fl">Légende</label>
                    <input type="text" id="edit-photo-caption" class="fi" value="${this._esc(p.caption||'')}" placeholder="Description...">
                </div>
            </div>
            <div style="margin-top:16px;padding:10px 14px;background:var(--subtle-bg);border-radius:var(--rd-sm);font-size:11px;color:var(--t3);display:flex;align-items:center;gap:8px;">
                <svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:var(--t3);fill:none;stroke-width:2;flex-shrink:0;"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
                <code style="word-break:break-all;font-family:'Space Mono',monospace;font-size:10px;">${p.file_url}</code>
            </div>`;

        const footerHtml = `<button class="btn bp" onclick="APP.photos.saveEdit(${p.id},${locationId})" style="flex:1;justify-content:center;">
            <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Enregistrer</button>
        ${p.status !== 'published' ? `<button class="btn bs" onclick="APP.photos.publish(${p.id},${locationId})" style="flex:1;justify-content:center;border-color:var(--primary);color:var(--primary);">
            <svg viewBox="0 0 24 24"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></svg>
            Publier sur GBP</button>` : ''}`;
        APP.sidePanel.open('Modifier la photo', bodyHtml, footerHtml);
    },

    async saveEdit(photoId, locationId) {
        const fd = new FormData();
        fd.append('action', 'update');
        fd.append('id', photoId);
        fd.append('seo_keyword', document.getElementById('edit-photo-seo')?.value || '');
        fd.append('category', document.getElementById('edit-photo-cat')?.value || 'ADDITIONAL');
        fd.append('caption', document.getElementById('edit-photo-caption')?.value || '');
        try {
            const r = await fetch('api/photos.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (d.success) { APP.toast('Photo mise à jour', 'success'); this.load(locationId); }
            else APP.toast(d.error || 'Erreur', 'error');
        } catch (e) { APP.toast('Erreur réseau', 'error'); }
    },

    async deletePhoto(photoId, locationId) {
        if (!await APP.modal.confirm('Supprimer la photo', 'Supprimer cette photo ? Cette action est irréversible.', 'Supprimer', true)) return;
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', photoId);
        try {
            const r = await fetch('api/photos.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (d.success) { APP.toast('Photo supprimée', 'success'); this.load(locationId); }
            else APP.toast(d.error || 'Erreur', 'error');
        } catch (e) { APP.toast('Erreur réseau', 'error'); }
    },

    toggleSelectMode() {
        this._selectMode = !this._selectMode;
        this._selectedIds.clear();
        this.render(this._photos, null, this._locationId);
    },

    toggleSelect(id) {
        if (this._selectedIds.has(id)) this._selectedIds.delete(id);
        else this._selectedIds.add(id);
        this.render(this._photos, null, this._locationId);
    },

    async bulkDelete(locationId) {
        const ids = [...this._selectedIds];
        if (!ids.length) return;
        if (!await APP.modal.confirm('Supprimer', `Supprimer ${ids.length} photo${ids.length > 1 ? 's' : ''} ? Cette action est irréversible.`, 'Supprimer', true)) return;
        const fd = new FormData();
        fd.append('action', 'bulk_delete');
        fd.append('ids', JSON.stringify(ids));
        try {
            const r = await fetch('api/photos.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (d.success) {
                APP.toast(`${d.deleted} photo(s) supprimée(s)`, 'success');
                this._selectMode = false;
                this._selectedIds.clear();
                this.load(locationId);
            } else APP.toast(d.error || 'Erreur', 'error');
        } catch (e) { APP.toast('Erreur réseau', 'error'); }
    },

    _esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; },
    _formatSize(bytes) {
        if (!bytes) return '';
        if (bytes < 1024) return bytes + ' o';
        if (bytes < 1048576) return Math.round(bytes / 1024) + ' Ko';
        return (bytes / 1048576).toFixed(1) + ' Mo';
    }
};

// ====================================================================
// PREMIUM UX — Dashboard KPIs clickable
// ====================================================================
document.addEventListener('DOMContentLoaded', () => {
    const sg5 = document.querySelector('.sg.sg-5');
    if (!sg5) return;
    const cards = sg5.querySelectorAll('.sc');
    // Card 0: Fiches actives → locations
    if (cards[0] && !cards[0].querySelector('a')) {
        cards[0].style.cursor = 'pointer';
        cards[0].onclick = () => { window.location.href = '?view=locations'; };
    }
    // Card 1: Position moyenne → sort by position
    if (cards[1]) {
        cards[1].style.cursor = 'pointer';
        cards[1].onclick = () => {
            const sel = document.getElementById('dashboard-sort');
            if (sel) { sel.value = 'position'; APP.dashboardSort(); }
            cards[1].scrollIntoView({ behavior: 'smooth', block: 'center' });
        };
    }
    // Card 2: Note moyenne → sort by rating
    if (cards[2]) {
        cards[2].style.cursor = 'pointer';
        cards[2].onclick = () => {
            const sel = document.getElementById('dashboard-sort');
            if (sel) { sel.value = 'rating'; APP.dashboardSort(); }
        };
    }
    // Card 3: Avis sans réponse — already a link <a>
    // Card 4: Posts programmes — no action for now
});

// ====================================================================
// PREMIUM UX — Client KPIs clickable
// ====================================================================
document.addEventListener('DOMContentLoaded', () => {
    const kpiGrid = document.querySelector('.kpi-grid');
    if (!kpiGrid) return;
    const kpis = kpiGrid.querySelectorAll('.kpi-card');
    const params = new URLSearchParams(window.location.search);
    const locId = params.get('location');
    if (!locId) return;

    // KPI 0: Rang moyen → keywords tab
    if (kpis[0]) {
        kpis[0].style.cursor = 'pointer';
        kpis[0].onclick = () => { window.location.href = `?view=client&location=${locId}&tab=keywords`; };
    }
    // KPI 1: Top 3 → keywords tab
    if (kpis[1]) {
        kpis[1].style.cursor = 'pointer';
        kpis[1].onclick = () => { window.location.href = `?view=client&location=${locId}&tab=keywords`; };
    }
    // KPI 2: Note Google → reviews tab
    if (kpis[2]) {
        kpis[2].style.cursor = 'pointer';
        kpis[2].onclick = () => { window.location.href = `?view=client&location=${locId}&tab=reviews`; };
    }
    // KPI 3: Avis sans réponse → reviews tab
    if (kpis[3]) {
        kpis[3].style.cursor = 'pointer';
        kpis[3].onclick = () => { window.location.href = `?view=client&location=${locId}&tab=reviews`; };
    }
    // KPI 4: Posts programmes → posts tab
    if (kpis[4]) {
        kpis[4].style.cursor = 'pointer';
        kpis[4].onclick = () => { window.location.href = `?view=client&location=${locId}&tab=posts`; };
    }
});

// ====================================================================
// PHASE 1 — REVIEWS ALL ENHANCED (overrides render)
// ====================================================================
(function() {
    const origRender = APP.reviewsAll.render;
    APP.reviewsAll.render = function(reviews, stats, locations, pagination) {
        const c = document.getElementById('module-content');
        if (!c) return;

        const total = stats?.total || 0;
        const avg = stats?.avg_rating || 0;
        const unanswered = stats?.unanswered || 0;
        const deletedTotal = stats?.deleted_count || 0;

        let h = '';

        // Enhanced stats dashboard
        h += `<div class="reviews-dashboard">
            <div class="reviews-avg-block">
                <div class="reviews-avg-num">${avg}</div>
                <div class="reviews-avg-stars">${'&#9733;'.repeat(Math.round(avg))}${'&#9734;'.repeat(5 - Math.round(avg))}</div>
                <div class="reviews-avg-total">${total} avis</div>
            </div>
            <div class="reviews-distribution">`;
        for (const s of [5,4,3,2,1]) {
            const cnt = stats?.['stars_' + s] || 0;
            const pct = total > 0 ? (cnt / total * 100) : 0;
            h += `<div class="reviews-dist-row">
                <span class="dist-star">${s}</span>
                <span class="dist-icon">&#9733;</span>
                <div class="dist-bar"><div class="dist-bar-fill" style="width:${pct}%"></div></div>
                <span class="dist-count">${cnt}</span>
            </div>`;
        }
        h += `</div>
            <div class="reviews-kpi-block">
                <div class="reviews-kpi">
                    <div class="reviews-kpi-num" style="color:${unanswered > 0 ? 'var(--o)' : 'var(--g)'}">${unanswered}</div>
                    <div class="reviews-kpi-label">A traiter</div>
                </div>
                <div class="reviews-kpi">
                    <div class="reviews-kpi-num" style="color:var(--g)">${total - unanswered - deletedTotal}</div>
                    <div class="reviews-kpi-label">Répondus</div>
                </div>
            </div>
            ${unanswered > 0 ? `<div style="margin-left:8px;"><button class="btn bp bsm" id="btn-gen-all-ai" onclick="APP.reviewsAll._generateAllAI()" style="background:var(--primary);color:#fff;"><svg viewBox="0 0 24 24" style="width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg> Générer les réponses IA</button></div>` : ''}
        </div>`;

        // Enhanced filter bar
        h += `<div class="reviews-filter-bar">
            <select class="si" style="width:auto;min-width:180px;" onchange="APP.reviewsAll.load(APP.reviewsAll._filter, this.value, 1)">
                <option value="">Toutes les fiches</option>`;
        for (const loc of (locations || [])) {
            const sel = this._filterLocation == loc.id ? 'selected' : '';
            h += `<option value="${loc.id}" ${sel}>${loc.name}${loc.unanswered_count > 0 ? ' (' + loc.unanswered_count + ')' : ''}</option>`;
        }
        h += `</select>
            <select class="si" style="width:auto;min-width:130px;" onchange="APP.reviewsAll.load(this.value,APP.reviewsAll._filterLocation,1)">
                <option value="unanswered" ${this._filter==='unanswered'?'selected':''}>A traiter</option>
                <option value="all" ${this._filter==='all'?'selected':''}>Tous</option>
                <option value="deleted" ${this._filter==='deleted'?'selected':''}>Supprimes</option>
                <option value="5" ${this._filter==='5'?'selected':''}>5 &#9733;</option>
                <option value="4" ${this._filter==='4'?'selected':''}>4 &#9733;</option>
                <option value="3" ${this._filter==='3'?'selected':''}>3 &#9733;</option>
                <option value="2" ${this._filter==='2'?'selected':''}>2 &#9733;</option>
                <option value="1" ${this._filter==='1'?'selected':''}>1 &#9733;</option>
            </select>`;

        h += `<div style="margin-left:auto;"><input type="text" id="reviews-search" class="si" placeholder="Rechercher..." style="width:140px;padding:5px 10px;font-size:12px;" oninput="APP.reviewsAll._filterSearch()"></div>`;
        h += `</div>`;

        // List with enhanced states
        if (!reviews.length) {
            h += `<div style="padding:40px;text-align:center;color:var(--t2);"><p>Aucun avis trouve.</p></div>`;
        } else {
            h += `<div class="reviews-list" id="reviews-list-container">`;
            for (const r of reviews) {
                const stars = '&#9733;'.repeat(r.rating) + '&#9734;'.repeat(5 - r.rating);
                const isDeleted = r.deleted_by_google == 1;
                const isReplied = r.is_replied == 1;
                const isNegative = r.rating <= 2;
                const initial = (r.author_name || r.reviewer_name || '?')[0].toUpperCase();
                const ago = r.review_date ? APP.reviewsAll._timeAgo(r.review_date) : '';

                const isAiDraft = !isReplied && r.reply_text && r.reply_source === 'ai_draft';
                let stateHtml = '';
                if (isDeleted) {
                    stateHtml = '<span class="rev-state rev-state-deleted">Supprime</span>';
                } else if (isAiDraft) {
                    stateHtml = '<span class="rev-state rev-state-auto">Brouillon IA</span>';
                } else if (!isReplied && isNegative) {
                    stateHtml = '<span class="rev-state rev-state-urgent">Urgent</span>';
                } else if (!isReplied) {
                    stateHtml = '<span class="rev-state rev-state-pending">A traiter</span>';
                } else if (r.reply_source === 'ai_auto') {
                    stateHtml = '<span class="rev-state rev-state-auto">Auto IA</span>';
                } else {
                    stateHtml = '<span class="rev-state rev-state-replied">Répondu</span>';
                }

                const borderStyle = isDeleted ? ' style="opacity:.7;border-left:3px solid var(--r);"' : ((!isReplied && isNegative) ? ' style="border-left:3px solid var(--r);"' : '');
                const commentText = r.comment || '';
                // Commentaire complet affiché directement (pas de troncature)

                h += `<div class="rev-card" id="review-${r.id}"${borderStyle} data-author="${(r.author_name||'').toLowerCase()}" data-comment="${commentText.toLowerCase()}" data-reply="${(r.reply_text||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;')}">
                    <div class="rev-header">
                        <div class="rev-avatar">${initial}</div>
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                <span class="rev-author">${r.author_name||r.reviewer_name||'Anonyme'}</span>
                                ${stateHtml}
                            </div>
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:2px;">
                                <span style="color:var(--o);font-size:14px;">${stars}</span>
                                <span style="font-size:11px;color:var(--t3);">${ago}</span>
                                <span class="rev-location-badge">${r.location_name||''}</span>
                            </div>
                        </div>
                    </div>`;

                if (commentText) {
                    h += `<div class="rev-comment" style="white-space:pre-wrap;">${commentText}</div>`;
                }

                if (isAiDraft) {
                    h += `<div class="rev-reply" style="border-left:2px solid var(--acc);background:rgba(0,212,255,.03);">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;flex-wrap:wrap;gap:6px;">
                            <div style="font-size:11px;font-weight:600;color:var(--acc);">&#9889; Réponse IA (brouillon)</div>
                            <div style="display:flex;gap:6px;">
                                <button class="btn bp bsm" onclick="event.stopPropagation();APP.reviewsAll._publishDraft(${r.id})" style="font-size:11px;padding:3px 10px;">Publier</button>
                                <button class="btn bs bsm" onclick="event.stopPropagation();APP.reviewsAll._toggleEdit(${r.id})" style="font-size:11px;padding:3px 10px;">Modifier</button>
                                <button class="btn bs bsm" onclick="event.stopPropagation();APP.reviewsAll._inlineRegen(${r.id})" style="font-size:11px;padding:3px 10px;">Re-générer</button>
                            </div>
                        </div>
                        <div id="revall-reply-display-${r.id}" style="font-size:13px;color:var(--t2);white-space:pre-wrap;">${r.reply_text}</div>
                        <div id="revall-reply-edit-${r.id}" style="display:none;margin-top:8px;">
                            <textarea id="revall-reply-text-${r.id}" class="si" style="width:100%;height:100px;resize:vertical;">${(r.reply_text||'').replace(/</g,'&lt;')}</textarea>
                            <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
                                <button class="btn bp bsm" onclick="APP.reviewsAll._inlineSave(${r.id},true)">Publier sur Google</button>
                                <button class="btn bs bsm" onclick="APP.reviewsAll._inlineSave(${r.id},false)">Sauver local</button>
                                <button class="btn bs bsm" onclick="APP.reviewsAll._toggleEdit(${r.id})">Annuler</button>
                                <button class="btn bs bsm" onclick="navigator.clipboard.writeText(document.getElementById('revall-reply-text-${r.id}').value);APP.toast('Copié !','success')">Copier</button>
                            </div>
                        </div>
                    </div>`;
                } else if (!isReplied && !isDeleted) {
                    h += `<div class="rev-actions" id="revall-actions-${r.id}">
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <button class="btn bp bsm" onclick="event.stopPropagation();APP.reviewsAll._inlineReply(${r.id})">
                                <svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg> Répondre IA
                            </button>
                            <button class="btn bs bsm" onclick="event.stopPropagation();APP.reviewsAll._showReplyZone(${r.id})">Écrire manuellement</button>
                        </div>
                    </div>
                    <div id="revall-reply-zone-${r.id}" style="display:none;margin-top:8px;padding:12px;background:var(--inp);border-radius:8px;border:1px solid var(--bdr);">
                        <textarea id="revall-reply-text-${r.id}" class="si" style="width:100%;height:100px;resize:vertical;" placeholder="Écrire une réponse..."></textarea>
                        <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
                            <button class="btn bp bsm" onclick="APP.reviewsAll._inlineGenerate(${r.id})">
                                <svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg> Générer IA
                            </button>
                            <button class="btn bp bsm" onclick="APP.reviewsAll._inlineSave(${r.id},true)">Publier sur Google</button>
                            <button class="btn bs bsm" onclick="APP.reviewsAll._inlineSave(${r.id},false)">Sauver local</button>
                            <button class="btn bs bsm" onclick="navigator.clipboard.writeText(document.getElementById('revall-reply-text-${r.id}').value);APP.toast('Copié !','success')">Copier</button>
                        </div>
                    </div>`;
                } else if (isReplied && r.reply_text) {
                    h += `<div class="rev-reply">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                            <div style="font-size:11px;color:var(--acc);">&#8617; Votre réponse</div>
                            <div style="display:flex;gap:6px;">
                                <button class="btn bs bsm" onclick="event.stopPropagation();APP.reviewsAll._toggleEdit(${r.id})" style="font-size:11px;padding:3px 10px;">Modifier</button>
                                <button class="btn bs bsm" onclick="event.stopPropagation();APP.reviewsAll._inlineRegen(${r.id})" style="font-size:11px;padding:3px 10px;">Re-générer</button>
                            </div>
                        </div>
                        <div id="revall-reply-display-${r.id}" style="font-size:12px;color:var(--t2);white-space:pre-wrap;">${r.reply_text}</div>
                        <div id="revall-reply-edit-${r.id}" style="display:none;margin-top:8px;">
                            <textarea id="revall-reply-text-${r.id}" class="si" style="width:100%;height:100px;resize:vertical;">${(r.reply_text||'').replace(/</g,'&lt;')}</textarea>
                            <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
                                <button class="btn bp bsm" onclick="APP.reviewsAll._inlineSave(${r.id},true)">Publier sur Google</button>
                                <button class="btn bs bsm" onclick="APP.reviewsAll._inlineSave(${r.id},false)">Sauver local</button>
                                <button class="btn bs bsm" onclick="APP.reviewsAll._toggleEdit(${r.id})">Annuler</button>
                            </div>
                        </div>
                    </div>`;
                }

                h += `</div>`;
            }
            h += `</div>`;
        }

        // Pagination
        if (pagination && pagination.pages > 1) {
            h += `<div style="display:flex;justify-content:center;gap:8px;padding:16px;">`;
            for (let p = 1; p <= pagination.pages; p++) {
                const cls = p === pagination.page ? 'bp' : 'bs';
                h += `<button class="btn ${cls} bsm" onclick="APP.reviewsAll.load('${this._filter}','${this._filterLocation}',${p})">${p}</button>`;
            }
            h += `</div>`;
        }

        c.innerHTML = h;
    };

    APP.reviewsAll._timeAgo = function(dateStr) {
        const d = new Date(dateStr);
        const now = new Date();
        const diff = Math.floor((now - d) / 1000);
        if (diff < 3600) return Math.floor(diff / 60) + ' min';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h';
        if (diff < 604800) return Math.floor(diff / 86400) + 'j';
        return d.toLocaleDateString('fr-FR');
    };

    APP.reviewsAll._filterSearch = function() {
        const q = (document.getElementById('reviews-search')?.value || '').toLowerCase();
        const cards = document.querySelectorAll('#reviews-list-container .rev-card');
        cards.forEach(card => {
            const author = card.dataset.author || '';
            const comment = card.dataset.comment || '';
            card.style.display = (!q || author.includes(q) || comment.includes(q)) ? '' : 'none';
        });
    };

    APP.reviewsAll._inlineReply = async function(reviewId) {
        const zone = document.getElementById(`revall-reply-zone-${reviewId}`);
        const ta = document.getElementById(`revall-reply-text-${reviewId}`);
        if (zone) zone.style.display = 'block';
        if (ta) { ta.value = 'Génération en cours...'; ta.disabled = true; }
        const actionsDiv = document.getElementById(`revall-actions-${reviewId}`);
        if (actionsDiv) actionsDiv.style.display = 'none';

        const fd = new FormData();
        fd.append('action', 'generate_reply');
        fd.append('review_id', reviewId);
        const data = await APP.fetch('/api/reviews-all.php', { method: 'POST', body: fd });

        if (ta) {
            ta.disabled = false;
            ta.value = data.success ? data.reply : 'Erreur: ' + (data.error || 'Impossible de générer');
            ta.focus();
        }
    };

    APP.reviewsAll._showReplyZone = function(reviewId) {
        const zone = document.getElementById(`revall-reply-zone-${reviewId}`);
        if (zone) zone.style.display = zone.style.display === 'none' ? 'block' : 'none';
        const ta = document.getElementById(`revall-reply-text-${reviewId}`);
        if (ta) ta.focus();
    };

    APP.reviewsAll._inlineGenerate = async function(reviewId) {
        const ta = document.getElementById(`revall-reply-text-${reviewId}`);
        if (ta) { ta.value = 'Génération en cours...'; ta.disabled = true; }
        const fd = new FormData();
        fd.append('action', 'generate_reply');
        fd.append('review_id', reviewId);
        const data = await APP.fetch('/api/reviews-all.php', { method: 'POST', body: fd });
        if (ta) { ta.disabled = false; ta.value = data.success ? data.reply : 'Erreur: ' + (data.error || ''); }
    };

    APP.reviewsAll._inlineSave = async function(reviewId, postToGoogle) {
        const ta = document.getElementById(`revall-reply-text-${reviewId}`);
        if (!ta || !ta.value.trim()) { APP.toast('Veuillez écrire une réponse', 'warning'); return; }
        const fd = new FormData();
        fd.append('action', 'save_reply');
        fd.append('review_id', reviewId);
        fd.append('reply_text', ta.value.trim());
        fd.append('post_to_google', postToGoogle ? '1' : '0');
        const data = await APP.fetch('/api/reviews-all.php', { method: 'POST', body: fd });
        if (data.success) {
            if (data.posted_to_google) APP.toast('Réponse publiée sur Google !', 'success');
            else if (data.google_error) APP.toast('Sauvegardée. Erreur Google : ' + data.google_error, 'warning');
            else APP.toast('Réponse sauvegardée', 'success');
            APP.reviewsAll.load(APP.reviewsAll._filter, APP.reviewsAll._filterLocation, APP.reviewsAll._page);
        } else {
            APP.toast(data.error || 'Erreur', 'error');
        }
    };

    APP.reviewsAll._toggleEdit = function(reviewId) {
        const display = document.getElementById(`revall-reply-display-${reviewId}`);
        const edit = document.getElementById(`revall-reply-edit-${reviewId}`);
        if (!display || !edit) return;
        if (edit.style.display === 'none') {
            display.style.display = 'none';
            edit.style.display = 'block';
            const ta = document.getElementById(`revall-reply-text-${reviewId}`);
            if (ta) ta.focus();
        } else {
            display.style.display = 'block';
            edit.style.display = 'none';
        }
    };

    APP.reviewsAll._inlineRegen = async function(reviewId) {
        const display = document.getElementById(`revall-reply-display-${reviewId}`);
        const edit = document.getElementById(`revall-reply-edit-${reviewId}`);
        if (display) display.style.display = 'none';
        if (edit) edit.style.display = 'block';
        const ta = document.getElementById(`revall-reply-text-${reviewId}`);
        if (ta) { ta.value = 'Re-génération en cours...'; ta.disabled = true; }
        const fd = new FormData();
        fd.append('action', 'generate_reply');
        fd.append('review_id', reviewId);
        const data = await APP.fetch('/api/reviews-all.php', { method: 'POST', body: fd });
        if (ta) { ta.disabled = false; ta.value = data.success ? data.reply : 'Erreur: ' + (data.error || ''); }
    };

    APP.reviewsAll._generateAllAI = async function() {
        const btn = document.getElementById('btn-gen-all-ai');
        if (btn) { btn.disabled = true; btn.innerHTML = '<svg class="spin" viewBox="0 0 24 24" style="width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;"><path d="M12 2v4m0 12v4m-7.07-3.93l2.83-2.83m8.48-8.48l2.83-2.83M2 12h4m12 0h4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83"/></svg> Generation en cours...'; }
        try {
            const fd = new FormData();
            fd.append('action', 'generate_all_replies');
            const data = await APP.fetch('/api/reviews-all.php', { method: 'POST', body: fd });
            if (data.success && data.generated > 0) {
                APP.toast(data.generated + ' réponse(s) IA générée(s) !', 'success');
                APP.reviewsAll.load(APP.reviewsAll._filter, APP.reviewsAll._filterLocation, APP.reviewsAll._page);
            } else if (data.success) {
                APP.toast('Aucun avis a traiter', 'info');
                if (btn) { btn.disabled = false; btn.innerHTML = '<svg viewBox="0 0 24 24" style="width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg> Générer les réponses IA'; }
            } else {
                APP.toast(data.error || 'Erreur', 'error');
                if (btn) { btn.disabled = false; btn.innerHTML = '<svg viewBox="0 0 24 24" style="width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg> Générer les réponses IA'; }
            }
        } catch (e) {
            console.error('Generate all AI error:', e);
            APP.toast('Erreur de generation', 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = '<svg viewBox="0 0 24 24" style="width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg> Générer les réponses IA'; }
        }
    };

    APP.reviewsAll._publishDraft = async function(reviewId) {
        const displayEl = document.getElementById(`revall-reply-display-${reviewId}`);
        const replyText = displayEl ? displayEl.textContent.trim() : '';
        if (!replyText) { APP.toast('Pas de brouillon à publier', 'warning'); return; }
        const safeText = replyText.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        if (!await APP.modal.confirm(
            'Publier cette réponse IA sur Google ?',
            `<div style="font-size:13px;color:var(--t2);max-height:200px;overflow:auto;white-space:pre-wrap;">${safeText}</div>`,
            'Publier sur Google'
        )) return;
        const fd = new FormData();
        fd.append('action', 'save_reply');
        fd.append('review_id', reviewId);
        fd.append('reply_text', replyText);
        fd.append('post_to_google', '1');
        const data = await APP.fetch('/api/reviews-all.php', { method: 'POST', body: fd });
        if (data.success) {
            APP.toast(data.posted_to_google ? 'Réponse publiée sur Google !' : 'Sauvegardee' + (data.google_error ? '. Erreur Google: ' + data.google_error : ''), data.posted_to_google ? 'success' : 'warning');
            APP.reviewsAll.load(APP.reviewsAll._filter, APP.reviewsAll._filterLocation, APP.reviewsAll._page);
        } else { APP.toast(data.error || 'Erreur', 'error'); }
    };

    // Side panel supprimé — tout est inline dans les cartes d'avis
})();

/* ============================================
   PHASE 2.2 — Posts Calendar View
   ============================================ */
(function() {
    const origPostsRender = APP.posts.render;
    APP.posts._viewMode = 'list';
    APP.posts._calMonth = new Date().getMonth();
    APP.posts._calYear = new Date().getFullYear();

    APP.posts.render = function(posts, stats, pagination, locationId, activeStatus) {
        origPostsRender.call(this, posts, stats, pagination, locationId, activeStatus);
        const container = document.getElementById('module-content');
        if (!container) return;

        // Inject view toggle button after the header
        const header = container.querySelector('.sh');
        if (header && !container.querySelector('.posts-view-toggle')) {
            const toggle = document.createElement('div');
            toggle.className = 'posts-view-toggle';
            toggle.style.cssText = 'margin:0 0 0 auto;';
            toggle.innerHTML = '<button class="posts-view-btn ' + (APP.posts._viewMode === 'list' ? 'active' : '') + '" onclick="APP.posts._switchView(\'list\')">Liste</button>' +
                '<button class="posts-view-btn ' + (APP.posts._viewMode === 'calendar' ? 'active' : '') + '" onclick="APP.posts._switchView(\'calendar\')">Calendrier</button>';
            const ha = header.querySelector('.ha') || header;
            if (ha.style) ha.style.display = 'flex';
            if (ha.style) ha.style.alignItems = 'center';
            if (ha.style) ha.style.gap = '12px';
            ha.appendChild(toggle);
        }

        // Store posts for calendar rendering
        APP.posts._allPosts = posts;

        if (APP.posts._viewMode === 'calendar') {
            APP.posts._renderCalendar(container);
        }
    };

    APP.posts._switchView = function(mode) {
        APP.posts._viewMode = mode;
        document.querySelectorAll('.posts-view-btn').forEach(function(b) {
            b.classList.toggle('active', b.textContent.toLowerCase().indexOf(mode === 'list' ? 'liste' : 'calendrier') >= 0);
        });
        const container = document.getElementById('module-content');
        if (!container) return;

        if (mode === 'calendar') {
            APP.posts._renderCalendar(container);
        } else {
            const calEl = container.querySelector('.posts-calendar-wrap');
            if (calEl) calEl.remove();
            // Show the list items
            container.querySelectorAll('.posts-list-item, .post-card, [style*="border-left"], .pagination-wrap').forEach(function(el) { el.style.display = ''; });
        }
    };

    APP.posts._renderCalendar = function(container) {
        // Hide list items
        container.querySelectorAll('.posts-list-item, .post-card, [style*="border-left:3px"], .pagination-wrap').forEach(function(el) {
            if (el.closest('.posts-calendar-wrap')) return;
            el.style.display = 'none';
        });

        let wrap = container.querySelector('.posts-calendar-wrap');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.className = 'posts-calendar-wrap';
            wrap.style.padding = '16px 20px';
            container.appendChild(wrap);
        }

        const m = APP.posts._calMonth;
        const y = APP.posts._calYear;
        const months = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
        const days = ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];

        const firstDay = new Date(y, m, 1);
        const lastDay = new Date(y, m + 1, 0);
        let startWeekday = firstDay.getDay();
        if (startWeekday === 0) startWeekday = 7;
        startWeekday--;

        const today = new Date();
        const todayStr = today.getFullYear() + '-' + String(today.getMonth()+1).padStart(2,'0') + '-' + String(today.getDate()).padStart(2,'0');

        // Map posts to dates
        const postsByDate = {};
        (APP.posts._allPosts || []).forEach(function(p) {
            let d = p.scheduled_at || p.published_at || p.created_at;
            if (d) {
                const dateKey = d.substring(0, 10);
                if (!postsByDate[dateKey]) postsByDate[dateKey] = [];
                postsByDate[dateKey].push(p);
            }
        });

        // Type legend in calendar
        let html = APP.posts._renderTypeLegend ? APP.posts._renderTypeLegend() : '';

        html += '<div class="cal-nav">' +
            '<button class="cal-nav-btn" onclick="APP.posts._calPrev()">&larr;</button>' +
            '<span class="cal-month-label">' + months[m] + ' ' + y + '</span>' +
            '<button class="cal-nav-btn" onclick="APP.posts._calNext()">&rarr;</button>' +
            '<button class="cal-nav-btn" onclick="APP.posts._calToday()" style="font-size:12px;width:auto;padding:0 10px;">Aujourd\'hui</button>' +
            '</div>';

        html += '<div class="posts-calendar">';
        days.forEach(function(d) { html += '<div class="cal-header">' + d + '</div>'; });

        // Previous month padding
        const prevMonthLast = new Date(y, m, 0).getDate();
        for (let i = startWeekday - 1; i >= 0; i--) {
            html += '<div class="cal-day other-month"><div class="cal-day-num">' + (prevMonthLast - i) + '</div></div>';
        }

        // Current month days
        for (let d = 1; d <= lastDay.getDate(); d++) {
            const dateStr = y + '-' + String(m+1).padStart(2,'0') + '-' + String(d).padStart(2,'0');
            const isToday = dateStr === todayStr;
            const dayPosts = postsByDate[dateStr] || [];
            const hasEvent = dayPosts.length > 0;

            html += '<div class="cal-day' + (isToday ? ' today' : '') + (hasEvent ? ' has-event' : '') + '">';
            html += '<div class="cal-day-num">' + d + '</div>';
            dayPosts.slice(0, 3).forEach(function(p) {
                const st = p.status || 'draft';
                const badge = APP.posts._getTypeBadge ? APP.posts._getTypeBadge(p) : null;
                const bgStyle = badge ? 'background:' + badge.bg + ';border-left:3px solid ' + badge.color + ';' : '';
                const icon = badge ? badge.icon + ' ' : '';
                const label = (p.content || p.post_type || 'Post').substring(0, 18);
                html += '<div class="cal-event ' + st + '" style="' + bgStyle + '" title="' + APP.escHtml(p.content || '') + '">' + icon + APP.escHtml(label) + '</div>';
            });
            if (dayPosts.length > 3) html += '<div style="font-size:10px;color:var(--t3);padding-left:5px;">+' + (dayPosts.length - 3) + '</div>';
            html += '</div>';
        }

        // Next month padding
        const totalCells = startWeekday + lastDay.getDate();
        const remaining = (7 - (totalCells % 7)) % 7;
        for (let i = 1; i <= remaining; i++) {
            html += '<div class="cal-day other-month"><div class="cal-day-num">' + i + '</div></div>';
        }

        html += '</div>';
        wrap.innerHTML = html;
    };

    APP.posts._calPrev = function() {
        APP.posts._calMonth--;
        if (APP.posts._calMonth < 0) { APP.posts._calMonth = 11; APP.posts._calYear--; }
        APP.posts._renderCalendar(document.getElementById('module-content'));
    };

    APP.posts._calNext = function() {
        APP.posts._calMonth++;
        if (APP.posts._calMonth > 11) { APP.posts._calMonth = 0; APP.posts._calYear++; }
        APP.posts._renderCalendar(document.getElementById('module-content'));
    };

    APP.posts._calToday = function() {
        APP.posts._calMonth = new Date().getMonth();
        APP.posts._calYear = new Date().getFullYear();
        APP.posts._renderCalendar(document.getElementById('module-content'));
    };
})();

/* ============================================
   PHASE 2.3 — Fiches GBP Table Enrichie
   ============================================ */
(function() {
    const origLocRender = APP.locations.render;

    // Selection en masse
    APP.locations._selected = new Set();

    APP.locations.render = function(locations) {
        // L'original render() n'accepte pas de parametre, il lit this._data
        // S'assurer qu'on utilise _data si locations n'est pas fourni
        if (!locations) locations = APP.locations._data || [];
        APP.locations._selected.clear();
        const container = document.getElementById('module-content');
        if (!container) { origLocRender.call(this); return; }

        const count = locations.length;
        const activeCount = locations.filter(function(l) { return l.is_active == 1; }).length;

        // Compute health scores
        locations.forEach(function(l) {
            let h = 0;
            if (parseInt(l.unanswered_count || 0) === 0) h++;
            else if (parseInt(l.unanswered_count || 0) <= 2) h += 0.5;
            if (parseInt(l.published_week || 0) > 0 || parseInt(l.scheduled_count || 0) > 0) h++;
            if (parseInt(l.keyword_count || 0) > 0) h += 0.5;
            if (parseFloat(l.avg_rating || 0) >= 4) h += 0.5;
            l._health = Math.min(h, 3);
        });

        let html = '<div class="sh" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">';
        html += '<div><div class="stit">Toutes les fiches (' + count + ')</div>';
        html += '<div style="font-size:12px;color:var(--t3);margin-top:2px">' + activeCount + ' active' + (activeCount > 1 ? 's' : '') + '</div></div>';
        html += '<div class="ha" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">';

        // Check for duplicates (par google_location_id)
        const seenGids = {};
        let dupCount = 0;
        locations.forEach(function(l) {
            const gid = l.google_location_id || '';
            if (gid && seenGids[gid]) dupCount++;
            if (gid) seenGids[gid] = true;
        });
        if (dupCount > 0) {
            html += '<button class="btn bs" onclick="APP.locations.removeDuplicates()" style="font-size:12px;color:var(--o)">Supprimer ' + dupCount + ' doublon(s)</button>';
        }

        html += '<button class="btn bs" id="btn-refresh-locations" onclick="APP.locations.forceRefresh()">';
        html += '<svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>';
        html += ' Actualiser les fiches</button>';
        html += '<button class="btn bp" onclick="APP.locations.confirmSync()" id="btn-sync-locations">';
        html += '<svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4m4-5l5 5 5-5m-5 5V3"/></svg>';
        html += ' Importer des fiches</button>';
        html += '</div></div>';

        // Summary KPIs
        const totalReviews = locations.reduce(function(s, l) { return s + parseInt(l.review_count || 0); }, 0);
        const totalUnanswered = locations.reduce(function(s, l) { return s + parseInt(l.unanswered_count || 0); }, 0);
        const totalNeg = locations.reduce(function(s, l) { return s + parseInt(l.negative_unanswered || 0); }, 0);
        const avgHealth = count > 0 ? (locations.reduce(function(s, l) { return s + l._health; }, 0) / count).toFixed(1) : 0;

        html += '<div style="display:flex;gap:16px;padding:12px 20px;flex-wrap:wrap;">';
        html += '<div style="font-size:12px;color:var(--t3)">Avis total: <strong style="color:var(--t1)">' + totalReviews + '</strong></div>';
        if (totalUnanswered > 0) html += '<div style="font-size:12px;color:var(--o)">Sans réponse: <strong>' + totalUnanswered + '</strong></div>';
        if (totalNeg > 0) html += '<div style="font-size:12px;color:var(--r)">Negatifs urgents: <strong>' + totalNeg + '</strong></div>';
        html += '<div style="font-size:12px;color:var(--t3)">Sante moy.: <strong style="color:' + (avgHealth >= 2 ? 'var(--g)' : avgHealth >= 1 ? 'var(--o)' : 'var(--r)') + '">' + avgHealth + '/3</strong></div>';
        html += '</div>';

        // Barre d'actions en masse (cachee par defaut)
        html += '<div id="loc-bulk-bar" class="bulk-bar" style="display:none">';
        html += '<span id="loc-bulk-count" style="font-weight:600;font-size:13px"></span>';
        html += '<div style="display:flex;gap:8px">';
        html += '<button class="btn bs" onclick="APP.locations.bulkDeactivate()" style="font-size:12px">⏸ Desactiver</button>';
        html += '<button class="btn bs" onclick="APP.locations.bulkDelete()" style="font-size:12px;color:var(--r)">✕ Supprimer</button>';
        html += '<button class="btn bs" onclick="APP.locations.clearSelection()" style="font-size:12px;color:var(--t3)">Annuler</button>';
        html += '</div></div>';

        // Table
        html += '<div style="overflow-x:auto;"><table class="tb"><thead><tr>';
        html += '<th style="width:36px;text-align:center"><input type="checkbox" id="loc-select-all" onchange="APP.locations.toggleSelectAll(this.checked)" style="cursor:pointer"></th>';
        html += '<th>Nom</th><th>Ville</th><th>Sante</th><th style="text-align:center">Mots-cles</th><th style="text-align:center">Avis</th><th style="text-align:center">Note</th><th style="text-align:center">Activite</th><th style="text-align:center">Statut</th><th>Actions</th>';
        html += '</tr></thead><tbody>';

        locations.forEach(function(l) {
            const hs = l._health;
            const dot1 = hs >= 1 ? 'dot-g' : (hs >= 0.5 ? 'dot-o' : 'dot-r');
            const dot2 = hs >= 2 ? 'dot-g' : (hs >= 1.5 ? 'dot-o' : 'dot-r');
            const dot3 = hs >= 3 ? 'dot-g' : (hs >= 2.5 ? 'dot-o' : 'dot-r');
            const healthLabel = hs >= 2.5 ? 'Bon' : (hs >= 1.5 ? 'Moyen' : 'Faible');
            const healthClass = hs >= 2.5 ? 'good' : (hs >= 1.5 ? 'medium' : 'poor');

            html += '<tr' + (l.is_active == 0 ? ' style="opacity:.5"' : '') + '>';

            // Checkbox
            html += '<td style="text-align:center"><input type="checkbox" class="loc-cb" data-id="' + l.id + '" onchange="APP.locations.toggleSelect(' + l.id + ',this.checked)" style="cursor:pointer"></td>';

            // Nom
            html += '<td><a href="?view=client&location=' + l.id + '&tab=keywords" style="color:var(--t1);text-decoration:none;font-weight:600">' + APP.escHtml(l.name || '') + '</a>';
            if (l.phone) html += '<div style="font-size:11px;color:var(--t3)">' + APP.escHtml(l.phone) + '</div>';
            html += '</td>';

            // Ville
            html += '<td style="color:var(--t2)">' + APP.escHtml(l.city || '—') + '</td>';

            // Sante
            html += '<td><div class="loc-health-cell"><span class="health-indicator"><span class="dot ' + dot1 + '"></span><span class="dot ' + dot2 + '"></span><span class="dot ' + dot3 + '"></span></span>';
            html += '<span class="loc-health-label ' + healthClass + '">' + healthLabel + '</span></div></td>';

            // Mots-cles
            const kc = parseInt(l.keyword_count || 0);
            html += '<td style="text-align:center"><span style="display:inline-block;padding:2px 10px;border-radius:4px;font-size:12px;font-weight:600;' +
                (kc > 0 ? 'background:rgba(0,212,255,.1);color:var(--acc)' : 'color:var(--t3)') + '">' + kc + '</span></td>';

            // Avis
            const rc = parseInt(l.review_count || 0);
            const uc = parseInt(l.unanswered_count || 0);
            const neg = parseInt(l.negative_unanswered || 0);
            html += '<td style="text-align:center">' + rc;
            if (neg > 0) html += ' <span class="loc-neg-badge">&#9888; ' + neg + '</span>';
            else if (uc > 0) html += ' <span style="font-size:11px;color:var(--o)">(' + uc + ')</span>';
            html += '</td>';

            // Note
            const rat = parseFloat(l.avg_rating || 0);
            html += '<td style="text-align:center;color:var(--o)">' + (rat > 0 ? '&#9733; ' + rat : '<span style="color:var(--t3)">—</span>') + '</td>';

            // Activite (posts cette semaine + programmes)
            const pw = parseInt(l.published_week || 0);
            const sc = parseInt(l.scheduled_count || 0);
            const fc = parseInt(l.failed_count || 0);
            html += '<td style="text-align:center">';
            if (pw > 0) html += '<span style="font-size:11px;color:var(--g)">&#10003; ' + pw + ' pub.</span> ';
            if (sc > 0) html += '<span style="font-size:11px;color:var(--acc)">' + sc + ' prog.</span> ';
            if (fc > 0) html += '<span style="font-size:11px;color:var(--r)">' + fc + ' err.</span>';
            if (pw === 0 && sc === 0 && fc === 0) html += '<span style="font-size:11px;color:var(--t3)">—</span>';
            html += '</td>';

            // Statut
            html += '<td style="text-align:center">';
            if (l.is_active == 1) html += '<span class="badge" style="background:var(--gbg);color:var(--g)">Actif</span>';
            else html += '<span class="badge" style="background:rgba(148,163,184,.08);color:var(--t3)">Inactif</span>';
            html += '</td>';

            // Actions
            html += '<td><div style="display:flex;gap:4px;justify-content:flex-end;">';
            html += '<button class="btn bs" onclick="APP.locations.toggle(' + l.id + ')" title="' + (l.is_active == 1 ? 'Desactiver' : 'Activer') + '" style="padding:6px 8px;font-size:11px">' + (l.is_active == 1 ? '⏸' : '▶') + '</button>';
            html += '<button class="btn bs" onclick="APP.locations.syncReviews(' + l.id + ')" title="Sync avis" style="padding:6px 8px;font-size:11px">↻</button>';
            html += '<a href="?view=client&location=' + l.id + '&tab=keywords" class="btn bs" style="padding:6px 8px;font-size:11px">👁</a>';
            html += '<button class="btn bs" onclick="APP.locations.deleteLocation(' + l.id + ',\'' + APP.escHtml((l.name || '').replace(/'/g, "\\'")) + '\')" title="Supprimer" style="padding:6px 8px;font-size:11px;color:var(--r)">✕</button>';
            html += '</div></td>';

            html += '</tr>';
        });

        html += '</tbody></table></div>';
        container.innerHTML = html;
    };

// ====================================================================
// MODULE : FICHE ETABLISSEMENT — Editeur de profil GBP
// ====================================================================
APP.gbpProfile = {
    _locationId: null,
    _local: null,
    _google: null,
    _syncStatus: null,
    _dirty: {},
    _saving: {},
    _catDebounce: null,
    _openSections: { identity: true, description: true, contact: true },

    DAYS: ['MONDAY','TUESDAY','WEDNESDAY','THURSDAY','FRIDAY','SATURDAY','SUNDAY'],
    DAY_LABELS: { MONDAY:'Lundi', TUESDAY:'Mardi', WEDNESDAY:'Mercredi', THURSDAY:'Jeudi', FRIDAY:'Vendredi', SATURDAY:'Samedi', SUNDAY:'Dimanche' },

    async load(locationId) {
        this._locationId = locationId;
        const sec = document.querySelector('#module-content .sec') || document.getElementById('module-content');
        if (!sec) return;
        sec.innerHTML = '<div class="sh"><div class="stit">Fiche établissement</div></div>' + APP.skeleton.rows(8);

        const data = await APP.fetch('/api/gbp-profile.php?action=get_profile&location_id=' + locationId);
        if (!data.success) {
            sec.innerHTML = '<div class="sh"><div class="stit">Fiche établissement</div></div>' +
                '<div class="gbp-error-state"><svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="var(--o)" stroke-width="1.5"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>' +
                '<div class="gbp-error-title">' + APP.escHtml(data.error || 'Impossible de charger la fiche') + '</div>' +
                '<div class="gbp-error-sub">Vérifiez que le compte Google est connecté et actif.</div></div>';
            return;
        }
        this._local = data.local;
        this._google = data.google;
        this._syncStatus = data.sync_status;
        this._protection = data.protection || {};
        this._dirty = {};
        this._saving = {};
        this._descKeywords = [];
        this.render();

        // Dirty guard
        window._gbpBeforeUnload = window._gbpBeforeUnload || function(e) {
            if (Object.keys(APP.gbpProfile._dirty).length) { e.preventDefault(); e.returnValue = ''; }
        };
        window.removeEventListener('beforeunload', window._gbpBeforeUnload);
        window.addEventListener('beforeunload', window._gbpBeforeUnload);
    },

    render() {
        const sec = document.querySelector('#module-content .sec') || document.getElementById('module-content');
        if (!sec) return;
        const g = this._google || {};

        let html = '<div class="sh"><div class="stit">Fiche établissement</div><div class="gbp-sync-info"><span class="gbp-sync-badge synced">✓ Synchronisé</span></div></div>';

        // Bandeau Protection de la fiche (basé sur l'API Voice of Merchant)
        const prot = this._protection || {};
        const placeId = (g.metadata || {}).placeId || this._local?.place_id || '';
        const isProtected = prot.verified === true || prot.hasVoice === true;
        html += '<div class="gbp-protection-bar">';
        html += '<div class="gbp-protection-status">';
        if (!prot.apiSuccess) {
            html += '<div class="gbp-protection-badge unknown"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg> Statut de protection indisponible</div>';
        } else if (isProtected) {
            html += '<div class="gbp-protection-badge verified"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg> Fiche protégée — Vous êtes propriétaire vérifié</div>';
        } else {
            html += '<div class="gbp-protection-badge unverified"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Fiche non protégée — Des tiers peuvent suggérer des modifications</div>';
        }
        html += '</div>';
        html += '<div class="gbp-protection-actions">';
        if (placeId && !isProtected) {
            html += '<a href="https://business.google.com/create?gmbsrc=ww-ww-et-gs-z-gmb-v-z-h~bhc-core-u&ppsrc=GMBSI&utm_campaign=ww-ww-et-gs-z-gmb-v-z-h~bhc-core-u" target="_blank" rel="noopener" class="gbp-protect-link">' +
                '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> Revendiquer sur Google</a>';
        }
        html += '<a href="https://support.google.com/business/answer/7107242" target="_blank" rel="noopener" class="gbp-protect-help">En savoir plus</a>';
        html += '</div></div>';

        html += '<div class="gbp-sections">';
        html += this._renderSection('identity', 'Identité', this._renderIdentity());
        html += this._renderSection('description', 'Description', this._renderDescription());
        html += this._renderSection('contact', 'Coordonnées', this._renderContact());
        html += this._renderSection('address', 'Adresse', this._renderAddress());
        html += this._renderSection('catégories', 'Catégories', this._renderCatégories());
        html += this._renderSection('hours', 'Horaires d\'ouverture', this._renderHours());
        html += this._renderSection('special-hours', 'Horaires exceptionnels', this._renderSpecialHours());
        html += this._renderSection('service-area', 'Zone de couverture', this._renderServiceArea());
        html += '</div>';

        sec.innerHTML = html;

        // Bind toggle sections
        sec.querySelectorAll('.gbp-section-header').forEach(h => {
            h.addEventListener('click', () => {
                const s = h.closest('.gbp-section');
                const key = s.dataset.section;
                s.classList.toggle('open');
                this._openSections[key] = s.classList.contains('open');
            });
        });

        // Bind input change → dirty tracking
        sec.querySelectorAll('.gbp-section [data-field]').forEach(el => {
            el.addEventListener('input', () => {
                const section = el.closest('.gbp-section').dataset.section;
                this.markDirty(section);
            });
            el.addEventListener('change', () => {
                const section = el.closest('.gbp-section').dataset.section;
                this.markDirty(section);
            });
        });

        // Description char counter + scoring
        const descTA = sec.querySelector('#gbp-description');
        if (descTA) {
            const updateDesc = () => {
                const counter = sec.querySelector('.gbp-char-counter');
                const len = descTA.value.length;
                if (counter) {
                    counter.textContent = len + ' / 750';
                    counter.classList.toggle('over', len > 750);
                }
                this._updateDescScore();
            };
            descTA.addEventListener('input', updateDesc);
            // Initial score
            setTimeout(() => this._updateDescScore(), 100);
        }

        // Keywords chip input binding
        const kwInput = sec.querySelector('#gbp-kw-input');
        if (kwInput) {
            kwInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ',') {
                    e.preventDefault();
                    this._addDescKeyword(kwInput.value.trim());
                    kwInput.value = '';
                }
            });
            kwInput.addEventListener('blur', () => {
                if (kwInput.value.trim()) {
                    this._addDescKeyword(kwInput.value.trim());
                    kwInput.value = '';
                }
            });
        }
    },

    _renderSection(key, title, bodyHtml) {
        const isOpen = this._openSections[key] ? ' open' : '';
        const isDirty = this._dirty[key] ? ' dirty' : '';
        const isSaving = this._saving[key] ? ' saving' : '';
        const badgeClass = this._saving[key] ? 'saving' : (this._dirty[key] ? 'dirty' : 'synced');
        const badgeText = this._saving[key] ? '⟳ Envoi...' : (this._dirty[key] ? '● Modifié' : '✓ Sync');
        const icon = '<svg class="gbp-section-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>';

        return '<div class="gbp-section' + isOpen + isDirty + isSaving + '" data-section="' + key + '">' +
            '<div class="gbp-section-header">' +
                '<div class="gbp-section-title">' + icon + ' ' + title + '</div>' +
                '<span class="gbp-sync-badge ' + badgeClass + '" data-badge="' + key + '">' + badgeText + '</span>' +
            '</div>' +
            '<div class="gbp-section-body"><div class="gbp-section-content">' + bodyHtml + '</div>' +
                '<div class="gbp-section-footer"><button class="bp" data-save="' + key + '" onclick="APP.gbpProfile.saveSection(\'' + key + '\')">Enregistrer</button></div>' +
            '</div>' +
        '</div>';
    },

    _renderIdentity() {
        const title = this._google?.title || this._local?.name || '';
        return '<div class="fg"><label class="fl">Nom de l\'établissement</label>' +
            '<div class="gbp-field-with-ai">' +
                '<input type="text" class="fi" id="gbp-title" data-field="title" value="' + APP.escHtml(title) + '" maxlength="100" placeholder="Nom commercial">' +
                '<button type="button" class="gbp-ai-btn" onclick="APP.gbpProfile._aiSuggest(\'title\')" title="Vérifier la conformité du nom"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg> IA</button>' +
            '</div>' +
            '<div class="gbp-ai-result" id="gbp-ai-title" style="display:none"></div>' +
            '<span class="fl-hint">Tel qu\'il apparaît sur Google Maps — nom commercial exact uniquement (100 car. max)</span></div>';
    },

    _renderDescription() {
        const desc = this._google?.profile?.description || '';
        const len = desc.length;

        // Zone mots-clés (chips)
        let kwHtml = '<div class="fg gbp-kw-section">' +
            '<label class="fl">Mots-clés à intégrer <span style="color:var(--t3);font-weight:400">(2-3 recommandés)</span></label>' +
            '<div class="gbp-kw-chips" id="gbp-kw-chips">';
        this._descKeywords.forEach((kw, i) => {
            kwHtml += '<span class="gbp-kw-chip">' + APP.escHtml(kw) +
                '<button type="button" onclick="APP.gbpProfile._removeDescKeyword(' + i + ')">&times;</button></span>';
        });
        kwHtml += '<input type="text" class="gbp-kw-input" id="gbp-kw-input" placeholder="Tapez un mot-clé + Entrée…">' +
            '</div>' +
            '<span class="fl-hint">Ex : restaurant italien, pizza, livraison — ces mots-clés seront intégrés par l\'IA</span></div>';

        // Description textarea
        let html = kwHtml;
        html += '<div class="fg"><div class="fl" style="display:flex;justify-content:space-between;align-items:center;">Description de l\'établissement' +
            '<button type="button" class="gbp-ai-btn" onclick="APP.gbpProfile._aiSuggest(\'description\')" title="Générer une description optimisée avec l\'IA"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg> IA</button></div>' +
            '<textarea class="fi" id="gbp-description" data-field="description" rows="6" maxlength="750" placeholder="Décrivez votre activité, vos services, votre zone géographique...">' + APP.escHtml(desc) + '</textarea>' +
            '<div class="gbp-ai-result" id="gbp-ai-description" style="display:none"></div>' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px;"><span class="fl-hint">Optimisez avec l\'IA pour le SEO local</span>' +
            '<span class="gbp-char-counter' + (len > 750 ? ' over' : '') + '">' + len + ' / 750</span></div></div>';

        // Scoring meter
        html += '<div class="gbp-score-meter" id="gbp-desc-score">' +
            '<div class="gbp-score-header"><span class="gbp-score-label">Optimisation SEO</span><span class="gbp-score-value" id="gbp-score-val">—</span></div>' +
            '<div class="gbp-score-bar"><div class="gbp-score-fill" id="gbp-score-fill" style="width:0%"></div></div>' +
            '<div class="gbp-score-details" id="gbp-score-details"></div>' +
        '</div>';

        return html;
    },

    _renderContact() {
        const phone = this._google?.phoneNumbers?.primaryPhone || this._local?.phone || '';
        const website = this._google?.websiteUri || this._local?.website || '';
        return '<div class="gbp-form-row">' +
            '<div class="fg"><label class="fl">Téléphone</label>' +
                '<input type="tel" class="fi" id="gbp-phone" data-field="phone" value="' + APP.escHtml(phone) + '" placeholder="+33 1 23 45 67 89"></div>' +
            '<div class="fg"><label class="fl">Site web</label>' +
                '<input type="url" class="fi" id="gbp-website" data-field="website" value="' + APP.escHtml(website) + '" placeholder="https://example.com"></div>' +
        '</div>';
    },

    _renderAddress() {
        const addr = this._google?.storefrontAddress || {};
        const lines = (addr.addressLines || []).join('\n') || this._local?.address || '';
        const locality = addr.locality || this._local?.city || '';
        const postalCode = addr.postalCode || this._local?.postal_code || '';
        const regionCode = addr.regionCode || 'FR';
        return '<div class="gbp-address-note"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg> Les modifications d\'adresse peuvent nécessiter une vérification Google.</div>' +
            '<div class="fg"><label class="fl">Adresse</label>' +
                '<textarea class="fi" id="gbp-address-lines" data-field="address_lines" rows="2" placeholder="Numero et rue">' + APP.escHtml(lines) + '</textarea></div>' +
            '<div class="gbp-form-row">' +
                '<div class="fg"><label class="fl">Ville</label><input type="text" class="fi" id="gbp-locality" data-field="locality" value="' + APP.escHtml(locality) + '"></div>' +
                '<div class="fg"><label class="fl">Code postal</label><input type="text" class="fi" id="gbp-postal" data-field="postal_code" value="' + APP.escHtml(postalCode) + '"></div>' +
                '<div class="fg"><label class="fl">Pays</label><input type="text" class="fi" id="gbp-region" data-field="region_code" value="' + APP.escHtml(regionCode) + '" maxlength="2"></div>' +
            '</div>';
    },

    _renderCatégories() {
        const cats = this._google?.catégories || {};
        const primary = cats.primaryCategory || {};
        const additional = cats.additionalCatégories || [];

        let html = '<div class="fg"><label class="fl">Catégorie principale</label>' +
            '<div class="gbp-autocomplete">' +
                '<input type="text" class="fi gbp-cat-input" id="gbp-primary-cat" data-field="primary_cat" value="' + APP.escHtml(primary.displayName || '') + '" ' +
                    'data-cat-name="' + APP.escHtml(primary.name || '') + '" data-validated="' + (primary.name ? '1' : '0') + '" ' +
                    'placeholder="Rechercher une catégorie Google…" autocomplete="off" oninput="APP.gbpProfile._onCatSearch(this)" ' +
                    'onblur="APP.gbpProfile._validateCatInput(this)">' +
                '<div class="gbp-autocomplete-list" id="gbp-cat-results" style="display:none"></div>' +
            '</div>' +
            (primary.name ? '<span class="fl-hint gbp-cat-valid">✓ ' + APP.escHtml(primary.name) + '</span>' : '<span class="fl-hint gbp-cat-invalid">⚠ Sélectionnez une catégorie dans la liste</span>') +
            '</div>';

        html += '<div class="fg"><label class="fl">Catégories additionnelles <span style="color:var(--t3);font-weight:400">(max 9)</span></label>';
        html += '<div id="gbp-additional-cats">';
        additional.forEach((cat, i) => {
            html += '<div class="gbp-cat-tag" data-cat-name="' + APP.escHtml(cat.name || '') + '">' +
                '<span>' + APP.escHtml(cat.displayName || cat.name || '') + '</span>' +
                '<button type="button" class="gbp-cat-remove" onclick="APP.gbpProfile._removeAdditionalCat(this)" title="Supprimer">&times;</button></div>';
        });
        html += '</div>';
        if (additional.length < 9) {
            html += '<div class="gbp-autocomplete" style="margin-top:8px;">' +
                '<input type="text" class="fi gbp-cat-input" id="gbp-add-cat" placeholder="Ajouter une catégorie Google…" autocomplete="off" ' +
                    'oninput="APP.gbpProfile._onAddCatSearch(this)">' +
                '<div class="gbp-autocomplete-list" id="gbp-add-cat-results" style="display:none"></div></div>';
        }
        html += '</div>';
        return html;
    },

    _renderHours() {
        const periods = this._google?.regularHours?.periods || [];
        const dayPeriods = {};
        this.DAYS.forEach(d => { dayPeriods[d] = []; });
        periods.forEach(p => {
            if (p.openDay) {
                dayPeriods[p.openDay] = dayPeriods[p.openDay] || [];
                dayPeriods[p.openDay].push(p);
            }
        });

        let html = '<div class="gbp-hours-grid">';
        this.DAYS.forEach(day => {
            const slots = dayPeriods[day] || [];
            const isOpen = slots.length > 0;
            html += '<div class="gbp-hours-row" data-day="' + day + '">';
            html += '<div class="gbp-hours-day">' + this.DAY_LABELS[day] + '</div>';
            html += '<div class="gbp-hours-slots">';

            if (isOpen) {
                slots.forEach((s, idx) => {
                    const openH = (s.openTime?.hours ?? 9).toString().padStart(2,'0');
                    const openM = (s.openTime?.minutes ?? 0).toString().padStart(2,'0');
                    const closeH = (s.closeTime?.hours ?? 18).toString().padStart(2,'0');
                    const closeM = (s.closeTime?.minutes ?? 0).toString().padStart(2,'0');
                    html += '<div class="gbp-hours-slot" data-slot="' + idx + '">' +
                        '<input type="time" class="gbp-time-input" data-field="hours" value="' + openH + ':' + openM + '"> ' +
                        '<span style="color:var(--t3)">–</span> ' +
                        '<input type="time" class="gbp-time-input" data-field="hours" value="' + closeH + ':' + closeM + '">';
                    if (idx > 0) html += ' <button type="button" class="gbp-slot-remove" onclick="APP.gbpProfile._removeSlot(this)" title="Supprimer">&times;</button>';
                    html += '</div>';
                });
            } else {
                html += '<div class="gbp-hours-slot" data-slot="0">' +
                    '<input type="time" class="gbp-time-input" data-field="hours" value="09:00"> ' +
                    '<span style="color:var(--t3)">–</span> ' +
                    '<input type="time" class="gbp-time-input" data-field="hours" value="18:00"></div>';
            }

            html += '</div>';
            html += '<div class="gbp-hours-actions">' +
                '<label class="gbp-hours-toggle"><input type="checkbox" data-field="hours" ' + (isOpen ? 'checked' : '') + ' onchange="APP.gbpProfile._toggleDay(this)"> Ouvert</label>' +
                '<button type="button" class="gbp-hours-add-btn" onclick="APP.gbpProfile._addSlot(this)" title="Ajouter un creneau">+</button>' +
            '</div>';
            html += '</div>';
        });
        html += '</div>';
        html += '<div style="margin-top:12px;"><button type="button" class="bs" style="font-size:12px;padding:6px 12px;" onclick="APP.gbpProfile._copyHoursToAll()">Copier lundi vers tous les jours</button></div>';
        return html;
    },

    _renderSpecialHours() {
        const specials = this._google?.specialHours?.specialHourPeriods || [];
        let html = '<div id="gbp-special-hours-list">';
        if (specials.length === 0) {
            html += '<div class="gbp-empty-hint">Aucun horaire exceptionnel défini.</div>';
        } else {
            specials.forEach((sp, i) => {
                const d = sp.startDate || {};
                const dateStr = (d.year || '') + '-' + String(d.month || 1).padStart(2,'0') + '-' + String(d.day || 1).padStart(2,'0');
                const isClosed = sp.closed === true;
                html += '<div class="gbp-special-row" data-index="' + i + '">' +
                    '<input type="date" class="fi gbp-special-date" data-field="special-hours" value="' + dateStr + '">' +
                    '<label class="gbp-hours-toggle"><input type="checkbox" ' + (isClosed ? 'checked' : '') + ' data-field="special-hours"> Fermé</label>';
                if (!isClosed) {
                    const oH = (sp.openTime?.hours ?? 0).toString().padStart(2,'0');
                    const oM = (sp.openTime?.minutes ?? 0).toString().padStart(2,'0');
                    const cH = (sp.closeTime?.hours ?? 0).toString().padStart(2,'0');
                    const cM = (sp.closeTime?.minutes ?? 0).toString().padStart(2,'0');
                    html += '<input type="time" class="gbp-time-input" data-field="special-hours" value="' + oH + ':' + oM + '">' +
                        '<span style="color:var(--t3)">–</span>' +
                        '<input type="time" class="gbp-time-input" data-field="special-hours" value="' + cH + ':' + cM + '">';
                }
                html += '<button type="button" class="gbp-slot-remove" onclick="APP.gbpProfile._removeSpecial(this)">&times;</button></div>';
            });
        }
        html += '</div>';
        html += '<button type="button" class="bs" style="font-size:12px;padding:6px 12px;margin-top:10px;" onclick="APP.gbpProfile._addSpecialHour()">+ Ajouter une date</button>';
        return html;
    },

    _renderServiceArea() {
        const sa = this._google?.serviceArea || {};
        const bType = sa.businessType || 'CUSTOMER_LOCATION_ONLY';
        const places = sa.places?.placeInfos || [];
        let html = '<div class="fg"><label class="fl">Type de service</label>' +
            '<select class="fi" id="gbp-service-type" data-field="service-area">' +
                '<option value="CUSTOMER_LOCATION_ONLY"' + (bType === 'CUSTOMER_LOCATION_ONLY' ? ' selected' : '') + '>Clients sur place uniquement</option>' +
                '<option value="CUSTOMER_AND_BUSINESS_LOCATION"' + (bType === 'CUSTOMER_AND_BUSINESS_LOCATION' ? ' selected' : '') + '>Sur place + livraison/déplacement</option>' +
                '<option value="CUSTOMER_LOCATION_ONLY"' + (bType === 'CUSTOMER_LOCATION_ONLY' ? ' selected' : '') + '>Déplacement uniquement</option>' +
            '</select></div>';

        html += '<div class="fg"><label class="fl">Zones desservies</label>';
        html += '<div id="gbp-service-places">';
        places.forEach((p, i) => {
            html += '<div class="gbp-cat-tag"><span>' + APP.escHtml(p.placeName || p.placeId || '') + '</span>' +
                '<button type="button" class="gbp-cat-remove" onclick="APP.gbpProfile._removeServicePlace(this)">&times;</button></div>';
        });
        html += '</div>';
        html += '<div class="gbp-autocomplete" style="margin-top:8px;">' +
            '<input type="text" class="fi" id="gbp-new-place" placeholder="Rechercher une ville…" autocomplete="off" oninput="APP.gbpProfile._onCitySearch(this)">' +
            '<div class="gbp-autocomplete-list" id="gbp-city-results" style="display:none"></div>' +
        '</div>' +
        '<span class="fl-hint">Recherchez parmi les communes officielles françaises</span></div>';
        return html;
    },

    // ==== ACTIONS ====

    markDirty(section) {
        this._dirty[section] = true;
        const el = document.querySelector(`.gbp-section[data-section="${section}"]`);
        if (el) {
            el.classList.add('dirty');
            const badge = el.querySelector('[data-badge]');
            if (badge) { badge.className = 'gbp-sync-badge dirty'; badge.textContent = '● Modifié'; }
        }
    },

    _updateSyncBadge(section, status) {
        const badge = document.querySelector(`[data-badge="${section}"]`);
        if (!badge) return;
        const texts = { synced: '✓ Sync', dirty: '● Modifié', saving: '⟳ Envoi…', error: '⚠ Erreur' };
        badge.className = 'gbp-sync-badge ' + status;
        badge.textContent = texts[status] || status;
        const el = badge.closest('.gbp-section');
        if (el) {
            el.classList.toggle('dirty', status === 'dirty');
            el.classList.toggle('error', status === 'error');
        }
    },

    async saveSection(section) {
        const btn = document.querySelector('[data-save="' + section + '"]');
        if (!btn) return;

        // Validation front avant envoi
        if (section === 'catégories') {
            const primaryInput = document.getElementById('gbp-primary-cat');
            if (!primaryInput?.dataset.catName) {
                APP.toast('Sélectionnez une catégorie dans la liste déroulante Google', 'error');
                return;
            }
        }
        if (section === 'description') {
            const desc = document.getElementById('gbp-description')?.value || '';
            if (desc.length > 750) {
                APP.toast('La description dépasse 750 caractères', 'error');
                return;
            }
        }

        APP.btnLoading(btn, true);
        this._saving[section] = true;
        this._updateSyncBadge(section, 'saving');

        const fd = this._collectSectionData(section);
        fd.append('action', 'save_section');
        fd.append('location_id', this._locationId);
        fd.append('section', section);

        const data = await APP.fetch('/api/gbp-profile.php', { method: 'POST', body: fd });
        APP.btnLoading(btn, false);
        this._saving[section] = false;

        if (data.success) {
            APP.toast(data.message || 'Fiche mise a jour sur Google', 'success');
            delete this._dirty[section];
            this._updateSyncBadge(section, 'synced');
        } else {
            APP.toast(data.error || 'Erreur Google', 'error');
            this._updateSyncBadge(section, 'error');
        }
    },

    _collectSectionData(section) {
        const fd = new FormData();
        switch (section) {
            case 'identity':
                fd.append('title', document.getElementById('gbp-title')?.value || '');
                break;
            case 'description':
                fd.append('description', document.getElementById('gbp-description')?.value || '');
                break;
            case 'contact':
                fd.append('phone', document.getElementById('gbp-phone')?.value || '');
                fd.append('website', document.getElementById('gbp-website')?.value || '');
                break;
            case 'address':
                fd.append('address_lines', document.getElementById('gbp-address-lines')?.value || '');
                fd.append('locality', document.getElementById('gbp-locality')?.value || '');
                fd.append('postal_code', document.getElementById('gbp-postal')?.value || '');
                fd.append('region_code', document.getElementById('gbp-region')?.value || 'FR');
                break;
            case 'catégories': {
                const primaryInput = document.getElementById('gbp-primary-cat');
                fd.append('primary_category_name', primaryInput?.dataset.catName || '');
                const addTags = document.querySelectorAll('#gbp-additional-cats .gbp-cat-tag');
                const additional = [];
                addTags.forEach(t => { if (t.dataset.catName) additional.push({ name: t.dataset.catName, displayName: t.querySelector('span')?.textContent || '' }); });
                fd.append('additional_catégories', JSON.stringify(additional));
                break;
            }
            case 'hours': {
                const periods = [];
                document.querySelectorAll('.gbp-hours-row').forEach(row => {
                    const day = row.dataset.day;
                    const isOpen = row.querySelector('input[type="checkbox"]')?.checked;
                    if (!isOpen) return;
                    row.querySelectorAll('.gbp-hours-slot').forEach(slot => {
                        const times = slot.querySelectorAll('.gbp-time-input');
                        if (times.length >= 2) {
                            const [oH, oM] = times[0].value.split(':').map(Number);
                            const [cH, cM] = times[1].value.split(':').map(Number);
                            periods.push({
                                openDay: day, closeDay: day,
                                openTime: { hours: oH, minutes: oM },
                                closeTime: { hours: cH, minutes: cM }
                            });
                        }
                    });
                });
                fd.append('periods', JSON.stringify(periods));
                break;
            }
            case 'special-hours': {
                const specials = [];
                document.querySelectorAll('.gbp-special-row').forEach(row => {
                    const dateVal = row.querySelector('.gbp-special-date')?.value;
                    if (!dateVal) return;
                    const [y, m, d] = dateVal.split('-').map(Number);
                    const isClosed = row.querySelector('input[type="checkbox"]')?.checked;
                    const sp = { startDate: { year: y, month: m, day: d }, closed: isClosed };
                    if (!isClosed) {
                        const times = row.querySelectorAll('.gbp-time-input');
                        if (times.length >= 2) {
                            const [oH, oM] = times[0].value.split(':').map(Number);
                            const [cH, cM] = times[1].value.split(':').map(Number);
                            sp.openTime = { hours: oH, minutes: oM };
                            sp.closeTime = { hours: cH, minutes: cM };
                        }
                    }
                    specials.push(sp);
                });
                fd.append('special_hour_periods', JSON.stringify(specials));
                break;
            }
            case 'service-area': {
                const bType = document.getElementById('gbp-service-type')?.value || 'CUSTOMER_LOCATION_ONLY';
                const placeTags = document.querySelectorAll('#gbp-service-places .gbp-cat-tag');
                const places = [];
                placeTags.forEach(t => { places.push({ placeName: t.querySelector('span')?.textContent || '' }); });
                fd.append('service_area', JSON.stringify({ businessType: bType, places: { placeInfos: places } }));
                break;
            }
        }
        return fd;
    },

    // ==== HOURS HELPERS ====

    _toggleDay(checkbox) {
        const row = checkbox.closest('.gbp-hours-row');
        const slots = row.querySelector('.gbp-hours-slots');
        if (checkbox.checked) {
            slots.style.opacity = '1';
            slots.style.pointerEvents = '';
        } else {
            slots.style.opacity = '0.3';
            slots.style.pointerEvents = 'none';
        }
        this.markDirty('hours');
    },

    _addSlot(btn) {
        const row = btn.closest('.gbp-hours-row');
        const slotsDiv = row.querySelector('.gbp-hours-slots');
        const idx = slotsDiv.querySelectorAll('.gbp-hours-slot').length;
        const slotHtml = '<div class="gbp-hours-slot" data-slot="' + idx + '">' +
            '<input type="time" class="gbp-time-input" data-field="hours" value="14:00"> ' +
            '<span style="color:var(--t3)">–</span> ' +
            '<input type="time" class="gbp-time-input" data-field="hours" value="18:00">' +
            ' <button type="button" class="gbp-slot-remove" onclick="APP.gbpProfile._removeSlot(this)">&times;</button></div>';
        slotsDiv.insertAdjacentHTML('beforeend', slotHtml);
        this.markDirty('hours');
    },

    _removeSlot(btn) {
        btn.closest('.gbp-hours-slot').remove();
        this.markDirty('hours');
    },

    _copyHoursToAll() {
        const firstRow = document.querySelector('.gbp-hours-row[data-day="MONDAY"]');
        if (!firstRow) return;
        const isOpen = firstRow.querySelector('input[type="checkbox"]')?.checked;
        const slots = firstRow.querySelectorAll('.gbp-hours-slot');
        const times = [];
        slots.forEach(s => {
            const inputs = s.querySelectorAll('.gbp-time-input');
            if (inputs.length >= 2) times.push([inputs[0].value, inputs[1].value]);
        });

        document.querySelectorAll('.gbp-hours-row').forEach(row => {
            if (row.dataset.day === 'MONDAY') return;
            const cb = row.querySelector('input[type="checkbox"]');
            if (cb) cb.checked = isOpen;
            const slotsDiv = row.querySelector('.gbp-hours-slots');
            let slotsHtml = '';
            times.forEach((t, idx) => {
                slotsHtml += '<div class="gbp-hours-slot" data-slot="' + idx + '">' +
                    '<input type="time" class="gbp-time-input" data-field="hours" value="' + t[0] + '"> ' +
                    '<span style="color:var(--t3)">–</span> ' +
                    '<input type="time" class="gbp-time-input" data-field="hours" value="' + t[1] + '">';
                if (idx > 0) slotsHtml += ' <button type="button" class="gbp-slot-remove" onclick="APP.gbpProfile._removeSlot(this)">&times;</button>';
                slotsHtml += '</div>';
            });
            slotsDiv.innerHTML = slotsHtml;
            if (cb) {
                const s = row.querySelector('.gbp-hours-slots');
                if (isOpen) { s.style.opacity = '1'; s.style.pointerEvents = ''; }
                else { s.style.opacity = '0.3'; s.style.pointerEvents = 'none'; }
            }
        });
        this.markDirty('hours');
        APP.toast('Horaires copiés depuis lundi', 'success');
    },

    // ==== SPECIAL HOURS HELPERS ====

    _addSpecialHour() {
        const list = document.getElementById('gbp-special-hours-list');
        if (!list) return;
        const emptyHint = list.querySelector('.gbp-empty-hint');
        if (emptyHint) emptyHint.remove();
        const idx = list.querySelectorAll('.gbp-special-row').length;
        const today = new Date().toISOString().split('T')[0];
        list.insertAdjacentHTML('beforeend',
            '<div class="gbp-special-row" data-index="' + idx + '">' +
                '<input type="date" class="fi gbp-special-date" data-field="special-hours" value="' + today + '">' +
                '<label class="gbp-hours-toggle"><input type="checkbox" data-field="special-hours"> Ferme</label>' +
                '<input type="time" class="gbp-time-input" data-field="special-hours" value="09:00">' +
                '<span style="color:var(--t3)">–</span>' +
                '<input type="time" class="gbp-time-input" data-field="special-hours" value="18:00">' +
                '<button type="button" class="gbp-slot-remove" onclick="APP.gbpProfile._removeSpecial(this)">&times;</button></div>');
        this.markDirty('special-hours');
    },

    _removeSpecial(btn) {
        btn.closest('.gbp-special-row').remove();
        this.markDirty('special-hours');
    },

    // ==== CATEGORY AUTOCOMPLETE ====

    _onCatSearch(input) {
        // Quand l'utilisateur tape, invalider la selection precedente
        input.dataset.validated = '0';
        input.dataset.catName = '';
        clearTimeout(this._catDebounce);
        this._catDebounce = setTimeout(async () => {
            const q = input.value.trim();
            const results = document.getElementById('gbp-cat-results');
            if (!results) return;
            if (q.length < 2) { results.style.display = 'none'; return; }
            const data = await APP.fetch('/api/gbp-profile.php?action=list_catégories&q=' + encodeURIComponent(q));
            if (!data.catégories || data.catégories.length === 0) { results.style.display = 'none'; return; }
            results.innerHTML = data.catégories.map(c =>
                '<div class="gbp-autocomplete-item" onclick="APP.gbpProfile._selectPrimaryCat(this)" data-name="' + APP.escHtml(c.name) + '">' + APP.escHtml(c.displayName) + '</div>'
            ).join('');
            results.style.display = 'block';
        }, 300);
    },

    _selectPrimaryCat(item) {
        const input = document.getElementById('gbp-primary-cat');
        if (input) {
            input.value = item.textContent;
            input.dataset.catName = item.dataset.name;
            input.dataset.validated = '1';
        }
        document.getElementById('gbp-cat-results').style.display = 'none';
        // Update hint
        const section = input.closest('.gbp-section-content');
        if (section) {
            const hints = section.querySelectorAll('.gbp-cat-valid, .gbp-cat-invalid');
            hints.forEach(h => h.remove());
            input.closest('.fg').insertAdjacentHTML('beforeend', '<span class="fl-hint gbp-cat-valid">✓ ' + APP.escHtml(item.dataset.name) + '</span>');
        }
        this.markDirty('catégories');
    },

    _validateCatInput(input) {
        // Si l'utilisateur a tape du texte sans selectionner dans la liste, on reset
        setTimeout(() => {
            if (input.dataset.validated !== '1' && input.value.trim()) {
                // Garder le texte mais avertir
                const section = input.closest('.fg');
                if (section) {
                    const existing = section.querySelector('.gbp-cat-valid, .gbp-cat-invalid');
                    if (existing) existing.remove();
                    section.insertAdjacentHTML('beforeend', '<span class="fl-hint gbp-cat-invalid">⚠ Sélectionnez une catégorie dans la liste déroulante</span>');
                }
            }
        }, 200); // delai pour laisser le onclick du dropdown se declencher
    },

    _onAddCatSearch(input) {
        clearTimeout(this._catDebounce);
        this._catDebounce = setTimeout(async () => {
            const q = input.value.trim();
            const results = document.getElementById('gbp-add-cat-results');
            if (!results) return;
            if (q.length < 2) { results.style.display = 'none'; return; }
            const data = await APP.fetch('/api/gbp-profile.php?action=list_catégories&q=' + encodeURIComponent(q));
            if (!data.catégories || data.catégories.length === 0) { results.style.display = 'none'; return; }
            results.innerHTML = data.catégories.map(c =>
                '<div class="gbp-autocomplete-item" onclick="APP.gbpProfile._addAdditionalCat(this)" data-name="' + APP.escHtml(c.name) + '">' + APP.escHtml(c.displayName) + '</div>'
            ).join('');
            results.style.display = 'block';
        }, 300);
    },

    _addAdditionalCat(item) {
        const container = document.getElementById('gbp-additional-cats');
        if (!container) return;
        // Check max 9
        if (container.querySelectorAll('.gbp-cat-tag').length >= 9) { APP.toast('Maximum 9 catégories additionnelles', 'warning'); return; }
        // Check duplicate
        const existing = container.querySelectorAll('.gbp-cat-tag');
        for (const t of existing) { if (t.dataset.catName === item.dataset.name) { APP.toast('Catégorie déjà ajoutée', 'warning'); return; } }
        container.insertAdjacentHTML('beforeend',
            '<div class="gbp-cat-tag" data-cat-name="' + APP.escHtml(item.dataset.name) + '">' +
                '<span>' + APP.escHtml(item.textContent) + '</span>' +
                '<button type="button" class="gbp-cat-remove" onclick="APP.gbpProfile._removeAdditionalCat(this)">&times;</button></div>');
        document.getElementById('gbp-add-cat').value = '';
        document.getElementById('gbp-add-cat-results').style.display = 'none';
        this.markDirty('catégories');
    },

    _removeAdditionalCat(btn) {
        btn.closest('.gbp-cat-tag').remove();
        this.markDirty('catégories');
    },

    // ==== SERVICE AREA HELPERS (autocomplete villes) ====

    _cityDebounce: null,

    _onCitySearch(input) {
        clearTimeout(this._cityDebounce);
        this._cityDebounce = setTimeout(async () => {
            const q = input.value.trim();
            const results = document.getElementById('gbp-city-results');
            if (!results) return;
            if (q.length < 2) { results.style.display = 'none'; return; }
            const data = await APP.fetch('/api/gbp-profile.php?action=search_cities&q=' + encodeURIComponent(q));
            if (!data.cities || data.cities.length === 0) {
                results.innerHTML = '<div class="gbp-autocomplete-item" style="color:var(--t3);cursor:default;">Aucune commune trouvée</div>';
                results.style.display = 'block';
                return;
            }
            results.innerHTML = data.cities.map(c =>
                '<div class="gbp-autocomplete-item" onclick="APP.gbpProfile._selectCity(this)" data-name="' + APP.escHtml(c.name) + '" data-code="' + APP.escHtml(c.code) + '">' +
                    '<strong>' + APP.escHtml(c.name) + '</strong>' +
                    (c.postalCode ? ' <span style="color:var(--t3)">(' + APP.escHtml(c.postalCode) + ')</span>' : '') +
                    (c.department ? ' <span style="color:var(--t3);font-size:11px;">— ' + APP.escHtml(c.department) + '</span>' : '') +
                '</div>'
            ).join('');
            results.style.display = 'block';
        }, 300);
    },

    _selectCity(item) {
        const container = document.getElementById('gbp-service-places');
        if (!container) return;
        const cityName = item.dataset.name;
        // Check duplicate
        const existing = container.querySelectorAll('.gbp-cat-tag');
        for (const t of existing) { if (t.querySelector('span')?.textContent === cityName) { APP.toast('Ville déjà ajoutée', 'warning'); return; } }
        container.insertAdjacentHTML('beforeend',
            '<div class="gbp-cat-tag"><span>' + APP.escHtml(cityName) + '</span>' +
                '<button type="button" class="gbp-cat-remove" onclick="APP.gbpProfile._removeServicePlace(this)">&times;</button></div>');
        document.getElementById('gbp-new-place').value = '';
        document.getElementById('gbp-city-results').style.display = 'none';
        this.markDirty('service-area');
    },

    _removeServicePlace(btn) {
        btn.closest('.gbp-cat-tag').remove();
        this.markDirty('service-area');
    },

    // ==== AI SUGGESTION ====

    async _aiSuggest(field) {
        const resultBox = document.getElementById('gbp-ai-' + field);
        if (!resultBox) return;

        // Récupérer la valeur actuelle
        let currentValue = '';
        if (field === 'title') currentValue = document.getElementById('gbp-title')?.value || '';
        else if (field === 'description') currentValue = document.getElementById('gbp-description')?.value || '';

        // Afficher le loading
        resultBox.style.display = 'block';
        resultBox.className = 'gbp-ai-result loading';
        resultBox.innerHTML = '<div class="gbp-ai-loading"><svg class="spin" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg> Génération en cours…</div>';

        const fd = new FormData();
        fd.append('action', 'ai_suggest');
        fd.append('location_id', this._locationId);
        fd.append('field', field);
        fd.append('current_value', currentValue);
        if (field === 'description' && this._descKeywords.length > 0) {
            fd.append('keywords', this._descKeywords.join(', '));
        }

        const data = await APP.fetch('/api/gbp-profile.php', { method: 'POST', body: fd });

        if (data.success && data.suggestion) {
            const suggestion = data.suggestion;
            resultBox.className = 'gbp-ai-result';
            resultBox.innerHTML =
                '<div class="gbp-ai-header"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg> Suggestion IA</div>' +
                '<div class="gbp-ai-text">' + APP.escHtml(suggestion) + '</div>' +
                '<div class="gbp-ai-actions">' +
                    '<button type="button" class="bp" style="font-size:12px;padding:5px 14px;" onclick="APP.gbpProfile._applyAiSuggestion(\'' + field + '\')">Appliquer</button>' +
                    '<button type="button" class="bs" style="font-size:12px;padding:5px 14px;" onclick="APP.gbpProfile._aiSuggest(\'' + field + '\')">Régénérer</button>' +
                    '<button type="button" class="bs" style="font-size:12px;padding:5px 14px;" onclick="this.closest(\'.gbp-ai-result\').style.display=\'none\'">Fermer</button>' +
                '</div>';
            resultBox._suggestion = suggestion;
        } else {
            resultBox.className = 'gbp-ai-result error';
            resultBox.innerHTML = '<div style="color:var(--r);font-size:12px;">⚠ ' + APP.escHtml(data.error || 'Erreur de génération') + '</div>';
        }
    },

    _applyAiSuggestion(field) {
        const resultBox = document.getElementById('gbp-ai-' + field);
        if (!resultBox || !resultBox._suggestion) return;

        if (field === 'title') {
            const input = document.getElementById('gbp-title');
            if (input) { input.value = resultBox._suggestion; this.markDirty('identity'); }
        } else if (field === 'description') {
            const ta = document.getElementById('gbp-description');
            if (ta) {
                ta.value = resultBox._suggestion;
                this.markDirty('description');
                // Update char counter
                const counter = document.querySelector('.gbp-char-counter');
                if (counter) {
                    counter.textContent = ta.value.length + ' / 750';
                    counter.classList.toggle('over', ta.value.length > 750);
                }
            }
        }
        resultBox.style.display = 'none';
        APP.toast('Suggestion appliquée', 'success');
        // Mettre à jour le score après application
        if (field === 'description') this._updateDescScore();
    },

    // ==== KEYWORDS MANAGEMENT ====

    _addDescKeyword(kw) {
        if (!kw || kw.length < 2) return;
        kw = kw.replace(/,/g, '').trim();
        if (!kw) return;
        if (this._descKeywords.includes(kw.toLowerCase())) {
            APP.toast('Mot-clé déjà ajouté', 'warning');
            return;
        }
        if (this._descKeywords.length >= 5) {
            APP.toast('Maximum 5 mots-clés', 'warning');
            return;
        }
        this._descKeywords.push(kw.toLowerCase());
        this._renderKeywordChips();
        this._updateDescScore();
    },

    _removeDescKeyword(idx) {
        this._descKeywords.splice(idx, 1);
        this._renderKeywordChips();
        this._updateDescScore();
    },

    _renderKeywordChips() {
        const container = document.getElementById('gbp-kw-chips');
        if (!container) return;
        const input = container.querySelector('.gbp-kw-input');
        // Supprimer les chips existantes
        container.querySelectorAll('.gbp-kw-chip').forEach(c => c.remove());
        // Recréer
        this._descKeywords.forEach((kw, i) => {
            const chip = document.createElement('span');
            chip.className = 'gbp-kw-chip';
            chip.innerHTML = APP.escHtml(kw) + '<button type="button" onclick="APP.gbpProfile._removeDescKeyword(' + i + ')">&times;</button>';
            container.insertBefore(chip, input);
        });
    },

    // ==== DESCRIPTION SCORING ====

    _updateDescScore() {
        const desc = (document.getElementById('gbp-description')?.value || '').trim();
        const scoreEl = document.getElementById('gbp-score-val');
        const fillEl = document.getElementById('gbp-score-fill');
        const detailsEl = document.getElementById('gbp-score-details');
        if (!scoreEl || !fillEl || !detailsEl) return;

        const checks = [];
        const len = desc.length;
        const city = this._local?.city || '';
        const keywords = this._descKeywords;

        // 1. Longueur (25 pts)
        let lenScore = 0;
        if (len >= 700 && len <= 750) { lenScore = 25; }
        else if (len >= 600 && len < 700) { lenScore = 18; }
        else if (len >= 400 && len < 600) { lenScore = 10; }
        else if (len >= 200 && len < 400) { lenScore = 5; }
        else if (len > 750) { lenScore = 5; }
        checks.push({
            label: 'Longueur (' + len + '/750)',
            score: lenScore,
            max: 25,
            ok: lenScore >= 18,
            hint: len < 600 ? 'Visez 700-750 caractères' : (len > 750 ? 'Trop long, max 750' : '')
        });

        // 2. Mots-clés intégrés (30 pts)
        let kwScore = 0;
        const kwDétails = [];
        if (keywords.length === 0) {
            kwScore = 0;
            kwDétails.push({ label: 'Ajoutez des mots-clés ci-dessus', found: false });
        } else {
            const descLower = desc.toLowerCase();
            let foundCount = 0;
            keywords.forEach(kw => {
                const regex = new RegExp(kw.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
                const matches = desc.match(regex);
                const count = matches ? matches.length : 0;
                const found = count > 0;
                if (found) foundCount++;
                kwDétails.push({
                    label: kw,
                    found: found,
                    count: count,
                    tooMany: count > 2
                });
            });
            kwScore = Math.round((foundCount / keywords.length) * 30);
        }
        checks.push({
            label: 'Mots-clés',
            score: kwScore,
            max: 30,
            ok: kwScore >= 20,
            details: kwDétails
        });

        // 3. Mention géographique (15 pts)
        let geoScore = 0;
        if (city && desc.toLowerCase().includes(city.toLowerCase())) {
            geoScore = 15;
        } else if (desc.match(/\b(paris|lyon|marseille|toulouse|nice|nantes|montpellier|strasbourg|bordeaux|lille|rennes|reims|toulon|grenoble|dijon|angers|nîmes|saint-étienne)\b/i)) {
            geoScore = 10;
        }
        checks.push({
            label: 'Mention géographique',
            score: geoScore,
            max: 15,
            ok: geoScore >= 10,
            hint: geoScore === 0 ? ('Mentionnez "' + city + '" dans la description') : ''
        });

        // 4. Première phrase impactante (10 pts)
        let firstScore = 0;
        const first250 = desc.substring(0, 250).toLowerCase();
        if (keywords.length > 0 && keywords.some(kw => first250.includes(kw))) {
            firstScore += 5;
        }
        if (city && first250.includes(city.toLowerCase())) {
            firstScore += 5;
        }
        checks.push({
            label: 'Accroche (250 premiers car.)',
            score: firstScore,
            max: 10,
            ok: firstScore >= 5,
            hint: firstScore < 5 ? 'Placez un mot-clé + ville en début de description' : ''
        });

        // 5. Appel à l'action (10 pts)
        let ctaScore = 0;
        const ctaPatterns = /contactez|appelez|rendez-vous|découvrez|visitez|venez|n'hésitez|réservez|demandez|profitez|bénéficiez/i;
        if (ctaPatterns.test(desc)) ctaScore = 10;
        checks.push({
            label: 'Appel à l\'action',
            score: ctaScore,
            max: 10,
            ok: ctaScore > 0,
            hint: ctaScore === 0 ? 'Ajoutez un CTA (ex: "Contactez-nous", "Découvrez…")' : ''
        });

        // 6. Pas de contenu interdit (10 pts)
        let cleanScore = 10;
        const forbidden = /\b(https?:\/\/|www\.|@|€|\$|%\s*de réduction|promo|solde|gratuit|meilleur prix)\b/i;
        if (forbidden.test(desc)) cleanScore = 0;
        checks.push({
            label: 'Conformité (pas d\'URL, prix, promo)',
            score: cleanScore,
            max: 10,
            ok: cleanScore === 10,
            hint: cleanScore === 0 ? 'Retirez les URLs, prix ou promos' : ''
        });

        // Total
        const total = checks.reduce((s, c) => s + c.score, 0);
        const maxTotal = checks.reduce((s, c) => s + c.max, 0);
        const pct = Math.round((total / maxTotal) * 100);

        let level, color;
        if (pct >= 80) { level = 'Excellent'; color = 'var(--g)'; }
        else if (pct >= 60) { level = 'Bon'; color = 'var(--acc)'; }
        else if (pct >= 40) { level = 'Moyen'; color = 'var(--o)'; }
        else { level = 'À améliorer'; color = 'var(--r)'; }

        scoreEl.textContent = total + '/' + maxTotal + ' — ' + level;
        scoreEl.style.color = color;
        fillEl.style.width = pct + '%';
        fillEl.style.background = color;

        // Détails
        let detailHtml = '';
        checks.forEach(c => {
            const icon = c.ok ? '✓' : '✗';
            const cls = c.ok ? 'ok' : 'warn';
            detailHtml += '<div class="gbp-score-row ' + cls + '"><span class="gbp-score-icon">' + icon + '</span> ' +
                '<span class="gbp-score-check-label">' + c.label + '</span>' +
                '<span class="gbp-score-pts">' + c.score + '/' + c.max + '</span>';
            if (c.hint) detailHtml += '<div class="gbp-score-hint">' + c.hint + '</div>';
            // Keyword details
            if (c.details) {
                c.details.forEach(d => {
                    if (d.found !== undefined) {
                        const kwIcon = d.found ? '✓' : '✗';
                        const kwCls = d.found ? 'ok' : 'warn';
                        let kwInfo = d.found ? (d.count + 'x' + (d.tooMany ? ' ⚠ trop de répétitions' : '')) : 'absent';
                        detailHtml += '<div class="gbp-score-kw ' + kwCls + '"><span>' + kwIcon + '</span> "' + APP.escHtml(d.label) + '" — ' + kwInfo + '</div>';
                    }
                });
            }
            detailHtml += '</div>';
        });
        detailsEl.innerHTML = detailHtml;
    }
};

// ====================================================================
// MODULE : STATISTIQUES GOOGLE BUSINESS PROFILE
// ====================================================================
APP.stats = {
    _locationId: null,
    _data: null,
    _months: 0,
    _customFrom: null,
    _customTo: null,

    _fmtDate(d) { return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0'); },

    _initDefaultRange() {
        if (!this._customFrom && !this._customTo) {
            const today = new Date();
            const from = new Date(today);
            from.setDate(from.getDate() - 179);
            this._customFrom = this._fmtDate(from);
            this._customTo = this._fmtDate(today);
        }
    },

    async load(locationId) {
        if (locationId) this._locationId = locationId;
        this._initDefaultRange();
        const c = document.getElementById('module-content');
        if (c) c.innerHTML = '<div style="padding:40px;text-align:center;color:var(--t2)"><svg class="spin" viewBox="0 0 24 24" style="width:32px;height:32px;stroke:var(--acc);fill:none;stroke-width:2"><path d="M12 2v4m0 12v4m-7.07-3.93l2.83-2.83m8.48-8.48l2.83-2.83M2 12h4m12 0h4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83"/></svg><div style="margin-top:12px">Chargement des statistiques...</div></div>';

        let url = `/api/stats.php?action=list&location_id=${this._locationId}`;
        url += `&from=${this._customFrom}&to=${this._customTo}`;
        const data = await APP.fetch(url);
        if (data.error && data.total_days === undefined) {
            this._data = { monthly: [], trends: {}, daily: [], last_sync: null, total_days: 0 };
        } else {
            this._data = data;
        }
        this.render();
    },

    setMonths(months) {
        // Convertir en jours glissants
        const today = new Date();
        const from = new Date(today);
        from.setDate(from.getDate() - (months * 30 - 1));
        this._customFrom = this._fmtDate(from);
        this._customTo = this._fmtDate(today);
        this._months = 0;
        this.load();
    },

    setCustomRange(from, to) {
        if (!from || !to) return;
        if (from > to) { const tmp = from; from = to; to = tmp; }
        this._customFrom = from;
        this._customTo = to;
        this._months = 0;
        this.load();
    },

    // ---- Date Range Picker ----
    _pickerFrom: null,
    _pickerTo: null,
    _pickerLeftMonth: null,
    _pickerLeftYear: null,

    openDatePicker() {
        if (document.getElementById('stats-drp-overlay')) { this.closeDatePicker(); return; }
        const today = new Date();
        this._pickerFrom = this._customFrom ? new Date(this._customFrom + 'T00:00:00') : null;
        this._pickerTo = this._customTo ? new Date(this._customTo + 'T00:00:00') : null;
        this._pickerLeftMonth = today.getMonth() - 1;
        this._pickerLeftYear = today.getFullYear();
        if (this._pickerLeftMonth < 0) { this._pickerLeftMonth = 11; this._pickerLeftYear--; }
        this._renderPicker();
        // Fermer au clic exterieur
        setTimeout(() => {
            document.addEventListener('click', this._pickerOutsideClick = (e) => {
                const el = document.getElementById('stats-drp-overlay');
                if (el && !el.contains(e.target) && !e.target.closest('.dash-period-btn')) this.closeDatePicker();
            });
        }, 10);
    },

    closeDatePicker() {
        const el = document.getElementById('stats-drp-overlay');
        if (el) el.remove();
        if (this._pickerOutsideClick) document.removeEventListener('click', this._pickerOutsideClick);
    },

    _pickerNav(delta) {
        this._pickerLeftMonth += delta;
        if (this._pickerLeftMonth > 11) { this._pickerLeftMonth = 0; this._pickerLeftYear++; }
        if (this._pickerLeftMonth < 0) { this._pickerLeftMonth = 11; this._pickerLeftYear--; }
        this._renderPicker();
    },

    _pickerPreset(key) {
        const today = new Date();
        const y = today.getFullYear(), m = today.getMonth(), d = today.getDate();
        let from, to = new Date(y, m, d);
        switch(key) {
            case 'today': from = new Date(y, m, d); break;
            case 'yesterday': from = new Date(y, m, d-1); to = new Date(y, m, d-1); break;
            case '7d': from = new Date(y, m, d-6); break;
            case '14d': from = new Date(y, m, d-13); break;
            case '28d': from = new Date(y, m, d-27); break;
            case 'this_month': from = new Date(y, m, 1); break;
            case 'last_month': from = new Date(y, m-1, 1); to = new Date(y, m, 0); break;
            case '3m': from = new Date(y, m-3, d); break;
            case '6m': from = new Date(y, m-6, d); break;
            case '1y': from = new Date(y-1, m, d); break;
            case 'this_year': from = new Date(y, 0, 1); break;
            case 'last_year': from = new Date(y-1, 0, 1); to = new Date(y-1, 11, 31); break;
            default: return;
        }
        this._pickerFrom = from;
        this._pickerTo = to;
        // Appliquer directement
        this._pickerApply();
    },

    _pickerClickDay(dateStr) {
        const clicked = new Date(dateStr + 'T00:00:00');
        if (!this._pickerFrom || (this._pickerFrom && this._pickerTo)) {
            this._pickerFrom = clicked;
            this._pickerTo = null;
        } else {
            if (clicked < this._pickerFrom) {
                this._pickerTo = this._pickerFrom;
                this._pickerFrom = clicked;
            } else {
                this._pickerTo = clicked;
            }
        }
        this._renderPicker();
    },

    _pickerApply() {
        if (this._pickerFrom && this._pickerTo) {
            const fmt = d => d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
            this.closeDatePicker();
            this.setCustomRange(fmt(this._pickerFrom), fmt(this._pickerTo));
        }
    },

    _fmtDateFr(d) {
        if (!d) return '';
        const mois = ['jan','fév','mars','avr','mai','juin','juil','août','sept','oct','nov','déc'];
        return d.getDate() + ' ' + mois[d.getMonth()] + ' ' + d.getFullYear();
    },

    _renderPicker() {
        let el = document.getElementById('stats-drp-overlay');
        if (!el) {
            el = document.createElement('div');
            el.id = 'stats-drp-overlay';
            const anchor = document.querySelector('.dash-period-selector');
            if (anchor) anchor.parentElement.style.position = 'relative';
            if (anchor) anchor.parentElement.appendChild(el);
        }
        const moisNoms = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
        const jours = ['Lu','Ma','Me','Je','Ve','Sa','Di'];
        const lm = this._pickerLeftMonth, ly = this._pickerLeftYear;
        let rm = lm + 1, ry = ly;
        if (rm > 11) { rm = 0; ry++; }

        const presets = [
            {key:'today',label:"Aujourd'hui"},{key:'yesterday',label:'Hier'},
            {key:'7d',label:'7 derniers jours'},{key:'14d',label:'14 derniers jours'},{key:'28d',label:'28 derniers jours'},
            {key:'this_month',label:'Ce mois-ci'},{key:'last_month',label:'Le mois dernier'},
            {key:'3m',label:'3 derniers mois'},{key:'6m',label:'6 derniers mois'},
            {key:'1y',label:'1 an'},{key:'this_year',label:'Cette année'},{key:'last_year',label:'Année dernière'},
        ];

        let h = '<div class="drp-container">';
        // Presets
        h += '<div class="drp-presets">';
        for (const p of presets) {
            h += `<div class="drp-preset" onclick="APP.stats._pickerPreset('${p.key}')">${p.label}</div>`;
        }
        h += '</div>';
        // Calendars
        h += '<div class="drp-calendars">';
        h += '<div class="drp-nav"><button class="drp-arrow" onclick="APP.stats._pickerNav(-1)">◀</button><span class="drp-month-title">' + moisNoms[lm] + ' ' + ly + '</span><span class="drp-month-title">' + moisNoms[rm] + ' ' + ry + '</span><button class="drp-arrow" onclick="APP.stats._pickerNav(1)">▶</button></div>';
        h += '<div class="drp-cals-row">';
        h += this._renderPickerMonth(ly, lm, jours);
        h += this._renderPickerMonth(ry, rm, jours);
        h += '</div></div>';
        // Footer
        h += '<div class="drp-footer">';
        h += '<span class="drp-range-label">' + (this._pickerFrom ? this._fmtDateFr(this._pickerFrom) : '...') + ' — ' + (this._pickerTo ? this._fmtDateFr(this._pickerTo) : '...') + '</span>';
        h += '<div class="drp-actions"><button class="btn bs bsm" onclick="APP.stats.closeDatePicker()">Annuler</button>';
        h += '<button class="btn bp bsm" onclick="APP.stats._pickerApply()"' + (!this._pickerFrom || !this._pickerTo ? ' disabled style="opacity:.4"' : '') + '>Mettre à jour</button></div>';
        h += '</div></div>';
        el.innerHTML = h;
    },

    _renderPickerMonth(year, month, jours) {
        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);
        let startDow = firstDay.getDay();
        startDow = startDow === 0 ? 6 : startDow - 1;
        const totalDays = lastDay.getDate();
        const today = new Date();
        const todayStr = today.getFullYear() + '-' + String(today.getMonth()+1).padStart(2,'0') + '-' + String(today.getDate()).padStart(2,'0');

        let h = '<div class="drp-month-grid">';
        for (const d of jours) h += `<div class="drp-dow">${d}</div>`;
        for (let i = 0; i < startDow; i++) h += '<div class="drp-empty"></div>';
        for (let day = 1; day <= totalDays; day++) {
            const dt = new Date(year, month, day);
            const dateStr = year + '-' + String(month+1).padStart(2,'0') + '-' + String(day).padStart(2,'0');
            let cls = 'drp-day';
            if (dateStr === todayStr) cls += ' drp-today';
            if (this._pickerFrom && dt.getTime() === this._pickerFrom.getTime()) cls += ' drp-sel-start';
            if (this._pickerTo && dt.getTime() === this._pickerTo.getTime()) cls += ' drp-sel-end';
            if (this._pickerFrom && this._pickerTo && dt > this._pickerFrom && dt < this._pickerTo) cls += ' drp-in-range';
            if (dt > today) cls += ' drp-disabled';
            h += `<div class="${cls}" onclick="APP.stats._pickerClickDay('${dateStr}')">${day}</div>`;
        }
        h += '</div>';
        return h;
    },

    async fetchFromGoogle() {
        const btn = document.getElementById('btn-sync-stats');
        if (btn) { btn.disabled = true; btn.innerHTML = '<svg class="spin" viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2"><path d="M12 2v4m0 12v4m-7.07-3.93l2.83-2.83m8.48-8.48l2.83-2.83M2 12h4m12 0h4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83"/></svg> Synchronisation...'; }

        const fd = new FormData();
        fd.append('action', 'fetch');
        fd.append('location_id', this._locationId);
        const data = await APP.fetch('/api/stats.php', { method: 'POST', body: fd });
        console.log('Stats fetch response:', data);

        if (data.success) {
            if (data.days_synced > 0) {
                APP.toast(data.message || 'Statistiques synchronisées', 'success');
            } else {
                APP.toast('Aucune donnée retournée par Google. Verifiez la console (F12) pour le debug.', 'warning');
                console.warn('Stats debug:', data._debug);
            }
            this.load(this._locationId);
        } else {
            APP.toast(data.error || 'Erreur de synchronisation', 'error');
            console.error('Stats error:', data);
            if (btn) { btn.disabled = false; btn.innerHTML = '<svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Synchroniser avec Google'; }
        }
    },

    render() {
        const c = document.getElementById('module-content');
        if (!c) return;
        const d = this._data;
        const monthly = d.monthly || [];
        const trends = d.trends || {};
        const cm = d.compared_months || null;
        const hasData = monthly.length > 0;
        const last6 = monthly.slice(-6);
        const seo = d.seo || {};
        const reviews = d.reviews || {};
        const loc = d.location_info || {};
        const posts = d.posts || {};
        const kws = seo.keywords || [];
        const periodTotals = d.period_totals || null;
        const periodTrends = d.period_trends || null;
        const isCustom = !!(this._customFrom && this._customTo);
        const dayCount = isCustom ? Math.ceil((new Date(this._customTo + 'T00:00:00') - new Date(this._customFrom + 'T00:00:00')) / 86400000) + 1 : 0;
        const usePeriodMode = isCustom && periodTotals;

        let h = '';

        // ====== HEADER ======
        h += '<div class="sh" style="flex-wrap:wrap;gap:10px;padding:14px 20px">';
        h += '<div style="flex:1;min-width:180px;display:flex;align-items:center;gap:10px">';
        h += '<div class="stit" style="font-size:14px;margin:0">Statistiques</div>';
        if (usePeriodMode && periodTrends) h += '<span style="font-size:11px;color:var(--primary);font-weight:600;background:var(--primary-soft);padding:2px 8px;border-radius:5px">' + dayCount + ' jours glissants vs ' + dayCount + 'j précédents</span>';
        else if (cm) h += '<span style="font-size:11px;color:var(--primary);font-weight:600;background:var(--primary-soft);padding:2px 8px;border-radius:5px">' + this._monthNameFull(cm.current) + ' vs ' + this._monthNameFull(cm.previous) + '</span>';
        h += '</div>';
        h += '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">';
        // Period selector — un seul bouton qui ouvre le date picker
        h += '<div class="dash-period-selector">';
        let periodLabel = '';
        if (this._customFrom && this._customTo) {
            periodLabel = '📅 ' + this._fmtDateFr(new Date(this._customFrom+'T00:00:00')) + ' — ' + this._fmtDateFr(new Date(this._customTo+'T00:00:00'));
        } else {
            periodLabel = '📅 180 derniers jours';
        }
        h += '<button class="dash-period-btn active" onclick="APP.stats.openDatePicker()">' + periodLabel + '</button>';
        h += '</div>';
        if (d.last_sync) h += '<span style="font-size:11px;color:var(--t3)"><svg viewBox="0 0 24 24" style="width:11px;height:11px;stroke:currentColor;fill:none;stroke-width:2;vertical-align:-1px"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> ' + d.last_sync + '</span>';
        h += '<button class="btn bp" id="btn-sync-stats" onclick="APP.stats.fetchFromGoogle()" style="font-size:12px;padding:6px 12px">';
        h += '<svg viewBox="0 0 24 24" style="width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>';
        h += ' Sync</button>';
        h += '</div></div>';

        // ====================================================================
        // SECTION 1 : SCORE GLOBAL & KPIs CLES
        // ====================================================================
        h += '<div style="padding:20px 20px 0">';

        // Score global simplifie (inspiré acquisition)
        const avgPos = seo.avg_position || 0;
        const posColor = avgPos && avgPos <= 3 ? 'var(--g)' : avgPos && avgPos <= 7 ? 'var(--o)' : avgPos && avgPos <= 20 ? 'var(--p)' : 'var(--r)';
        const ratingVal = reviews.avg_rating || 0;
        const ratingColor = ratingVal >= 4.5 ? 'var(--g)' : ratingVal >= 4.0 ? 'var(--o)' : ratingVal >= 3.0 ? 'var(--p)' : 'var(--r)';

        h += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:24px;">';

        // KPI: Position moyenne
        h += `<div class="sc" style="padding:16px;text-align:center;">
            <div class="sl" style="margin-bottom:4px">Position moyenne</div>
            <div style="font-size:32px;font-weight:700;font-family:'Space Mono',monospace;color:${posColor};letter-spacing:-2px;">${avgPos || '—'}</div>
            <div style="font-size:11px;color:var(--t3);margin-top:2px">${seo.tracked || 0} mot(s) suivi(s)</div>
        </div>`;

        // KPI: Top 3
        h += `<div class="sc" style="padding:16px;text-align:center;">
            <div class="sl" style="margin-bottom:4px">Top 3</div>
            <div style="font-size:32px;font-weight:700;font-family:'Space Mono',monospace;color:var(--g);letter-spacing:-2px;">${seo.top3 || 0}</div>
            <div style="font-size:11px;color:var(--t3);margin-top:2px">sur ${seo.total || 0} mots-cles</div>
        </div>`;

        // KPI: Note Google
        h += `<div class="sc" style="padding:16px;text-align:center;">
            <div class="sl" style="margin-bottom:4px">Note Google</div>
            <div style="font-size:32px;font-weight:700;font-family:'Space Mono',monospace;color:${ratingColor};letter-spacing:-2px;">${ratingVal || '—'}</div>
            <div style="font-size:11px;color:var(--t3);margin-top:2px">${reviews.total || 0} avis</div>
        </div>`;

        // KPI: Avis sans réponse
        const unColor = reviews.unanswered > 0 ? 'var(--o)' : 'var(--g)';
        h += `<div class="sc" style="padding:16px;text-align:center;">
            <div class="sl" style="margin-bottom:4px">Sans réponse</div>
            <div style="font-size:32px;font-weight:700;font-family:'Space Mono',monospace;color:${unColor};letter-spacing:-2px;">${reviews.unanswered || 0}</div>
            <div style="font-size:11px;color:var(--t3);margin-top:2px">a traiter</div>
        </div>`;

        // KPI: Posts
        h += `<div class="sc" style="padding:16px;text-align:center;">
            <div class="sl" style="margin-bottom:4px">Posts</div>
            <div style="font-size:32px;font-weight:700;font-family:'Space Mono',monospace;color:var(--primary);letter-spacing:-2px;">${posts.scheduled || 0}</div>
            <div style="font-size:11px;color:var(--t3);margin-top:2px">${posts.published || 0} pub. / ${posts.draft || 0} brouil.</div>
        </div>`;

        h += '</div>';
        h += '</div>';

        // ====================================================================
        // SECTION 2 : SEO LOCAL — Positions & Visibilité grille
        // ====================================================================
        h += '<div style="padding:0 20px">';
        h += '<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--t3);margin-bottom:12px;display:flex;align-items:center;gap:8px"><svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg> Visibilité SEO locale</div>';

        // Distribution des positions (barres horizontales)
        if (seo.total > 0) {
            const posData = [
                { label: 'Top 3', count: seo.top3 || 0, color: 'var(--g)' },
                { label: 'Top 4-10', count: seo.top10 || 0, color: 'var(--o)' },
                { label: 'Top 11-20', count: seo.top20 || 0, color: 'var(--p)' },
                { label: 'Hors 20', count: seo.out || 0, color: 'var(--r)' },
            ];
            const maxCount = Math.max(...posData.map(p => p.count), 1);

            h += '<div class="sec" style="margin-bottom:16px"><div style="padding:16px 20px">';
            h += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">';
            h += '<div style="font-size:14px;font-weight:600;">Répartition des positions</div>';
            h += `<div style="font-size:12px;color:var(--t2);">${seo.total} mots-cles actifs</div>`;
            h += '</div>';

            for (const p of posData) {
                const pct = seo.total > 0 ? Math.round(p.count / seo.total * 100) : 0;
                const barW = maxCount > 0 ? Math.round(p.count / maxCount * 100) : 0;
                h += `<div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
                    <div style="width:70px;font-size:12px;color:var(--t2);font-weight:500;">${p.label}</div>
                    <div style="flex:1;height:24px;background:var(--subtle-bg);border-radius:6px;overflow:hidden;position:relative;">
                        <div style="height:100%;width:${barW}%;background:${p.color};border-radius:6px;transition:width .5s;min-width:${p.count > 0 ? '2px' : '0'};"></div>
                    </div>
                    <div style="width:50px;text-align:right;font-size:13px;font-weight:700;font-family:'Space Mono',monospace;color:${p.color};">${p.count}</div>
                    <div style="width:40px;text-align:right;font-size:11px;color:var(--t3);">${pct}%</div>
                </div>`;
            }
            h += '</div></div>';

            // Tableau des mots-cles avec visibilité grille
            h += '<div class="sec" style="margin-bottom:16px">';
            h += '<div class="sh" style="padding:10px 16px"><div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Détail par mot-clé</div></div>';
            h += '<div style="overflow-x:auto"><table><thead><tr>';
            h += '<th>Mot-cle</th><th style="text-align:center">Position</th><th style="text-align:center">Tendance</th><th style="text-align:center">Visibilité grille</th><th style="text-align:center">Pos. moy. grille</th>';
            h += '</tr></thead><tbody>';

            for (const kw of kws) {
                const kwPos = kw.position;
                const kwPosColor = kwPos === null ? 'var(--t3)' : kwPos <= 3 ? 'var(--g)' : kwPos <= 10 ? 'var(--o)' : kwPos <= 20 ? 'var(--p)' : 'var(--r)';
                const trendVal = kw.trend;
                let trendHtml = '<span style="color:var(--t3)">—</span>';
                if (trendVal !== null && trendVal !== 0) {
                    if (trendVal > 0) trendHtml = `<span style="color:var(--g);font-weight:600;">&#9650; +${trendVal}</span>`;
                    else trendHtml = `<span style="color:var(--r);font-weight:600;">&#9660; ${trendVal}</span>`;
                } else if (trendVal === 0) {
                    trendHtml = '<span style="color:var(--t3)">= stable</span>';
                }
                const vis = kw.visibility;
                const visColor = vis === null ? 'var(--t3)' : vis >= 50 ? 'var(--g)' : vis >= 20 ? 'var(--o)' : 'var(--r)';
                const gridAvg = kw.grid_avg;
                const gridAvgColor = gridAvg === null ? 'var(--t3)' : gridAvg <= 3 ? 'var(--g)' : gridAvg <= 10 ? 'var(--o)' : 'var(--r)';

                h += `<tr>`;
                h += `<td><div style="font-weight:600;font-size:13px;">${kw.keyword}</div><div style="font-size:11px;color:var(--t3);">${kw.city || ''}</div></td>`;
                h += `<td style="text-align:center;"><span style="display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:8px;font-weight:700;font-family:'Space Mono',monospace;font-size:13px;background:${kwPos !== null ? (kwPos <= 3 ? 'var(--gbg)' : kwPos <= 10 ? 'var(--obg)' : kwPos <= 20 ? 'var(--pbg)' : 'var(--rbg)') : 'var(--subtle-bg)'};color:${kwPosColor};">${kwPos !== null ? kwPos : '—'}</span></td>`;
                h += `<td style="text-align:center;font-size:12px;">${trendHtml}</td>`;
                h += `<td style="text-align:center;"><span style="font-weight:700;font-family:'Space Mono',monospace;color:${visColor};">${vis !== null ? vis + '%' : '—'}</span>${kw.grid_top3 !== null ? '<div style="font-size:10px;color:var(--t3);">' + kw.grid_top3 + '/' + (kw.grid_total || '?') + ' top 3</div>' : ''}</td>`;
                h += `<td style="text-align:center;font-weight:600;font-family:'Space Mono',monospace;font-size:13px;color:${gridAvgColor};">${gridAvg !== null ? gridAvg : '—'}</td>`;
                h += '</tr>';
            }
            h += '</tbody></table></div></div>';
        } else {
            h += '<div class="sec" style="padding:32px;text-align:center;color:var(--t3);font-size:13px;margin-bottom:16px">Aucun mot-clé configuré. <a href="?view=client&location=' + this._locationId + '&tab=keywords" style="color:var(--primary);text-decoration:underline;">Ajouter des mots-cles</a></div>';
        }
        h += '</div>';

        // ====================================================================
        // SECTION 3 : E-REPUTATION
        // ====================================================================
        h += '<div style="padding:0 20px">';
        h += '<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--t3);margin-bottom:12px;display:flex;align-items:center;gap:8px"><svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg> E-réputation</div>';

        if (reviews.total > 0) {
            const dist = reviews.distribution || {};
            const maxDist = Math.max(...Object.values(dist), 1);

            h += '<div class="sec" style="margin-bottom:16px"><div style="padding:20px;display:flex;gap:32px;flex-wrap:wrap;align-items:flex-start">';

            // Note globale + etoiles
            h += `<div style="text-align:center;min-width:120px;">
                <div style="font-size:48px;font-weight:700;font-family:'Space Mono',monospace;color:${ratingColor};letter-spacing:-3px;">${ratingVal}</div>
                <div style="color:var(--o);font-size:18px;letter-spacing:2px;margin:4px 0;">${'&#9733;'.repeat(Math.round(ratingVal))}${'&#9734;'.repeat(5 - Math.round(ratingVal))}</div>
                <div style="font-size:12px;color:var(--t3);">${reviews.total} avis</div>
            </div>`;

            // Distribution des etoiles
            h += '<div style="flex:1;min-width:200px;">';
            for (let star = 5; star >= 1; star--) {
                const cnt = dist[star] || 0;
                const barW = maxDist > 0 ? Math.round(cnt / maxDist * 100) : 0;
                const barColor = star >= 4 ? 'var(--g)' : star === 3 ? 'var(--o)' : 'var(--r)';
                h += `<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                    <div style="width:16px;font-size:12px;font-weight:600;color:var(--t2);text-align:right;">${star}&#9733;</div>
                    <div style="flex:1;height:16px;background:var(--subtle-bg);border-radius:4px;overflow:hidden;">
                        <div style="height:100%;width:${barW}%;background:${barColor};border-radius:4px;transition:width .5s;min-width:${cnt > 0 ? '2px' : '0'};"></div>
                    </div>
                    <div style="width:32px;font-size:11px;color:var(--t3);text-align:right;font-family:'Space Mono',monospace;">${cnt}</div>
                </div>`;
            }
            h += '</div>';

            // Indicateurs rapides
            h += `<div style="min-width:140px;">
                <div style="background:var(--subtle-bg);border-radius:10px;padding:14px;margin-bottom:8px;text-align:center;">
                    <div style="font-size:11px;color:var(--t3);text-transform:uppercase;font-weight:600;letter-spacing:.5px;margin-bottom:4px;">Sans réponse</div>
                    <div style="font-size:24px;font-weight:700;font-family:'Space Mono',monospace;color:${unColor};">${reviews.unanswered || 0}</div>
                </div>
                <div style="background:var(--subtle-bg);border-radius:10px;padding:14px;text-align:center;">
                    <div style="font-size:11px;color:var(--t3);text-transform:uppercase;font-weight:600;letter-spacing:.5px;margin-bottom:4px;">Taux réponse</div>
                    <div style="font-size:24px;font-weight:700;font-family:'Space Mono',monospace;color:var(--g);">${reviews.total > 0 ? Math.round((1 - reviews.unanswered / reviews.total) * 100) : 0}%</div>
                </div>
            </div>`;

            h += '</div></div>';

            // 5 derniers avis
            const recent = reviews.recent || [];
            if (recent.length > 0) {
                h += '<div class="sec" style="margin-bottom:16px">';
                h += '<div class="sh" style="padding:10px 16px"><div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Derniers avis</div></div>';
                for (const r of recent) {
                    const stars = parseInt(r.rating) || 0;
                    const starColor = stars >= 4 ? 'var(--o)' : stars >= 3 ? 'var(--t2)' : 'var(--r)';
                    const hasReply = (r.is_replied == 1) || (r.reply && r.reply.trim());
                    const date = r.create_time ? new Date(r.create_time).toLocaleDateString('fr-FR', {day:'2-digit', month:'short', year:'numeric'}) : '';
                    h += `<div style="padding:12px 20px;border-bottom:1px solid var(--subtle-line);display:flex;gap:12px;align-items:flex-start;">
                        <div style="width:34px;height:34px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;flex-shrink:0;">${(r.author_name || '?')[0].toUpperCase()}</div>
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:2px;">
                                <span style="font-weight:600;font-size:13px;">${r.author_name || 'Anonyme'}</span>
                                <span style="color:${starColor};font-size:12px;">${'&#9733;'.repeat(stars)}${'&#9734;'.repeat(5 - stars)}</span>
                                <span style="font-size:11px;color:var(--t3);margin-left:auto;">${date}</span>
                            </div>
                            ${r.comment ? `<div style="font-size:13px;color:var(--t2);line-height:1.5;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">${r.comment}</div>` : '<div style="font-size:12px;color:var(--t3);font-style:italic;">Aucun commentaire</div>'}
                            ${hasReply ? '<div style="font-size:11px;color:var(--g);margin-top:4px;display:flex;align-items:center;gap:4px;"><svg viewBox="0 0 24 24" style="width:12px;height:12px;stroke:var(--g);fill:none;stroke-width:2;"><path d="M20 6L9 17l-5-5"/></svg> Répondu</div>' : ''}
                        </div>
                    </div>`;
                }
                h += '</div>';
            }
        } else {
            h += '<div class="sec" style="padding:32px;text-align:center;color:var(--t3);font-size:13px;margin-bottom:16px">Aucun avis synchronise. <a href="?view=client&location=' + this._locationId + '&tab=reviews" style="color:var(--primary);text-decoration:underline;">Voir les avis</a></div>';
        }
        h += '</div>';

        // ====================================================================
        // SECTION 4 : PRESENCE DIGITALE
        // ====================================================================
        h += '<div style="padding:0 20px">';
        h += '<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--t3);margin-bottom:12px;display:flex;align-items:center;gap:8px"><svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg> Présence digitale</div>';

        const visualCount = parseInt(loc.total_visuals) || 0;
        const hasWeb = !!loc.website;
        const hasPhone = !!loc.phone;
        const hasDesc = !!loc.description;
        const hasCat = !!loc.category;
        const hasAddr = !!loc.address;

        // Score de complétude
        let complétude = 0;
        if (hasWeb) complétude += 20;
        if (hasPhone) complétude += 20;
        if (hasDesc) complétude += 15;
        if (hasCat) complétude += 15;
        if (hasAddr) complétude += 10;
        if (reviews.total >= 10) complétude += 10;
        else if (reviews.total >= 5) complétude += 5;
        if (seo.total >= 3) complétude += 10;
        else if (seo.total >= 1) complétude += 5;
        const compColor = complétude >= 80 ? 'var(--g)' : complétude >= 50 ? 'var(--o)' : 'var(--r)';

        h += '<div class="sec" style="margin-bottom:24px"><div style="padding:20px">';

        // Barre complétude
        h += `<div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;">
            <div style="flex:1;">
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                    <span style="font-size:14px;font-weight:600;">Complétude de la fiche</span>
                    <span style="font-size:14px;font-weight:700;font-family:'Space Mono',monospace;color:${compColor};">${complétude}%</span>
                </div>
                <div style="height:8px;background:var(--subtle-bg);border-radius:4px;overflow:hidden;">
                    <div style="height:100%;width:${complétude}%;background:${compColor};border-radius:4px;transition:width .5s;"></div>
                </div>
            </div>
        </div>`;

        // Grille d'infos
        h += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px;">';

        const checks = [
            { label: 'Site web', val: loc.website || null, ok: hasWeb, display: hasWeb ? loc.website.replace(/^https?:\/\//, '').replace(/\/$/, '') : 'Non renseigne' },
            { label: 'Téléphone', val: loc.phone || null, ok: hasPhone, display: loc.phone || 'Non renseigne' },
            { label: 'Catégorie', val: loc.category || null, ok: hasCat, display: loc.category || 'Non renseignee' },
            { label: 'Adresse', val: loc.address || null, ok: hasAddr, display: loc.address || 'Non renseignee' },
            { label: 'Mots-cles', val: seo.total, ok: seo.total >= 3, display: seo.total + ' actif(s)', color: seo.total >= 3 ? 'var(--g)' : seo.total >= 1 ? 'var(--o)' : 'var(--r)' },
            { label: 'Avis', val: reviews.total, ok: reviews.total >= 10, display: reviews.total + ' avis', color: reviews.total >= 10 ? 'var(--g)' : reviews.total >= 5 ? 'var(--o)' : 'var(--r)' },
        ];

        for (const ck of checks) {
            const iconColor = ck.ok ? 'var(--g)' : 'var(--r)';
            const icon = ck.ok ? '<svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:var(--g);fill:none;stroke-width:2.5;"><path d="M20 6L9 17l-5-5"/></svg>'
                               : '<svg viewBox="0 0 24 24" style="width:16px;height:16px;stroke:var(--r);fill:none;stroke-width:2.5;"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
            h += `<div style="background:var(--subtle-bg);border-radius:10px;padding:14px;">
                <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;">
                    ${icon}
                    <span style="font-size:11px;color:var(--t3);text-transform:uppercase;font-weight:600;letter-spacing:.5px;">${ck.label}</span>
                </div>
                <div style="font-size:13px;font-weight:600;color:${ck.color || (ck.ok ? 'var(--t1)' : 'var(--r)')};overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${ck.display}">${ck.display}</div>
            </div>`;
        }
        h += '</div>';
        h += '</div></div>';
        h += '</div>';

        // ====================================================================
        // SECTION 5 : PERFORMANCE GOOGLE BUSINESS (existant ameliore)
        // ====================================================================
        if (hasData) {
            h += '<div style="padding:0 20px">';
            h += '<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--t3);margin-bottom:12px;display:flex;align-items:center;gap:8px"><svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg> Performance Google Business</div>';

            // Données pour les cartes metriques
            const effTrends = (usePeriodMode && periodTrends) ? periodTrends : trends;
            const effCm = usePeriodMode ? null : cm;
            const pt = usePeriodMode ? periodTotals : null;

            // Visibilité (2 cards)
            h += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">';
            h += this._metricCard('Recherche Google', 'impressions_search', last6, monthly, effTrends, effCm, 'var(--inf)', pt ? pt.impressions_search : null);
            h += this._metricCard('Google Maps', 'impressions_maps', last6, monthly, effTrends, effCm, 'var(--o)', pt ? pt.impressions_maps : null);
            h += '</div>';

            // Interactions (3 cards)
            h += '<div class="stats-interaction-grid" style="margin-bottom:12px">';
            h += this._metricCard('Appels', 'call_clicks', last6, monthly, effTrends, effCm, 'var(--g)', pt ? pt.call_clicks : null);
            h += this._metricCard('Site web', 'website_clicks', last6, monthly, effTrends, effCm, 'var(--primary)', pt ? pt.website_clicks : null);
            h += this._metricCard('Itinéraires', 'direction_requests', last6, monthly, effTrends, effCm, 'var(--p)', pt ? pt.direction_requests : null);
            h += '</div>';

            // Table mensuelle
            if (monthly.length >= 2) {
                const isCurrent = cm ? cm.current : null;
                const isPrevious = cm ? cm.previous : null;

                h += '<div class="sec" style="margin-bottom:24px">';
                h += '<div class="sh" style="padding:10px 16px">';
                h += '<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Détail mensuel</div>';
                h += '<div style="font-size:11px;color:var(--t3)">' + monthly.length + ' mois</div>';
                h += '</div>';
                h += '<div style="overflow-x:auto"><table class="stats-table"><thead><tr>';
                h += '<th>Mois</th><th style="text-align:center">J.</th><th style="text-align:right">Rech.</th><th style="text-align:right">Maps</th><th style="text-align:right">Appels</th><th style="text-align:right">Web</th><th style="text-align:right">Itin.</th><th style="text-align:right">Total</th>';
                h += '</tr></thead><tbody>';

                for (let i = monthly.length - 1; i >= 0; i--) {
                    const m = monthly[i];
                    const total = (m.impressions_search||0) + (m.impressions_maps||0) + (m.call_clicks||0) + (m.website_clicks||0) + (m.direction_requests||0);
                    const prev = i > 0 ? monthly[i-1] : null;
                    let trendIcon = '';
                    if (prev) {
                        const prevTotal = (prev.impressions_search||0) + (prev.impressions_maps||0) + (prev.call_clicks||0) + (prev.website_clicks||0) + (prev.direction_requests||0);
                        if (total > prevTotal) trendIcon = ' <span style="color:var(--g)">&#9650;</span>';
                        else if (total < prevTotal) trendIcon = ' <span style="color:var(--r)">&#9660;</span>';
                    }
                    const isHL = (m.month === isCurrent || m.month === isPrevious);
                    const rowCls = isHL ? ' class="stats-row-hl"' : '';
                    h += '<tr' + rowCls + '>';
                    h += '<td style="font-weight:600;white-space:nowrap;font-size:12px">' + this._monthName(m.month) + (isHL ? ' <span style="color:var(--primary)">&#9679;</span>' : '') + '</td>';
                    h += '<td style="text-align:center;color:var(--t3);font-family:\'Space Mono\',monospace;font-size:11px">' + (m.days || '—') + '</td>';
                    h += '<td style="text-align:right;font-family:\'Space Mono\',monospace;font-size:12px">' + this._formatNum(m.impressions_search) + '</td>';
                    h += '<td style="text-align:right;font-family:\'Space Mono\',monospace;font-size:12px">' + this._formatNum(m.impressions_maps) + '</td>';
                    h += '<td style="text-align:right;font-family:\'Space Mono\',monospace;font-size:12px">' + (m.call_clicks || 0) + '</td>';
                    h += '<td style="text-align:right;font-family:\'Space Mono\',monospace;font-size:12px">' + (m.website_clicks || 0) + '</td>';
                    h += '<td style="text-align:right;font-family:\'Space Mono\',monospace;font-size:12px">' + (m.direction_requests || 0) + '</td>';
                    h += '<td style="text-align:right;font-weight:700;font-family:\'Space Mono\',monospace;font-size:12px">' + this._formatNum(total) + trendIcon + '</td>';
                    h += '</tr>';
                }
                h += '</tbody></table></div></div>';
            }
            h += '</div>';
        } else {
            h += '<div style="padding:0 20px">';
            h += '<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--t3);margin-bottom:12px">Performance Google Business</div>';
            h += '<div class="sec" style="padding:40px;text-align:center;color:var(--t3);font-size:13px;margin-bottom:24px">';
            h += 'Aucune donnée. Cliquez "Sync" pour récupérer les statistiques Google.';
            h += '</div></div>';
        }

        // ====================================================================
        // SECTION 6 : DONNÉES GOOGLE PLACES (temps réel)
        // ====================================================================
        if (this._placesData && this._placesData.linked) {
            h += this._renderPlacesSection();
        }

        c.innerHTML = h;

        // Charger les données Places API en async si pas encore fait
        if (!this._placesData && this._locationId) {
            this._loadPlacesData();
        }
    },

    _placesData: null,
    _placesHistory: null,

    async _loadPlacesData() {
        try {
            const [pData, pHistory] = await Promise.all([
                APP.fetch(`/api/places-data.php?action=get_cached&location_id=${this._locationId}`),
                APP.fetch(`/api/places-data.php?action=stats_history&location_id=${this._locationId}`),
            ]);
            this._placesData = pData;
            this._placesHistory = pHistory;
            if (pData.linked) this.render(); // Re-render avec les données Places
        } catch (e) {
            console.warn('Places data load error:', e);
        }
    },

    _renderPlacesSection() {
        const pd = this._placesData;
        const place = pd.place || {};
        const reviews = pd.reviews || [];
        const comp = pd.completeness || {};
        const history = this._placesHistory?.history || [];
        const esc = (s) => { if(!s)return''; const d=document.createElement('div');d.textContent=s;return d.innerHTML; };

        let h = '<div style="padding:0 20px">';
        h += '<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--t3);margin-bottom:12px;display:flex;align-items:center;gap:8px;justify-content:space-between;">';
        h += '<div style="display:flex;align-items:center;gap:8px;"><svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg> Données Google en temps réel</div>';
        // Bouton rafraîchir + timestamp
        const lastRefresh = pd.last_refresh ? new Date(pd.last_refresh) : null;
        const agoStr = lastRefresh ? this._timeAgo(lastRefresh) : '';
        h += `<div style="display:flex;align-items:center;gap:8px;">`;
        if (agoStr) h += `<span style="font-size:10px;color:var(--t3);font-weight:400;text-transform:none;letter-spacing:0;">Mis à jour ${agoStr}</span>`;
        h += `<button class="btn bp" style="font-size:10px;padding:4px 8px;text-transform:none;letter-spacing:0;font-weight:500;" onclick="APP.stats._refreshPlaces()">
            <svg viewBox="0 0 24 24" style="width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Actualiser
        </button></div>`;
        h += '</div>';

        // === CARTE D'IDENTITÉ ===
        const name = place.displayName?.text || '';
        const addr = place.formattedAddress || '';
        const phone = place.nationalPhoneNumber || '';
        const website = place.websiteUri || '';
        const cat = place.primaryTypeDisplayName?.text || place.primaryType || '';
        const rating = place.rating || 0;
        const reviewCount = place.userRatingCount || 0;
        const status = place.businessStatus || 'OPERATIONAL';
        const isSab = pd.is_sab == 1;
        const mapsUrl = place.googleMapsUri || '';

        const statusColor = status === 'OPERATIONAL' ? 'var(--g)' : status === 'CLOSED_TEMPORARILY' ? 'var(--o)' : 'var(--r)';
        const statusLabel = status === 'OPERATIONAL' ? 'Opérationnelle' : status === 'CLOSED_TEMPORARILY' ? 'Fermée temporairement' : status === 'CLOSED_PERMANENTLY' ? 'Fermée définitivement' : status;

        h += '<div class="sec" style="margin-bottom:16px"><div style="padding:20px">';
        h += `<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;">
            <div>
                <div style="font-size:16px;font-weight:700;color:var(--t1);margin-bottom:4px;">${esc(name)}</div>
                <div style="font-size:12px;color:var(--t3);margin-bottom:2px;">${esc(cat)}</div>
                <div style="font-size:12px;color:var(--t3);">${isSab ? '<span style="color:var(--o);font-style:italic;">📍 Adresse masquée (fiche SAB)</span>' : esc(addr)}</div>
            </div>
            <div style="text-align:right;">
                <span style="font-size:11px;padding:3px 8px;border-radius:5px;background:${statusColor}22;color:${statusColor};font-weight:600;">${statusLabel}</span>
            </div>
        </div>`;

        // Infos contact
        h += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-bottom:16px;">';
        if (phone) h += `<div style="background:var(--subtle-bg);border-radius:8px;padding:10px 12px;"><div style="font-size:10px;color:var(--t3);margin-bottom:2px;">Téléphone</div><div style="font-size:13px;color:var(--t1);font-weight:500;">${esc(phone)}</div></div>`;
        if (website) h += `<div style="background:var(--subtle-bg);border-radius:8px;padding:10px 12px;"><div style="font-size:10px;color:var(--t3);margin-bottom:2px;">Site web</div><div style="font-size:13px;color:var(--acc);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><a href="${esc(website)}" target="_blank" style="color:var(--acc);">${esc(website.replace(/^https?:\/\//, '').replace(/\/$/, ''))}</a></div></div>`;
        if (mapsUrl) h += `<div style="background:var(--subtle-bg);border-radius:8px;padding:10px 12px;"><div style="font-size:10px;color:var(--t3);margin-bottom:2px;">Google Maps</div><div style="font-size:13px;"><a href="${esc(mapsUrl)}" target="_blank" style="color:var(--acc);">Voir sur Maps →</a></div></div>`;
        h += '</div>';

        // === NOTE & AVIS ===
        if (rating > 0) {
            const rColor = rating >= 4.5 ? 'var(--g)' : rating >= 4.0 ? 'var(--o)' : rating >= 3.0 ? 'var(--p)' : 'var(--r)';
            // Delta 30j
            let delta30 = null;
            if (history.length >= 2) {
                const oldEntry = history.find(h => {
                    const d = new Date(h.stat_date);
                    return d <= new Date(Date.now() - 25 * 86400000);
                });
                if (oldEntry && oldEntry.rating) delta30 = (rating - parseFloat(oldEntry.rating)).toFixed(1);
            }
            h += `<div style="display:flex;gap:20px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
                <div style="text-align:center;">
                    <div style="font-size:42px;font-weight:700;font-family:'Space Mono',monospace;color:${rColor};letter-spacing:-2px;">${rating}</div>
                    <div style="color:var(--o);font-size:16px;letter-spacing:1px;">${'★'.repeat(Math.round(rating))}${'☆'.repeat(5 - Math.round(rating))}</div>
                    <div style="font-size:12px;color:var(--t3);margin-top:2px;">${reviewCount} avis</div>
                    ${delta30 !== null ? `<div style="font-size:11px;color:${parseFloat(delta30) >= 0 ? 'var(--g)' : 'var(--r)'};margin-top:4px;">${parseFloat(delta30) >= 0 ? '▲' : '▼'} ${delta30} / 30j</div>` : ''}
                </div>`;

            // Mini sparkline SVG si historique disponible
            if (history.length >= 3) {
                const pts = history.filter(h => h.rating).map((h, i) => ({ x: i, y: parseFloat(h.rating) }));
                if (pts.length >= 3) {
                    const minY = Math.min(...pts.map(p => p.y)) - 0.1;
                    const maxY = Math.max(...pts.map(p => p.y)) + 0.1;
                    const rangeY = maxY - minY || 1;
                    const w = 200, ht = 60;
                    const path = pts.map((p, i) => {
                        const x = (i / (pts.length - 1)) * w;
                        const y = ht - ((p.y - minY) / rangeY * ht);
                        return (i === 0 ? 'M' : 'L') + x.toFixed(1) + ',' + y.toFixed(1);
                    }).join(' ');
                    h += `<div style="flex:1;min-width:200px;">
                        <div style="font-size:11px;color:var(--t3);margin-bottom:4px;">Évolution note (${history.length}j)</div>
                        <svg viewBox="0 0 ${w} ${ht}" style="width:100%;height:60px;"><path d="${path}" fill="none" stroke="var(--acc)" stroke-width="2"/></svg>
                    </div>`;
                }
            }
            h += '</div>';
        }

        // === HORAIRES ===
        const hoursDesc = place.regularOpeningHours?.weekdayDescriptions || [];
        const isOpenNow = place.currentOpeningHours?.openNow;
        if (hoursDesc.length > 0) {
            h += '<div style="margin-bottom:16px;">';
            h += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">';
            h += '<span style="font-size:12px;font-weight:600;color:var(--t2);">Horaires</span>';
            if (isOpenNow !== undefined && isOpenNow !== null) {
                h += isOpenNow
                    ? '<span style="font-size:11px;padding:2px 8px;border-radius:4px;background:rgba(34,197,94,.15);color:#22c55e;">● Ouvert</span>'
                    : '<span style="font-size:11px;padding:2px 8px;border-radius:4px;background:rgba(239,68,68,.15);color:#ef4444;">● Fermé</span>';
            }
            h += '</div>';
            h += '<div style="display:grid;grid-template-columns:1fr;gap:2px;">';
            const dayNames = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
            const today = dayNames[new Date().getDay() === 0 ? 6 : new Date().getDay() - 1];
            for (const desc of hoursDesc) {
                const isToday = dayNames.some(d => desc.toLowerCase().startsWith(d) && d === today);
                h += `<div style="padding:6px 10px;font-size:12px;color:${isToday ? 'var(--acc)' : 'var(--t2)'};background:${isToday ? 'rgba(0,255,209,.06)' : 'transparent'};border-radius:4px;${isToday ? 'font-weight:600;' : ''}">${esc(desc)}</div>`;
            }
            h += '</div></div>';
        }

        // === PHOTOS ===
        const photos = place.photos || [];
        if (photos.length > 0) {
            h += '<div style="margin-bottom:16px;">';
            h += `<div style="font-size:12px;font-weight:600;color:var(--t2);margin-bottom:8px;">Photos <span style="font-size:11px;color:var(--t3);font-weight:400;">(${photos.length})</span></div>`;
            h += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:6px;">';
            const maxPhotos = Math.min(photos.length, 12);
            for (let i = 0; i < maxPhotos; i++) {
                const ref = photos[i].name || '';
                if (ref) {
                    h += `<div style="aspect-ratio:1;border-radius:8px;overflow:hidden;background:var(--subtle-bg);">
                        <img src="/api/places-photo.php?ref=${encodeURIComponent(ref)}&maxw=200" style="width:100%;height:100%;object-fit:cover;" loading="lazy" onerror="this.style.display='none'">
                    </div>`;
                }
            }
            if (photos.length > 12) {
                h += `<div style="aspect-ratio:1;border-radius:8px;background:var(--subtle-bg);display:flex;align-items:center;justify-content:center;font-size:13px;color:var(--t3);font-weight:600;">+${photos.length - 12}</div>`;
            }
            h += '</div></div>';
        }

        // === ATTRIBUTS ===
        const attrs = [];
        const attrMap = {
            takeout: 'Vente à emporter', delivery: 'Livraison', dineIn: 'Sur place',
            reservable: 'Réservation', outdoorSeating: 'Terrasse', liveMusic: 'Musique live',
            servesBreakfast: 'Petit-déjeuner', servesLunch: 'Déjeuner', servesDinner: 'Dîner',
            servesVegetarianFood: 'Végétarien', allowsDogs: 'Chiens acceptés', restroom: 'Toilettes',
            goodForChildren: 'Enfants', goodForGroups: 'Groupes',
        };
        for (const [key, label] of Object.entries(attrMap)) {
            if (place[key] === true) attrs.push(label);
        }
        // Accessibilité
        if (place.accessibilityOptions) {
            for (const [k, v] of Object.entries(place.accessibilityOptions)) {
                if (v === true) {
                    const aLabels = { wheelchairAccessibleParking: 'Parking PMR', wheelchairAccessibleEntrance: 'Entrée PMR', wheelchairAccessibleRestroom: 'WC PMR', wheelchairAccessibleSeating: 'Places PMR' };
                    attrs.push(aLabels[k] || k);
                }
            }
        }
        // Paiement
        if (place.paymentOptions) {
            for (const [k, v] of Object.entries(place.paymentOptions)) {
                if (v === true) {
                    const pLabels = { acceptsCreditCards: 'CB', acceptsNfc: 'Sans contact', acceptsDebitCards: 'Débit' };
                    if (pLabels[k]) attrs.push(pLabels[k]);
                }
            }
        }
        if (attrs.length > 0) {
            h += '<div style="margin-bottom:16px;">';
            h += '<div style="font-size:12px;font-weight:600;color:var(--t2);margin-bottom:8px;">Attributs</div>';
            h += '<div style="display:flex;flex-wrap:wrap;gap:6px;">';
            for (const a of attrs) {
                h += `<span style="font-size:11px;padding:4px 10px;border-radius:6px;background:rgba(34,197,94,.1);color:var(--g);border:1px solid rgba(34,197,94,.2);">${esc(a)}</span>`;
            }
            h += '</div></div>';
        }

        // === 5 DERNIERS AVIS ===
        if (reviews.length > 0) {
            h += '<div style="margin-bottom:16px;">';
            h += '<div style="font-size:12px;font-weight:600;color:var(--t2);margin-bottom:8px;">Derniers avis (Google Places)</div>';
            for (const r of reviews) {
                const stars = parseInt(r.rating) || 0;
                const starColor = stars >= 4 ? 'var(--o)' : stars >= 3 ? 'var(--t2)' : 'var(--r)';
                const hasReply = r.has_owner_reply == 1;
                const date = r.review_time ? new Date(r.review_time).toLocaleDateString('fr-FR', {day:'2-digit', month:'short', year:'numeric'}) : '';
                const textTrunc = (r.text_content || '').substring(0, 200) + ((r.text_content || '').length > 200 ? '...' : '');
                h += `<div style="padding:10px 0;border-bottom:1px solid var(--subtle-line);display:flex;gap:10px;">
                    <div style="width:30px;height:30px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;flex-shrink:0;">${(r.author_name || '?')[0].toUpperCase()}</div>
                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;align-items:center;gap:6px;margin-bottom:2px;">
                            <span style="font-weight:600;font-size:12px;">${esc(r.author_name || 'Anonyme')}</span>
                            <span style="color:${starColor};font-size:11px;">${'★'.repeat(stars)}${'☆'.repeat(5-stars)}</span>
                            <span style="font-size:10px;color:var(--t3);margin-left:auto;">${date}</span>
                        </div>
                        ${textTrunc ? `<div style="font-size:12px;color:var(--t2);line-height:1.4;">${esc(textTrunc)}</div>` : ''}
                        ${hasReply ? '<div style="font-size:10px;color:var(--g);margin-top:3px;">✓ Répondu</div>' : '<div style="font-size:10px;color:var(--o);margin-top:3px;">Sans réponse</div>'}
                    </div>
                </div>`;
            }
            h += '</div>';
        }

        // === SCORE DE COMPLÉTUDE ===
        if (comp.score !== undefined) {
            const cScore = comp.score || 0;
            const cColor = cScore >= 80 ? 'var(--g)' : cScore >= 50 ? 'var(--o)' : 'var(--r)';
            const checks = comp.checks || {};
            h += '<div style="margin-bottom:16px;">';
            h += `<div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
                <div style="font-size:12px;font-weight:600;color:var(--t2);">Score de complétude</div>
                <span style="font-size:18px;font-weight:700;font-family:'Space Mono',monospace;color:${cColor};">${cScore}/100</span>
            </div>`;
            h += '<div style="height:6px;background:var(--subtle-bg);border-radius:3px;overflow:hidden;margin-bottom:10px;">';
            h += `<div style="height:100%;width:${cScore}%;background:${cColor};border-radius:3px;transition:width .5s;"></div></div>`;
            h += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:4px;">';
            for (const [key, check] of Object.entries(checks)) {
                const icon = check.filled
                    ? '<svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:var(--g);fill:none;stroke-width:2.5;flex-shrink:0;"><path d="M20 6L9 17l-5-5"/></svg>'
                    : '<svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:var(--r);fill:none;stroke-width:2.5;flex-shrink:0;opacity:.5;"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
                h += `<div style="display:flex;align-items:center;gap:6px;padding:4px 0;font-size:12px;color:${check.filled ? 'var(--t1)' : 'var(--t3)'};">${icon} ${esc(check.label)} <span style="font-size:10px;color:var(--t3);margin-left:auto;">${check.weight}pts</span></div>`;
            }
            h += '</div></div>';
        }

        h += '</div></div>'; // .sec
        h += '</div>'; // padding wrapper
        return h;
    },

    _timeAgo(date) {
        const sec = Math.floor((Date.now() - date.getTime()) / 1000);
        if (sec < 60) return 'il y a quelques secondes';
        if (sec < 3600) return `il y a ${Math.floor(sec/60)} min`;
        if (sec < 86400) return `il y a ${Math.floor(sec/3600)}h`;
        return `il y a ${Math.floor(sec/86400)}j`;
    },

    async _refreshPlaces() {
        APP.toast('Actualisation des données Google...', 'info');
        const data = await APP.fetch('/api/places-data.php', {
            method: 'POST',
            body: (() => { const f = new FormData(); f.append('action', 'refresh'); f.append('location_id', this._locationId); return f; })(),
        });
        if (data.success) {
            this._placesData = data;
            APP.toast('Données Google mises à jour !', 'success');
            this.render();
        } else {
            APP.toast(data.error || 'Erreur de rafraîchissement', 'error');
        }
    },

    /** Carte metrique compacte : valeur + trend + mini bar chart */
    _metricCard(label, key, last6, monthly, trends, cm, color, periodVal) {
        const t = trends[key];
        let val = 0;
        if (periodVal !== undefined && periodVal !== null) {
            val = periodVal;
        } else if (cm && cm.current) {
            const row = monthly.find(r => r.month === cm.current);
            val = row ? (row[key] || 0) : 0;
        } else if (monthly.length > 0) {
            val = monthly[monthly.length - 1][key] || 0;
        }

        let badgeHtml = '';
        if (t) {
            const cls = t.direction === 'up' ? 'u' : (t.direction === 'down' ? 'd' : '');
            const arrow = t.direction === 'up' ? '&#9650;' : (t.direction === 'down' ? '&#9660;' : '&#8212;');
            const pct = (t.change_pct > 0 ? '+' : '') + t.change_pct;
            if (cls) badgeHtml = '<span class="sch ' + cls + '" style="margin:0;font-size:10px">' + arrow + pct + '</span>';
            else badgeHtml = '<span class="sch" style="margin:0;font-size:10px;color:var(--t3);background:var(--subtle-bg)">' + arrow + pct + '</span>';
        }

        let h = '<div class="sc" style="padding:14px 16px">';
        h += '<div class="sl" style="margin-bottom:6px;font-size:10px">' + label + '</div>';
        h += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">';
        h += '<span class="sv" style="font-size:24px;line-height:1">' + this._formatNum(val) + '</span>';
        h += badgeHtml;
        h += '</div>';

        let maxV = 0;
        for (const m of last6) { if ((m[key]||0) > maxV) maxV = m[key]||0; }
        if (maxV === 0) maxV = 1;

        h += '<div style="display:flex;gap:3px;align-items:flex-end;height:36px">';
        for (let i = 0; i < last6.length; i++) {
            const v = last6[i][key] || 0;
            const barH = Math.max(2, Math.round(v / maxV * 32));
            const isLast = i === last6.length - 1;
            h += '<div style="flex:1;height:' + barH + 'px;background:' + color + ';border-radius:2px;opacity:' + (isLast ? '1' : '.5') + '" title="' + this._monthName(last6[i].month) + ' : ' + this._formatNum(v) + '"></div>';
        }
        h += '</div>';

        h += '<div style="display:flex;gap:3px;margin-top:3px">';
        for (const m of last6) {
            h += '<div style="flex:1;text-align:center;font-size:8px;color:var(--t3);letter-spacing:0">' + this._monthName(m.month) + '</div>';
        }
        h += '</div>';

        h += '</div>';
        return h;
    },

    _formatNum(n) {
        if (!n) return '0';
        if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
        if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
        return String(n);
    },

    _monthName(ym) {
        if (!ym) return '';
        const parts = ym.split('-');
        const months = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];
        return months[parseInt(parts[1]) - 1] + ' ' + parts[0].slice(2);
    },

    _monthNameFull(ym) {
        if (!ym) return '';
        const parts = ym.split('-');
        const months = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
        return months[parseInt(parts[1]) - 1] + ' ' + parts[0];
    },
};

// ====================================================================
// MODULE : MONITORING ERREURS (ADMIN)
// ====================================================================
APP.errors = {
    _filters: { type: '', severity: '', days: 7 },
    _page: 1,
    _perPage: 30,
    _data: null,

    async load() {
        const c = document.getElementById('errors-content');
        if (!c) return;
        c.innerHTML = '<div style="padding:40px;text-align:center;color:var(--t2)"><svg class="spin" viewBox="0 0 24 24" style="width:32px;height:32px;stroke:var(--acc);fill:none;stroke-width:2"><path d="M12 2v4m0 12v4m-7.07-3.93l2.83-2.83m8.48-8.48l2.83-2.83M2 12h4m12 0h4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83"/></svg><div style="margin-top:12px">Chargement du monitoring...</div></div>';
        await this._fetch();
        this.render();
    },

    async _fetch() {
        const f = this._filters;
        let url = `/api/errors.php?action=list&page=${this._page}&per_page=${this._perPage}`;
        if (f.type) url += `&type=${f.type}`;
        if (f.severity) url += `&severity=${f.severity}`;
        if (f.days) url += `&days=${f.days}`;
        this._data = await APP.fetch(url);
    },

    render() {
        const c = document.getElementById('errors-content');
        if (!c) return;
        const data = this._data || {};
        const errors = data.errors || [];
        const total = data.total || 0;
        const pages = data.pages || 1;

        let h = '';

        // ====== HEADER + FILTERS ======
        h += '<div class="sec" style="margin:20px">';
        h += '<div class="sh" style="flex-wrap:wrap;gap:12px">';
        h += '<div style="flex:1;min-width:200px">';
        h += '<div class="stit" style="font-size:15px;margin-bottom:2px">Monitoring erreurs</div>';
        h += '<div style="font-size:12px;color:var(--t3)">' + total + ' erreur(s) trouvee(s)</div>';
        h += '</div>';
        h += '<button class="btn bp" onclick="APP.errors._page=1;APP.errors.load()" style="white-space:nowrap">';
        h += '<svg viewBox="0 0 24 24" style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>';
        h += ' Actualiser</button>';
        h += '</div>';

        // Filters row
        h += '<div style="padding:12px 20px;display:flex;flex-wrap:wrap;gap:10px;border-bottom:1px solid var(--bdr);background:var(--overlay)">';
        const f = this._filters;

        // Type filter
        h += '<select class="si" style="width:auto;padding:6px 10px;font-size:12px" onchange="APP.errors._filters.type=this.value;APP.errors._page=1;APP.errors.load()">';
        h += '<option value="">Tous les types</option>';
        const types = ['scan_error', 'api_error', 'cron_error', 'db_error', 'auth_error', 'general'];
        for (const t of types) {
            h += '<option value="' + t + '"' + (f.type === t ? ' selected' : '') + '>' + this._typeLabel(t) + '</option>';
        }
        h += '</select>';

        // Severity filter
        h += '<select class="si" style="width:auto;padding:6px 10px;font-size:12px" onchange="APP.errors._filters.severity=this.value;APP.errors._page=1;APP.errors.load()">';
        h += '<option value="">Toutes severites</option>';
        const sevs = ['critical', 'error', 'warning', 'info'];
        for (const s of sevs) {
            h += '<option value="' + s + '"' + (f.severity === s ? ' selected' : '') + '>' + this._sevLabel(s) + '</option>';
        }
        h += '</select>';

        // Days filter
        h += '<select class="si" style="width:auto;padding:6px 10px;font-size:12px" onchange="APP.errors._filters.days=parseInt(this.value);APP.errors._page=1;APP.errors.load()">';
        const dayOpts = [{v:1,l:'24h'},{v:7,l:'7 jours'},{v:30,l:'30 jours'},{v:90,l:'90 jours'}];
        for (const d of dayOpts) {
            h += '<option value="' + d.v + '"' + (f.days === d.v ? ' selected' : '') + '>' + d.l + '</option>';
        }
        h += '</select>';
        h += '</div>';

        // ====== TABLE ======
        if (errors.length === 0) {
            h += '<div style="padding:60px 20px;text-align:center">';
            h += '<svg viewBox="0 0 24 24" style="width:48px;height:48px;stroke:var(--g);fill:none;stroke-width:1.5;margin-bottom:16px;opacity:.5"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
            h += '<div style="font-size:15px;font-weight:600;margin-bottom:6px">Aucune erreur</div>';
            h += '<div style="font-size:12px;color:var(--t3)">Tout fonctionne correctement sur cette periode.</div>';
            h += '</div>';
        } else {
            h += '<div style="overflow-x:auto"><table class="stats-table error-table"><thead><tr>';
            h += '<th style="width:140px">Date</th><th style="width:90px">Severite</th><th style="width:110px">Type</th><th style="width:140px">Source</th><th>Message</th>';
            h += '</tr></thead><tbody>';

            for (const err of errors) {
                const sevCls = this._sevClass(err.severity);
                h += '<tr class="error-row" onclick="APP.errors.showDétail(' + err.id + ')" style="cursor:pointer">';
                h += '<td style="font-size:12px;color:var(--t3);white-space:nowrap;font-family:\'Space Mono\',monospace">' + this._fmtDate(err.error_date) + '</td>';
                h += '<td><span class="error-badge ' + sevCls + '">' + this._sevLabel(err.severity) + '</span></td>';
                h += '<td><span style="font-size:11px;color:var(--t2);font-family:\'Space Mono\',monospace;background:var(--subtle-bg);padding:2px 6px;border-radius:4px">' + this._typeLabel(err.error_type) + '</span></td>';
                h += '<td style="font-size:12px;color:var(--t3);font-family:\'Space Mono\',monospace">' + (err.source || '—') + '</td>';
                h += '<td style="font-size:12px;max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + this._esc(err.message) + '</td>';
                h += '</tr>';
            }
            h += '</tbody></table></div>';

            // Pagination
            if (pages > 1) {
                h += '<div style="padding:12px 20px;display:flex;justify-content:center;gap:6px;border-top:1px solid var(--bdr)">';
                for (let p = 1; p <= pages; p++) {
                    const active = p === this._page ? 'background:var(--primary);color:#fff;font-weight:700' : 'background:var(--subtle-bg);color:var(--t2)';
                    h += '<button onclick="APP.errors._page=' + p + ';APP.errors.load()" style="border:none;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:12px;font-family:\'Space Mono\',monospace;' + active + '">' + p + '</button>';
                }
                h += '</div>';
            }
        }

        h += '</div>';
        c.innerHTML = h;
    },

    async showDétail(id) {
        const data = await APP.fetch(`/api/errors.php?action=detail&id=${id}`);
        if (!data || data.error) { APP.toast('Erreur de chargement', 'error'); return; }
        const e = data;

        let h = '<div class="error-detail-overlay" onclick="this.remove()">';
        h += '<div class="error-detail-modal" onclick="event.stopPropagation()">';

        // Modal header
        h += '<div style="display:flex;justify-content:space-between;align-items:center;padding:20px 24px;border-bottom:1px solid var(--bdr)">';
        h += '<div style="display:flex;align-items:center;gap:12px">';
        h += '<span class="error-badge ' + this._sevClass(e.severity) + '" style="font-size:12px;padding:4px 10px">' + this._sevLabel(e.severity) + '</span>';
        h += '<span style="font-size:11px;font-family:\'Space Mono\',monospace;color:var(--t3);background:var(--subtle-bg);padding:3px 8px;border-radius:4px">' + this._typeLabel(e.error_type) + '</span>';
        h += '</div>';
        h += '<button onclick="this.closest(\'.error-detail-overlay\').remove()" style="background:none;border:none;color:var(--t3);cursor:pointer;font-size:22px;line-height:1;padding:4px">&times;</button>';
        h += '</div>';

        // Modal body
        h += '<div style="padding:20px 24px;max-height:70vh;overflow-y:auto">';

        // Meta info
        h += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:20px">';
        const metas = [
            { l: 'Date', v: e.error_date || '—' },
            { l: 'Source', v: e.source || '—' },
            { l: 'Action', v: e.action || '—' },
            { l: 'User ID', v: e.user_id || '—' },
            { l: 'Location ID', v: e.location_id || '—' },
            { l: 'Keyword ID', v: e.keyword_id || '—' },
        ];
        for (const mt of metas) {
            h += '<div><div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--t3);margin-bottom:3px">' + mt.l + '</div>';
            h += '<div style="font-size:13px;font-family:\'Space Mono\',monospace">' + mt.v + '</div></div>';
        }
        h += '</div>';

        // Message
        h += '<div style="margin-bottom:16px">';
        h += '<div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--t3);margin-bottom:6px">Message</div>';
        h += '<div style="background:var(--inp);padding:12px 16px;border-radius:8px;font-size:13px;line-height:1.6;border:1px solid var(--bdr)">' + this._esc(e.message) + '</div>';
        h += '</div>';

        // Stack trace
        if (e.stack) {
            h += '<div style="margin-bottom:16px">';
            h += '<div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--t3);margin-bottom:6px">Stack Trace</div>';
            h += '<pre style="background:var(--inp);padding:12px 16px;border-radius:8px;font-size:11px;font-family:\'Space Mono\',monospace;line-height:1.7;overflow-x:auto;border:1px solid var(--bdr);color:var(--t2);white-space:pre-wrap;word-break:break-all">' + this._esc(e.stack) + '</pre>';
            h += '</div>';
        }

        // Context JSON
        if (e.context) {
            let ctxStr = '';
            try { ctxStr = JSON.stringify(typeof e.context === 'string' ? JSON.parse(e.context) : e.context, null, 2); } catch(x) { ctxStr = String(e.context); }
            h += '<div>';
            h += '<div style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--t3);margin-bottom:6px">Context</div>';
            h += '<pre style="background:var(--inp);padding:12px 16px;border-radius:8px;font-size:11px;font-family:\'Space Mono\',monospace;line-height:1.7;overflow-x:auto;border:1px solid var(--bdr);color:var(--t2);white-space:pre-wrap;word-break:break-all">' + this._esc(ctxStr) + '</pre>';
            h += '</div>';
        }

        h += '</div></div></div>';

        document.getElementById('errors-content').insertAdjacentHTML('beforeend', h);
    },

    _typeLabel(t) {
        const map = { scan_error: 'Scan', api_error: 'API', cron_error: 'Cron', db_error: 'Base', auth_error: 'Auth', general: 'General' };
        return map[t] || t || '—';
    },

    _sevLabel(s) {
        const map = { critical: 'Critique', error: 'Erreur', warning: 'Alerte', info: 'Info' };
        return map[s] || s || '—';
    },

    _sevClass(s) {
        const map = { critical: 'sev-critical', error: 'sev-error', warning: 'sev-warning', info: 'sev-info' };
        return map[s] || '';
    },

    _fmtDate(dt) {
        if (!dt) return '—';
        // "2026-02-27 14:30:00" → "27/02 14:30"
        const p = dt.split(' ');
        if (p.length < 2) return dt;
        const d = p[0].split('-');
        const t = p[1].substring(0, 5);
        return d[2] + '/' + d[1] + ' ' + t;
    },

    _esc(s) {
        if (!s) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    },
};
})();