const CANVAS_W = 800;
const CANVAS_H = 540;

function drawRoundedRectPath(ctx, x, y, w, h, r) {
    const rad = Math.min(r, w * 0.5, h * 0.5);
    ctx.beginPath();
    ctx.moveTo(x + rad, y);
    ctx.arcTo(x + w, y, x + w, y + h, rad);
    ctx.arcTo(x + w, y + h, x, y + h, rad);
    ctx.arcTo(x, y + h, x, y, rad);
    ctx.arcTo(x, y, x + w, y, rad);
    ctx.closePath();
}

function wrapLines(ctx, text, maxWidth, maxLines = 3) {
    const words = String(text || '').trim().split(/\s+/).filter(Boolean);
    if (!words.length) return [];
    const lines = [];
    let current = '';
    for (let i = 0; i < words.length; i++) {
        const candidate = current ? `${current} ${words[i]}` : words[i];
        if (ctx.measureText(candidate).width <= maxWidth) {
            current = candidate;
        } else {
            if (current) lines.push(current);
            current = words[i];
            if (lines.length >= maxLines - 1) {
                while (i + 1 < words.length && ctx.measureText(`${current} ${words[i + 1]}...`).width <= maxWidth) {
                    current += ` ${words[++i]}`;
                }
                if (i + 1 < words.length) current += '...';
                break;
            }
        }
    }
    if (current && lines.length < maxLines) {
        lines.push(current);
    }
    return lines;
}

function formatLiveClockInTz(tzName, lang = 'id') {
    const tz = tzName || 'Asia/Makassar';
    const now = new Date();
    const locale = lang === 'en' ? 'en-US' : 'id-ID';
    try {
        const timeParts = new Intl.DateTimeFormat('en-GB', {
            timeZone: tz,
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false
        }).formatToParts(now);
        const map = {};
        timeParts.forEach((p) => { map[p.type] = p.value; });
        const hh = Number(map.hour || 0);
        const mm = Number(map.minute || 0);
        const ss = Number(map.second || 0);
        const dateStr = new Intl.DateTimeFormat(locale, {
            timeZone: tz,
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric'
        }).format(now);
        return {
            hms: `${String(hh).padStart(2, '0')}:${String(mm).padStart(2, '0')}:${String(ss).padStart(2, '0')}`,
            hh,
            mm,
            ss,
            dateStr
        };
    } catch (_) {
        const hh = now.getHours();
        const mm = now.getMinutes();
        const ss = now.getSeconds();
        return {
            hms: `${String(hh).padStart(2, '0')}:${String(mm).padStart(2, '0')}:${String(ss).padStart(2, '0')}`,
            hh,
            mm,
            ss,
            dateStr: now.toDateString()
        };
    }
}

export class HologramPanel3D {
    constructor(THREE, scene) {
        this.THREE = THREE;
        this.scene = scene;
        this.active = false;
        this.cardData = null;
        this.appearProgress = 0;
        this.shownAtSec = 0;
        this.autoHideAtSec = 0;
        this.lastClockSecDrawn = -1;

        this.smoothHandPos = new THREE.Vector3(0.18, 1.24, 0.20);
        this.smoothPanelPos = new THREE.Vector3(0.24, 1.39, 0.24);
        this.posInitialized = false;

        this._tmpVec = new THREE.Vector3();
        this._tmpRight = new THREE.Vector3();
        this._tmpUp = new THREE.Vector3();
        this._tmpFwd = new THREE.Vector3();
        this._tmpNdc = new THREE.Vector3();
        this._tmpHeadNdc = new THREE.Vector3();
        this._tmpMat = new THREE.Matrix4();
        this._tmpQuat = new THREE.Quaternion();

        this._buildSceneObjects();
    }

