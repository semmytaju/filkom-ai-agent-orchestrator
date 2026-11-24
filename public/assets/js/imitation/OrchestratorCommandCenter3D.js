import { applySrgbTexture, createChibiHuman, createOrchestratorRobot, createRoundedExtrudeGeo, createRoundedSlabGeo, preloadChibiAssets } from './ChibiAvatarBuilder.js?v=20261124v400';
import { HologramPanel3D } from './HologramPanel3D.js?v=20261124v375';

const POD_LAYOUT = [
    {
        zoneKey: 'DEAN',
        jobLabel: 'DEAN AGENT',
        icon: 'crown',
        accent: 0xf59e0b,
        accentCss: '#f59e0b',
        persona: 'dean',
        pos: [-7.0, 0, -2.5],
        rotY: Math.PI * 0.5,
        propType: 'dean_chart',
        chartType: 'dean_kpi',
        route: {
            startStanding: false,
            initialDelay: 4.5,
            sitDuration: 9.5,
            activityDuration: 7.5,
            activityType: 'confer',
            activityWorldYaw: Math.PI * 0.42,
            worldWaypoints: [
                { x: -7.2, z: -1.1 },
                { x: -4.2, z: -1.1 },
                { x: -3.2, z: -0.6 }
            ]
        }
    },
    {
        zoneKey: 'KAPRODI',
        jobLabel: 'KAPRODI AGENT',
        icon: 'grad',
        accent: 0x3b82f6,
        accentCss: '#3b82f6',
        persona: 'kaprodi_f',
        pos: [-7.0, 0, 1.8],
        rotY: Math.PI * 0.5,
        propType: 'kaprodi_grad',
        chartType: 'kaprodi_bars',
        route: {
            startStanding: false,
            initialDelay: 9.0,
            sitDuration: 11.0,
            activityDuration: 8.0,
            activityType: 'kiosk',
            activityWorldYaw: Math.PI,
            worldWaypoints: [
                { x: -7.2, z: 0.4 },
                { x: -4.2, z: 0.4 },
                { x: -4.2, z: -4.6 },
                { x: 4.1, z: -5.4 }
            ]
        }
    },
    {
        zoneKey: 'SECRETARY',
        jobLabel: 'SECRETARY AGENT',
        icon: 'doc',
        accent: 0xf43f5e,
        accentCss: '#f43f5e',
        persona: 'secretary_f',
        pos: [6.8, 0, 1.8],
        rotY: -Math.PI * 0.5,
        propType: 'sec_calendar',
        chartType: 'sec_donut',
        route: {
            startStanding: true,
            initialDelay: 6.5,
            sitDuration: 9.0,
            activityDuration: 8.0,
            activityType: 'confer',
            activityWorldYaw: -Math.PI * 0.55,
            worldWaypoints: [
                { x: 7.0, z: 0.4 },
                { x: 4.3, z: 0.4 },
                { x: 3.15, z: 0.55 }
            ]
        }
    },
    {
        zoneKey: 'UPMF',
        jobLabel: 'UPMF AGENT',
        icon: 'shield',
        accent: 0xa855f7,
        accentCss: '#a855f7',
        persona: 'upmf',
        pos: [6.8, 0, -2.5],
        rotY: -Math.PI * 0.5,
        propType: 'upmf_seal',
        chartType: 'upmf_radar',
        route: {
            startStanding: true,
            initialDelay: 8.5,
            sitDuration: 10.5,
            activityDuration: 7.5,
            activityType: 'audit',
            activityWorldYaw: Math.PI,
            worldWaypoints: [
                { x: 7.0, z: -3.8 },
                { x: 4.8, z: -4.5 },
                { x: 4.8, z: -5.4 }
            ]
        }
    },
    {
        zoneKey: 'RESEARCH_HEAD',
        jobLabel: 'RESEARCH AGENT',
        icon: 'flask',
        accent: 0x06b6d4,
        accentCss: '#06b6d4',
        persona: 'research',
        pos: [-3.4, 0, 5.2],
        rotY: Math.PI * 0.68,
        propType: 'research_lab',
        chartType: 'research_wave',
        route: {
            startStanding: false,
            initialDelay: 6.0,
            sitDuration: 10.0,
            activityDuration: 7.5,
            activityType: 'confer',
            activityWorldYaw: Math.PI * 0.85,
            worldWaypoints: [
                { x: -4.6, z: 4.8 },
                { x: -4.2, z: 3.2 },
                { x: -2.0, z: 2.9 }
            ]
        }
    },
    {
        zoneKey: 'STUDENT',
        jobLabel: 'STUDENT AGENT',
        icon: 'users',
        accent: 0xf97316,
        accentCss: '#f97316',
        persona: 'student',
        pos: [3.4, 0, 5.2],
        rotY: -Math.PI * 0.68,
        propType: 'student_study',
        chartType: 'student_progress',
        route: {
            startStanding: false,
            initialDelay: 11.0,
            sitDuration: 9.5,
            activityDuration: 7.0,
            activityType: 'browse',
            activityWorldYaw: Math.PI,
            worldWaypoints: [
                { x: 4.6, z: 4.8 },
                { x: 4.3, z: 3.2 },
                { x: 4.3, z: -4.6 },
                { x: -1.4, z: -5.6 }
            ]
        }
    }
];

const STATE_COLOR = {
    idle: 0x38bdf8,
    listening: 0x0ea5e9,
    routing: 0x2563eb,
    searching: 0x22d3ee,
    thinking: 0x8b5cf6,
    working: 0x3b82f6,
    typing: 0x0284c7,
    collaborating: 0xf59e0b,
    success: 0x10b981,
    error: 0xef4444
};

function disposeObjectTree(group) {
    if (!group) return;
    group.traverse((obj) => {
        if (obj.geometry && !obj.geometry.userData?._shared) {
            obj.geometry.dispose();
        }
        const mats = Array.isArray(obj.material) ? obj.material : [obj.material];
        mats.forEach((m) => {
            if (!m || m.userData?._shared) return;
            ['map', 'alphaMap', 'emissiveMap'].forEach((k) => {
                if (m[k]) m[k].dispose();
            });
            m.dispose();
        });
    });
    group.clear();
}

function makeCanvasTex(THREE, w, h, drawFn) {
    const c = document.createElement('canvas');
    c.width = w;
    c.height = h;
    const ctx = c.getContext('2d');
    drawFn(ctx, w, h);
    return applySrgbTexture(THREE, new THREE.CanvasTexture(c));
}

function createDynamicCanvasTex(THREE, w, h) {
    const c = document.createElement('canvas');
    c.width = w;
    c.height = h;
    const ctx = c.getContext('2d');
    const tex = applySrgbTexture(THREE, new THREE.CanvasTexture(c));
    return { canvas: c, ctx, tex, w, h };
}

function roundRectCtx(ctx, x, y, w, h, r) {
    const rad = Math.min(r, w * 0.5, h * 0.5);
    ctx.beginPath();
    ctx.moveTo(x + rad, y);
    ctx.arcTo(x + w, y, x + w, y + h, rad);
    ctx.arcTo(x + w, y + h, x, y + h, rad);
    ctx.arcTo(x, y + h, x, y, rad);
    ctx.arcTo(x, y, x + w, y, rad);
    ctx.closePath();
}

function createGlowSprite(THREE, colorCss, size = 1.6, opacity = 0.7) {
    const tex = makeCanvasTex(THREE, 256, 256, (ctx, w, h) => {
        const g = ctx.createRadialGradient(w / 2, h / 2, 4, w / 2, h / 2, w / 2);
        g.addColorStop(0.0, 'rgba(255,255,255,0.95)');
        g.addColorStop(0.28, colorCss);
        g.addColorStop(1.0, 'rgba(56,189,248,0)');
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, w, h);
    });
    const mat = new THREE.SpriteMaterial({
        map: tex,
        transparent: true,
        opacity,
        depthWrite: false,
        blending: THREE.AdditiveBlending
    });
    const sp = new THREE.Sprite(mat);
    sp.scale.set(size, size, 1);
    return sp;
}

function buildStudioEnvironmentMap(THREE, renderer) {
    try {
        const pmrem = new THREE.PMREMGenerator(renderer);
        const envScene = new THREE.Scene();
        envScene.background = new THREE.Color(0x162947);

        const topLight = new THREE.Mesh(
            new THREE.PlaneGeometry(30, 30),
            new THREE.MeshBasicMaterial({ color: 0xe0f2fe, side: THREE.DoubleSide })
        );
        topLight.position.set(0, 12, 0);
        topLight.rotation.x = Math.PI / 2;
        envScene.add(topLight);

        const sideCyan = new THREE.Mesh(
            new THREE.PlaneGeometry(22, 14),
            new THREE.MeshBasicMaterial({ color: 0x38bdf8, side: THREE.DoubleSide })
        );
        sideCyan.position.set(-14, 5, -8);
        sideCyan.lookAt(0, 0, 0);
        envScene.add(sideCyan);

        const sideWarm = new THREE.Mesh(
            new THREE.PlaneGeometry(22, 14),
            new THREE.MeshBasicMaterial({ color: 0xfff5e4, side: THREE.DoubleSide })
        );
        sideWarm.position.set(14, 5, 10);
        sideWarm.lookAt(0, 0, 0);
        envScene.add(sideWarm);

        const floorBounce = new THREE.Mesh(
            new THREE.PlaneGeometry(30, 30),
            new THREE.MeshBasicMaterial({ color: 0x1e3a5f, side: THREE.DoubleSide })
        );
        floorBounce.position.set(0, -10, 0);
        floorBounce.rotation.x = -Math.PI / 2;
        envScene.add(floorBounce);

        const rt = pmrem.fromScene(envScene, 0.04);
        pmrem.dispose();
        return rt;
    } catch (_) {
        return null;
    }
}

function drawMiniIcon(ctx, icon, cx, cy, r, color) {
    ctx.save();
    ctx.fillStyle = color;
    ctx.strokeStyle = color;
    ctx.lineWidth = 4;
    if (icon === 'crown') {
        ctx.beginPath();
        ctx.moveTo(cx - r, cy + r * 0.55);
        ctx.lineTo(cx - r * 0.8, cy - r * 0.45);
        ctx.lineTo(cx - r * 0.25, cy + r * 0.08);
        ctx.lineTo(cx, cy - r * 0.72);
        ctx.lineTo(cx + r * 0.25, cy + r * 0.08);
        ctx.lineTo(cx + r * 0.8, cy - r * 0.45);
        ctx.lineTo(cx + r, cy + r * 0.55);
        ctx.closePath();
        ctx.fill();
    } else if (icon === 'grad') {
        ctx.beginPath();
        ctx.moveTo(cx, cy - r * 0.7);
        ctx.lineTo(cx + r, cy - r * 0.1);
        ctx.lineTo(cx, cy + r * 0.45);
        ctx.lineTo(cx - r, cy - r * 0.1);
        ctx.closePath();
        ctx.fill();
    } else if (icon === 'shield') {
        ctx.beginPath();
        ctx.moveTo(cx, cy - r * 0.85);
        ctx.lineTo(cx + r * 0.75, cy - r * 0.42);
        ctx.lineTo(cx + r * 0.65, cy + r * 0.35);
        ctx.lineTo(cx, cy + r * 0.85);
        ctx.lineTo(cx - r * 0.65, cy + r * 0.35);
        ctx.lineTo(cx - r * 0.75, cy - r * 0.42);
        ctx.closePath();
        ctx.fill();
    } else if (icon === 'flask') {
        ctx.beginPath();
        ctx.moveTo(cx - r * 0.28, cy - r * 0.75);
        ctx.lineTo(cx + r * 0.28, cy - r * 0.75);
        ctx.lineTo(cx + r * 0.28, cy - r * 0.2);
        ctx.lineTo(cx + r * 0.82, cy + r * 0.75);
        ctx.lineTo(cx - r * 0.82, cy + r * 0.75);
        ctx.lineTo(cx - r * 0.28, cy - r * 0.2);
        ctx.closePath();
        ctx.fill();
    } else if (icon === 'doc') {
        roundRectCtx(ctx, cx - r * 0.65, cy - r * 0.78, r * 1.3, r * 1.56, 6);
        ctx.fill();
    } else {
        ctx.beginPath();
        ctx.arc(cx, cy - r * 0.28, r * 0.36, 0, Math.PI * 2);
        ctx.fill();
        ctx.beginPath();
        ctx.arc(cx, cy + r * 0.75, r * 0.65, Math.PI, 0);
        ctx.fill();
    }
    ctx.restore();
}