    _buildSceneObjects() {
        const THREE = this.THREE;
        this.rootGroup = new THREE.Group();
        this.rootGroup.visible = false;
        this.rootGroup.renderOrder = 25;
        this.scene.add(this.rootGroup);

        this.emitterGroup = new THREE.Group();
        this.rootGroup.add(this.emitterGroup);

        this.outerRingMat = new THREE.MeshBasicMaterial({
            color: 0x38bdf8,
            transparent: true,
            opacity: 0.0,
            side: THREE.DoubleSide,
            depthWrite: false,
            blending: THREE.AdditiveBlending
        });
        this.outerRing = new THREE.Mesh(new THREE.RingGeometry(0.032, 0.038, 36), this.outerRingMat);
        this.outerRing.rotation.x = -Math.PI * 0.48;
        this.emitterGroup.add(this.outerRing);

        this.innerRingMat = new THREE.MeshBasicMaterial({
            color: 0xfbbf24,
            transparent: true,
            opacity: 0.0,
            side: THREE.DoubleSide,
            depthWrite: false,
            blending: THREE.AdditiveBlending
        });
        this.innerRing = new THREE.Mesh(new THREE.RingGeometry(0.015, 0.020, 28), this.innerRingMat);
        this.innerRing.rotation.x = -Math.PI * 0.48;
        this.emitterGroup.add(this.innerRing);

        this.beamMat = new THREE.MeshBasicMaterial({
            color: 0x38bdf8,
            transparent: true,
            opacity: 0.0,
            side: THREE.DoubleSide,
            depthWrite: false,
            blending: THREE.AdditiveBlending
        });
        this.beamMesh = new THREE.Mesh(new THREE.CylinderGeometry(0.11, 0.022, 1.0, 24, 1, true), this.beamMat);
        this.beamMesh.renderOrder = 27;
        this.emitterGroup.renderOrder = 28;
        this.rootGroup.add(this.beamMesh);

        this.panelGroup = new THREE.Group();
        this.panelGroup.renderOrder = 30;
        this.rootGroup.add(this.panelGroup);

        this.canvas = document.createElement('canvas');
        this.canvas.width = CANVAS_W;
        this.canvas.height = CANVAS_H;
        this.ctx = this.canvas.getContext('2d');

        this.panelTexture = new THREE.CanvasTexture(this.canvas);
        this.panelTexture.minFilter = THREE.LinearFilter;
        this.panelTexture.magFilter = THREE.LinearFilter;
        if (THREE.SRGBColorSpace) {
            this.panelTexture.colorSpace = THREE.SRGBColorSpace;
        }
        this.panelTexture.needsUpdate = true;

        this.cardW = 0.305;
        this.cardH = 0.206;

        this.cardMat = new THREE.MeshBasicMaterial({
            map: this.panelTexture,
            transparent: true,
            opacity: 0.0,
            depthWrite: false,
            side: THREE.DoubleSide
        });
        this.cardMesh = new THREE.Mesh(new THREE.PlaneGeometry(this.cardW, this.cardH), this.cardMat);
        this.cardMesh.renderOrder = 30;
        this.panelGroup.add(this.cardMesh);

        const hw = this.cardW * 0.508;
        const hh = this.cardH * 0.512;
        const framePts = new Float32Array([
            -hw, -hh, 0.002,  hw, -hh, 0.002,
             hw, -hh, 0.002,  hw,  hh, 0.002,
             hw,  hh, 0.002, -hw,  hh, 0.002,
            -hw,  hh, 0.002, -hw, -hh, 0.002
        ]);
        const frameGeo = new THREE.BufferGeometry();
        frameGeo.setAttribute('position', new THREE.BufferAttribute(framePts, 3));
        this.frameMat = new THREE.LineBasicMaterial({
            color: 0x38bdf8,
            transparent: true,
            opacity: 0.0,
            depthWrite: false
        });
        this.frameLines = new THREE.LineSegments(frameGeo, this.frameMat);
        this.frameLines.renderOrder = 31;
        this.panelGroup.add(this.frameLines);
    }

    show(cardData, durationSec = 18.0) {
        if (!cardData || !cardData.type) {
            this.hide();
            return;
        }
        this.cardData = cardData;
        this.active = true;
        this.shownAtSec = performance.now() * 0.001;
        this.autoHideAtSec = this.shownAtSec + Math.max(8.0, durationSec);
        this.lastClockSecDrawn = -1;
        this.rootGroup.visible = true;

        const accentHex = cardData.accent || '#38bdf8';
        this.frameMat.color.set(accentHex);
        this.outerRingMat.color.set(accentHex);
        this.beamMat.color.set(accentHex);

        this._renderCardCanvas();
    }

    extendDuration(extraSec = 9.0) {
        if (!this.active) return;
        const now = performance.now() * 0.001;
        this.autoHideAtSec = Math.max(this.autoHideAtSec, now + extraSec);
    }

    hide(immediate = false) {
        this.active = false;
        this.autoHideAtSec = 0;
        if (immediate) {
            this.appearProgress = 0;
            this.rootGroup.visible = false;
            this.posInitialized = false;
        }
    }

    isActive() {
        return this.active || this.appearProgress > 0.05;
    }

    _renderCardCanvas() {
        const ctx = this.ctx;
        const c = this.cardData || {};
        const accent = c.accent || '#38bdf8';
        const secondaryAccent = c.secondaryAccent || '#fbbf24';

        ctx.clearRect(0, 0, CANVAS_W, CANVAS_H);

        drawRoundedRectPath(ctx, 10, 10, CANVAS_W - 20, CANVAS_H - 20, 28);
        const bgGrad = ctx.createLinearGradient(0, 10, CANVAS_W, CANVAS_H - 10);
        bgGrad.addColorStop(0, 'rgba(9, 18, 36, 0.94)');
        bgGrad.addColorStop(0.55, 'rgba(13, 27, 52, 0.92)');
        bgGrad.addColorStop(1, 'rgba(8, 17, 34, 0.95)');
        ctx.fillStyle = bgGrad;
        ctx.fill();

        ctx.save();
        ctx.clip();
        ctx.strokeStyle = 'rgba(56, 189, 248, 0.06)';
        ctx.lineWidth = 1;
        for (let y = 30; y < CANVAS_H; y += 26) {
            ctx.beginPath();
            ctx.moveTo(10, y);
            ctx.lineTo(CANVAS_W - 10, y);
            ctx.stroke();
        }
        for (let x = 36; x < CANVAS_W; x += 48) {
            ctx.beginPath();
            ctx.moveTo(x, 10);
            ctx.lineTo(x, CANVAS_H - 10);
            ctx.stroke();
        }

        const orb = ctx.createRadialGradient(CANVAS_W * 0.85, CANVAS_H * 0.20, 10, CANVAS_W * 0.85, CANVAS_H * 0.20, 260);
        orb.addColorStop(0, 'rgba(56, 189, 248, 0.18)');
        orb.addColorStop(1, 'rgba(56, 189, 248, 0)');
        ctx.fillStyle = orb;
        ctx.fillRect(0, 0, CANVAS_W, CANVAS_H);
        ctx.restore();

        drawRoundedRectPath(ctx, 10, 10, CANVAS_W - 20, CANVAS_H - 20, 28);
        ctx.strokeStyle = accent;
        ctx.lineWidth = 3;
        ctx.stroke();

        ctx.fillStyle = 'rgba(255, 255, 255, 0.05)';
        drawRoundedRectPath(ctx, 26, 24, CANVAS_W - 52, 54, 14);
        ctx.fill();

        ctx.beginPath();
        ctx.arc(54, 51, 7, 0, Math.PI * 2);
        ctx.fillStyle = accent;
        ctx.fill();

        ctx.fillStyle = '#f1f5f9';
        ctx.font = '700 24px "Segoe UI", system-ui, sans-serif';
        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
        ctx.fillText(String(c.title || 'UNKLABOT LIVE DATA').slice(0, 34), 74, 51);

        const badgeText = String(c.badge || 'LIVE').slice(0, 20);
        ctx.font = '700 17px "Segoe UI", system-ui, sans-serif';
        const bW = Math.min(220, ctx.measureText(badgeText).width + 28);
        drawRoundedRectPath(ctx, CANVAS_W - 38 - bW, 33, bW, 36, 18);
        ctx.fillStyle = 'rgba(56, 189, 248, 0.18)';
        ctx.fill();
        ctx.strokeStyle = accent;
        ctx.lineWidth = 1.5;
        ctx.stroke();
        ctx.fillStyle = '#e0f2fe';
        ctx.textAlign = 'center';
        ctx.fillText(badgeText, CANVAS_W - 38 - bW * 0.5, 51);

        const type = c.type || 'ai_response';
        if (type === 'clock') {
            this._drawClockBody(ctx, c, accent, secondaryAccent);
        } else if (type === 'weather') {
            this._drawWeatherBody(ctx, c, accent, secondaryAccent);
        } else if (type === 'finance_chart') {
            this._drawFinanceBody(ctx, c, accent, secondaryAccent);
        } else if (type === 'health_gauge') {
            this._drawHealthBody(ctx, c, accent, secondaryAccent);
        } else if (type === 'academic_metric') {
            this._drawAcademicBody(ctx, c, accent, secondaryAccent);
        } else if (type === 'emergency_cards') {
            this._drawEmergencyBody(ctx, c, accent);
        } else if (type === 'math_convert') {
            this._drawMathBody(ctx, c, accent, secondaryAccent);
        } else if (type === 'data_highlight') {
            this._drawDataHighlightBody(ctx, c, accent, secondaryAccent);
        } else {
            this._drawAiResponseBody(ctx, c, accent, secondaryAccent);
        }

        if (type !== 'data_highlight' && type !== 'ai_response') {
            this._drawResponseStrip(ctx, c, accent);
        }

        this._drawFeedbackStatusFooter(ctx, c, accent, secondaryAccent);

        this.panelTexture.needsUpdate = true;
    }

    _drawClockBody(ctx, c, accent, gold) {
        const live = c.liveClock !== false
            ? formatLiveClockInTz(c.timezone || 'Asia/Makassar', c.lang || 'id')
            : { hms: c.timeText || '--:--:--', hh: 12, mm: 0, ss: 0, dateStr: c.dateText || '' };

        ctx.textAlign = 'left';
        ctx.textBaseline = 'alphabetic';
        ctx.fillStyle = '#ffffff';
        ctx.font = '800 94px "Consolas", "Segoe UI", monospace';
        ctx.fillText(live.hms, 38, 205);

        ctx.fillStyle = '#bae6fd';
        ctx.font = '600 25px "Segoe UI", system-ui, sans-serif';
        ctx.fillText(String(c.dateText && !c.liveClock ? c.dateText : live.dateStr).slice(0, 40), 40, 252);

        const chips = Array.isArray(c.chips) && c.chips.length
            ? c.chips
            : [
                c.timezoneLabel || (c.timezone || 'Asia/Makassar'),
                c.lang === 'en' ? 'Active Live Time' : 'Waktu Real-Time'
            ];
        this._drawChipsRow(ctx, chips, 40, 290, accent, gold);

        const cx = CANVAS_W - 135;
        const cy = 225;
        const rad = 82;

        ctx.beginPath();
        ctx.arc(cx, cy, rad, 0, Math.PI * 2);
        ctx.strokeStyle = 'rgba(148, 163, 184, 0.22)';
        ctx.lineWidth = 10;
        ctx.stroke();

        const secRatio = Math.max(0.02, live.ss / 60);
        ctx.beginPath();
        ctx.arc(cx, cy, rad, -Math.PI / 2, -Math.PI / 2 + secRatio * Math.PI * 2);
        ctx.strokeStyle = accent;
        ctx.lineWidth = 10;
        ctx.lineCap = 'round';
        ctx.stroke();

        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = gold;
        ctx.font = '700 28px "Segoe UI", system-ui, sans-serif';
        ctx.fillText(`${String(live.ss).padStart(2, '0')}s`, cx, cy - 10);
        ctx.fillStyle = '#94a3b8';
        ctx.font = '600 16px "Segoe UI", system-ui, sans-serif';
        ctx.fillText(String(c.zoneShort || 'WITA'), cx, cy + 20);
    }