function drawAgentLiveChart(surf, chartType, accentCss, t, activeBoost = 0) {
    const { ctx, w, h } = surf;
    const bg = ctx.createLinearGradient(0, 0, 0, h);
    bg.addColorStop(0, '#071326');
    bg.addColorStop(1, '#0d2240');
    ctx.fillStyle = bg;
    ctx.fillRect(0, 0, w, h);

    ctx.strokeStyle = 'rgba(56, 189, 248, 0.14)';
    ctx.lineWidth = 1;
    for (let gx = 24; gx < w; gx += 32) {
        ctx.beginPath(); ctx.moveTo(gx, 28); ctx.lineTo(gx, h - 12); ctx.stroke();
    }
    for (let gy = 36; gy < h; gy += 28) {
        ctx.beginPath(); ctx.moveTo(12, gy); ctx.lineTo(w - 12, gy); ctx.stroke();
    }

    ctx.fillStyle = accentCss;
    ctx.beginPath();
    ctx.arc(18, 16, 4.5, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = '#e0f2fe';
    ctx.font = 'bold 12px "Segoe UI", sans-serif';
    ctx.textAlign = 'left';

    if (chartType === 'dean_kpi') {
        const kpiVal = (93.2 + Math.sin(t * 0.9) * 2.1 + activeBoost * 2.4).toFixed(1);
        ctx.fillText('EXEC KPI  ' + kpiVal + '%', 30, 20);

        const drawWave = (col, fillCol, amp, freq, phase, baseY) => {
            ctx.beginPath();
            ctx.moveTo(14, h - 14);
            for (let i = 0; i <= 24; i++) {
                const px = 14 + (i / 24) * (w - 28);
                const py = baseY - (Math.sin(t * 2.1 + i * freq + phase) * amp + Math.cos(t * 1.2 + i * 0.5) * (amp * 0.45));
                ctx.lineTo(px, py);
            }
            ctx.lineTo(w - 14, h - 14);
            ctx.closePath();
            ctx.fillStyle = fillCol;
            ctx.fill();

            ctx.beginPath();
            for (let i = 0; i <= 24; i++) {
                const px = 14 + (i / 24) * (w - 28);
                const py = baseY - (Math.sin(t * 2.1 + i * freq + phase) * amp + Math.cos(t * 1.2 + i * 0.5) * (amp * 0.45));
                if (i === 0) ctx.moveTo(px, py); else ctx.lineTo(px, py);
            }
            ctx.strokeStyle = col;
            ctx.lineWidth = 3;
            ctx.stroke();
        };
        drawWave('#fbbf24', 'rgba(251, 191, 36, 0.24)', 22, 0.38, 0, 96);
        drawWave('#38bdf8', 'rgba(56, 189, 248, 0.20)', 16, 0.48, 1.8, 122);
    } else if (chartType === 'kaprodi_bars') {
        ctx.fillText('PRODI LOAD  LIVE', 30, 20);
        const labels = ['IF', 'SI', 'TI', 'MSI'];
        const cols = ['#38bdf8', '#3b82f6', '#22d3ee', '#818cf8'];
        labels.forEach((lb, i) => {
            const bx = 26 + i * 56;
            const val1 = 44 + Math.sin(t * 2.2 + i * 1.1) * 26 + activeBoost * 16;
            const val2 = 34 + Math.cos(t * 1.8 + i * 0.9) * 22;
            ctx.fillStyle = cols[i];
            roundRectCtx(ctx, bx, h - 26 - val1, 20, val1, 4);
            ctx.fill();
            ctx.fillStyle = '#fde047';
            roundRectCtx(ctx, bx + 23, h - 26 - val2, 16, val2, 4);
            ctx.fill();
            ctx.fillStyle = '#bae6fd';
            ctx.font = 'bold 11px "Segoe UI", sans-serif';
            ctx.fillText(lb, bx + 8, h - 10);
        });
        const threshY = 66 + Math.sin(t * 1.2) * 5;
        ctx.strokeStyle = '#f43f5e';
        ctx.lineWidth = 2;
        ctx.setLineDash([5, 4]);
        ctx.beginPath(); ctx.moveTo(14, threshY); ctx.lineTo(w - 14, threshY); ctx.stroke();
        ctx.setLineDash([]);
    } else if (chartType === 'sec_donut') {
        ctx.fillText('ADMIN FLOW  LIVE', 30, 20);
        const cx = 74;
        const cy = 96;
        const r = 40;
        const rot = t * 0.8;
        const slices = [
            { frac: 0.36, col: '#f43f5e' },
            { frac: 0.32, col: '#10b981' },
            { frac: 0.20, col: '#38bdf8' },
            { frac: 0.12, col: '#fbbf24' }
        ];
        let a0 = rot;
        slices.forEach((sl) => {
            ctx.beginPath();
            ctx.arc(cx, cy, r, a0, a0 + sl.frac * Math.PI * 2 - 0.08);
            ctx.strokeStyle = sl.col;
            ctx.lineWidth = 14;
            ctx.stroke();
            a0 += sl.frac * Math.PI * 2;
        });
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 15px "Segoe UI", sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(Math.round(86 + Math.sin(t * 1.5) * 8) + '%', cx, cy + 5);
        ctx.textAlign = 'left';

        for (let rIdx = 0; rIdx < 4; rIdx++) {
            const ry = 48 + rIdx * 24;
            const bw = 46 + Math.sin(t * 2.6 + rIdx * 1.3) * 32 + 20;
            ctx.fillStyle = 'rgba(255,255,255,0.12)';
            roundRectCtx(ctx, 134, ry, 106, 14, 5);
            ctx.fill();
            ctx.fillStyle = slices[rIdx].col;
            roundRectCtx(ctx, 134, ry, Math.min(104, bw), 14, 5);
            ctx.fill();
        }
    } else if (chartType === 'sec_queue') {
        ctx.fillText('AGENDA SYNC', 30, 20);
        for (let i = 0; i < 5; i++) {
            const y = 36 + i * 24;
            const activeRow = Math.floor(t * 1.8) % 5 === i;
            ctx.fillStyle = activeRow ? 'rgba(16, 185, 129, 0.36)' : 'rgba(56, 189, 248, 0.12)';
            roundRectCtx(ctx, 16, y, w - 32, 18, 5);
            ctx.fill();
            ctx.fillStyle = activeRow ? '#34d399' : '#38bdf8';
            ctx.fillRect(24, y + 5, 42 + ((i * 29) % 85), 8);
            ctx.fillStyle = '#fbbf24';
            ctx.beginPath(); ctx.arc(w - 28, y + 9, 4, 0, Math.PI * 2); ctx.fill();
        }
    } else if (chartType === 'upmf_radar') {
        ctx.fillText('QA AUDIT RADAR', 30, 20);
        const cx = 128;
        const cy = 96;
        const maxR = 48;
        [0.35, 0.68, 1.0].forEach((sc) => {
            ctx.beginPath();
            for (let k = 0; k < 5; k++) {
                const a = -Math.PI / 2 + (k / 5) * Math.PI * 2;
                const rx = cx + Math.cos(a) * maxR * sc;
                const ry = cy + Math.sin(a) * maxR * sc;
                if (k === 0) ctx.moveTo(rx, ry); else ctx.lineTo(rx, ry);
            }
            ctx.closePath();
            ctx.strokeStyle = 'rgba(168, 85, 247, 0.38)';
            ctx.lineWidth = 1.5;
            ctx.stroke();
        });
        ctx.beginPath();
        for (let k = 0; k < 5; k++) {
            const a = -Math.PI / 2 + (k / 5) * Math.PI * 2;
            const v = 0.56 + 0.32 * Math.sin(t * 2.0 + k * 1.3);
            const rx = cx + Math.cos(a) * maxR * v;
            const ry = cy + Math.sin(a) * maxR * v;
            if (k === 0) ctx.moveTo(rx, ry); else ctx.lineTo(rx, ry);
        }
        ctx.closePath();
        ctx.fillStyle = 'rgba(168, 85, 247, 0.42)';
        ctx.fill();
        ctx.strokeStyle = '#34d399';
        ctx.lineWidth = 2.5;
        ctx.stroke();

        const sweep = t * 1.8;
        ctx.strokeStyle = 'rgba(56, 189, 248, 0.85)';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.lineTo(cx + Math.cos(sweep) * maxR, cy + Math.sin(sweep) * maxR);
        ctx.stroke();
    } else if (chartType === 'research_wave') {
        ctx.fillText('NEURAL SIGNAL LAB', 30, 20);
        const drawOsc = (col, baseY, amp, freq, spd) => {
            ctx.beginPath();
            for (let x = 14; x <= w - 14; x += 4) {
                const norm = (x - 14) / (w - 28);
                const env = Math.sin(norm * Math.PI);
                const y = baseY + Math.sin(norm * freq + t * spd) * amp * env + Math.cos(norm * freq * 2.1 - t * spd * 1.3) * (amp * 0.35);
                if (x === 14) ctx.moveTo(x, y); else ctx.lineTo(x, y);
            }
            ctx.strokeStyle = col;
            ctx.lineWidth = 2.6;
            ctx.stroke();
        };
        drawOsc('#22d3ee', 72, 24, 16, 4.8);
        drawOsc('#4ade80', 118, 18, 22, -3.6);
        for (let n = 0; n < 6; n++) {
            const nx = 34 + n * 38;
            const ny = 95 + Math.sin(t * 2.8 + n) * 26;
            ctx.fillStyle = '#fde047';
            ctx.beginPath(); ctx.arc(nx, ny, 3.5, 0, Math.PI * 2); ctx.fill();
        }
    } else {
        ctx.fillText('ACADEMIC PROGRESS', 30, 20);
        const rings = [
            { x: 52, col: '#f97316', pct: 0.78 + Math.sin(t * 1.3) * 0.12 },
            { x: 128, col: '#22d3ee', pct: 0.86 + Math.cos(t * 1.6) * 0.08 },
            { x: 204, col: '#22c55e', pct: 0.72 + Math.sin(t * 1.9 + 1) * 0.14 }
        ];
        rings.forEach((rg) => {
            ctx.beginPath();
            ctx.arc(rg.x, 76, 25, 0, Math.PI * 2);
            ctx.strokeStyle = 'rgba(255,255,255,0.14)';
            ctx.lineWidth = 8;
            ctx.stroke();
            ctx.beginPath();
            ctx.arc(rg.x, 76, 25, -Math.PI / 2, -Math.PI / 2 + rg.pct * Math.PI * 2);
            ctx.strokeStyle = rg.col;
            ctx.lineWidth = 8;
            ctx.stroke();
            ctx.fillStyle = '#ffffff';
            ctx.font = 'bold 12px "Segoe UI", sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText(Math.round(rg.pct * 100) + '%', rg.x, 80);
        });
        ctx.textAlign = 'left';
        for (let c = 0; c < 12; c++) {
            const pulse = 0.35 + 0.65 * Math.abs(Math.sin(t * 2.2 + c * 0.65));
            ctx.fillStyle = `rgba(34, 211, 238, ${pulse.toFixed(2)})`;
            roundRectCtx(ctx, 18 + c * 18.5, 122, 14, 22, 4);
            ctx.fill();
        }
    }

    surf.tex.needsUpdate = true;
}

function drawBigWallTelemetry(surf, t, activeZoneKey, activeBoost) {
    const { ctx, w, h } = surf;
    const bg = ctx.createLinearGradient(0, 0, w, h);
    bg.addColorStop(0, '#061124');
    bg.addColorStop(0.5, '#0b1e3b');
    bg.addColorStop(1, '#07152c');
    ctx.fillStyle = bg;
    ctx.fillRect(0, 0, w, h);

    ctx.strokeStyle = 'rgba(56, 189, 248, 0.12)';
    ctx.lineWidth = 1;
    for (let x = 24; x < w; x += 40) {
        ctx.beginPath(); ctx.moveTo(x, 56); ctx.lineTo(x, h - 20); ctx.stroke();
    }
    for (let y = 56; y < h - 20; y += 36) {
        ctx.beginPath(); ctx.moveTo(20, y); ctx.lineTo(w - 20, y); ctx.stroke();
    }

    ctx.fillStyle = 'rgba(15, 35, 68, 0.92)';
    roundRectCtx(ctx, 16, 14, w - 32, 44, 12);
    ctx.fill();
    ctx.strokeStyle = 'rgba(56, 189, 248, 0.55)';
    ctx.lineWidth = 2;
    ctx.stroke();

    const blinkDot = 0.55 + 0.45 * Math.sin(t * 5.0);
    ctx.fillStyle = `rgba(52, 211, 153, ${blinkDot.toFixed(2)})`;
    ctx.beginPath(); ctx.arc(38, 36, 7, 0, Math.PI * 2); ctx.fill();

    ctx.fillStyle = '#f8fafc';
    ctx.font = 'bold 20px "Segoe UI", sans-serif';
    ctx.textAlign = 'left';
    ctx.fillText('FILKOM REAL-TIME AI TELEMETRY', 56, 43);

    const ops = Math.round(142 + Math.sin(t * 1.9) * 28 + activeBoost * 85);
    ctx.fillStyle = '#38bdf8';
    ctx.font = 'bold 18px "Segoe UI", sans-serif';
    ctx.textAlign = 'right';
    ctx.fillText(ops + ' OPS/S  •  6 ACTIVE NODES', w - 32, 42);
    ctx.textAlign = 'left';

    ctx.fillStyle = 'rgba(9, 24, 48, 0.78)';
    roundRectCtx(ctx, 18, 72, 280, 224, 14);
    ctx.fill();
    ctx.strokeStyle = 'rgba(56, 189, 248, 0.38)';
    ctx.lineWidth = 1.8;
    ctx.stroke();

    ctx.fillStyle = '#7dd3fc';
    ctx.font = 'bold 13px "Segoe UI", sans-serif';
    ctx.fillText('LIVE ORCHESTRATION FLOW', 32, 95);

    const drawSeries = (col, fillCol, amp, freq, phase, baseY) => {
        ctx.beginPath();
        ctx.moveTo(30, 282);
        for (let i = 0; i <= 30; i++) {
            const px = 30 + (i / 30) * 254;
            const py = baseY - (Math.sin(t * 2.5 + i * freq + phase) * amp + Math.cos(t * 1.4 + i * 0.45) * (amp * 0.42));
            ctx.lineTo(px, py);
        }
        ctx.lineTo(284, 282);
        ctx.closePath();
        ctx.fillStyle = fillCol;
        ctx.fill();

        ctx.beginPath();
        for (let i = 0; i <= 30; i++) {
            const px = 30 + (i / 30) * 254;
            const py = baseY - (Math.sin(t * 2.5 + i * freq + phase) * amp + Math.cos(t * 1.4 + i * 0.45) * (amp * 0.42));
            if (i === 0) ctx.moveTo(px, py); else ctx.lineTo(px, py);
        }
        ctx.strokeStyle = col;
        ctx.lineWidth = 3;
        ctx.stroke();
    };
    drawSeries('#38bdf8', 'rgba(56, 189, 248, 0.22)', 34, 0.32, 0.0, 178);
    drawSeries('#34d399', 'rgba(52, 211, 153, 0.18)', 26, 0.42, 1.7, 218);
    drawSeries('#fbbf24', 'rgba(251, 191, 36, 0.16)', 18, 0.50, 3.1, 248);

    ctx.fillStyle = 'rgba(9, 24, 48, 0.78)';
    roundRectCtx(ctx, 312, 72, 242, 224, 14);
    ctx.fill();
    ctx.strokeStyle = 'rgba(139, 92, 246, 0.42)';
    ctx.lineWidth = 1.8;
    ctx.stroke();

    ctx.fillStyle = '#c4b5fd';
    ctx.font = 'bold 13px "Segoe UI", sans-serif';
    ctx.fillText('AGENT LOAD SPECTRUM', 326, 95);

    const roleBars = [
        { k: 'DEAN', lb: 'DEAN', col: '#f59e0b' },
        { k: 'KAPRODI', lb: 'KPR', col: '#3b82f6' },
        { k: 'SECRETARY', lb: 'SEC', col: '#f43f5e' },
        { k: 'UPMF', lb: 'UPMF', col: '#a855f7' },
        { k: 'RESEARCH_HEAD', lb: 'RES', col: '#06b6d4' },
        { k: 'STUDENT', lb: 'STD', col: '#f97316' }
    ];
    roleBars.forEach((rb, i) => {
        const bx = 326 + i * 36;
        const boost = activeZoneKey === rb.k ? 44 : 0;
        const bh = Math.min(156, 56 + Math.sin(t * 2.4 + i * 1.05) * 34 + boost);
        ctx.fillStyle = 'rgba(255,255,255,0.08)';
        roundRectCtx(ctx, bx, 112, 24, 156, 6);
        ctx.fill();

        const bGrad = ctx.createLinearGradient(0, 268 - bh, 0, 268);
        bGrad.addColorStop(0, '#ffffff');
        bGrad.addColorStop(0.18, rb.col);
        bGrad.addColorStop(1, 'rgba(15, 23, 42, 0.65)');
        ctx.fillStyle = bGrad;
        roundRectCtx(ctx, bx, 268 - bh, 24, bh, 6);
        ctx.fill();

        ctx.fillStyle = '#cbd5e1';
        ctx.font = 'bold 10px "Segoe UI", sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(rb.lb, bx + 12, 286);
    });
    ctx.textAlign = 'left';

    ctx.fillStyle = 'rgba(9, 24, 48, 0.78)';
    roundRectCtx(ctx, 568, 72, 182, 224, 14);
    ctx.fill();
    ctx.strokeStyle = 'rgba(34, 211, 238, 0.42)';
    ctx.lineWidth = 1.8;
    ctx.stroke();

    const gx = 659;
    const gy = 186;
    ctx.save();
    ctx.translate(gx, gy);
    ctx.rotate(t * 0.9);
    ctx.beginPath();
    ctx.arc(0, 0, 62, 0, Math.PI * 1.4);
    ctx.strokeStyle = '#38bdf8';
    ctx.lineWidth = 8;
    ctx.stroke();
    ctx.rotate(-t * 1.7);
    ctx.beginPath();
    ctx.arc(0, 0, 46, 0, Math.PI * 1.2);
    ctx.strokeStyle = '#a855f7';
    ctx.lineWidth = 6;
    ctx.stroke();
    ctx.restore();

    const scoreVal = (96.4 + Math.sin(t * 1.1) * 1.8).toFixed(1);
    ctx.fillStyle = '#ffffff';
    ctx.font = 'bold 24px "Segoe UI", sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText(scoreVal, gx, gy + 7);
    ctx.fillStyle = '#38bdf8';
    ctx.font = 'bold 11px "Segoe UI", sans-serif';
    ctx.fillText('SYNC INDEX', gx, gy + 24);
    ctx.fillText('NEURAL HUD', gx, 95);
    ctx.textAlign = 'left';

    ctx.fillStyle = 'rgba(9, 24, 48, 0.85)';
    roundRectCtx(ctx, 18, 310, w - 36, 120, 14);
    ctx.fill();
    ctx.strokeStyle = 'rgba(56, 189, 248, 0.35)';
    ctx.lineWidth = 1.8;
    ctx.stroke();

    ctx.fillStyle = '#38bdf8';
    ctx.font = 'bold 12px "Segoe UI", sans-serif';
    ctx.fillText('REAL-TIME EVENT PULSE STREAM', 32, 332);

    ctx.beginPath();
    for (let x = 32; x <= w - 32; x += 6) {
        const u = (x - 32) / (w - 64);
        const spike = Math.sin(u * 26 - t * 6.5) * (16 + activeBoost * 16) * Math.sin(u * Math.PI);
        const y = 378 + spike + Math.cos(u * 12 + t * 3.2) * 10;
        if (x === 32) ctx.moveTo(x, y); else ctx.lineTo(x, y);
    }
    ctx.strokeStyle = activeBoost > 0.2 ? '#fbbf24' : '#22d3ee';
    ctx.lineWidth = 3;
    ctx.stroke();

    surf.tex.needsUpdate = true;
}

function drawHubHoloScreen(surf, kind, t) {
    const { ctx, w, h } = surf;
    ctx.clearRect(0, 0, w, h);
    const bg = ctx.createLinearGradient(0, 0, w, h);
    bg.addColorStop(0, 'rgba(14, 165, 233, 0.56)');
    bg.addColorStop(1, 'rgba(37, 99, 235, 0.28)');
    ctx.fillStyle = bg;
    roundRectCtx(ctx, 8, 8, w - 16, h - 16, 26);
    ctx.fill();
    ctx.strokeStyle = 'rgba(186, 230, 254, 0.92)';
    ctx.lineWidth = 4;
    ctx.stroke();

    if (kind === 'analytics') {
        [72, 118, 92, 146, 108].forEach((baseH, k) => {
            const bh = baseH + Math.sin(t * 2.6 + k * 0.9) * 28;
            ctx.fillStyle = k % 2 === 0 ? 'rgba(255,255,255,0.88)' : '#7dd3fc';
            roundRectCtx(ctx, 42 + k * 54, h - 38 - bh, 34, bh, 8);
            ctx.fill();
        });
        ctx.save();
        ctx.translate(396, 178);
        ctx.rotate(t * 0.9);
        ctx.beginPath();
        ctx.arc(0, 0, 56, 0, Math.PI * 1.45);
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = 16;
        ctx.stroke();
        ctx.restore();
    } else {
        for (let r = 0; r < 6; r++) {
            const y = 42 + r * 44;
            const bw = 110 + ((Math.sin(t * 2.2 + r * 1.1) + 1) * 0.5) * 190;
            ctx.fillStyle = 'rgba(255,255,255,0.86)';
            roundRectCtx(ctx, 38, y, 102, 20, 8);
            ctx.fill();
            ctx.fillStyle = r % 2 === 0 ? '#7dd3fc' : '#fde047';
            roundRectCtx(ctx, 164, y, bw, 20, 8);
            ctx.fill();
        }
    }
    surf.tex.needsUpdate = true;
}

function drawSecurityTelemetry(surf, t, boost) {
    const { ctx, w, h } = surf;
    const bg = ctx.createLinearGradient(0, 0, w, h);
    bg.addColorStop(0, '#041422');
    bg.addColorStop(1, '#092438');
    ctx.fillStyle = bg;
    ctx.fillRect(0, 0, w, h);

    ctx.strokeStyle = 'rgba(52, 211, 153, 0.45)';
    ctx.lineWidth = 3;
    roundRectCtx(ctx, 6, 6, w - 12, h - 12, 12);
    ctx.stroke();

    ctx.fillStyle = '#34d399';
    ctx.font = 'bold 14px "Segoe UI", sans-serif';
    ctx.textAlign = 'left';
    ctx.fillText('RBAC · WAF GUARDRAIL', 16, 26);

    const cx = 62;
    const cy = 92;
    [22, 36].forEach((r) => {
        ctx.beginPath();
        ctx.arc(cx, cy, r, 0, Math.PI * 2);
        ctx.strokeStyle = 'rgba(52, 211, 153, 0.34)';
        ctx.lineWidth = 2;
        ctx.stroke();
    });
    const ang = t * (2.2 + boost * 1.8);
    ctx.beginPath();
    ctx.moveTo(cx, cy);
    ctx.lineTo(cx + Math.cos(ang) * 36, cy + Math.sin(ang) * 36);
    ctx.strokeStyle = '#6ee7b7';
    ctx.lineWidth = 2.5;
    ctx.stroke();

    const rows = ['RBAC SCOPE', 'CSRF TOKEN', 'PDO PARAM', 'API VAULT'];
    rows.forEach((lbl, idx) => {
        const y = 48 + idx * 24;
        ctx.fillStyle = '#bae6fd';
        ctx.font = 'bold 11px "Segoe UI", sans-serif';
        ctx.fillText(lbl, 114, y + 11);
        const pulse = 0.55 + 0.45 * Math.sin(t * 3.5 + idx * 1.2);
        ctx.fillStyle = `rgba(52, 211, 153, ${pulse.toFixed(2)})`;
        roundRectCtx(ctx, 198, y, 42, 14, 5);
        ctx.fill();
        ctx.fillStyle = '#041422';
        ctx.font = 'bold 9px "Segoe UI", sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('OK', 219, y + 10);
        ctx.textAlign = 'left';
    });
    surf.tex.needsUpdate = true;
}

function createFresnelHoloShader(THREE, hexColor = 0x38bdf8, opacity = 0.72) {
    return new THREE.ShaderMaterial({
        uniforms: {
            uTime: { value: 0 },
            uColor: { value: new THREE.Color(hexColor) },
            uOpacity: { value: opacity },
            uBoost: { value: 0 }
        },
        vertexShader: `
            varying vec3 vNormal;
            varying vec3 vViewDir;
            varying float vLocalY;
            void main() {
                vLocalY = position.y;
                vec4 mvPos = modelViewMatrix * vec4(position, 1.0);
                vNormal = normalize(normalMatrix * normal);
                vViewDir = normalize(-mvPos.xyz);
                gl_Position = projectionMatrix * mvPos;
            }
        `,
        fragmentShader: `
            uniform float uTime;
            uniform vec3 uColor;
            uniform float uOpacity;
            uniform float uBoost;
            varying vec3 vNormal;
            varying vec3 vViewDir;
            varying float vLocalY;
            void main() {
                float fresnel = pow(max(0.001, 1.0 - abs(dot(normalize(vNormal), normalize(vViewDir)))), 2.1);
                float scan = 0.68 + 0.32 * sin(vLocalY * 34.0 - uTime * 5.2);
                float pulse = 0.86 + 0.14 * sin(uTime * 2.8) + uBoost * 0.48;
                float alpha = clamp((fresnel * 1.15 + 0.14) * scan * uOpacity * pulse, 0.0, 0.95);
                gl_FragColor = vec4(uColor * (1.15 + fresnel * 0.85 + uBoost * 0.55), alpha);
            }
        `,
        transparent: true,
        depthWrite: false,
        side: THREE.DoubleSide,
        blending: THREE.AdditiveBlending
    });
}

function createEnergyFlowShader(THREE, hexColor = 0x38bdf8, speed = 0.85) {
    return new THREE.ShaderMaterial({
        uniforms: {
            uTime: { value: 0 },
            uColor: { value: new THREE.Color(hexColor) },
            uSpeed: { value: speed },
            uBoost: { value: 0 }
        },
        vertexShader: `
            varying vec2 vUv;
            void main() {
                vUv = uv;
                gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
            }
        `,
        fragmentShader: `
            uniform float uTime;
            uniform vec3 uColor;
            uniform float uSpeed;
            uniform float uBoost;
            varying vec2 vUv;
            void main() {
                float spd = uSpeed * (1.0 + uBoost * 1.4);
                float stream = fract(vUv.x * 4.0 - uTime * spd);
                float bolt = smoothstep(0.0, 0.16, stream) * smoothstep(0.62, 0.16, stream);
                float baseGlow = 0.22 + uBoost * 0.24;
                float alpha = clamp(baseGlow + bolt * 0.82, 0.0, 0.96);
                gl_FragColor = vec4(uColor * (0.95 + bolt * 1.55), alpha);
            }
        `,
        transparent: true,
        depthWrite: false,
        blending: THREE.AdditiveBlending
    });
}

function createQuantumPointsShader(THREE, hexColor = 0x7dd3fc) {
    return new THREE.ShaderMaterial({
        uniforms: {
            uTime: { value: 0 },
            uColor: { value: new THREE.Color(hexColor) }
        },
        vertexShader: `
            attribute float aScale;
            attribute float aPhase;
            uniform float uTime;
            varying float vAlpha;
            void main() {
                vec3 p = position;
                float yCycle = mod(p.y + uTime * 0.22 + aPhase * 4.8, 4.9);
                p.y = yCycle + 0.22;
                p.x += sin(uTime * 0.7 + aPhase * 6.28) * 0.16;
                p.z += cos(uTime * 0.6 + aPhase * 6.28) * 0.16;
                vec4 mvPos = modelViewMatrix * vec4(p, 1.0);
                gl_Position = projectionMatrix * mvPos;
                gl_PointSize = clamp(aScale * (170.0 / max(1.0, -mvPos.z)), 2.5, 14.0);
                float edgeFade = sin(clamp(yCycle / 4.9, 0.0, 1.0) * 3.14159);
                vAlpha = edgeFade * (0.42 + 0.34 * sin(uTime * 2.4 + aPhase * 12.56));
            }
        `,
        fragmentShader: `
            uniform vec3 uColor;
            varying float vAlpha;
            void main() {
                float d = length(gl_PointCoord - vec2(0.5));
                if (d > 0.5) discard;
                float g = pow(max(0.001, 1.0 - d * 2.0), 2.2);
                gl_FragColor = vec4(uColor * 1.35, g * vAlpha);
            }
        `,
        transparent: true,
        depthWrite: false,
        blending: THREE.AdditiveBlending
    });
}

export class OrchestratorCommandCenter3D {
    constructor(options = {}) {
        this.canvas = options.canvas;
        this.container = options.container || this.canvas?.parentElement;
        this.onAgentSelect = options.onAgentSelect || (() => {});
        this.onStateUpdate = options.onStateUpdate || (() => {});
        this.roleZone = options.roleZone || 'COMMAND_CENTER';
        this._initialAgents = Array.isArray(options.initialAgents) ? options.initialAgents : [];
        this._lastAgentsSig = '';
        this._screenRedrawTick = 0;

        this.scene = null;
        this.camera = null;
        this.renderer = null;
        this.envRT = null;
        this.animId = null;
        this.isDisposed = false;

        this.defaultTheta = Math.PI * 0.36;
        this.defaultPhi = 1.03;
        this.defaultRadius = 18.4;
        this.camTheta = this.defaultTheta;
        this.camPhi = this.defaultPhi;
        this.camRadius = this.defaultRadius;
        this.targetCamTheta = this.defaultTheta;
        this.targetCamPhi = this.defaultPhi;
        this.targetCamRadius = this.defaultRadius;
        this.camTarget = null;
        this.desiredCamTarget = null;

        this._isDragging = false;
        this._dragMoved = false;
        this._lastMouse = { x: 0, y: 0 };

        this.hologramPanel = null;
        this.hubGroup = null;
        this.zonesGroup = null;
        this.envGroup = null;
        this.hubRobot = null;
        this.shelfHoloOrb = null;
        this.wallTelemetrySurf = null;
        this.securityTelemetrySurf = null;
        this.ragHelix = null;
        this.securityNode = null;
        this.gpuLedMatrix = null;
        this.relayDrone = null;
        this.quantumField = null;
        this.shaderUniformMaterials = [];
        this.hubHoloScreens = [];
        this.liveMonitors = [];
        this.hubRings = [];
        this.zonePods = new Map();
        this.agentRegistryByCode = new Map();
        this.circuitPulses = [];
        this.clickableMeshes = [];

        this.hubAnimationState = 'idle';
        this.hubLookYaw = 0;
        this.activeTelemetryZone = '';
        this.activeTelemetryBoost = 0;
        this.hubVisualPanelActive = false;
        this._tmpPalmVec = null;
        this._tmpHeadVec = null;
        this._tmpActorVec = null;
        this._lastRenderAt = performance.now();
        this._lastScreenRedrawAt = 0;

        this._init();
    }

    _init() {
        if (!this.canvas || typeof window.THREE === 'undefined') return;
        preloadChibiAssets();
        const THREE = window.THREE;

        if (THREE.ColorManagement) {
            if ('enabled' in THREE.ColorManagement) THREE.ColorManagement.enabled = true;
            if ('legacyMode' in THREE.ColorManagement) THREE.ColorManagement.legacyMode = false;
        }

        this.scene = new THREE.Scene();
        this.scene.fog = new THREE.FogExp2(0x0d1b30, 0.0082);

        const w = Math.max(320, this.container?.clientWidth || 1200);
        const h = Math.max(300, this.container?.clientHeight || 680);

        this.camera = new THREE.PerspectiveCamera(34, w / h, 0.1, 150);
        this.camTarget = new THREE.Vector3(-0.2, 1.15, -0.35);
        this.desiredCamTarget = this.camTarget.clone();

        this.renderer = new THREE.WebGLRenderer({
            canvas: this.canvas,
            antialias: true,
            alpha: false,
            powerPreference: 'high-performance'
        });
        this.renderer.setClearColor(0x0b1628, 1);
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));
        this.renderer.setSize(w, h, false);
        this.renderer.shadowMap.enabled = true;
        this.renderer.shadowMap.type = THREE.PCFSoftShadowMap;
        if ('outputColorSpace' in this.renderer && THREE.SRGBColorSpace) {
            this.renderer.outputColorSpace = THREE.SRGBColorSpace;
        } else if ('outputEncoding' in this.renderer && THREE.sRGBEncoding !== undefined) {
            this.renderer.outputEncoding = THREE.sRGBEncoding;
        }
        if ('toneMapping' in this.renderer && THREE.ACESFilmicToneMapping) {
            this.renderer.toneMapping = THREE.ACESFilmicToneMapping;
            this.renderer.toneMappingExposure = 1.08;
        }

        this.envRT = buildStudioEnvironmentMap(THREE, this.renderer);
        if (this.envRT) {
            this.scene.environment = this.envRT.texture;
        }

        const hemi = new THREE.HemisphereLight(0xe0f2fe, 0x1e3a5f, 0.96);
        this.scene.add(hemi);

        const sun = new THREE.DirectionalLight(0xfffaf0, 1.32);
        sun.position.set(12, 20, 14);
        sun.castShadow = true;
        sun.shadow.mapSize.width = 1024;
        sun.shadow.mapSize.height = 1024;
        sun.shadow.camera.near = 2;
        sun.shadow.camera.far = 52;
        sun.shadow.camera.left = -16;
        sun.shadow.camera.right = 16;
        sun.shadow.camera.top = 16;
        sun.shadow.camera.bottom = -16;
        sun.shadow.bias = -0.0005;
        this.scene.add(sun);

        const frontWarm = new THREE.DirectionalLight(0xfff4e4, 0.72);
        frontWarm.position.set(8, 8, 16);
        this.scene.add(frontWarm);

        const cyanRim = new THREE.DirectionalLight(0x38bdf8, 0.62);
        cyanRim.position.set(-12, 9, -10);
        this.scene.add(cyanRim);

        this.envGroup = new THREE.Group();
        this.scene.add(this.envGroup);
        this.hubGroup = new THREE.Group();
        this.scene.add(this.hubGroup);
        this.zonesGroup = new THREE.Group();
        this.scene.add(this.zonesGroup);

        this._buildFuturisticOfficeShell(THREE);
        this._buildLivingOfficeObjects(THREE);
        this._buildCentralRobotPodium(THREE);
        this._buildOrchestratorInfrastructure(THREE);

        this.hologramPanel = new HologramPanel3D(THREE, this.scene);
        this._tmpPalmVec = new THREE.Vector3();
        this._tmpHeadVec = new THREE.Vector3();
        this._tmpActorVec = new THREE.Vector3();

        this._bindPointerControls();
        this._resizeObserver = new ResizeObserver(() => this.resize());
        if (this.container) this._resizeObserver.observe(this.container);
        window.addEventListener('resize', this._onWinResize = () => this.resize());

        this.setAuthorizedAgents(this._initialAgents, this.roleZone);
        this._updateCamera();
        this._startRenderLoop();
    }

    _createIndoorPlant(THREE, scale = 1.0, tall = true) {
        const g = new THREE.Group();
        const leaf1 = new THREE.MeshStandardMaterial({ color: 0x16a34a, roughness: 0.44 });
        const leaf2 = new THREE.MeshStandardMaterial({ color: 0x22c55e, roughness: 0.40 });
        const leaf3 = new THREE.MeshStandardMaterial({ color: 0x4ade80, roughness: 0.36 });

        const yOff = tall ? 0.44 : 0.16;
        if (tall) {
            const stem = new THREE.Mesh(
                new THREE.CylinderGeometry(0.035, 0.056, 0.48, 10),
                new THREE.MeshStandardMaterial({ color: 0x7c4d2b, roughness: 0.76 })
            );
            stem.position.y = 0.24;
            stem.castShadow = true;
            g.add(stem);
        }

        const blobs = [
            { x: 0, y: yOff + 0.16, z: 0, r: 0.26, m: leaf2 },
            { x: -0.18, y: yOff + 0.08, z: 0.08, r: 0.19, m: leaf1 },
            { x: 0.18, y: yOff + 0.10, z: 0.06, r: 0.20, m: leaf3 },
            { x: 0.02, y: yOff + 0.34, z: -0.04, r: 0.18, m: leaf3 },
            { x: -0.08, y: yOff + 0.14, z: -0.15, r: 0.17, m: leaf1 }
        ];
        blobs.forEach((p) => {
            const s = new THREE.Mesh(new THREE.SphereGeometry(p.r, 14, 12), p.m);
            s.position.set(p.x, p.y, p.z);
            s.castShadow = true;
            g.add(s);
        });
        g.scale.setScalar(scale);
        return g;
    }

    _buildFuturisticOfficeShell(THREE) {
        const floorTex = makeCanvasTex(THREE, 1024, 768, (ctx, w, h) => {
            const grad = ctx.createRadialGradient(w * 0.5, h * 0.5, 55, w * 0.5, h * 0.5, w * 0.62);
            grad.addColorStop(0.0, '#1a3356');
            grad.addColorStop(0.45, '#12243e');
            grad.addColorStop(1.0, '#0a1526');
            ctx.fillStyle = grad;
            ctx.fillRect(0, 0, w, h);

            ctx.strokeStyle = 'rgba(56, 189, 248, 0.16)';
            ctx.lineWidth = 1.5;
            for (let x = 0; x <= w; x += 32) {
                ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, h); ctx.stroke();
            }
            for (let y = 0; y <= h; y += 32) {
                ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(w, y); ctx.stroke();
            }

            ctx.strokeStyle = 'rgba(99, 102, 241, 0.18)';
            ctx.lineWidth = 1.5;
            const hexR = 26;
            const dx = hexR * 1.5;
            const dy = hexR * Math.sqrt(3);
            for (let col = 0; col < 27; col++) {
                for (let row = 0; row < 18; row++) {
                    const cx = col * dx;
                    const cy = row * dy + (col % 2 === 1 ? dy * 0.5 : 0);
                    ctx.beginPath();
                    for (let k = 0; k < 6; k++) {
                        const a = (Math.PI / 3) * k;
                        const hx = cx + Math.cos(a) * hexR;
                        const hy = cy + Math.sin(a) * hexR;
                        if (k === 0) ctx.moveTo(hx, hy); else ctx.lineTo(hx, hy);
                    }
                    ctx.closePath();
                    ctx.stroke();
                }
            }

            [130, 210, 295].forEach((r, idx) => {
                ctx.beginPath();
                ctx.arc(w * 0.5, h * 0.48, r, 0, Math.PI * 2);
                ctx.strokeStyle = idx === 1 ? 'rgba(56, 189, 248, 0.44)' : 'rgba(99, 102, 241, 0.30)';
                ctx.lineWidth = idx === 1 ? 3 : 2;
                ctx.stroke();
            });
        });

        const baseFloor = new THREE.Mesh(
            createRoundedSlabGeo(THREE, 22.8, 17.6, 0.26, 0.9, 0.04),
            new THREE.MeshStandardMaterial({ map: floorTex, roughness: 0.22, metalness: 0.36 })
        );
        baseFloor.position.set(0, -0.13, -0.1);
        baseFloor.receiveShadow = true;
        this.envGroup.add(baseFloor);

        const floorEdgeLed = new THREE.Mesh(
            createRoundedSlabGeo(THREE, 23.05, 17.85, 0.05, 0.96, 0.01),
            new THREE.MeshStandardMaterial({ color: 0x38bdf8, emissive: 0x0ea5e9, emissiveIntensity: 1.45 })
        );
        floorEdgeLed.position.set(0, -0.12, -0.1);
        this.envGroup.add(floorEdgeLed);

        const floorShadowRim = new THREE.Mesh(
            createRoundedSlabGeo(THREE, 23.4, 18.2, 0.20, 1.05, 0.03),
            new THREE.MeshStandardMaterial({ color: 0x09111f, roughness: 0.35, metalness: 0.50 })
        );
        floorShadowRim.position.set(0, -0.28, -0.1);
        this.envGroup.add(floorShadowRim);

        const wallPanelTex = makeCanvasTex(THREE, 512, 256, (ctx, w, h) => {
            const g = ctx.createLinearGradient(0, 0, 0, h);
            g.addColorStop(0.0, '#1e3557');
            g.addColorStop(0.5, '#142642');
            g.addColorStop(1.0, '#0e1c33');
            ctx.fillStyle = g;
            ctx.fillRect(0, 0, w, h);

            ctx.strokeStyle = 'rgba(56, 189, 248, 0.22)';
            ctx.lineWidth = 2;
            for (let x = 0; x <= w; x += 64) {
                ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, h); ctx.stroke();
            }
            [60, 130, 205].forEach((y) => {
                ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(w, y); ctx.stroke();
            });

            ctx.strokeStyle = 'rgba(99, 102, 241, 0.26)';
            ctx.lineWidth = 1.5;
            for (let i = 0; i < 8; i++) {
                const sx = 24 + i * 64;
                ctx.beginPath();
                ctx.moveTo(sx, 130);
                ctx.lineTo(sx + 16, 148);
                ctx.lineTo(sx + 16, 190);
                ctx.stroke();
                ctx.fillStyle = '#38bdf8';
                ctx.beginPath(); ctx.arc(sx + 16, 190, 3, 0, Math.PI * 2); ctx.fill();
            }
        });

        const techWallMat = new THREE.MeshStandardMaterial({ map: wallPanelTex, roughness: 0.30, metalness: 0.42 });
        const titaniumMat = new THREE.MeshStandardMaterial({ color: 0x263c5e, roughness: 0.26, metalness: 0.62 });
        const royalAccentMat = new THREE.MeshStandardMaterial({ color: 0x2563eb, emissive: 0x1d4ed8, emissiveIntensity: 0.55, roughness: 0.22, metalness: 0.35 });
        const ledCyanMat = new THREE.MeshStandardMaterial({ color: 0x7dd3fc, emissive: 0x00d4ff, emissiveIntensity: 1.9 });
        const ledVioletMat = new THREE.MeshStandardMaterial({ color: 0xc4b5fd, emissive: 0x8b5cf6, emissiveIntensity: 1.5 });
        const skyGlassMat = new THREE.MeshPhysicalMaterial({
            color: 0x38bdf8,
            emissive: 0x0284c7,
            emissiveIntensity: 0.42,
            roughness: 0.08,
            metalness: 0.14,
            transparent: true,
            opacity: 0.58
        });

        const wallH = 5.45;

        const leftWall = new THREE.Mesh(new THREE.BoxGeometry(0.38, wallH, 16.4), techWallMat);
        leftWall.position.set(-10.4, wallH * 0.5, -0.3);
        leftWall.receiveShadow = true;
        this.envGroup.add(leftWall);

        const leftSoffit = new THREE.Mesh(new THREE.BoxGeometry(1.42, 0.54, 16.4), titaniumMat);
        leftSoffit.position.set(-9.8, wallH - 0.22, -0.3);
        this.envGroup.add(leftSoffit);

        const leftLedCove = new THREE.Mesh(new THREE.BoxGeometry(0.09, 0.09, 15.8), ledCyanMat);
        leftLedCove.position.set(-9.10, wallH - 0.44, -0.3);
        this.envGroup.add(leftLedCove);

        const leftVioletStripe = new THREE.Mesh(new THREE.BoxGeometry(0.08, 0.06, 15.6), ledVioletMat);
        leftVioletStripe.position.set(-9.10, wallH - 0.08, -0.3);
        this.envGroup.add(leftVioletStripe);

        for (let rz = -7.2; rz <= 6.8; rz += 2.8) {
            const rib = new THREE.Mesh(new THREE.BoxGeometry(0.18, wallH - 0.5, 0.16), titaniumMat);
            rib.position.set(-10.16, (wallH - 0.5) * 0.5, rz);
            this.envGroup.add(rib);
        }

        [-4.5, 0.5, 5.2].forEach((wz) => {
            const winFrame = new THREE.Mesh(new THREE.BoxGeometry(0.12, 1.48, 3.96), titaniumMat);
            winFrame.position.set(-10.18, 3.65, wz);
            this.envGroup.add(winFrame);
            const win = new THREE.Mesh(new THREE.BoxGeometry(0.14, 1.32, 3.80), skyGlassMat);
            win.position.set(-10.15, 3.65, wz);
            this.envGroup.add(win);
        });

        const leftBrandTex = makeCanvasTex(THREE, 512, 96, (ctx, w, h) => {
            ctx.clearRect(0, 0, w, h);
            ctx.fillStyle = '#38bdf8';
            ctx.beginPath();
            ctx.arc(40, h / 2, 12, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = '#e0f2fe';
            ctx.font = 'bold 40px "Segoe UI", sans-serif';
            ctx.textAlign = 'left';
            ctx.textBaseline = 'middle';
            ctx.fillText('FILKOM AI OFFICE', 68, h / 2 + 2);
        });
        const leftBrand = new THREE.Mesh(
            new THREE.PlaneGeometry(3.4, 0.64),
            new THREE.MeshBasicMaterial({ map: leftBrandTex, transparent: true })
        );
        leftBrand.position.set(-9.06, 4.74, 2.6);
        leftBrand.rotation.y = Math.PI * 0.5;
        this.envGroup.add(leftBrand);

        const backWall = new THREE.Mesh(new THREE.BoxGeometry(21.2, wallH, 0.38), techWallMat);
        backWall.position.set(0, wallH * 0.5, -8.3);
        backWall.receiveShadow = true;
        this.envGroup.add(backWall);

        const backBlueAccent = new THREE.Mesh(new THREE.BoxGeometry(20.8, 0.12, 0.10), royalAccentMat);
        backBlueAccent.position.set(0, 4.18, -8.08);
        this.envGroup.add(backBlueAccent);

        const backSoffit = new THREE.Mesh(new THREE.BoxGeometry(21.2, 0.48, 1.25), titaniumMat);
        backSoffit.position.set(0, wallH - 0.22, -7.85);
        this.envGroup.add(backSoffit);

        const backLed = new THREE.Mesh(new THREE.BoxGeometry(19.8, 0.08, 0.08), ledCyanMat);
        backLed.position.set(0, wallH - 0.44, -7.22);
        this.envGroup.add(backLed);

        for (let rx = -9.2; rx <= 9.2; rx += 3.68) {
            const bRib = new THREE.Mesh(new THREE.BoxGeometry(0.18, wallH - 0.5, 0.18), titaniumMat);
            bRib.position.set(rx, (wallH - 0.5) * 0.5, -8.08);
            this.envGroup.add(bRib);
        }

        [-6.6, 8.6].forEach((wx) => {
            const bWin = new THREE.Mesh(new THREE.BoxGeometry(2.6, 2.1, 0.10), skyGlassMat);
            bWin.position.set(wx, 2.65, -8.06);
            this.envGroup.add(bWin);
        });

        const rightPartition = new THREE.Mesh(
            new THREE.BoxGeometry(0.10, 1.52, 11.4),
            new THREE.MeshPhysicalMaterial({ color: 0x38bdf8, roughness: 0.08, transparent: true, opacity: 0.22 })
        );
        rightPartition.position.set(10.35, 0.78, -2.5);
        this.envGroup.add(rightPartition);

        const rightRail = new THREE.Mesh(new THREE.BoxGeometry(0.16, 0.09, 11.5), titaniumMat);
        rightRail.position.set(10.35, 1.56, -2.5);
        this.envGroup.add(rightRail);

        const rightRailLed = new THREE.Mesh(new THREE.BoxGeometry(0.05, 0.04, 11.3), ledCyanMat);
        rightRailLed.position.set(10.35, 1.62, -2.5);
        this.envGroup.add(rightRailLed);
    }

    _buildFuturisticBookshelf(THREE) {
        const shelf = new THREE.Group();
        shelf.position.set(-1.35, 0, -7.62);

        const titaniumDark = new THREE.MeshStandardMaterial({ color: 0x162742, roughness: 0.24, metalness: 0.65 });
        const sapphireTrim = new THREE.MeshStandardMaterial({ color: 0x2563eb, roughness: 0.24, metalness: 0.45 });
        const glassShelfMat = new THREE.MeshPhysicalMaterial({ color: 0xbae6fd, roughness: 0.08, metalness: 0.15, transparent: true, opacity: 0.55 });
        const cyanStripMat = new THREE.MeshStandardMaterial({ color: 0x7dd3fc, emissive: 0x00d4ff, emissiveIntensity: 1.9 });
        const amberStripMat = new THREE.MeshStandardMaterial({ color: 0xfde68a, emissive: 0xf59e0b, emissiveIntensity: 1.4 });

        const hexBackTex = makeCanvasTex(THREE, 256, 256, (ctx, w, h) => {
            const g = ctx.createRadialGradient(w / 2, h / 2, 10, w / 2, h / 2, w * 0.6);
            g.addColorStop(0, '#0c3663');
            g.addColorStop(1, '#091930');
            ctx.fillStyle = g;
            ctx.fillRect(0, 0, w, h);
            ctx.strokeStyle = 'rgba(56, 189, 248, 0.42)';
            ctx.lineWidth = 2;
            const hr = 16;
            for (let col = 0; col < 12; col++) {
                for (let row = 0; row < 10; row++) {
                    const cx = col * hr * 1.5;
                    const cy = row * hr * 1.73 + (col % 2 ? hr * 0.86 : 0);
                    ctx.beginPath();
                    for (let k = 0; k < 6; k++) {
                        const a = (Math.PI / 3) * k;
                        const hx = cx + Math.cos(a) * hr;
                        const hy = cy + Math.sin(a) * hr;
                        if (k === 0) ctx.moveTo(hx, hy); else ctx.lineTo(hx, hy);
                    }
                    ctx.closePath();
                    ctx.stroke();
                }
            }
        });

        const backPlate = new THREE.Mesh(
            createRoundedExtrudeGeo(THREE, 2.92, 2.68, 0.08, 0.26, 0.015),
            new THREE.MeshBasicMaterial({ map: hexBackTex })
        );
        backPlate.position.set(0, 1.44, -0.22);
        shelf.add(backPlate);

        const outerArch = new THREE.Mesh(
            createRoundedExtrudeGeo(THREE, 3.12, 2.86, 0.34, 0.32, 0.02),
            titaniumDark
        );
        outerArch.position.set(0, 1.45, -0.28);
        shelf.add(outerArch);

        const basePedestal = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 3.06, 0.26, 0.54, 0.08, 0.015), sapphireTrim);
        basePedestal.position.set(0, 0.14, 0.02);
        basePedestal.castShadow = true;
        shelf.add(basePedestal);

        [-1.46, 1.46].forEach((sx) => {
            const pillar = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.14, 2.74, 0.48, 0.05, 0.01), titaniumDark);
            pillar.position.set(sx, 1.44, 0.02);
            pillar.castShadow = true;
            shelf.add(pillar);
            const sideLed = new THREE.Mesh(new THREE.BoxGeometry(0.035, 2.52, 0.04), cyanStripMat);
            sideLed.position.set(sx + (sx < 0 ? 0.075 : -0.075), 1.44, 0.24);
            shelf.add(sideLed);
        });

        const topCrown = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 3.10, 0.14, 0.50, 0.06, 0.01), sapphireTrim);
        topCrown.position.set(0, 2.82, 0.02);
        shelf.add(topCrown);

        [-0.56, 0.56].forEach((dx, di) => {
            const diag = new THREE.Mesh(new THREE.BoxGeometry(0.06, 0.62, 0.38), titaniumDark);
            diag.position.set(dx, di === 0 ? 0.88 : 1.52, 0.02);
            diag.rotation.z = di === 0 ? 0.24 : -0.24;
            shelf.add(diag);
        });

        const bookPalette = [0xf43f5e, 0x38bdf8, 0xf59e0b, 0x10b981, 0x8b5cf6, 0xec4899, 0x22d3ee, 0xf97316];
        const shelfLevels = [0.56, 1.18, 1.82, 2.40];
        shelfLevels.forEach((sy, rowIdx) => {
            const glassPlank = new THREE.Mesh(new THREE.BoxGeometry(2.82, 0.045, 0.42), glassShelfMat);
            glassPlank.position.set(0, sy, 0.02);
            shelf.add(glassPlank);

            const frontLed = new THREE.Mesh(new THREE.BoxGeometry(2.78, 0.025, 0.03), rowIdx % 2 === 0 ? cyanStripMat : amberStripMat);
            frontLed.position.set(0, sy, 0.235);
            shelf.add(frontLed);

            if (rowIdx === 0 || rowIdx === 2) {
                for (let b = 0; b < 10; b++) {
                    if (rowIdx === 2 && b >= 4 && b <= 5) continue;
                    const col = bookPalette[(rowIdx * 3 + b) % bookPalette.length];
                    const bh = 0.28 + ((b * 13 + rowIdx * 7) % 10) * 0.015;
                    const bk = new THREE.Mesh(
                        new THREE.BoxGeometry(0.10, bh, 0.24),
                        new THREE.MeshStandardMaterial({ color: col, roughness: 0.32, metalness: 0.16 })
                    );
                    const bx = -1.20 + b * 0.265;
                    bk.position.set(bx, sy + 0.025 + bh * 0.5, 0.02);
                    if (b === 3 || b === 8) bk.rotation.z = b === 3 ? -0.22 : 0.20;
                    bk.castShadow = true;
                    shelf.add(bk);
                }
            } else if (rowIdx === 1) {
                for (let b = 0; b < 3; b++) {
                    const col = bookPalette[b + 2];
                    const bkL = new THREE.Mesh(new THREE.BoxGeometry(0.11, 0.34, 0.24), new THREE.MeshStandardMaterial({ color: col, roughness: 0.34 }));
                    bkL.position.set(-1.18 + b * 0.14, sy + 0.19, 0.02);
                    shelf.add(bkL);

                    const bkR = new THREE.Mesh(new THREE.BoxGeometry(0.11, 0.32, 0.24), new THREE.MeshStandardMaterial({ color: bookPalette[b + 5], roughness: 0.34 }));
                    bkR.position.set(0.88 + b * 0.14, sy + 0.18, 0.02);
                    shelf.add(bkR);
                }
                const holoOrbGroup = new THREE.Group();
                holoOrbGroup.position.set(0, sy + 0.30, 0.04);
                const orbCore = new THREE.Mesh(
                    new THREE.IcosahedronGeometry(0.14, 1),
                    new THREE.MeshStandardMaterial({ color: 0x38bdf8, emissive: 0x00d4ff, emissiveIntensity: 1.7, wireframe: true })
                );
                const orbRing = new THREE.Mesh(new THREE.TorusGeometry(0.21, 0.012, 8, 32), cyanStripMat);
                orbRing.rotation.x = Math.PI * 0.38;
                holoOrbGroup.add(orbCore, orbRing);
                shelf.add(holoOrbGroup);
                this.shelfHoloOrb = holoOrbGroup;
            }
        });

        const trophy = new THREE.Mesh(
            new THREE.CylinderGeometry(0.12, 0.06, 0.28, 16),
            new THREE.MeshStandardMaterial({ color: 0xfbbf24, emissive: 0xb45309, emissiveIntensity: 0.35, roughness: 0.16, metalness: 0.88 })
        );
        trophy.position.set(0.0, 2.56, 0.02);
        shelf.add(trophy);

        [-0.95, 0.95].forEach((px) => {
            const miniPot = new THREE.Mesh(new THREE.CylinderGeometry(0.08, 0.06, 0.12, 12), titaniumDark);
            miniPot.position.set(px, 2.48, 0.04);
            shelf.add(miniPot);
            const bush = new THREE.Mesh(new THREE.SphereGeometry(0.11, 10, 8), new THREE.MeshStandardMaterial({ color: 0x22c55e, roughness: 0.42 }));
            bush.position.set(px, 2.60, 0.04);
            shelf.add(bush);
        });

        this.envGroup.add(shelf);
    }

    _buildBackWallCommandMonitor(THREE) {
        const wallMon = new THREE.Group();
        wallMon.position.set(4.35, 0, -7.75);

        const darkTitanium = new THREE.MeshPhysicalMaterial({ color: 0x162640, roughness: 0.24, metalness: 0.62, clearcoat: 0.5 });
        const royalBlue = new THREE.MeshStandardMaterial({ color: 0x1d4ed8, roughness: 0.24, metalness: 0.32 });
        const cyanLed = new THREE.MeshStandardMaterial({ color: 0x7dd3fc, emissive: 0x00d4ff, emissiveIntensity: 1.9 });

        const bezel = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 4.75, 2.95, 0.28, 0.20, 0.03), darkTitanium);
        bezel.position.y = 2.62;
        bezel.castShadow = true;
        wallMon.add(bezel);

        const topAccent = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 4.56, 0.14, 0.32, 0.06, 0.015), royalBlue);
        topAccent.position.y = 4.06;
        wallMon.add(topAccent);

        const rimGlow = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 4.82, 3.02, 0.05, 0.22, 0.01), cyanLed);
        rimGlow.position.set(0, 2.62, 0.04);
        wallMon.add(rimGlow);

        const consoleDesk = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 4.20, 0.88, 0.66, 0.14, 0.02), darkTitanium);
        consoleDesk.position.set(0, 0.46, 0.22);
        consoleDesk.castShadow = true;
        wallMon.add(consoleDesk);

        const consoleBlueFront = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 3.92, 0.36, 0.06, 0.08, 0.01), royalBlue);
        consoleBlueFront.position.set(0, 0.52, 0.56);
        wallMon.add(consoleBlueFront);

        const consoleLedBar = new THREE.Mesh(new THREE.BoxGeometry(3.6, 0.045, 0.05), cyanLed);
        consoleLedBar.position.set(0, 0.90, 0.54);
        wallMon.add(consoleLedBar);

        this.wallTelemetrySurf = createDynamicCanvasTex(THREE, 768, 448);
        drawBigWallTelemetry(this.wallTelemetrySurf, 0, '', 0);

        const bigScreen = new THREE.Mesh(
            new THREE.PlaneGeometry(4.42, 2.58),
            new THREE.MeshBasicMaterial({ map: this.wallTelemetrySurf.tex })
        );
        bigScreen.position.set(0, 2.62, 0.175);
        wallMon.add(bigScreen);

        this.envGroup.add(wallMon);
    }

    _buildLivingOfficeObjects(THREE) {
        const whiteGloss = new THREE.MeshPhysicalMaterial({ color: 0xe8f1fc, roughness: 0.22, metalness: 0.14, clearcoat: 0.65 });
        const titaniumMat = new THREE.MeshStandardMaterial({ color: 0x243b5e, roughness: 0.26, metalness: 0.58 });
        const oakMat = new THREE.MeshStandardMaterial({ color: 0xd4a373, roughness: 0.44 });
        const chromeMat = new THREE.MeshStandardMaterial({ color: 0x94a3b8, roughness: 0.20, metalness: 0.78 });

        this._buildBackWallCommandMonitor(THREE);
        this._buildFuturisticBookshelf(THREE);

        const coffeeBar = new THREE.Group();
        coffeeBar.position.set(-6.3, 0, -7.45);
        const cCounter = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 2.4, 0.88, 0.74, 0.08, 0.015), titaniumMat);
        cCounter.position.y = 0.44;
        cCounter.castShadow = true;
        coffeeBar.add(cCounter);
        const cTop = new THREE.Mesh(createRoundedSlabGeo(THREE, 2.48, 0.80, 0.06, 0.06, 0.01), oakMat);
        cTop.position.y = 0.90;
        coffeeBar.add(cTop);
        const espresso = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.44, 0.42, 0.36, 0.05, 0.01), new THREE.MeshStandardMaterial({ color: 0xf43f5e, roughness: 0.22, metalness: 0.35 }));
        espresso.position.set(-0.55, 1.14, -0.05);
        coffeeBar.add(espresso);
        const waterBase = new THREE.Mesh(new THREE.BoxGeometry(0.36, 0.48, 0.36), whiteGloss);
        waterBase.position.set(0.68, 1.16, -0.05);
        coffeeBar.add(waterBase);
        const waterJug = new THREE.Mesh(
            new THREE.CylinderGeometry(0.15, 0.15, 0.40, 16),
            new THREE.MeshPhysicalMaterial({ color: 0x38bdf8, emissive: 0x0284c7, emissiveIntensity: 0.35, transparent: true, opacity: 0.76, roughness: 0.08 })
        );
        waterJug.position.set(0.68, 1.60, -0.05);
        coffeeBar.add(waterJug);
        [0xf59e0b, 0x10b981, 0x38bdf8].forEach((mc, mi) => {
            const cup = new THREE.Mesh(new THREE.CylinderGeometry(0.042, 0.036, 0.088, 12), new THREE.MeshStandardMaterial({ color: mc, roughness: 0.26 }));
            cup.position.set(-0.08 + mi * 0.14, 0.97, 0.16);
            coffeeBar.add(cup);
        });
        this.envGroup.add(coffeeBar);

        const printer = new THREE.Group();
        printer.position.set(8.7, 0, -5.6);
        const pCabinet = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 1.05, 0.72, 0.76, 0.06, 0.012), titaniumMat);
        pCabinet.position.y = 0.36;
        pCabinet.castShadow = true;
        printer.add(pCabinet);
        const pBody = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.88, 0.42, 0.62, 0.05, 0.01), whiteGloss);
        pBody.position.y = 0.92;
        printer.add(pBody);
        const pStatus = new THREE.Mesh(new THREE.BoxGeometry(0.28, 0.04, 0.04), new THREE.MeshStandardMaterial({ color: 0x38bdf8, emissive: 0x00d4ff, emissiveIntensity: 1.8 }));
        pStatus.position.set(0, 1.02, 0.32);
        printer.add(pStatus);
        this.envGroup.add(printer);

        const buildColorfulLounge = (lx, lz, rotY, sofaHex, pillowHex) => {
            const lg = new THREE.Group();
            lg.position.set(lx, 0, lz);
            lg.rotation.y = rotY;
            const rug = new THREE.Mesh(createRoundedSlabGeo(THREE, 2.5, 2.1, 0.025, 0.32, 0.004), new THREE.MeshStandardMaterial({ color: 0x1b3358, roughness: 0.78 }));
            rug.position.y = 0.014;
            lg.add(rug);
            const sMat = new THREE.MeshStandardMaterial({ color: sofaHex, roughness: 0.48 });
            const pMat = new THREE.MeshStandardMaterial({ color: pillowHex, roughness: 0.48 });
            const seat = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 1.72, 0.30, 0.68, 0.08, 0.02), sMat);
            seat.position.set(0, 0.24, -0.45);
            seat.castShadow = true;
            lg.add(seat);
            const back = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 1.72, 0.52, 0.18, 0.08, 0.02), sMat);
            back.position.set(0, 0.52, -0.72);
            back.castShadow = true;
            lg.add(back);
            [-0.50, 0.50].forEach((px) => {
                const pl = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.32, 0.26, 0.12, 0.06, 0.014), pMat);
                pl.position.set(px, 0.48, -0.56);
                pl.rotation.z = px < 0 ? 0.16 : -0.16;
                lg.add(pl);
            });
            const cTable = new THREE.Mesh(new THREE.CylinderGeometry(0.46, 0.46, 0.045, 24), new THREE.MeshPhysicalMaterial({ color: 0x38bdf8, transparent: true, opacity: 0.55, roughness: 0.08 }));
            cTable.position.set(0, 0.36, 0.40);
            lg.add(cTable);
            const cPed = new THREE.Mesh(new THREE.CylinderGeometry(0.10, 0.18, 0.34, 16), chromeMat);
            cPed.position.set(0, 0.17, 0.40);
            lg.add(cPed);
            this.envGroup.add(lg);
        };

        buildColorfulLounge(-8.4, 5.4, 0.65, 0x2563eb, 0xfbbf24);
        buildColorfulLounge(8.3, 5.4, -0.65, 0x0d9488, 0xf43f5e);

        [
            { x: 7.4, z: -6.5, topColor: 0x38bdf8 },
            { x: -2.2, z: 3.85, topColor: 0x8b5cf6 }
        ].forEach((st) => {
            const stoolBody = new THREE.Mesh(new THREE.CylinderGeometry(0.38, 0.38, 0.52, 24), titaniumMat);
            stoolBody.position.set(st.x, 0.26, st.z);
            stoolBody.castShadow = true;
            this.envGroup.add(stoolBody);
            const stoolTop = new THREE.Mesh(new THREE.CylinderGeometry(0.36, 0.36, 0.05, 24), new THREE.MeshStandardMaterial({ color: st.topColor, emissive: st.topColor, emissiveIntensity: 0.35, roughness: 0.3 }));
            stoolTop.position.set(st.x, 0.535, st.z);
            this.envGroup.add(stoolTop);
        });

        const plants = [
            [-9.5, -7.2, 1.32, true, 0x38bdf8],
            [9.4, -7.2, 1.32, true, 0x38bdf8],
            [-9.5, -0.3, 1.10, true, 0x8b5cf6],
            [9.5, 2.8, 1.15, true, 0x10b981],
            [-5.6, 6.8, 1.05, false, 0xf59e0b],
            [5.6, 6.8, 1.05, false, 0xf43f5e]
        ];
        plants.forEach(([tx, tz, sc, tall, ringHex]) => {
            const pot = new THREE.Mesh(new THREE.CylinderGeometry(0.34, 0.26, 0.46, 18), titaniumMat);
            pot.position.set(tx, 0.23, tz);
            pot.castShadow = true;
            this.envGroup.add(pot);
            const potBand = new THREE.Mesh(new THREE.TorusGeometry(0.335, 0.025, 10, 24), new THREE.MeshStandardMaterial({ color: ringHex, emissive: ringHex, emissiveIntensity: 1.2, roughness: 0.2 }));
            potBand.rotation.x = Math.PI / 2;
            potBand.position.set(tx, 0.42, tz);
            this.envGroup.add(potBand);
            const pl = this._createIndoorPlant(THREE, sc, tall);
            pl.position.set(tx, 0.42, tz);
            this.envGroup.add(pl);
        });
    }

    _buildCentralRobotPodium(THREE) {
        this.hubGroup.position.set(0, 0, -0.4);

        const silverTitanium = new THREE.MeshPhysicalMaterial({ color: 0xdbeafe, roughness: 0.20, metalness: 0.34, clearcoat: 0.80 });
        const darkNavyDeck = new THREE.MeshPhysicalMaterial({ color: 0x162947, roughness: 0.24, metalness: 0.50, clearcoat: 0.6 });
        const royalBlue = new THREE.MeshPhysicalMaterial({ color: 0x1d4ed8, emissive: 0x1e40af, emissiveIntensity: 0.35, roughness: 0.20, metalness: 0.25, clearcoat: 0.85 });
        const cyanGlow = new THREE.MeshStandardMaterial({ color: 0x7dd3fc, emissive: 0x00d4ff, emissiveIntensity: 2.0, roughness: 0.14 });

        const outerSquare = new THREE.Mesh(createRoundedSlabGeo(THREE, 5.2, 5.2, 0.22, 0.48, 0.04), darkNavyDeck);
        outerSquare.position.y = 0.11;
        outerSquare.receiveShadow = true;
        outerSquare.castShadow = true;
        this.hubGroup.add(outerSquare);

        const outerGlow = new THREE.Mesh(createRoundedSlabGeo(THREE, 5.34, 5.34, 0.045, 0.50, 0.01), cyanGlow);
        outerGlow.position.y = 0.05;
        this.hubGroup.add(outerGlow);

        const innerBaseSilver = new THREE.Mesh(createRoundedSlabGeo(THREE, 2.86, 2.86, 0.24, 0.36, 0.03), silverTitanium);
        innerBaseSilver.position.y = 0.30;
        innerBaseSilver.receiveShadow = true;
        this.hubGroup.add(innerBaseSilver);

        const innerBluePed = new THREE.Mesh(createRoundedSlabGeo(THREE, 2.52, 2.52, 0.14, 0.30, 0.025), royalBlue);
        innerBluePed.position.y = 0.44;
        innerBluePed.receiveShadow = true;
        this.hubGroup.add(innerBluePed);

        [0.48, 0.76, 1.04].forEach((r, ri) => {
            const ring = new THREE.Mesh(new THREE.TorusGeometry(r, 0.018, 10, 48), cyanGlow);
            ring.rotation.x = Math.PI / 2;
            ring.position.y = 0.535;
            this.hubGroup.add(ring);
            this.hubRings.push({ mesh: ring, phase: ri * 0.9 });
        });

        this.hubRobot = createOrchestratorRobot(THREE);
        this.hubRobot.root.position.set(0, 0.52, 0);
        this.hubRobot.root.scale.setScalar(1.48);
        this.hubGroup.add(this.hubRobot.root);

        const holoSpecs = [
            { x: -2.25, y: 2.22, z: -1.75, rotY: 0.36, kind: 'analytics' },
            { x: 2.25, y: 2.22, z: -1.75, rotY: -0.36, kind: 'telemetry' }
        ];
        this.hubHoloScreens = [];
        holoSpecs.forEach((hs, idx) => {
            const surf = createDynamicCanvasTex(THREE, 512, 340);
            drawHubHoloScreen(surf, hs.kind, 0);
            const scr = new THREE.Mesh(
                new THREE.PlaneGeometry(2.65, 1.76),
                new THREE.MeshBasicMaterial({ map: surf.tex, transparent: true, opacity: 0.92, side: THREE.DoubleSide })
            );
            scr.position.set(hs.x, hs.y, hs.z);
            scr.rotation.y = hs.rotY;
            this.hubGroup.add(scr);
            this.hubHoloScreens.push({ mesh: scr, surf, kind: hs.kind, baseY: hs.y, phase: idx * 1.4 });
        });

        const hubHitBox = new THREE.Mesh(
            new THREE.CylinderGeometry(1.6, 1.6, 2.6, 16),
            new THREE.MeshBasicMaterial({ visible: false })
        );
        hubHitBox.position.set(0, 1.5, 0);
        hubHitBox.userData = { zoneKey: 'ORCHESTRATOR_FOCUS' };
        this.hubGroup.add(hubHitBox);
        this._hubHitBox = hubHitBox;
    }

    _createInfraBadgeSprite(THREE, label, accentCss) {
        const tex = makeCanvasTex(THREE, 384, 72, (ctx, w, h) => {
            ctx.clearRect(0, 0, w, h);
            ctx.fillStyle = 'rgba(6, 18, 40, 0.88)';
            roundRectCtx(ctx, 6, 8, w - 12, h - 16, 16);
            ctx.fill();
            ctx.strokeStyle = accentCss;
            ctx.lineWidth = 3;
            ctx.stroke();
            ctx.fillStyle = accentCss;
            ctx.beginPath();
            ctx.arc(30, h / 2, 7, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = '#f8fafc';
            ctx.font = 'bold 23px "Segoe UI", sans-serif';
            ctx.textAlign = 'left';
            ctx.textBaseline = 'middle';
            ctx.fillText(label, 48, h / 2 + 1);
        });
        const sp = new THREE.Sprite(new THREE.SpriteMaterial({ map: tex, transparent: true, depthWrite: false }));
        sp.scale.set(1.42, 0.27, 1);
        return sp;
    }

    _buildOrchestratorInfrastructure(THREE) {
        this._buildGpuInferenceCluster(THREE);
        this._buildRagVectorCore(THREE);
        this._buildSecurityGuardrailNode(THREE);
        this._buildDataRelayDrone(THREE);
        this._buildQuantumTelemetryField(THREE);
    }

    _buildGpuInferenceCluster(THREE) {
        const cluster = new THREE.Group();
        cluster.position.set(-8.75, 0, -5.45);
        cluster.rotation.y = 0.42;
        this.envGroup.add(cluster);

        const rackMetal = new THREE.MeshPhysicalMaterial({ color: 0x101c30, roughness: 0.26, metalness: 0.72, clearcoat: 0.45 });
        const trimAlloy = new THREE.MeshStandardMaterial({ color: 0x334155, roughness: 0.24, metalness: 0.80 });
        const glassDoorMat = new THREE.MeshPhysicalMaterial({
            color: 0xbae6fd,
            roughness: 0.08,
            metalness: 0.14,
            clearcoat: 1.0,
            iridescence: 0.50,
            transparent: true,
            opacity: 0.28,
            depthWrite: false
        });
        const coolantMat = createEnergyFlowShader(THREE, 0x00d4ff, 1.15);
        this.shaderUniformMaterials.push(coolantMat);

        const plinth = new THREE.Mesh(createRoundedSlabGeo(THREE, 2.18, 1.10, 0.14, 0.12, 0.02), trimAlloy);
        plinth.position.y = 0.07;
        plinth.receiveShadow = true;
        cluster.add(plinth);

        const ledCount = 96;
        const ledGeo = new THREE.BoxGeometry(0.055, 0.022, 0.024);
        const ledMat = new THREE.MeshBasicMaterial({ color: 0xffffff });
        const ledInst = new THREE.InstancedMesh(ledGeo, ledMat, ledCount);
        const ledMeta = [];
        const dummy = new THREE.Object3D();
        const colPalette = [0x38bdf8, 0x34d399, 0x818cf8, 0xfbbf24];

        let li = 0;
        [-0.50, 0.50].forEach((rx, rackIdx) => {
            const body = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.88, 2.24, 0.78, 0.05, 0.012), rackMetal);
            body.position.set(rx, 1.26, 0);
            body.castShadow = true;
            cluster.add(body);

            const frontDoor = new THREE.Mesh(new THREE.BoxGeometry(0.76, 2.02, 0.024), glassDoorMat);
            frontDoor.position.set(rx, 1.26, 0.405);
            cluster.add(frontDoor);

            for (let b = 0; b < 8; b++) {
                const by = 0.44 + b * 0.23;
                const blade = new THREE.Mesh(new THREE.BoxGeometry(0.70, 0.14, 0.06), trimAlloy);
                blade.position.set(rx, by, 0.365);
                cluster.add(blade);

                for (let k = 0; k < 6; k++) {
                    if (li >= ledCount) break;
                    const lx = rx - 0.25 + k * 0.10;
                    dummy.position.set(lx, by, 0.398);
                    dummy.scale.set(1, 1, 1);
                    dummy.updateMatrix();
                    ledInst.setMatrixAt(li, dummy.matrix);
                    const baseHex = colPalette[(b + k + rackIdx) % colPalette.length];
                    ledInst.setColorAt(li, new THREE.Color(baseHex));
                    ledMeta.push({ baseColor: new THREE.Color(baseHex), phase: li * 0.37, row: b });
                    li++;
                }
            }

            [-0.41, 0.41].forEach((cx) => {
                const tubeCurve = new THREE.CatmullRomCurve3([new THREE.Vector3(rx + cx, 0.22, 0.41), new THREE.Vector3(rx + cx, 2.30, 0.41)]);
                const tube = new THREE.Mesh(new THREE.TubeGeometry(tubeCurve, 8, 0.018, 8, false), coolantMat);
                cluster.add(tube);
            });
        });

        ledInst.instanceMatrix.needsUpdate = true;
        if (ledInst.instanceColor) ledInst.instanceColor.needsUpdate = true;
        cluster.add(ledInst);

        const badge = this._createInfraBadgeSprite(THREE, 'LLM GPU CLUSTER', '#38bdf8');
        badge.position.set(0, 2.66, 0.12);
        cluster.add(badge);

        this.gpuLedMatrix = { mesh: ledInst, meta: ledMeta, tmpColor: new THREE.Color() };
    }

    _buildRagVectorCore(THREE) {
        const vault = new THREE.Group();
        vault.position.set(-4.05, 0, -6.92);
        this.envGroup.add(vault);

        const darkMetal = new THREE.MeshPhysicalMaterial({ color: 0x13233b, roughness: 0.24, metalness: 0.72, clearcoat: 0.5 });
        const cyanRingMat = new THREE.MeshStandardMaterial({ color: 0x38bdf8, emissive: 0x00d4ff, emissiveIntensity: 1.9 });
        const violetRingMat = new THREE.MeshStandardMaterial({ color: 0xa78bfa, emissive: 0x8b5cf6, emissiveIntensity: 1.8 });
        const crystalGlass = new THREE.MeshPhysicalMaterial({
            color: 0xe0f2fe,
            roughness: 0.06,
            metalness: 0.14,
            iridescence: 0.72,
            clearcoat: 1.0,
            transparent: true,
            opacity: 0.26,
            depthWrite: false
        });

        const base = new THREE.Mesh(new THREE.CylinderGeometry(0.66, 0.78, 0.38, 8), darkMetal);
        base.position.y = 0.19;
        base.castShadow = true;
        vault.add(base);

        const baseLed = new THREE.Mesh(new THREE.TorusGeometry(0.67, 0.022, 10, 32), cyanRingMat);
        baseLed.rotation.x = Math.PI / 2;
        baseLed.position.y = 0.39;
        vault.add(baseLed);

        const glassTube = new THREE.Mesh(new THREE.CylinderGeometry(0.54, 0.54, 1.82, 28, 1, true), crystalGlass);
        glassTube.position.y = 1.30;
        vault.add(glassTube);

        const cap = new THREE.Mesh(new THREE.CylinderGeometry(0.66, 0.60, 0.20, 8), darkMetal);
        cap.position.y = 2.30;
        cap.castShadow = true;
        vault.add(cap);

        const capLed = new THREE.Mesh(new THREE.TorusGeometry(0.60, 0.020, 10, 32), violetRingMat);
        capLed.rotation.x = Math.PI / 2;
        capLed.position.y = 2.20;
        vault.add(capLed);

        const holoScanMat = createFresnelHoloShader(THREE, 0x8b5cf6, 0.42);
        this.shaderUniformMaterials.push(holoScanMat);
        const scanCore = new THREE.Mesh(new THREE.CylinderGeometry(0.24, 0.24, 1.72, 18, 1, true), holoScanMat);
        scanCore.position.y = 1.30;
        vault.add(scanCore);

        const chunkCount = 36;
        const chunkMesh = new THREE.InstancedMesh(
            new THREE.OctahedronGeometry(0.056, 0),
            new THREE.MeshPhysicalMaterial({ color: 0xffffff, emissive: 0x38bdf8, emissiveIntensity: 0.65, metalness: 0.4, roughness: 0.15, iridescence: 0.9 }),
            chunkCount
        );
        const chunkData = [];
        const helixCols = [0x38bdf8, 0xa855f7, 0x34d399, 0xfbbf24];
        for (let i = 0; i < chunkCount; i++) {
            const strand = i % 2;
            const frac = Math.floor(i / 2) / (chunkCount / 2);
            chunkData.push({ strand, frac, yBase: 0.56 + frac * 1.48, r: 0.29 });
            chunkMesh.setColorAt(i, new THREE.Color(helixCols[i % helixCols.length]));
        }
        if (chunkMesh.instanceColor) chunkMesh.instanceColor.needsUpdate = true;
        vault.add(chunkMesh);

        const badge = this._createInfraBadgeSprite(THREE, 'RAG VECTOR DB', '#a855f7');
        badge.position.set(0, 2.72, 0);
        vault.add(badge);

        this.ragHelix = { mesh: chunkMesh, data: chunkData, dummy: new THREE.Object3D() };
    }

    _buildSecurityGuardrailNode(THREE) {
        const node = new THREE.Group();
        node.position.set(8.85, 0, -0.35);
        node.rotation.y = -Math.PI * 0.5;
        this.envGroup.add(node);

        const darkMetal = new THREE.MeshPhysicalMaterial({ color: 0x102136, roughness: 0.24, metalness: 0.72, clearcoat: 0.55 });
        const emeraldGlow = new THREE.MeshStandardMaterial({ color: 0x34d399, emissive: 0x10b981, emissiveIntensity: 1.9, roughness: 0.16 });

        const ped = new THREE.Mesh(new THREE.CylinderGeometry(0.52, 0.64, 1.05, 6), darkMetal);
        ped.position.y = 0.525;
        ped.castShadow = true;
        node.add(ped);

        const pedRing = new THREE.Mesh(new THREE.TorusGeometry(0.54, 0.020, 10, 24), emeraldGlow);
        pedRing.rotation.x = Math.PI / 2;
        pedRing.position.y = 1.06;
        node.add(pedRing);

        this.securityTelemetrySurf = createDynamicCanvasTex(THREE, 256, 160);
        drawSecurityTelemetry(this.securityTelemetrySurf, 0, 0);
        const secScreen = new THREE.Mesh(
            new THREE.PlaneGeometry(0.76, 0.48),
            new THREE.MeshBasicMaterial({ map: this.securityTelemetrySurf.tex })
        );
        secScreen.position.set(0, 0.74, 0.52);
        secScreen.rotation.x = -0.22;
        node.add(secScreen);

        const shieldGroup = new THREE.Group();
        shieldGroup.position.set(0, 1.68, 0);
        node.add(shieldGroup);

        const shieldShape = new THREE.Shape();
        shieldShape.moveTo(0, 0.28);
        shieldShape.lineTo(0.22, 0.18);
        shieldShape.lineTo(0.18, -0.10);
        shieldShape.quadraticCurveTo(0, -0.30, 0, -0.30);
        shieldShape.quadraticCurveTo(0, -0.30, -0.18, -0.10);
        shieldShape.lineTo(-0.22, 0.18);
        shieldShape.closePath();
        const shieldGeo = new THREE.ExtrudeGeometry(shieldShape, { depth: 0.045, bevelEnabled: true, bevelThickness: 0.012, bevelSize: 0.012, bevelSegments: 3 });
        shieldGeo.center();
        const shieldMesh = new THREE.Mesh(
            shieldGeo,
            new THREE.MeshPhysicalMaterial({ color: 0x10b981, emissive: 0x059669, emissiveIntensity: 0.85, metalness: 0.65, roughness: 0.16, iridescence: 0.95, clearcoat: 1.0 })
        );
        shieldGroup.add(shieldMesh);

        const shieldDomeMat = createFresnelHoloShader(THREE, 0x34d399, 0.62);
        this.shaderUniformMaterials.push(shieldDomeMat);
        const shieldDome = new THREE.Mesh(new THREE.SphereGeometry(0.46, 24, 18), shieldDomeMat);
        shieldGroup.add(shieldDome);

        const badge = this._createInfraBadgeSprite(THREE, 'RBAC GUARDRAIL', '#10b981');
        badge.position.set(0, 2.48, 0);
        node.add(badge);

        this.securityNode = { shieldGroup, shieldMesh, baseY: 1.68 };
    }

    _buildDataRelayDrone(THREE) {
        const drone = new THREE.Group();
        drone.position.set(4.6, 4.28, 0);
        this.envGroup.add(drone);

        const bodyMat = new THREE.MeshPhysicalMaterial({ color: 0xf8fafc, metalness: 0.35, roughness: 0.16, clearcoat: 1.0, iridescence: 0.65 });
        const accentMat = new THREE.MeshStandardMaterial({ color: 0x38bdf8, emissive: 0x00d4ff, emissiveIntensity: 2.0 });

        const hull = new THREE.Mesh(new THREE.SphereGeometry(0.18, 20, 14), bodyMat);
        hull.scale.set(1.25, 0.56, 1.25);
        drone.add(hull);

        const eye = new THREE.Mesh(new THREE.SphereGeometry(0.065, 14, 12), accentMat);
        eye.position.set(0, -0.02, 0.18);
        drone.add(eye);

        const rotorA = new THREE.Mesh(new THREE.TorusGeometry(0.30, 0.012, 8, 28, Math.PI * 1.5), accentMat);
        rotorA.rotation.x = Math.PI / 2;
        rotorA.position.y = 0.04;
        drone.add(rotorA);

        const rotorB = new THREE.Mesh(new THREE.TorusGeometry(0.38, 0.008, 8, 28, Math.PI * 1.4), new THREE.MeshStandardMaterial({ color: 0xa78bfa, emissive: 0x8b5cf6, emissiveIntensity: 1.8 }));
        rotorB.rotation.x = Math.PI / 2;
        rotorB.position.y = -0.02;
        drone.add(rotorB);

        const scanMat = createFresnelHoloShader(THREE, 0x38bdf8, 0.24);
        this.shaderUniformMaterials.push(scanMat);
        const scanRing = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.42, 0.72, 16, 1, true), scanMat);
        scanRing.position.y = -0.42;
        drone.add(scanRing);

        this.relayDrone = { group: drone, rotorA, rotorB };
    }

    _buildQuantumTelemetryField(THREE) {
        const count = 110;
        const positions = new Float32Array(count * 3);
        const scales = new Float32Array(count);
        const phases = new Float32Array(count);
        for (let i = 0; i < count; i++) {
            positions[i * 3] = (Math.random() - 0.5) * 19.6;
            positions[i * 3 + 1] = Math.random() * 4.8;
            positions[i * 3 + 2] = (Math.random() - 0.5) * 14.4;
            scales[i] = 0.7 + Math.random() * 1.4;
            phases[i] = Math.random();
        }
        const geo = new THREE.BufferGeometry();
        geo.setAttribute('position', new THREE.BufferAttribute(positions, 3));
        geo.setAttribute('aScale', new THREE.BufferAttribute(scales, 1));
        geo.setAttribute('aPhase', new THREE.BufferAttribute(phases, 1));

        const mat = createQuantumPointsShader(THREE, 0x7dd3fc);
        this.shaderUniformMaterials.push(mat);
        const pts = new THREE.Points(geo, mat);
        this.envGroup.add(pts);
        this.quantumField = pts;
    }

    _createDeskHologramBadge(THREE, spec, labelText, isAuthorized) {
        const tex = makeCanvasTex(THREE, 512, 128, (ctx, w, h) => {
            ctx.clearRect(0, 0, w, h);
            const accent = isAuthorized ? spec.accentCss : '#94a3b8';

            const g = ctx.createLinearGradient(0, 0, w, h);
            g.addColorStop(0, isAuthorized ? 'rgba(6, 18, 42, 0.90)' : 'rgba(30, 41, 59, 0.62)');
            g.addColorStop(0.5, isAuthorized ? 'rgba(15, 42, 92, 0.84)' : 'rgba(51, 65, 85, 0.52)');
            g.addColorStop(1, isAuthorized ? 'rgba(6, 18, 42, 0.90)' : 'rgba(30, 41, 59, 0.62)');
            ctx.fillStyle = g;
            roundRectCtx(ctx, 14, 14, w - 28, h - 32, 22);
            ctx.fill();

            ctx.strokeStyle = accent;
            ctx.lineWidth = 3.5;
            ctx.stroke();

            ctx.strokeStyle = 'rgba(186, 230, 254, 0.16)';
            ctx.lineWidth = 1.5;
            for (let sy = 24; sy < h - 24; sy += 10) {
                ctx.beginPath();
                ctx.moveTo(26, sy);
                ctx.lineTo(w - 26, sy);
                ctx.stroke();
            }

            ctx.strokeStyle = '#38bdf8';
            ctx.lineWidth = 4;
            const br = 20;
            const drawCorner = (cx, cy, dx, dy) => {
                ctx.beginPath();
                ctx.moveTo(cx + dx * br, cy);
                ctx.lineTo(cx, cy);
                ctx.lineTo(cx, cy + dy * br);
                ctx.stroke();
            };
            drawCorner(8, 8, 1, 1);
            drawCorner(w - 8, 8, -1, 1);
            drawCorner(8, h - 12, 1, -1);
            drawCorner(w - 8, h - 12, -1, -1);

            ctx.fillStyle = accent;
            roundRectCtx(ctx, w * 0.28, h - 14, w * 0.44, 5, 3);
            ctx.fill();

            ctx.fillStyle = accent;
            ctx.beginPath();
            ctx.arc(64, (h - 6) / 2, 26, 0, Math.PI * 2);
            ctx.fill();
            drawMiniIcon(ctx, spec.icon, 64, (h - 6) / 2, 14, '#ffffff');

            ctx.fillStyle = '#ffffff';
            ctx.font = 'bold 33px "Segoe UI", sans-serif';
            ctx.textAlign = 'left';
            ctx.textBaseline = 'middle';
            const cleanText = String(labelText || spec.jobLabel).slice(0, 20).toUpperCase();
            ctx.fillText(cleanText, 108, (h - 4) / 2);
        });
        return tex;
    }

    _getSharedDeskMats(THREE) {
        if (this._sharedDeskMats) return this._sharedDeskMats;
        const mark = (m) => {
            m.userData._shared = true;
            return m;
        };
        this._sharedDeskMats = {
            chassisDarkMat: mark(new THREE.MeshPhysicalMaterial({ color: 0x111827, roughness: 0.28, metalness: 0.56, clearcoat: 0.35 })),
            matteKeyMat: mark(new THREE.MeshStandardMaterial({ color: 0x1e293b, roughness: 0.55, metalness: 0.22 })),
            brushedAlumMat: mark(new THREE.MeshStandardMaterial({ color: 0x94a3b8, roughness: 0.24, metalness: 0.82 })),
            glassMat: mark(new THREE.MeshPhysicalMaterial({ color: 0x0b1628, roughness: 0.08, metalness: 0.15, transparent: true, opacity: 0.52, clearcoat: 0.9 })),
            cyanLedMat: mark(new THREE.MeshStandardMaterial({ color: 0x38bdf8, emissive: 0x00d4ff, emissiveIntensity: 1.9, roughness: 0.16 })),
            greenLedMat: mark(new THREE.MeshStandardMaterial({ color: 0x34d399, emissive: 0x10b981, emissiveIntensity: 2.0 })),
            deskMatMat: mark(new THREE.MeshStandardMaterial({ color: 0x0f172a, roughness: 0.84, metalness: 0.08 })),
            dockMat: mark(new THREE.MeshPhysicalMaterial({ color: 0x16243d, roughness: 0.22, metalness: 0.72, clearcoat: 0.55 })),
            chromeRingMat: mark(new THREE.MeshStandardMaterial({ color: 0x94a3b8, roughness: 0.18, metalness: 0.85 })),
            deskSurfaceMat: mark(new THREE.MeshPhysicalMaterial({ color: 0x1e3352, roughness: 0.24, metalness: 0.34, clearcoat: 0.55 })),
            legSilverMat: mark(new THREE.MeshStandardMaterial({ color: 0x94a3b8, roughness: 0.26, metalness: 0.72 })),
            bezelDarkMat: mark(new THREE.MeshStandardMaterial({ color: 0x0f172a, roughness: 0.30, metalness: 0.52 }))
        };
        return this._sharedDeskMats;
    }

    _buildRealisticDesktopPC(THREE, podGroup, spec, idx, deskTopY) {
        const { chassisDarkMat, matteKeyMat, brushedAlumMat, glassMat, cyanLedMat, greenLedMat, deskMatMat } = this._getSharedDeskMats(THREE);
        const accentGlowMat = new THREE.MeshStandardMaterial({ color: spec.accent, emissive: spec.accent, emissiveIntensity: 1.75, roughness: 0.18 });

        const monGroup = new THREE.Group();
        monGroup.position.set(0.04, deskTopY, 0.44);
        monGroup.rotation.y = -0.06;
        podGroup.add(monGroup);

        const standBase = new THREE.Mesh(createRoundedSlabGeo(THREE, 0.28, 0.18, 0.014, 0.03, 0.003), brushedAlumMat);
        standBase.position.set(0, 0.008, 0.02);
        standBase.castShadow = true;
        monGroup.add(standBase);

        const standNeck = new THREE.Mesh(new THREE.BoxGeometry(0.056, 0.20, 0.026), brushedAlumMat);
        standNeck.position.set(0, 0.11, 0.045);
        standNeck.rotation.x = -0.14;
        monGroup.add(standNeck);

        const monSurf = createDynamicCanvasTex(THREE, 256, 160);
        drawAgentLiveChart(monSurf, spec.chartType, spec.accentCss, 0, 0);
        this.liveMonitors.push({ surf: monSurf, chartType: spec.chartType, accentCss: spec.accentCss, zoneKey: spec.zoneKey, phase: idx * 0.7 });

        const screenFrame = new THREE.Group();
        screenFrame.position.set(0, 0.34, 0.0);
        screenFrame.rotation.x = 0.04;
        monGroup.add(screenFrame);

        const frameTop = new THREE.Mesh(new THREE.BoxGeometry(0.86, 0.022, 0.032), chassisDarkMat);
        frameTop.position.set(0, 0.236, 0);
        screenFrame.add(frameTop);
        const frameChin = new THREE.Mesh(new THREE.BoxGeometry(0.86, 0.038, 0.034), chassisDarkMat);
        frameChin.position.set(0, -0.244, 0);
        screenFrame.add(frameChin);
        [-0.422, 0.422].forEach((fx) => {
            const sideRim = new THREE.Mesh(new THREE.BoxGeometry(0.022, 0.47, 0.032), chassisDarkMat);
            sideRim.position.set(fx, -0.004, 0);
            screenFrame.add(sideRim);
        });

        const monPowerLed = new THREE.Mesh(new THREE.SphereGeometry(0.006, 8, 8), greenLedMat);
        monPowerLed.position.set(-0.37, -0.244, -0.018);
        screenFrame.add(monPowerLed);

        const webcam = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.085, 0.024, 0.024, 0.008, 0.002), chassisDarkMat);
        webcam.position.set(0, 0.258, 0);
        screenFrame.add(webcam);
        const camLens = new THREE.Mesh(new THREE.CylinderGeometry(0.007, 0.007, 0.01, 10), cyanLedMat);
        camLens.rotation.x = Math.PI / 2;
        camLens.position.set(0, 0.258, -0.012);
        screenFrame.add(camLens);

        const monScreenMat = new THREE.MeshBasicMaterial({ map: monSurf.tex });
        const screenPanel = new THREE.Mesh(
            new THREE.BoxGeometry(0.82, 0.45, 0.018),
            [chassisDarkMat, chassisDarkMat, chassisDarkMat, chassisDarkMat, monScreenMat, monScreenMat]
        );
        screenPanel.position.set(0, 0.0, 0);
        screenPanel.castShadow = true;
        screenFrame.add(screenPanel);

        const tower = new THREE.Group();
        tower.position.set(0.73, deskTopY, 0.36);
        podGroup.add(tower);

        [[-0.08, -0.16], [0.08, -0.16], [-0.08, 0.16], [0.08, 0.16]].forEach(([fx, fz]) => {
            const foot = new THREE.Mesh(new THREE.CylinderGeometry(0.018, 0.020, 0.014, 10), matteKeyMat);
            foot.position.set(fx, 0.007, fz);
            tower.add(foot);
        });

        const pcBody = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.21, 0.43, 0.40, 0.018, 0.005), chassisDarkMat);
        pcBody.position.set(0, 0.228, 0);
        pcBody.castShadow = true;
        pcBody.receiveShadow = true;
        tower.add(pcBody);

        const frontMesh = new THREE.Mesh(new THREE.BoxGeometry(0.16, 0.36, 0.010), matteKeyMat);
        frontMesh.position.set(0, 0.225, -0.205);
        tower.add(frontMesh);

        [-0.086, 0.086].forEach((sx) => {
            const ledBar = new THREE.Mesh(new THREE.BoxGeometry(0.010, 0.34, 0.012), accentGlowMat);
            ledBar.position.set(sx, 0.225, -0.206);
            tower.add(ledBar);
        });

        const pwrBtn = new THREE.Mesh(new THREE.CylinderGeometry(0.012, 0.012, 0.008, 12), cyanLedMat);
        pwrBtn.position.set(0.055, 0.448, -0.14);
        tower.add(pwrBtn);

        const usbStrip = new THREE.Mesh(new THREE.BoxGeometry(0.075, 0.008, 0.016), brushedAlumMat);
        usbStrip.position.set(-0.025, 0.448, -0.14);
        tower.add(usbStrip);

        const sideGlass = new THREE.Mesh(new THREE.BoxGeometry(0.008, 0.34, 0.31), glassMat);
        sideGlass.position.set(-0.109, 0.23, 0.01);
        tower.add(sideGlass);

        [0.14, 0.30].forEach((fy, fi) => {
            const fanRing = new THREE.Mesh(new THREE.TorusGeometry(0.050, 0.007, 8, 20), fi === 0 ? accentGlowMat : cyanLedMat);
            fanRing.rotation.y = Math.PI / 2;
            fanRing.position.set(-0.104, fy, -0.06);
            tower.add(fanRing);
        });

        const gpuBar = new THREE.Mesh(new THREE.BoxGeometry(0.012, 0.014, 0.20), cyanLedMat);
        gpuBar.position.set(-0.102, 0.22, 0.05);
        tower.add(gpuBar);

        const deskMat = new THREE.Mesh(createRoundedSlabGeo(THREE, 0.86, 0.34, 0.006, 0.025, 0.002), deskMatMat);
        deskMat.position.set(-0.04, deskTopY + 0.003, 0.02);
        deskMat.receiveShadow = true;
        podGroup.add(deskMat);

        const kbGroup = new THREE.Group();
        kbGroup.position.set(0.02, deskTopY + 0.015, 0.02);
        kbGroup.rotation.x = 0.06;
        podGroup.add(kbGroup);

        const kbBase = new THREE.Mesh(createRoundedSlabGeo(THREE, 0.42, 0.148, 0.016, 0.014, 0.003), chassisDarkMat);
        kbBase.castShadow = true;
        kbGroup.add(kbBase);

        const kbGlow = new THREE.Mesh(new THREE.BoxGeometry(0.426, 0.005, 0.152), accentGlowMat);
        kbGlow.position.y = -0.007;
        kbGroup.add(kbGlow);

        for (let r = 0; r < 5; r++) {
            const rz = -0.050 + r * 0.025;
            const rowBar = new THREE.Mesh(new THREE.BoxGeometry(r === 4 ? 0.26 : 0.38, 0.009, 0.016), matteKeyMat);
            rowBar.position.set(0, 0.011, rz);
            kbGroup.add(rowBar);
        }
        const escKey = new THREE.Mesh(new THREE.BoxGeometry(0.024, 0.010, 0.016), accentGlowMat);
        escKey.position.set(0.175, 0.012, 0.050);
        kbGroup.add(escKey);

        const mouseGroup = new THREE.Group();
        mouseGroup.position.set(-0.28, deskTopY + 0.012, 0.01);
        podGroup.add(mouseGroup);

        const mouseBody = new THREE.Mesh(new THREE.SphereGeometry(0.044, 16, 12), chassisDarkMat);
        mouseBody.scale.set(0.76, 0.46, 1.32);
        mouseBody.position.y = 0.008;
        mouseBody.castShadow = true;
        mouseGroup.add(mouseBody);

        const scrollWheel = new THREE.Mesh(new THREE.BoxGeometry(0.008, 0.008, 0.024), cyanLedMat);
        scrollWheel.position.set(0, 0.024, 0.022);
        mouseGroup.add(scrollWheel);

        [[-0.45, 0.22], [0.51, -0.22]].forEach(([sx, ry]) => {
            const spk = new THREE.Group();
            spk.position.set(sx, deskTopY + 0.072, 0.48);
            spk.rotation.y = ry;
            const spkBox = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.088, 0.14, 0.095, 0.012, 0.003), chassisDarkMat);
            spkBox.castShadow = true;
            spk.add(spkBox);
            const cone = new THREE.Mesh(new THREE.CylinderGeometry(0.026, 0.018, 0.010, 14), brushedAlumMat);
            cone.rotation.x = Math.PI / 2;
            cone.position.set(0, -0.012, -0.048);
            spk.add(cone);
            const spkLed = new THREE.Mesh(new THREE.BoxGeometry(0.05, 0.005, 0.005), accentGlowMat);
            spkLed.position.set(0, -0.058, -0.048);
            spk.add(spkLed);
            podGroup.add(spk);
        });
    }

    _buildFuturisticHoloProjector(THREE, podGroup, spec, isAuthorized, deskTopY) {
        const { dockMat, chromeRingMat } = this._getSharedDeskMats(THREE);
        const glowColor = isAuthorized ? spec.accent : 0x64748b;
        const emitterGlowMat = new THREE.MeshStandardMaterial({
            color: glowColor,
            emissive: glowColor,
            emissiveIntensity: isAuthorized ? 2.1 : 0.35,
            roughness: 0.14
        });
        const holoArcMat = new THREE.MeshBasicMaterial({
            color: glowColor,
            transparent: true,
            opacity: isAuthorized ? 0.72 : 0.22,
            blending: THREE.AdditiveBlending,
            depthWrite: false
        });
        const laserFilamentMat = new THREE.MeshBasicMaterial({
            color: 0x38bdf8,
            transparent: true,
            opacity: isAuthorized ? 0.36 : 0.10,
            blending: THREE.AdditiveBlending,
            depthWrite: false
        });

        const px = 0.0;
        const pz = 0.62;
        const projGroup = new THREE.Group();
        projGroup.position.set(px, deskTopY, pz);
        podGroup.add(projGroup);

        const baseDock = new THREE.Mesh(new THREE.CylinderGeometry(0.105, 0.132, 0.034, 8), dockMat);
        baseDock.position.y = 0.017;
        baseDock.castShadow = true;
        projGroup.add(baseDock);

        const upperBevel = new THREE.Mesh(new THREE.CylinderGeometry(0.088, 0.102, 0.016, 8), chromeRingMat);
        upperBevel.position.y = 0.040;
        projGroup.add(upperBevel);

        const lensRing = new THREE.Mesh(new THREE.TorusGeometry(0.072, 0.010, 10, 24), emitterGlowMat);
        lensRing.rotation.x = Math.PI / 2;
        lensRing.position.y = 0.050;
        projGroup.add(lensRing);

        const lensDome = new THREE.Mesh(
            new THREE.SphereGeometry(0.048, 16, 12, 0, Math.PI * 2, 0, Math.PI * 0.5),
            new THREE.MeshPhysicalMaterial({ color: 0x7dd3fc, emissive: glowColor, emissiveIntensity: isAuthorized ? 1.5 : 0.2, roughness: 0.05, clearcoat: 1.0 })
        );
        lensDome.position.y = 0.046;
        projGroup.add(lensDome);

        const holoRings = [];
        [
            { y: 0.18, r: 0.105, speed: 1.65, arc: Math.PI * 1.45 },
            { y: 0.52, r: 0.155, speed: -1.35, arc: Math.PI * 1.35 },
            { y: 0.86, r: 0.205, speed: 1.10, arc: Math.PI * 1.55 }
        ].forEach((cfg, ri) => {
            const ring = new THREE.Mesh(new THREE.TorusGeometry(cfg.r, 0.0085, 8, 32, cfg.arc), holoArcMat);
            ring.rotation.x = Math.PI / 2;
            ring.position.y = cfg.y;
            projGroup.add(ring);
            holoRings.push({ mesh: ring, baseY: cfg.y, speed: cfg.speed, phase: ri * 1.15 });
        });

        [-0.145, 0.145].forEach((lx) => {
            const filament = new THREE.Mesh(new THREE.CylinderGeometry(0.004, 0.004, 0.98, 6), laserFilamentMat);
            filament.position.set(lx, 0.56, 0);
            projGroup.add(filament);
        });

        const moteGeo = new THREE.OctahedronGeometry(0.016, 0);
        const moteMat = new THREE.MeshBasicMaterial({
            color: isAuthorized ? 0x7dd3fc : 0x64748b,
            transparent: true,
            opacity: isAuthorized ? 0.85 : 0.20,
            blending: THREE.AdditiveBlending,
            depthWrite: false
        });
        const holoMotes = [];
        for (let m = 0; m < 6; m++) {
            const mote = new THREE.Mesh(moteGeo, moteMat);
            const ang = (m / 6) * Math.PI * 2;
            const rad = 0.06 + (m % 3) * 0.042;
            mote.position.set(Math.cos(ang) * rad, 0.12 + m * 0.15, Math.sin(ang) * rad);
            projGroup.add(mote);
            holoMotes.push({ mesh: mote, angle: ang, radius: rad, phase: m / 6 });
        }

        return { holoRings, holoMotes };
    }

    setAuthorizedAgents(agents = [], roleZone = 'COMMAND_CENTER') {
        if (!this.zonesGroup || typeof window.THREE === 'undefined') return;
        const THREE = window.THREE;
        const nextZone = roleZone || 'COMMAND_CENTER';
        const sig = nextZone + '::' + agents.map((a) => `${a.agent_code || ''}:${a.zone_code || ''}`).sort().join('|');
        if (this._lastAgentsSig === sig && this.zonePods.size > 0) {
            return;
        }
        this._lastAgentsSig = sig;

        disposeObjectTree(this.zonesGroup);
        this.zonePods.clear();
        this.agentRegistryByCode.clear();
        this.liveMonitors = [];
        this.circuitPulses = [];
        this.clickableMeshes = this._hubHitBox ? [this._hubHitBox] : [];
        this.roleZone = roleZone || 'COMMAND_CENTER';

        const byZone = new Map();
        agents.forEach((ag) => {
            const z = String(ag.zone_code || 'COMMAND_CENTER').toUpperCase();
            if (!byZone.has(z)) byZone.set(z, []);
            byZone.get(z).push(ag);
        });
        const hasAllZoneView = byZone.has('COMMAND_CENTER');

        POD_LAYOUT.forEach((spec, idx) => {
            let zoneAgents = byZone.get(spec.zoneKey) || [];
            if (zoneAgents.length === 0 && hasAllZoneView) {
                zoneAgents = byZone.get('COMMAND_CENTER') || [];
            }
            const isAuthorized = zoneAgents.length > 0;
            this._buildModernDeskWorkstation(THREE, spec, zoneAgents, isAuthorized, idx);
        });

        this.focusZone(this.roleZone);
    }

    _buildModernDeskWorkstation(THREE, spec, zoneAgents, isAuthorized, idx) {
        const [px, , pz] = spec.pos;
        const podGroup = new THREE.Group();
        podGroup.position.set(px, 0, pz);
        podGroup.rotation.y = spec.rotY;
        this.zonesGroup.add(podGroup);

        const { deskSurfaceMat, legSilverMat, bezelDarkMat } = this._getSharedDeskMats(THREE);
        const accentMat = new THREE.MeshStandardMaterial({
            color: spec.accent,
            emissive: spec.accent,
            emissiveIntensity: isAuthorized ? 1.45 : 0.25,
            roughness: 0.18
        });

        const rug = new THREE.Mesh(
            createRoundedSlabGeo(THREE, 2.40, 2.00, 0.026, 0.26, 0.004),
            new THREE.MeshStandardMaterial({ color: isAuthorized ? 0x152a48 : 0x122238, roughness: 0.76 })
        );
        rug.position.set(0, 0.014, 0.02);
        rug.receiveShadow = true;
        podGroup.add(rug);

        const deskTopY = 0.597;
        const deskTop = new THREE.Mesh(createRoundedSlabGeo(THREE, 1.94, 0.86, 0.064, 0.10, 0.012), deskSurfaceMat);
        deskTop.position.set(0, 0.565, 0.28);
        deskTop.castShadow = true;
        deskTop.receiveShadow = true;
        podGroup.add(deskTop);

        const deskGlowStrip = new THREE.Mesh(createRoundedSlabGeo(THREE, 1.98, 0.90, 0.024, 0.11, 0.005), accentMat);
        deskGlowStrip.position.set(0, 0.525, 0.28);
        podGroup.add(deskGlowStrip);

        [-0.86, 0.86].forEach((lx) => {
            const leg = new THREE.Mesh(new THREE.BoxGeometry(0.06, 0.54, 0.68), legSilverMat);
            leg.position.set(lx, 0.27, 0.28);
            leg.castShadow = true;
            podGroup.add(leg);
        });

        this._buildRealisticDesktopPC(THREE, podGroup, spec, idx, deskTopY);

        const mug = new THREE.Mesh(new THREE.CylinderGeometry(0.036, 0.030, 0.078, 12), new THREE.MeshStandardMaterial({ color: spec.accent, roughness: 0.24 }));
        mug.position.set(-0.54, deskTopY + 0.039, 0.06);
        podGroup.add(mug);

        if (spec.propType === 'research_lab') {
            const scopeBase = new THREE.Mesh(new THREE.BoxGeometry(0.15, 0.038, 0.16), new THREE.MeshStandardMaterial({ color: 0x2563eb, roughness: 0.28 }));
            scopeBase.position.set(-0.74, deskTopY + 0.019, 0.34);
            podGroup.add(scopeBase);
            const scopeArm = new THREE.Mesh(new THREE.CylinderGeometry(0.022, 0.026, 0.22, 10), new THREE.MeshStandardMaterial({ color: 0x38bdf8, roughness: 0.2 }));
            scopeArm.rotation.z = 0.28;
            scopeArm.position.set(-0.71, deskTopY + 0.125, 0.34);
            podGroup.add(scopeArm);
            [0x38bdf8, 0x22c55e].forEach((fc, fi) => {
                const fl = new THREE.Mesh(
                    new THREE.ConeGeometry(0.042, 0.10, 12),
                    new THREE.MeshPhysicalMaterial({ color: fc, emissive: fc, emissiveIntensity: 0.8, transparent: true, opacity: 0.85 })
                );
                fl.position.set(-0.68 - fi * 0.09, deskTopY + 0.052, 0.14);
                podGroup.add(fl);
            });
        } else if (spec.propType === 'student_study') {
            [0x3b82f6, 0xf59e0b, 0x10b981].forEach((bc, bi) => {
                const bk = new THREE.Mesh(new THREE.BoxGeometry(0.24, 0.046, 0.18), new THREE.MeshStandardMaterial({ color: bc, roughness: 0.45 }));
                bk.position.set(-0.72, deskTopY + 0.023 + bi * 0.048, 0.28);
                podGroup.add(bk);
            });
            const cap = new THREE.Mesh(new THREE.BoxGeometry(0.20, 0.020, 0.20), new THREE.MeshStandardMaterial({ color: 0x0f172a, roughness: 0.38 }));
            cap.position.set(-0.72, deskTopY + 0.18, 0.28);
            cap.rotation.y = 0.4;
            podGroup.add(cap);
        } else if (spec.propType === 'sec_calendar') {
            const mon2Surf = createDynamicCanvasTex(THREE, 256, 160);
            drawAgentLiveChart(mon2Surf, 'sec_queue', '#10b981', 0, 0);
            this.liveMonitors.push({ surf: mon2Surf, chartType: 'sec_queue', accentCss: '#10b981', zoneKey: spec.zoneKey, phase: 2.3 });
            const mon2Mat = new THREE.MeshBasicMaterial({ map: mon2Surf.tex });
            const mon2Stand = new THREE.Mesh(new THREE.BoxGeometry(0.04, 0.12, 0.03), legSilverMat);
            mon2Stand.position.set(-0.66, deskTopY + 0.06, 0.40);
            podGroup.add(mon2Stand);
            const mon2 = new THREE.Mesh(
                new THREE.BoxGeometry(0.46, 0.30, 0.024),
                [bezelDarkMat, bezelDarkMat, bezelDarkMat, bezelDarkMat, mon2Mat, mon2Mat]
            );
            mon2.position.set(-0.66, deskTopY + 0.24, 0.38);
            mon2.rotation.y = 0.34;
            podGroup.add(mon2);
        } else if (spec.propType === 'upmf_seal') {
            [0x7c3aed, 0xa855f7, 0x6d28d9].forEach((bc, bi) => {
                const bnd = new THREE.Mesh(new THREE.BoxGeometry(0.22, 0.048, 0.17), new THREE.MeshStandardMaterial({ color: bc, roughness: 0.42 }));
                bnd.position.set(-0.73, deskTopY + 0.024 + bi * 0.050, 0.30);
                podGroup.add(bnd);
            });
        } else {
            const miniPot = new THREE.Mesh(new THREE.CylinderGeometry(0.058, 0.046, 0.09, 12), legSilverMat);
            miniPot.position.set(-0.74, deskTopY + 0.045, 0.32);
            podGroup.add(miniPot);
            const succ = new THREE.Mesh(new THREE.SphereGeometry(0.068, 10, 8), new THREE.MeshStandardMaterial({ color: 0x22c55e, roughness: 0.45 }));
            succ.position.set(-0.74, deskTopY + 0.125, 0.32);
            podGroup.add(succ);
        }

        const { holoRings, holoMotes } = this._buildFuturisticHoloProjector(THREE, podGroup, spec, isAuthorized, deskTopY);

        const holoTex = this._createDeskHologramBadge(THREE, spec, spec.jobLabel, isAuthorized);
        const holoMat = new THREE.SpriteMaterial({ map: holoTex, transparent: true, depthWrite: false });
        const holoBadge = new THREE.Sprite(holoMat);
        holoBadge.scale.set(1.78, 0.44, 1);
        holoBadge.position.set(0, 1.82, 0.56);
        podGroup.add(holoBadge);

        const chibi = createChibiHuman(THREE, spec.persona);
        chibi.root.position.set(0, 0.02, -0.26);
        chibi.root.scale.setScalar(1.16);
        podGroup.add(chibi.root);

        podGroup.updateMatrixWorld(true);
        if (spec.route && Array.isArray(spec.route.worldWaypoints)) {
            const localWaypoints = spec.route.worldWaypoints.map((wp) => {
                const v = new THREE.Vector3(wp.x, 0.02, wp.z);
                chibi.root.worldToLocal(v);
                return { x: v.x, z: v.z };
            });
            chibi.setActivityRoute({
                ...spec.route,
                waypoints: localWaypoints,
                activityYaw: (spec.route.activityWorldYaw ?? 0) - spec.rotY
            });
        }

        if (isAuthorized) {
            const hitBox = new THREE.Mesh(
                new THREE.BoxGeometry(2.1, 2.0, 1.8),
                new THREE.MeshBasicMaterial({ visible: false })
            );
            hitBox.position.set(0, 1.0, 0.1);
            hitBox.userData = { agent: zoneAgents[0], zoneKey: spec.zoneKey, agentsList: zoneAgents };
            podGroup.add(hitBox);
            this.clickableMeshes.push(hitBox);

            const actorHit = new THREE.Mesh(
                new THREE.CylinderGeometry(0.45, 0.45, 1.3, 10),
                new THREE.MeshBasicMaterial({ visible: false })
            );
            actorHit.position.set(0, 0.65, 0);
            actorHit.userData = { agent: zoneAgents[0], zoneKey: spec.zoneKey, agentsList: zoneAgents };
            chibi.actor.add(actorHit);
            this.clickableMeshes.push(actorHit);
        }

        const startPt = new THREE.Vector3(0, 0.03, -0.4);
        const elbow1 = new THREE.Vector3(px * 0.42, 0.03, -0.4 + (pz + 0.4) * 0.20);
        const elbow2 = new THREE.Vector3(px * 0.72, 0.03, pz * 0.80);
        const endPt = new THREE.Vector3(px, 0.03, pz);
        const curve = new THREE.CatmullRomCurve3([startPt, elbow1, elbow2, endPt]);
        const trackGeo = new THREE.TubeGeometry(curve, 24, 0.040, 8, false);
        const trackMat = new THREE.MeshStandardMaterial({
            color: isAuthorized ? 0x38bdf8 : 0x475569,
            emissive: isAuthorized ? 0x00d4ff : 0x1e293b,
            emissiveIntensity: isAuthorized ? 1.35 : 0.15,
            roughness: 0.18
        });
        const trackMesh = new THREE.Mesh(trackGeo, trackMat);
        this.zonesGroup.add(trackMesh);

        const pulseOrb = createGlowSprite(THREE, spec.accentCss, 0.62, 0.95);
        pulseOrb.visible = false;
        this.zonesGroup.add(pulseOrb);

        this.circuitPulses.push({
            zoneKey: spec.zoneKey,
            orb: pulseOrb,
            curve,
            trackMat,
            active: false,
            phase: (idx * 0.17) % 1
        });

        zoneAgents.forEach((ag) => {
            this.agentRegistryByCode.set(ag.agent_code, spec.zoneKey);
        });

        this.zonePods.set(spec.zoneKey, {
            spec,
            podGroup,
            chibi,
            accentMat,
            holoBadge,
            holoRings,
            holoMotes,
            holoBaseY: 1.82,
            isAuthorized,
            status: 'idle',
            phaseShift: idx * 1.1,
            agents: zoneAgents
        });
    }

    _updatePodHologramLabel(pod, labelText) {
        if (!pod || !pod.holoBadge || typeof window.THREE === 'undefined') return;
        const THREE = window.THREE;
        const oldMap = pod.holoBadge.material.map;
        pod.holoBadge.material.map = this._createDeskHologramBadge(THREE, pod.spec, labelText, pod.isAuthorized);
        pod.holoBadge.material.needsUpdate = true;
        if (oldMap) oldMap.dispose();
    }

    applyAgentEvent(ev) {
        if (!ev) return;
        const code = String(ev.agent_id || ev.agent_code || 'ORCHESTRATOR_HUB');
        const status = String(ev.status || 'idle').toLowerCase();
        const anim = String(ev.animation || status).toLowerCase();
        const evZone = String(ev.zone || this.agentRegistryByCode.get(code) || '').toUpperCase();

        if (code === 'ORCHESTRATOR_HUB' || !evZone || evZone === 'COMMAND_CENTER') {
            this.hubAnimationState = anim;
        } else {
            this.hubAnimationState = status === 'success' ? 'success' : (status === 'error' ? 'error' : 'collaborating');
        }

        const pod = this.zonePods.get(evZone);
        if (pod) {
            pod.status = status;
            pod.accentMat.emissiveIntensity = status === 'idle' ? 1.45 : 2.5;
            const colHex = STATE_COLOR[status] || STATE_COLOR[anim];
            if (colHex && status !== 'idle') {
                pod.accentMat.emissive.setHex(colHex);
            } else {
                pod.accentMat.emissive.setHex(pod.spec.accent);
            }

            const [px, , pz] = pod.spec.pos;
            this.hubLookYaw = Math.atan2(px, pz + 0.4);
            this.activeTelemetryZone = status === 'idle' ? '' : evZone;
            this.activeTelemetryBoost = status === 'idle' ? 0 : 1.0;

            if (ev.agent_name && status !== 'idle') {
                this._updatePodHologramLabel(pod, String(ev.agent_name).replace(/\s+Agent$/i, ''));
            }

            this.circuitPulses.forEach((cp) => {
                if (cp.zoneKey === evZone) {
                    const active = !['idle', 'success', 'error'].includes(status);
                    cp.active = active;
                    cp.orb.visible = active;
                    cp.trackMat.emissiveIntensity = active ? 2.4 : (pod.isAuthorized ? 1.35 : 0.15);
                }
            });
        }

        if (status === 'success' || status === 'error') {
            setTimeout(() => {
                if (this.isDisposed) return;
                this.hubAnimationState = 'idle';
                this.hubLookYaw = 0;
                this.activeTelemetryZone = '';
                this.activeTelemetryBoost = 0;
                if (pod) {
                    pod.status = 'idle';
                    pod.accentMat.emissive.setHex(pod.spec.accent);
                    pod.accentMat.emissiveIntensity = pod.isAuthorized ? 1.45 : 0.25;
                    this._updatePodHologramLabel(pod, pod.spec.jobLabel);
                }
                this.circuitPulses.forEach((cp) => {
                    cp.active = false;
                    cp.orb.visible = false;
                    cp.trackMat.emissiveIntensity = 1.35;
                });
            }, 3400);
        }

        this.onStateUpdate(ev);
    }

    showHologramSummary(title, subtitle, bodyText) {
        if (!this.hologramPanel) return;
        this.hologramPanel.show({
            layout: 'summary',
            title: title || 'FILKOM Orchestrator',
            badge: subtitle || 'READY',
            spokenText: bodyText || '',
            source: 'Orchestrator',
            evalScore: 0.98
        }, 12.0);
        this.hubVisualPanelActive = true;
    }

    focusZone(zoneKey) {
        const key = String(zoneKey || '').toUpperCase();
        if (key === 'ORCHESTRATOR_FOCUS') {
            if (this.desiredCamTarget) this.desiredCamTarget.set(0, 1.55, -0.4);
            this.targetCamTheta = Math.PI * 0.42;
            this.targetCamPhi = 1.24;
            this.targetCamRadius = 5.6;
            return;
        }
        const pod = this.zonePods.get(key);
        if (!pod || key === 'COMMAND_CENTER' || !pod.isAuthorized) {
            this.targetCamTheta = this.defaultTheta;
            this.targetCamPhi = this.defaultPhi;
            this.targetCamRadius = this.defaultRadius;
            if (this.desiredCamTarget) this.desiredCamTarget.set(-0.2, 1.15, -0.35);
            return;
        }
        const wp = pod.chibi.getActorWorldPos(this._tmpActorVec);
        if (this.desiredCamTarget) this.desiredCamTarget.set(wp.x, 1.05, wp.z);
        this.targetCamTheta = Math.PI * 0.36 + (wp.x < 0 ? 0.14 : -0.14);
        this.targetCamPhi = 1.22;
        this.targetCamRadius = 5.2;
    }

    resetCamera() {
        this.focusZone('COMMAND_CENTER');
    }

    _updateCamera() {
        if (!this.camera || !this.camTarget) return;
        const sinPhi = Math.sin(this.camPhi);
        const cx = this.camTarget.x + this.camRadius * sinPhi * Math.cos(this.camTheta);
        const cy = this.camTarget.y + this.camRadius * Math.cos(this.camPhi);
        const cz = this.camTarget.z + this.camRadius * sinPhi * Math.sin(this.camTheta);
        this.camera.position.set(cx, Math.max(1.35, cy), cz);
        this.camera.lookAt(this.camTarget);
    }

    _bindPointerControls() {
        const THREE = window.THREE;
        const raycaster = new THREE.Raycaster();
        const pointer = new THREE.Vector2();

        this.canvas.addEventListener('pointerdown', (e) => {
            this._isDragging = true;
            this._dragMoved = false;
            this._lastMouse = { x: e.clientX, y: e.clientY };
        });

        window.addEventListener('pointermove', this._onPtrMove = (e) => {
            if (!this._isDragging) return;
            const dx = e.clientX - this._lastMouse.x;
            const dy = e.clientY - this._lastMouse.y;
            if (Math.abs(dx) + Math.abs(dy) > 3) this._dragMoved = true;
            this._lastMouse = { x: e.clientX, y: e.clientY };
            this.targetCamTheta += dx * 0.005;
            this.targetCamPhi = Math.max(0.45, Math.min(1.40, this.targetCamPhi - dy * 0.0045));
        });

        window.addEventListener('pointerup', this._onPtrUp = (e) => {
            const wasClick = this._isDragging && !this._dragMoved;
            this._isDragging = false;
            if (wasClick && this.clickableMeshes.length > 0 && e.target === this.canvas) {
                const rect = this.canvas.getBoundingClientRect();
                pointer.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
                pointer.y = -(((e.clientY - rect.top) / rect.height) * 2 - 1);
                raycaster.setFromCamera(pointer, this.camera);
                const hits = raycaster.intersectObjects(this.clickableMeshes, false);
                if (hits.length > 0 && hits[0].object?.userData) {
                    const ud = hits[0].object.userData;
                    if (ud.agent) this.onAgentSelect(ud.agent);
                    if (ud.zoneKey) this.focusZone(ud.zoneKey);
                }
            }
        });

        this.canvas.addEventListener('wheel', (e) => {
            e.preventDefault();
            this.targetCamRadius = Math.max(3.6, Math.min(26.0, this.targetCamRadius + e.deltaY * 0.009));
        }, { passive: false });

        this.canvas.addEventListener('dblclick', () => this.resetCamera());
    }

    resize() {
        if (!this.renderer || !this.camera || !this.container) return;
        const w = Math.max(280, this.container.clientWidth || 1000);
        const h = Math.max(240, this.container.clientHeight || 600);
        if (this._lastW === w && this._lastH === h) return;
        this._lastW = w;
        this._lastH = h;
        this.camera.aspect = w / h;
        this.camera.updateProjectionMatrix();
        this.renderer.setSize(w, h, false);
    }

    _startRenderLoop() {
        const render = () => {
            if (this.isDisposed) return;
            this.animId = requestAnimationFrame(render);

            const now = performance.now();
            const dt = Math.min(0.1, Math.max(0.001, (now - this._lastRenderAt) * 0.001));
            const t = now * 0.001;
            this._lastRenderAt = now;

            const damp = 1.0 - Math.exp(-6.5 * dt);
            this.camTheta += (this.targetCamTheta - this.camTheta) * damp;
            this.camPhi += (this.targetCamPhi - this.camPhi) * damp;
            this.camRadius += (this.targetCamRadius - this.camRadius) * damp;
            if (this.desiredCamTarget && this.camTarget) {
                this.camTarget.lerp(this.desiredCamTarget, damp);
            }
            this._updateCamera();

            this.hubRings.forEach((hr) => {
                const s = 1.0 + Math.sin(t * 2.6 + hr.phase) * 0.04;
                hr.mesh.scale.set(s, s, 1);
            });

            if (this.shelfHoloOrb) {
                this.shelfHoloOrb.rotation.y = t * 1.1;
                this.shelfHoloOrb.rotation.x = Math.sin(t * 0.9) * 0.25;
            }

            this.hubHoloScreens.forEach((hs) => {
                hs.mesh.position.y = hs.baseY + Math.sin(t * 1.8 + hs.phase) * 0.045;
            });

            this.shaderUniformMaterials.forEach((sm) => {
                if (sm.uniforms.uTime) sm.uniforms.uTime.value = t;
                if (sm.uniforms.uBoost) sm.uniforms.uBoost.value = this.activeTelemetryBoost;
            });

            if (this.ragHelix) {
                const rh = this.ragHelix;
                for (let i = 0; i < rh.data.length; i++) {
                    const d = rh.data[i];
                    const ang = d.frac * Math.PI * 4.0 + d.strand * Math.PI + t * (1.05 + this.activeTelemetryBoost * 1.2);
                    rh.dummy.position.set(Math.cos(ang) * d.r, d.yBase + Math.sin(t * 2.2 + i) * 0.03, Math.sin(ang) * d.r);
                    rh.dummy.rotation.set(t * 1.4 + i, t * 1.9, 0);
                    rh.dummy.scale.setScalar(0.85 + 0.28 * Math.sin(t * 3.0 + i));
                    rh.dummy.updateMatrix();
                    rh.mesh.setMatrixAt(i, rh.dummy.matrix);
                }
                rh.mesh.instanceMatrix.needsUpdate = true;
            }

            if (this.securityNode) {
                this.securityNode.shieldGroup.position.y = this.securityNode.baseY + Math.sin(t * 1.9) * 0.06;
                this.securityNode.shieldMesh.rotation.y = t * 1.15;
            }

            if (this.gpuLedMatrix) {
                const gm = this.gpuLedMatrix;
                for (let i = 0; i < gm.meta.length; i++) {
                    const m = gm.meta[i];
                    const b = 0.25 + 0.75 * Math.pow(0.5 + 0.5 * Math.sin(t * (3.6 + this.activeTelemetryBoost * 4.0) + m.phase), 2.0);
                    gm.tmpColor.copy(m.baseColor).multiplyScalar(b);
                    gm.mesh.setColorAt(i, gm.tmpColor);
                }
                if (gm.mesh.instanceColor) gm.mesh.instanceColor.needsUpdate = true;
            }

            if (this.relayDrone) {
                const orbitA = t * 0.34;
                this.relayDrone.group.position.set(
                    Math.cos(orbitA) * 5.2,
                    4.25 + Math.sin(t * 2.1) * 0.14,
                    -0.3 + Math.sin(orbitA) * 3.8
                );
                this.relayDrone.group.rotation.y = -orbitA;
                this.relayDrone.rotorA.rotation.z = t * 6.5;
                this.relayDrone.rotorB.rotation.z = -t * 5.2;
            }

            if (now - this._lastScreenRedrawAt > 140) {
                this._lastScreenRedrawAt = now;
                this._screenRedrawTick++;
                if (this._screenRedrawTick % 2 === 0) {
                    if (this.wallTelemetrySurf) {
                        drawBigWallTelemetry(this.wallTelemetrySurf, t, this.activeTelemetryZone, this.activeTelemetryBoost);
                    }
                    if (this.securityTelemetrySurf) {
                        drawSecurityTelemetry(this.securityTelemetrySurf, t, this.activeTelemetryBoost);
                    }
                    this.hubHoloScreens.forEach((hs) => {
                        drawHubHoloScreen(hs.surf, hs.kind, t);
                    });
                } else {
                    this.liveMonitors.forEach((lm) => {
                        const boost = this.activeTelemetryZone === lm.zoneKey ? 1.0 : 0.0;
                        drawAgentLiveChart(lm.surf, lm.chartType, lm.accentCss, t + lm.phase, boost);
                    });
                }
            }

            if (this.hubRobot) {
                this.hubRobot.update(dt, t, this.hubAnimationState, this.hubLookYaw);
            }

            this.zonePods.forEach((pod) => {
                if (pod.chibi) {
                    pod.chibi.update(dt, t, pod.status || 'idle', pod.phaseShift);
                }
                if (pod.holoBadge) {
                    pod.holoBadge.position.y = pod.holoBaseY + Math.sin(t * 2.2 + pod.phaseShift) * 0.040;
                }
                if (pod.holoRings) {
                    pod.holoRings.forEach((hr) => {
                        hr.mesh.rotation.z = t * hr.speed + hr.phase;
                        hr.mesh.position.y = hr.baseY + Math.sin(t * 2.4 + hr.phase) * 0.022;
                    });
                }
                if (pod.holoMotes) {
                    pod.holoMotes.forEach((hm) => {
                        const cycle = (t * 0.36 + hm.phase) % 1.0;
                        const ang = hm.angle + t * 0.9;
                        const r = hm.radius * (1.0 + cycle * 0.65);
                        hm.mesh.position.set(Math.cos(ang) * r, 0.10 + cycle * 0.96, Math.sin(ang) * r);
                        hm.mesh.rotation.y = t * 2.2;
                        const s = Math.sin(cycle * Math.PI);
                        hm.mesh.scale.setScalar(0.35 + s * 0.85);
                    });
                }
            });

            this.circuitPulses.forEach((cp) => {
                if (!cp.active || !cp.curve) return;
                cp.phase = (cp.phase + dt * 1.1) % 1.0;
                const pt = cp.curve.getPointAt(cp.phase);
                cp.orb.position.set(pt.x, pt.y + 0.14 + Math.sin(cp.phase * Math.PI) * 0.14, pt.z);
            });

            if (this.hologramPanel && this.hubRobot) {
                const palmPos = this.hubRobot.getRightHandPalmWorldPos(this._tmpPalmVec);
                const headPos = this.hubRobot.getHeadWorldPos(this._tmpHeadVec);
                this.hologramPanel.update(dt, t, palmPos, headPos, this.camera, 'fullbody');
                if (this.hubVisualPanelActive && !this.hologramPanel.isActive()) {
                    this.hubVisualPanelActive = false;
                }
            }

            this.renderer.render(this.scene, this.camera);
        };
        this.animId = requestAnimationFrame(render);
    }

    dispose() {
        this.isDisposed = true;
        if (this.hologramPanel) this.hologramPanel.dispose();
        if (this.animId) cancelAnimationFrame(this.animId);
        if (this._resizeObserver) this._resizeObserver.disconnect();
        window.removeEventListener('resize', this._onWinResize);
        window.removeEventListener('pointermove', this._onPtrMove);
        window.removeEventListener('pointerup', this._onPtrUp);
        disposeObjectTree(this.envGroup);
        disposeObjectTree(this.hubGroup);
        disposeObjectTree(this.zonesGroup);
        if (this._sharedDeskMats) {
            Object.values(this._sharedDeskMats).forEach((m) => m.dispose());
            this._sharedDeskMats = null;
        }
        if (this.envRT) this.envRT.dispose();
        if (this.renderer) this.renderer.dispose();
    }
}