    _drawWeatherBody(ctx, c, accent, gold) {

        const gx = 108;
        const gy = 185;
        ctx.save();
        ctx.beginPath();
        ctx.arc(gx + 16, gy - 16, 30, 0, Math.PI * 2);
        ctx.fillStyle = gold;
        ctx.fill();
        ctx.beginPath();
        ctx.arc(gx - 18, gy + 12, 24, 0, Math.PI * 2);
        ctx.arc(gx + 8, gy + 4, 30, 0, Math.PI * 2);
        ctx.arc(gx + 36, gy + 14, 22, 0, Math.PI * 2);
        ctx.fillStyle = '#e2e8f0';
        ctx.fill();
        ctx.restore();

        ctx.textAlign = 'left';
        ctx.textBaseline = 'alphabetic';
        ctx.fillStyle = '#ffffff';
        ctx.font = '800 84px "Segoe UI", system-ui, sans-serif';
        ctx.fillText(String(c.primaryValue || '27°C'), 195, 210);

        ctx.fillStyle = '#cbd5e1';
        ctx.font = '600 24px "Segoe UI", system-ui, sans-serif';
        const descLines = wrapLines(ctx, c.subtitle || 'Kampus UNKLAB Airmadidi', CANVAS_W - 240, 2);
        descLines.forEach((ln, idx) => ctx.fillText(ln, 198, 255 + idx * 32));

        this._drawChipsRow(ctx, c.chips || ['Minahasa Utara', 'Iklim Tropis Sejuk'], 40, 332, accent, gold);
    }

    _drawFinanceBody(ctx, c, accent, gold) {
        const donut = Array.isArray(c.donut) && c.donut.length >= 2 ? c.donut : null;
        if (donut) {
            const cx = 148;
            const cy = 238;
            const r = 88;
            let startAng = -Math.PI / 2;
            const palette = ['#38bdf8', '#fbbf24', '#34d399', '#a78bfa'];
            const total = donut.reduce((acc, s) => acc + Math.max(0, Number(s.value || 0)), 0) || 100;

            donut.forEach((seg, idx) => {
                const span = (Math.max(0, Number(seg.value || 0)) / total) * Math.PI * 2;
                ctx.beginPath();
                ctx.arc(cx, cy, r, startAng, startAng + span - 0.04);
                ctx.strokeStyle = seg.color || palette[idx % palette.length];
                ctx.lineWidth = 28;
                ctx.stroke();
                startAng += span;
            });

            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = '#ffffff';
            ctx.font = '800 26px "Segoe UI", system-ui, sans-serif';
            ctx.fillText(String(c.centerLabel || '50/30/20'), cx, cy);

            donut.forEach((seg, idx) => {
                const ly = 142 + idx * 72;
                const col = seg.color || palette[idx % palette.length];
                ctx.fillStyle = col;
                drawRoundedRectPath(ctx, 290, ly, 16, 44, 6);
                ctx.fill();

                ctx.textAlign = 'left';
                ctx.textBaseline = 'alphabetic';
                ctx.fillStyle = '#f8fafc';
                ctx.font = '700 25px "Segoe UI", system-ui, sans-serif';
                ctx.fillText(`${seg.label}: ${seg.value}${seg.unit || '%'}`, 322, ly + 22);

                ctx.fillStyle = '#94a3b8';
                ctx.font = '500 18px "Segoe UI", system-ui, sans-serif';
                ctx.fillText(String(seg.hint || ''), 322, ly + 46);
            });
            return;
        }

        ctx.textAlign = 'left';
        ctx.textBaseline = 'alphabetic';
        ctx.fillStyle = gold;
        ctx.font = '800 58px "Segoe UI", system-ui, sans-serif';
        ctx.fillText(String(c.primaryValue || 'Data Keuangan').slice(0, 24), 38, 175);

        ctx.fillStyle = '#cbd5e1';
        ctx.font = '600 22px "Segoe UI", system-ui, sans-serif';
        const lines = wrapLines(ctx, c.subtitle || '', CANVAS_W - 80, 2);
        lines.forEach((ln, idx) => ctx.fillText(ln, 38, 218 + idx * 30));

        if (Array.isArray(c.bars) && c.bars.length) {
            this._drawBarsBlock(ctx, c.bars, 38, 286, CANVAS_W - 76, accent);
        } else if (Array.isArray(c.chips) && c.chips.length) {
            this._drawChipsRow(ctx, c.chips, 38, 315, accent, gold);
        }
    }

    _drawHealthBody(ctx, c, accent, gold) {

        const cx = 145;
        const cy = 240;
        const r = 84;
        const pct = Math.max(0.08, Math.min(1.0, Number(c.ratio ?? 0.72)));

        ctx.beginPath();
        ctx.arc(cx, cy, r, Math.PI * 0.75, Math.PI * 2.25);
        ctx.strokeStyle = 'rgba(148, 163, 184, 0.24)';
        ctx.lineWidth = 20;
        ctx.lineCap = 'round';
        ctx.stroke();

        ctx.beginPath();
        ctx.arc(cx, cy, r, Math.PI * 0.75, Math.PI * 0.75 + Math.PI * 1.5 * pct);
        ctx.strokeStyle = accent;
        ctx.lineWidth = 20;
        ctx.lineCap = 'round';
        ctx.stroke();

        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = '#ffffff';
        ctx.font = '800 34px "Segoe UI", system-ui, sans-serif';
        ctx.fillText(String(c.primaryValue || 'OK').slice(0, 10), cx, cy - 4);
        ctx.fillStyle = gold;
        ctx.font = '600 17px "Segoe UI", system-ui, sans-serif';
        ctx.fillText(String(c.unitLabel || '').slice(0, 14), cx, cy + 30);

        ctx.textAlign = 'left';
        ctx.textBaseline = 'alphabetic';
        ctx.fillStyle = '#f1f5f9';
        ctx.font = '700 26px "Segoe UI", system-ui, sans-serif';
        ctx.fillText(String(c.metricTitle || 'Indikator Kesehatan').slice(0, 32), 270, 165);

        ctx.fillStyle = '#cbd5e1';
        ctx.font = '500 21px "Segoe UI", system-ui, sans-serif';
        const lines = wrapLines(ctx, c.subtitle || '', CANVAS_W - 310, 3);
        lines.forEach((ln, idx) => ctx.fillText(ln, 270, 208 + idx * 30));

        this._drawChipsRow(ctx, c.chips || [], 270, 322, accent, gold);
    }

    _drawAcademicBody(ctx, c, accent, gold) {
        ctx.textAlign = 'left';
        ctx.textBaseline = 'alphabetic';
        ctx.fillStyle = '#38bdf8';
        ctx.font = '800 58px "Segoe UI", system-ui, sans-serif';
        ctx.fillText(String(c.primaryValue || 'Akademik').slice(0, 22), 38, 174);

        ctx.fillStyle = '#e2e8f0';
        ctx.font = '500 22px "Segoe UI", system-ui, sans-serif';
        const lines = wrapLines(ctx, c.subtitle || '', CANVAS_W - 80, 2);
        lines.forEach((ln, idx) => ctx.fillText(ln, 38, 222 + idx * 32));

        if (Array.isArray(c.bars) && c.bars.length) {
            this._drawBarsBlock(ctx, c.bars, 38, 286, CANVAS_W - 76, accent);
        } else {
            this._drawChipsRow(ctx, c.chips || [], 38, 318, accent, gold);
        }
    }

    _drawMathBody(ctx, c, accent, gold) {
        if (c.expressionText) {
            ctx.textAlign = 'left';
            ctx.textBaseline = 'alphabetic';
            ctx.fillStyle = '#94a3b8';
            ctx.font = '600 24px "Consolas", monospace';
            ctx.fillText(String(c.expressionText).slice(0, 44), 38, 142);
        }
        ctx.textAlign = 'left';
        ctx.textBaseline = 'alphabetic';
        ctx.fillStyle = '#ffffff';
        ctx.font = '800 66px "Segoe UI", system-ui, sans-serif';
        ctx.fillText(String(c.primaryValue || '').slice(0, 22), 38, 220);

        ctx.fillStyle = '#cbd5e1';
        ctx.font = '500 21px "Segoe UI", system-ui, sans-serif';
        const lines = wrapLines(ctx, c.subtitle || '', CANVAS_W - 80, 2);
        lines.forEach((ln, idx) => ctx.fillText(ln, 38, 260 + idx * 29));

        this._drawChipsRow(ctx, c.chips || [], 38, 326, accent, gold);
    }

    _drawEmergencyBody(ctx, c, accent) {
        const items = Array.isArray(c.items) && c.items.length
            ? c.items
            : [
                { num: '112', label: 'Darurat Terpadu' },
                { num: '110', label: 'Kepolisian' },
                { num: '118 / 119', label: 'Ambulans / Medis' },
                { num: '113', label: 'Pemadam Kebakaran' }
            ];
        const colW = (CANVAS_W - 96) * 0.5;
        const rowH = 118;
        items.slice(0, 4).forEach((it, idx) => {
            const col = idx % 2;
            const row = Math.floor(idx / 2);
            const x = 36 + col * (colW + 24);
            const y = 112 + row * (rowH + 18);

            drawRoundedRectPath(ctx, x, y, colW, rowH, 16);
            ctx.fillStyle = 'rgba(239, 68, 68, 0.14)';
            ctx.fill();
            ctx.strokeStyle = 'rgba(248, 113, 113, 0.72)';
            ctx.lineWidth = 2;
            ctx.stroke();

            ctx.textAlign = 'left';
            ctx.textBaseline = 'alphabetic';
            ctx.fillStyle = '#fca5a5';
            ctx.font = '800 40px "Segoe UI", system-ui, sans-serif';
            ctx.fillText(String(it.num), x + 22, y + 58);

            ctx.fillStyle = '#f8fafc';
            ctx.font = '600 21px "Segoe UI", system-ui, sans-serif';
            ctx.fillText(String(it.label), x + 22, y + 94);
        });
    }

    _drawDataHighlightBody(ctx, c, accent, gold) {
        let yCursor = 148;
        if (c.primaryValue) {
            ctx.textAlign = 'left';
            ctx.textBaseline = 'alphabetic';
            ctx.fillStyle = gold;
            ctx.font = '800 48px "Segoe UI", system-ui, sans-serif';
            ctx.fillText(String(c.primaryValue).slice(0, 26), 38, yCursor);
            yCursor += 46;
        }
        const replyBody = String(c.replyText || c.subtitle || '').trim();
        const hasChips = Array.isArray(c.chips) && c.chips.length > 0;
        const maxLines = hasChips ? 5 : 6;
        ctx.textAlign = 'left';
        ctx.textBaseline = 'alphabetic';
        ctx.fillStyle = '#f1f5f9';
        ctx.font = '500 24px "Segoe UI", system-ui, sans-serif';
        const lines = wrapLines(ctx, replyBody, CANVAS_W - 76, maxLines);
        lines.forEach((ln, idx) => ctx.fillText(ln, 38, yCursor + idx * 34));

        if (hasChips) {
            this._drawChipsRow(ctx, c.chips, 38, 424, accent, gold);
        }
    }

    _drawAiResponseBody(ctx, c, accent, gold) {
        const replyBody = String(c.replyText || c.subtitle || '').trim();
        const cardX = 28;
        const cardY = 100;
        const cardW = CANVAS_W - 56;
        const cardH = 376;

        drawRoundedRectPath(ctx, cardX, cardY, cardW, cardH, 18);
        const cardGrad = ctx.createLinearGradient(cardX, cardY, cardX + cardW, cardY + cardH);
        cardGrad.addColorStop(0, 'rgba(15, 23, 42, 0.72)');
        cardGrad.addColorStop(1, 'rgba(30, 41, 59, 0.52)');
        ctx.fillStyle = cardGrad;
        ctx.fill();
        ctx.strokeStyle = 'rgba(56, 189, 248, 0.34)';
        ctx.lineWidth = 1.6;
        ctx.stroke();

        drawRoundedRectPath(ctx, cardX + 14, cardY + 22, 6, cardH - 44, 3);
        ctx.fillStyle = accent;
        ctx.fill();

        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = gold;
        ctx.font = '700 16px "Segoe UI", system-ui, sans-serif';
        const eyebrow = c.lang === 'en' ? 'UNKLAbot LIVE RESPONSE' : 'RESPON LANGSUNG UNKLABOT';
        ctx.fillText(eyebrow.toUpperCase(), cardX + 36, cardY + 34);

        const len = replyBody.length;
        const fontSize = len <= 88 ? 31 : (len <= 170 ? 27 : (len <= 260 ? 23 : 20));
        const lineHeight = Math.round(fontSize * 1.40);
        const maxLines = Math.min(8, Math.floor((cardH - 86) / lineHeight));

        ctx.fillStyle = '#f8fafc';
        ctx.font = `600 ${fontSize}px "Segoe UI", system-ui, sans-serif`;
        ctx.textAlign = 'left';
        ctx.textBaseline = 'alphabetic';
        const textMaxW = cardW - 64;
        const lines = wrapLines(ctx, replyBody, textMaxW, maxLines);

        const blockH = lines.length * lineHeight;
        const availTop = cardY + 66;
        const availH = cardH - 84;
        const startY = availTop + Math.max(24, Math.floor((availH - blockH) * 0.42)) + fontSize * 0.65;
        lines.forEach((ln, idx) => {
            ctx.fillText(ln, cardX + 36, startY + idx * lineHeight);
        });
    }

    _drawResponseStrip(ctx, c, accent) {
        const replyBody = String(c.replyText || c.subtitle || '').trim();
        if (!replyBody) return;

        const sx = 26;
        const sy = 390;
        const sw = CANVAS_W - 52;
        const sh = 92;

        drawRoundedRectPath(ctx, sx, sy, sw, sh, 14);
        ctx.fillStyle = 'rgba(15, 23, 42, 0.78)';
        ctx.fill();
        ctx.strokeStyle = 'rgba(56, 189, 248, 0.30)';
        ctx.lineWidth = 1.4;
        ctx.stroke();

        drawRoundedRectPath(ctx, sx + 10, sy + 14, 4, sh - 28, 2);
        ctx.fillStyle = accent;
        ctx.fill();

        ctx.textAlign = 'left';
        ctx.textBaseline = 'alphabetic';
        ctx.fillStyle = '#f1f5f9';
        const fontSize = replyBody.length > 145 ? 18 : 20;
        const lineHeight = fontSize + 7;
        ctx.font = `500 ${fontSize}px "Segoe UI", system-ui, sans-serif`;
        const lines = wrapLines(ctx, replyBody, sw - 44, 3);
        const firstY = lines.length === 1 ? sy + 52 : (lines.length === 2 ? sy + 39 : sy + 29);
        lines.forEach((ln, idx) => {
            ctx.fillText(ln, sx + 26, firstY + idx * lineHeight);
        });
    }

    _drawFeedbackStatusFooter(ctx, c, accent, gold) {
        const fy = CANVAS_H - 36;
        const srcText = String(c.source || c.badge || 'Smart AI').slice(0, 32);
        const hasEval = Number.isFinite(Number(c.evalPct));
        const evalPct = hasEval ? Math.max(0, Math.min(100, Math.round(Number(c.evalPct)))) : 90;
        const evalColor = evalPct >= 90 ? '#34d399' : (evalPct >= 75 ? accent : gold);

        ctx.beginPath();
        ctx.arc(38, fy + 6, 5, 0, Math.PI * 2);
        ctx.fillStyle = evalColor;
        ctx.fill();

        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = '#cbd5e1';
        ctx.font = '600 17px "Segoe UI", system-ui, sans-serif';
        ctx.fillText(srcText, 52, fy + 6);

        const evalLabel = `Eval ${evalPct}%`;
        ctx.font = '700 16px "Segoe UI", system-ui, sans-serif';
        const pillW = ctx.measureText(evalLabel).width + 82;
        const pillX = CANVAS_W - 28 - pillW;
        const pillY = fy - 11;

        drawRoundedRectPath(ctx, pillX, pillY, pillW, 32, 16);
        ctx.fillStyle = 'rgba(15, 23, 42, 0.85)';
        ctx.fill();
        ctx.strokeStyle = evalColor;
        ctx.lineWidth = 1.4;
        ctx.stroke();

        const barX = pillX + 14;
        const barY = fy + 3;
        const barW = 48;
        drawRoundedRectPath(ctx, barX, barY, barW, 6, 3);
        ctx.fillStyle = 'rgba(148, 163, 184, 0.28)';
        ctx.fill();
        drawRoundedRectPath(ctx, barX, barY, Math.max(8, barW * (evalPct / 100)), 6, 3);
        ctx.fillStyle = evalColor;
        ctx.fill();

        ctx.textAlign = 'left';
        ctx.fillStyle = evalColor;
        ctx.fillText(evalLabel, barX + barW + 10, fy + 6);
    }

    _drawChipsRow(ctx, chips, startX, y, accent, gold) {
        let x = startX;
        ctx.font = '700 20px "Segoe UI", system-ui, sans-serif';
        chips.slice(0, 3).forEach((label, idx) => {
            const text = String(label || '').slice(0, 24);
            if (!text) return;
            const w = Math.min(240, ctx.measureText(text).width + 32);
            if (x + w > CANVAS_W - 28) return;
            drawRoundedRectPath(ctx, x, y, w, 44, 14);
            ctx.fillStyle = idx === 1 ? 'rgba(251, 191, 36, 0.14)' : 'rgba(56, 189, 248, 0.15)';
            ctx.fill();
            ctx.strokeStyle = idx === 1 ? gold : accent;
            ctx.lineWidth = 1.6;
            ctx.stroke();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = '#f8fafc';
            ctx.fillText(text, x + w * 0.5, y + 22);
            x += w + 14;
        });
    }

    _drawBarsBlock(ctx, bars, x, y, maxW, accent) {
        const rowH = 38;
        bars.slice(0, 2).forEach((b, idx) => {
            const ry = y + idx * (rowH + 14);
            const pct = Math.max(0.05, Math.min(1.0, Number(b.pct ?? 0.5)));
            ctx.textAlign = 'left';
            ctx.textBaseline = 'alphabetic';
            ctx.fillStyle = '#cbd5e1';
            ctx.font = '600 19px "Segoe UI", system-ui, sans-serif';
            ctx.fillText(String(b.label || '').slice(0, 28), x, ry + 14);

            ctx.textAlign = 'right';
            ctx.fillStyle = '#ffffff';
            ctx.fillText(String(b.valueText || ''), x + maxW, ry + 14);

            drawRoundedRectPath(ctx, x, ry + 22, maxW, 12, 6);
            ctx.fillStyle = 'rgba(148, 163, 184, 0.20)';
            ctx.fill();

            drawRoundedRectPath(ctx, x, ry + 22, Math.max(18, maxW * pct), 12, 6);
            ctx.fillStyle = b.color || accent;
            ctx.fill();
        });
    }

    update(dtSec, nowSec, handWorldPos, headWorldPos, camera, framingMode = 'portrait') {
        if (this.active && this.autoHideAtSec > 0 && nowSec >= this.autoHideAtSec) {
            this.active = false;
        }

        const targetAlpha = this.active ? 1.0 : 0.0;
        const fadeRate = 1.0 - Math.exp(-(this.active ? 12.0 : 9.0) * Math.max(0.001, dtSec));
        this.appearProgress += (targetAlpha - this.appearProgress) * fadeRate;

        if (this.appearProgress <= 0.015 && !this.active) {
            this.rootGroup.visible = false;
            this.posInitialized = false;
            return null;
        }
        this.rootGroup.visible = true;

        if (this.active && this.cardData?.type === 'clock' && this.cardData?.liveClock !== false) {
            const curSec = Math.floor(nowSec);
            if (curSec !== this.lastClockSecDrawn) {
                this.lastClockSecDrawn = curSec;
                this._renderCardCanvas();
            }
        }

        const palmAnchor = handWorldPos || this._tmpVec.set(0.36, 1.35, 0.24);

        const rawX = palmAnchor.x + 0.010;
        const rawY = palmAnchor.y + 0.022;
        const rawZ = palmAnchor.z + 0.005;

        const targetPanelX = rawX + 0.015;
        const targetPanelY = rawY + (framingMode === 'portrait' ? 0.194 : 0.214) + Math.sin(nowSec * 2.2) * 0.0045;
        const targetPanelZ = rawZ + (framingMode === 'portrait' ? 0.022 : 0.035);

        if (!this.posInitialized) {
            this.smoothHandPos.set(rawX, rawY, rawZ);
            this.smoothPanelPos.set(targetPanelX, targetPanelY, targetPanelZ);
            this.posInitialized = true;
        } else {
            const posRate = 1.0 - Math.exp(-11.0 * Math.max(0.001, dtSec));
            this.smoothHandPos.lerp(this._tmpVec.set(rawX, rawY, rawZ), posRate);
            this.smoothPanelPos.x += (targetPanelX - this.smoothPanelPos.x) * posRate;
            this.smoothPanelPos.y += (targetPanelY - this.smoothPanelPos.y) * posRate;
            this.smoothPanelPos.z += (targetPanelZ - this.smoothPanelPos.z) * posRate;
        }

        const baseScale = framingMode === 'fullbody' ? 1.16 : (framingMode === 'halfbody' ? 1.04 : 0.88);
        const s = baseScale * (0.65 + 0.35 * this.appearProgress);

        if (camera) {
            const halfW = this.cardW * s * 0.50;
            const halfH = this.cardH * s * 0.50;

            const bottomCheck = this._tmpNdc.set(this.smoothPanelPos.x, this.smoothPanelPos.y - halfH, this.smoothPanelPos.z).project(camera);
            if (framingMode === 'portrait' && bottomCheck.y < -0.16) {
                const liftUp = Math.min(0.11, (-0.16 - bottomCheck.y) * 0.34);
                this.smoothPanelPos.y += liftUp;
            }

            if (headWorldPos) {
                const rightCorner = this._tmpNdc.set(this.smoothPanelPos.x, this.smoothPanelPos.y, this.smoothPanelPos.z - halfW).project(camera);
                const headNdc = this._tmpHeadNdc.copy(headWorldPos).project(camera);
                if (rightCorner.x > (headNdc.x - 0.14) && Math.abs(rightCorner.y - headNdc.y) < 0.34) {
                    const pushOut = Math.min(0.10, (rightCorner.x - (headNdc.x - 0.14)) * 0.32);
                    this.smoothPanelPos.z += pushOut;
                }
            }
            const leftCorner = this._tmpHeadNdc.set(this.smoothPanelPos.x, this.smoothPanelPos.y, this.smoothPanelPos.z + halfW * 1.04).project(camera);
            if (leftCorner.x < -0.94) {
                const pullIn = Math.min(0.16, (-0.94 - leftCorner.x) * 0.40);
                this.smoothPanelPos.z -= pullIn;
            }
        }

        this.emitterGroup.position.copy(this.smoothHandPos);
        this.outerRing.rotation.z = nowSec * 1.15;
        this.innerRing.rotation.z = -nowSec * 1.85;
        const pulse = 0.92 + 0.10 * Math.sin(nowSec * 4.2);
        this.emitterGroup.scale.setScalar(this.appearProgress * pulse);

        this.panelGroup.position.copy(this.smoothPanelPos);
        this.panelGroup.scale.setScalar(s);

        if (camera) {

            this._tmpFwd.subVectors(camera.position, this.panelGroup.position).normalize();
            this._tmpRight.crossVectors(new this.THREE.Vector3(0, 1, 0), this._tmpFwd).normalize();
            this._tmpUp.crossVectors(this._tmpFwd, this._tmpRight).normalize();
            this._tmpMat.makeBasis(this._tmpRight, this._tmpUp, this._tmpFwd);
            this._tmpQuat.setFromRotationMatrix(this._tmpMat);
            this.panelGroup.quaternion.slerp(this._tmpQuat, 0.25);
        }

        const cardBottomY = this.smoothPanelPos.y - (this.cardH * s * 0.48);
        const beamH = Math.max(0.04, cardBottomY - this.smoothHandPos.y);
        this.beamMesh.position.set(
            (this.smoothHandPos.x + this.smoothPanelPos.x) * 0.5,
            this.smoothHandPos.y + beamH * 0.5,
            (this.smoothHandPos.z + this.smoothPanelPos.z) * 0.5
        );
        this.beamMesh.scale.set(1, beamH, 1);

        this.cardMat.opacity = 0.97 * this.appearProgress;
        this.frameMat.opacity = 0.88 * this.appearProgress;
        this.outerRingMat.opacity = 0.78 * this.appearProgress;
        this.innerRingMat.opacity = 0.85 * this.appearProgress;
        this.beamMat.opacity = 0.16 * this.appearProgress;

        return this.smoothPanelPos;
    }

    dispose() {
        if (this.panelTexture) this.panelTexture.dispose();
        if (this.cardMesh?.geometry) this.cardMesh.geometry.dispose();
        if (this.outerRing?.geometry) this.outerRing.geometry.dispose();
        if (this.innerRing?.geometry) this.innerRing.geometry.dispose();
        if (this.beamMesh?.geometry) this.beamMesh.geometry.dispose();
        if (this.frameLines?.geometry) this.frameLines.geometry.dispose();
        if (this.cardMat) this.cardMat.dispose();
        if (this.frameMat) this.frameMat.dispose();
        if (this.outerRingMat) this.outerRingMat.dispose();
        if (this.innerRingMat) this.innerRingMat.dispose();
        if (this.beamMat) this.beamMat.dispose();
        if (this.rootGroup && this.rootGroup.parent) {
            this.rootGroup.parent.remove(this.rootGroup);
        }
    }
}
