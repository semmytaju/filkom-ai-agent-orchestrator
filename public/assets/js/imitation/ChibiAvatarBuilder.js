import { GLTFLoader } from '../../vendor/three/loaders/GLTFLoader.r150.jsm.js?v=20261124v400';

const MODEL_BASE = new URL('../../vendor/models/chibi/', import.meta.url).href;
const gltfLoader = new GLTFLoader();
const geometryCache = new Map();
const extrudeGeoCache = new Map();
const slabGeoCache = new Map();

function loadFirstGeometry(fileName) {
    if (geometryCache.has(fileName)) {
        return geometryCache.get(fileName);
    }
    const p = new Promise((resolve) => {
        gltfLoader.load(
            MODEL_BASE + fileName + '?v=20261124v400',
            (gltf) => {
                let found = null;
                gltf.scene.traverse((obj) => {
                    if (!found && obj.isMesh && obj.geometry) {
                        const geo = obj.geometry;
                        if (!geo.attributes.normal) {
                            geo.computeVertexNormals();
                        }
                        geo.userData._shared = true;
                        found = geo;
                    }
                });
                resolve(found);
            },
            undefined,
            () => resolve(null)
        );
    });
    geometryCache.set(fileName, p);
    return p;
}

export function preloadChibiAssets() {
    return Promise.all([
        loadFirstGeometry('chibi_head.glb'),
        loadFirstGeometry('hair_orchestrator.glb'),
        loadFirstGeometry('hair_kaprodi_f.glb'),
        loadFirstGeometry('hair_secretary_f.glb'),
        loadFirstGeometry('hair_student.glb'),
        loadFirstGeometry('chibi_hand_r.glb'),
        loadFirstGeometry('chibi_suit_torso.glb')
    ]);
}

export function applySrgbTexture(THREE, tex) {
    if (!tex) return tex;
    if ('colorSpace' in tex && THREE.SRGBColorSpace) {
        tex.colorSpace = THREE.SRGBColorSpace;
    } else if ('encoding' in tex && THREE.sRGBEncoding !== undefined) {
        tex.encoding = THREE.sRGBEncoding;
    }
    tex.anisotropy = 2;
    tex.needsUpdate = true;
    return tex;
}

export function createRoundedExtrudeGeo(THREE, width, height, depth, radius = 0.08, bevel = 0.015) {
    const key = `${width}|${height}|${depth}|${radius}|${bevel}`;
    if (extrudeGeoCache.has(key)) {
        return extrudeGeoCache.get(key);
    }
    const hw = width * 0.5;
    const hh = height * 0.5;
    const r = Math.min(radius, hw * 0.45, hh * 0.45);
    const shape = new THREE.Shape();
    shape.moveTo(-hw + r, -hh);
    shape.lineTo(hw - r, -hh);
    shape.quadraticCurveTo(hw, -hh, hw, -hh + r);
    shape.lineTo(hw, hh - r);
    shape.quadraticCurveTo(hw, hh, hw - r, hh);
    shape.lineTo(-hw + r, hh);
    shape.quadraticCurveTo(-hw, hh, -hw, hh - r);
    shape.lineTo(-hw, -hh + r);
    shape.quadraticCurveTo(-hw, -hh, -hw + r, -hh);
    const geo = new THREE.ExtrudeGeometry(shape, {
        depth,
        curveSegments: 6,
        bevelEnabled: bevel > 0,
        bevelThickness: bevel,
        bevelSize: bevel,
        bevelSegments: 2
    });
    geo.center();
    geo.userData._shared = true;
    extrudeGeoCache.set(key, geo);
    return geo;
}

export function createRoundedSlabGeo(THREE, width, depth, thickness, radius = 0.16, bevel = 0.022) {
    const key = `${width}|${depth}|${thickness}|${radius}|${bevel}`;
    if (slabGeoCache.has(key)) {
        return slabGeoCache.get(key);
    }
    const base = createRoundedExtrudeGeo(THREE, width, depth, thickness, radius, bevel);
    const geo = base.clone();
    geo.rotateX(Math.PI / 2);
    geo.userData._shared = true;
    slabGeoCache.set(key, geo);
    return geo;
}

function createHollowRoundedFrameGeo(THREE, outerW, outerH, innerW, innerH, depth, outerR, innerR, bevel = 0.005) {
    const hw = outerW * 0.5;
    const hh = outerH * 0.5;
    const r = Math.min(outerR, hw * 0.45, hh * 0.45);
    const shape = new THREE.Shape();
    shape.moveTo(-hw + r, -hh);
    shape.lineTo(hw - r, -hh);
    shape.quadraticCurveTo(hw, -hh, hw, -hh + r);
    shape.lineTo(hw, hh - r);
    shape.quadraticCurveTo(hw, hh, hw - r, hh);
    shape.lineTo(-hw + r, hh);
    shape.quadraticCurveTo(-hw, hh, -hw, hh - r);
    shape.lineTo(-hw, -hh + r);
    shape.quadraticCurveTo(-hw, -hh, -hw + r, -hh);

    const ihw = innerW * 0.5;
    const ihh = innerH * 0.5;
    const ir = Math.min(innerR, ihw * 0.45, ihh * 0.45);
    const hole = new THREE.Path();
    hole.moveTo(-ihw + ir, -ihh);
    hole.lineTo(ihw - ir, -ihh);
    hole.quadraticCurveTo(ihw, -ihh, ihw, -ihh + ir);
    hole.lineTo(ihw, ihh - ir);
    hole.quadraticCurveTo(ihw, ihh, ihw - ir, ihh);
    hole.lineTo(-ihw + ir, ihh);
    hole.quadraticCurveTo(-ihw, ihh, -ihw, ihh - ir);
    hole.lineTo(-ihw, -ihh + ir);
    hole.quadraticCurveTo(-ihw, -ihh, -ihw + ir, -ihh);
    shape.holes.push(hole);

    const geo = new THREE.ExtrudeGeometry(shape, {
        depth,
        bevelEnabled: bevel > 0,
        bevelThickness: bevel,
        bevelSize: bevel,
        bevelSegments: 4
    });
    geo.center();
    return geo;
}

function createBlushTexture(THREE) {
    const c = document.createElement('canvas');
    c.width = 128;
    c.height = 128;
    const ctx = c.getContext('2d');
    const g = ctx.createRadialGradient(64, 64, 6, 64, 64, 58);
    g.addColorStop(0.0, 'rgba(244, 124, 124, 0.72)');
    g.addColorStop(0.55, 'rgba(244, 138, 138, 0.30)');
    g.addColorStop(1.0, 'rgba(244, 138, 138, 0.0)');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, 128, 128);
    return applySrgbTexture(THREE, new THREE.CanvasTexture(c));
}

function makeMat(THREE, color, roughness = 0.42, metalness = 0.06, extra = {}) {
    return new THREE.MeshStandardMaterial({ color, roughness, metalness, ...extra });
}

const PERSONA_STYLES = {
    dean: {
        hairMode: 'glb',
        hairGlb: 'hair_orchestrator.glb',
        hairTint: 0xcbd5e1,
        hairSheen: 0x94a3b8,
        browColor: 0x334155,
        irisAccent: 0x1d4ed8,
        skinColor: 0xf6c3a1,
        earInnerColor: 0xe29078,
        gender: 'male',
        openSmile: true,
        glasses: 'gold_rim',
        outfit: 'suit_tie',
        jacketColor: 0x162f68,
        shirtColor: 0xffffff,
        tieColor: 0xdc2626,
        pantsColor: 0x1e293b,
        shoeColor: 0x451a03,
        pinColor: 0xf59e0b,
        jobAnim: 'executive_review'
    },
    kaprodi_f: {
        hairMode: 'glb',
        hairGlb: 'hair_kaprodi_f.glb',
        hairTint: 0xffffff,
        hairSheen: 0x38bdf8,
        browColor: 0x16244b,
        irisAccent: 0x2563eb,
        skinColor: 0xfad0b4,
        earInnerColor: 0xe99d88,
        gender: 'female',
        openSmile: true,
        glasses: 'wayfarer',
        outfit: 'female_blazer',
        jacketColor: 0x0284c7,
        shirtColor: 0xfffbeb,
        tieColor: 0xfbbf24,
        pantsColor: 0x172554,
        shoeColor: 0x0f172a,
        pinColor: 0x38bdf8,
        jobAnim: 'academic_scheduling'
    },
    secretary_f: {
        hairMode: 'glb',
        hairGlb: 'hair_secretary_f.glb',
        hairTint: 0xffffff,
        hairSheen: 0xf59e0b,
        browColor: 0x522e1b,
        irisAccent: 0x0d9488,
        skinColor: 0xfbd2b6,
        earInnerColor: 0xe99e8a,
        gender: 'female',
        openSmile: true,
        glasses: 'none',
        outfit: 'female_vest_pants',
        jacketColor: 0xe11d48,
        shirtColor: 0xffffff,
        tieColor: 0x10b981,
        pantsColor: 0xd97706,
        shoeColor: 0xf8fafc,
        pinColor: 0xfbbf24,
        jobAnim: 'admin_dispatch'
    },
    upmf: {
        hairMode: 'procedural',
        hairStyle: 'neat_quiff',
        hairBaseColor: 0x2c1e18,
        hairSheen: 0xa78bfa,
        browColor: 0x2c1e18,
        irisAccent: 0x7c3aed,
        skinColor: 0xf7c6a6,
        earInnerColor: 0xe4927a,
        gender: 'male',
        openSmile: false,
        glasses: 'wayfarer',
        outfit: 'sweater_vest',
        jacketColor: 0x6d28d9,
        shirtColor: 0xe0f2fe,
        tieColor: 0xc084fc,
        pantsColor: 0x334155,
        shoeColor: 0x292524,
        pinColor: 0x8b5cf6,
        jobAnim: 'quality_audit'
    },
    research: {
        hairMode: 'procedural',
        hairStyle: 'tousled_scholar',
        hairBaseColor: 0x111a2e,
        hairSheen: 0x38bdf8,
        browColor: 0x111a2e,
        irisAccent: 0x0284c7,
        skinColor: 0xf8c8a9,
        earInnerColor: 0xe5947d,
        gender: 'male',
        openSmile: false,
        glasses: 'round',
        outfit: 'lab_coat',
        jacketColor: 0xf8fafc,
        shirtColor: 0x0891b2,
        tieColor: 0x0e7490,
        pantsColor: 0x1e3a5f,
        shoeColor: 0x2563eb,
        pinColor: 0x38bdf8,
        jobAnim: 'lab_research'
    },
    student: {
        hairMode: 'glb',
        hairGlb: 'hair_student.glb',
        hairTint: 0xffffff,
        hairSheen: 0xfb923c,
        browColor: 0x5c3319,
        irisAccent: 0x0891b2,
        skinColor: 0xf9caa8,
        earInnerColor: 0xe79780,
        gender: 'male',
        openSmile: true,
        glasses: 'none',
        outfit: 'hoodie',
        jacketColor: 0xf97316,
        shirtColor: 0xffffff,
        tieColor: 0xffffff,
        pantsColor: 0x1d4ed8,
        shoeColor: 0x06b6d4,
        pinColor: 0x06b6d4,
        jobAnim: 'student_study'
    }
};

function buildBespokeHair(THREE, kind, baseColor, sheenColor) {
    const g = new THREE.Group();
    const mat = new THREE.MeshPhysicalMaterial({
        color: baseColor,
        roughness: 0.34,
        metalness: 0.08,
        clearcoat: 0.28,
        clearcoatRoughness: 0.30,
        sheen: 0.55,
        sheenColor: new THREE.Color(sheenColor)
    });

    const cap = new THREE.Mesh(
        new THREE.SphereGeometry(0.272, 26, 20, 0, Math.PI * 2, 0, Math.PI * 0.62),
        mat
    );
    cap.scale.set(1.06, 0.94, 1.00);
    cap.position.set(0, 0.036, -0.014);
    cap.castShadow = true;
    g.add(cap);

    const backCrown = new THREE.Mesh(new THREE.SphereGeometry(0.258, 22, 18), mat);
    backCrown.scale.set(1.02, 0.92, 0.86);
    backCrown.position.set(0, 0.010, -0.072);
    backCrown.castShadow = true;
    g.add(backCrown);

    if (kind === 'neat_quiff') {
        const quiffSpecs = [
            { x: -0.10, y: 0.225, z: 0.135, sx: 0.16, sy: 0.095, sz: 0.13, rz: 0.35, rx: -0.25 },
            { x: 0.03, y: 0.255, z: 0.115, sx: 0.18, sy: 0.105, sz: 0.14, rz: 0.18, rx: -0.22 },
            { x: 0.15, y: 0.225, z: 0.075, sx: 0.13, sy: 0.085, sz: 0.12, rz: -0.28, rx: -0.12 },
            { x: -0.18, y: 0.165, z: 0.105, sx: 0.10, sy: 0.085, sz: 0.11, rz: 0.55, rx: 0.0 }
        ];
        quiffSpecs.forEach((q) => {
            const tuft = new THREE.Mesh(new THREE.SphereGeometry(1, 18, 14), mat);
            tuft.scale.set(q.sx, q.sy, q.sz);
            tuft.position.set(q.x, q.y, q.z);
            tuft.rotation.set(q.rx, 0, q.rz);
            tuft.castShadow = true;
            g.add(tuft);
        });
        [-1, 1].forEach((sx) => {
            const sideburn = new THREE.Mesh(new THREE.CapsuleGeometry(0.032, 0.11, 6, 10), mat);
            sideburn.position.set(sx * 0.255, 0.035, 0.068);
            sideburn.rotation.z = sx * 0.08;
            g.add(sideburn);
        });
    } else {
        const waveSpecs = [
            { x: -0.16, y: 0.23, z: 0.10, r: 0.115, sx: 1.25, sy: 0.85, sz: 1.15, rz: 0.35 },
            { x: -0.02, y: 0.275, z: 0.08, r: 0.128, sx: 1.30, sy: 0.88, sz: 1.20, rz: 0.10 },
            { x: 0.14, y: 0.24, z: 0.09, r: 0.118, sx: 1.20, sy: 0.86, sz: 1.15, rz: -0.32 },
            { x: -0.11, y: 0.175, z: 0.195, r: 0.072, sx: 1.25, sy: 0.72, sz: 0.95, rz: 0.48 },
            { x: 0.06, y: 0.185, z: 0.205, r: 0.076, sx: 1.35, sy: 0.72, sz: 0.95, rz: -0.28 },
            { x: 0.20, y: 0.145, z: 0.125, r: 0.082, sx: 0.95, sy: 1.20, sz: 1.00, rz: -0.42 },
            { x: -0.22, y: 0.135, z: 0.115, r: 0.082, sx: 0.95, sy: 1.18, sz: 1.00, rz: 0.42 },
            { x: 0.0, y: 0.24, z: -0.15, r: 0.14, sx: 1.45, sy: 0.95, sz: 1.10, rz: 0 }
        ];
        waveSpecs.forEach((w) => {
            const lock = new THREE.Mesh(new THREE.SphereGeometry(w.r, 16, 14), mat);
            lock.scale.set(w.sx, w.sy, w.sz);
            lock.position.set(w.x, w.y, w.z);
            lock.rotation.z = w.rz;
            lock.castShadow = true;
            g.add(lock);
        });
    }

    return g;
}

function buildErgonomicChair(THREE, accentHex = 0x2563eb) {
    const chair = new THREE.Group();
    const shellWhite = makeMat(THREE, 0xf8fafc, 0.26, 0.14);
    const cushionBlue = makeMat(THREE, accentHex, 0.44, 0.12);
    const chrome = makeMat(THREE, 0x94a3b8, 0.20, 0.76);

    const stem = new THREE.Mesh(new THREE.CylinderGeometry(0.034, 0.040, 0.29, 14), chrome);
    stem.position.set(0, 0.145, -0.05);
    chair.add(stem);

    for (let i = 0; i < 5; i++) {
        const a = (i / 5) * Math.PI * 2;
        const spoke = new THREE.Mesh(new THREE.BoxGeometry(0.032, 0.024, 0.24), chrome);
        spoke.position.set(Math.sin(a) * 0.11, 0.026, -0.05 + Math.cos(a) * 0.11);
        spoke.rotation.y = a;
        chair.add(spoke);
    }

    const seat = new THREE.Mesh(createRoundedSlabGeo(THREE, 0.48, 0.42, 0.065, 0.12, 0.016), cushionBlue);
    seat.position.set(0, 0.295, -0.04);
    seat.castShadow = true;
    chair.add(seat);

    const backShell = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.50, 0.54, 0.055, 0.16, 0.018), shellWhite);
    backShell.position.set(0, 0.585, -0.25);
    backShell.rotation.x = -0.08;
    backShell.castShadow = true;
    chair.add(backShell);

    const backPad = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.42, 0.44, 0.030, 0.12, 0.012), cushionBlue);
    backPad.position.set(0, 0.585, -0.215);
    backPad.rotation.x = -0.08;
    chair.add(backPad);

    return chair;
}

function buildGlasses(THREE, kind) {
    const g = new THREE.Group();
    const isRound = kind === 'round';
    const isGold = kind === 'gold_rim';
    const frameMat = new THREE.MeshPhysicalMaterial({
        color: isGold ? 0xd97706 : 0x111722,
        roughness: isGold ? 0.18 : 0.22,
        metalness: isGold ? 0.82 : 0.18,
        clearcoat: 0.55
    });
    const lensMat = new THREE.MeshPhysicalMaterial({
        color: 0xe0f2fe,
        roughness: 0.05,
        metalness: 0.1,
        transparent: true,
        opacity: 0.12,
        clearcoat: 1.0
    });

    if (isRound) {
        const ringGeo = new THREE.TorusGeometry(0.084, 0.011, 10, 28);
        const lensGeo = new THREE.CircleGeometry(0.076, 24);
        [-0.112, 0.112].forEach((ex) => {
            const rim = new THREE.Mesh(ringGeo, frameMat);
            rim.position.set(ex, -0.010, 0.274);
            rim.rotation.y = ex < 0 ? -0.08 : 0.08;
            g.add(rim);
            const lens = new THREE.Mesh(lensGeo, lensMat);
            lens.position.set(ex, -0.010, 0.272);
            lens.rotation.y = ex < 0 ? -0.08 : 0.08;
            g.add(lens);
        });
    } else {
        const frameGeo = createHollowRoundedFrameGeo(THREE, 0.214, 0.168, 0.168, 0.122, 0.018, 0.044, 0.025, 0.005);
        const lensGeo = createRoundedExtrudeGeo(THREE, 0.168, 0.122, 0.004, 0.022, 0);
        [-0.114, 0.114].forEach((ex) => {
            const rim = new THREE.Mesh(frameGeo, frameMat);
            rim.position.set(ex, -0.010, 0.274);
            rim.rotation.y = ex < 0 ? -0.08 : 0.08;
            g.add(rim);
            const lens = new THREE.Mesh(lensGeo, lensMat);
            lens.position.set(ex, -0.010, 0.272);
            lens.rotation.y = ex < 0 ? -0.08 : 0.08;
            g.add(lens);
        });
    }

    const bridge = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.052, 0.020, 0.016, 0.007, 0.003), frameMat);
    bridge.position.set(0, 0.012, 0.278);
    g.add(bridge);

    [-0.220, 0.220].forEach((tx) => {
        const arm = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.014, 0.018, 0.28, 0.004, 0.002), frameMat);
        arm.position.set(tx + (tx < 0 ? -0.014 : 0.014), 0.016, 0.132);
        arm.rotation.y = tx < 0 ? 0.12 : -0.12;
        g.add(arm);
    });

    return g;
}

function buildPhysical3DFace(THREE, style, skinMat) {
    const faceGroup = new THREE.Group();
    const earInnerMat = makeMat(THREE, style.earInnerColor, 0.50, 0.02);
    const whiteScleraMat = new THREE.MeshStandardMaterial({
        color: 0xffffff,
        emissive: 0xffffff,
        emissiveIntensity: 0.22,
        roughness: 0.16,
        metalness: 0.0
    });
    const pupilMat = new THREE.MeshPhysicalMaterial({
        color: 0x090f1d,
        roughness: 0.05,
        metalness: 0.05,
        clearcoat: 1.0,
        clearcoatRoughness: 0.05
    });
    const irisTintMat = makeMat(THREE, style.irisAccent, 0.15, 0.05, { emissive: style.irisAccent, emissiveIntensity: 0.42 });
    const catchMat = new THREE.MeshBasicMaterial({ color: 0xffffff });
    const lashMat = makeMat(THREE, 0x0f172a, 0.28, 0.05);
    const browMat = makeMat(THREE, style.browColor, 0.36, 0.05);

    const earGeoOuter = new THREE.SphereGeometry(0.062, 18, 14);
    const earGeoInner = new THREE.SphereGeometry(0.040, 14, 12);
    [-1, 1].forEach((sx) => {
        const earWrap = new THREE.Group();
        earWrap.position.set(sx * 0.296, -0.048, -0.004);

        const outerEar = new THREE.Mesh(earGeoOuter, skinMat);
        outerEar.scale.set(0.76, 1.24, 0.90);
        outerEar.castShadow = true;
        earWrap.add(outerEar);

        const innerEar = new THREE.Mesh(earGeoInner, earInnerMat);
        innerEar.scale.set(0.56, 0.96, 0.52);
        innerEar.position.set(sx * 0.015, -0.004, 0.034);
        earWrap.add(innerEar);

        if (style.gender === 'female') {
            const stud = new THREE.Mesh(
                new THREE.SphereGeometry(0.018, 12, 10),
                makeMat(THREE, style.pinColor === 0x38bdf8 ? 0xf8fafc : 0xfbbf24, 0.15, 0.72)
            );
            stud.position.set(sx * 0.028, -0.062, 0.024);
            earWrap.add(stud);
        }
        faceGroup.add(earWrap);
    });

    const blushTex = createBlushTexture(THREE);
    const blushMat = new THREE.MeshBasicMaterial({
        map: blushTex,
        transparent: true,
        opacity: 0.62,
        depthWrite: false
    });
    const blinkUnits = [];

    [-1, 1].forEach((sx) => {
        const eyeAnchor = new THREE.Group();
        eyeAnchor.position.set(sx * 0.104, -0.010, 0.230);
        eyeAnchor.rotation.y = sx * 0.12;
        faceGroup.add(eyeAnchor);

        const eyeContent = new THREE.Group();
        eyeAnchor.add(eyeContent);

        const sclera = new THREE.Mesh(new THREE.SphereGeometry(0.054, 22, 18), whiteScleraMat);
        sclera.scale.set(0.95, 1.14, 0.30);
        eyeContent.add(sclera);

        const upperLash = new THREE.Mesh(new THREE.TorusGeometry(0.052, 0.0082, 8, 22, Math.PI * 0.96), lashMat);
        upperLash.scale.set(0.96, 1.16, 1.0);
        upperLash.position.set(0, 0.002, 0.014);
        upperLash.rotation.z = Math.PI * 0.02;
        eyeContent.add(upperLash);

        if (style.gender === 'female') {
            const wing = new THREE.Mesh(new THREE.CapsuleGeometry(0.006, 0.026, 4, 8), lashMat);
            wing.position.set(sx * 0.052, 0.024, 0.014);
            wing.rotation.z = sx < 0 ? 0.65 : -0.65;
            eyeContent.add(wing);
        }

        const pupil = new THREE.Mesh(new THREE.SphereGeometry(0.035, 20, 16), pupilMat);
        pupil.scale.set(0.84, 1.24, 0.34);
        pupil.position.set(0, 0.0, 0.013);
        eyeContent.add(pupil);

        const irisGlow = new THREE.Mesh(new THREE.SphereGeometry(0.023, 14, 12), irisTintMat);
        irisGlow.scale.set(0.86, 0.66, 0.26);
        irisGlow.position.set(0, -0.016, 0.019);
        eyeContent.add(irisGlow);

        const glintMain = new THREE.Mesh(new THREE.SphereGeometry(0.012, 12, 10), catchMat);
        glintMain.scale.set(0.92, 1.18, 0.55);
        glintMain.position.set(-0.011, 0.018, 0.023);
        eyeContent.add(glintMain);

        const glintSub = new THREE.Mesh(new THREE.SphereGeometry(0.0055, 8, 8), catchMat);
        glintSub.position.set(0.011, -0.014, 0.023);
        eyeContent.add(glintSub);

        const lidShield = new THREE.Mesh(new THREE.SphereGeometry(0.056, 18, 14), skinMat);
        lidShield.scale.set(1.04, 0.02, 0.30);
        lidShield.position.set(0, 0.052, 0.024);
        lidShield.visible = false;
        eyeAnchor.add(lidShield);

        const closedCrease = new THREE.Mesh(new THREE.TorusGeometry(0.048, 0.0085, 8, 22, Math.PI * 0.88), lashMat);
        closedCrease.position.set(0, 0.018, 0.032);
        closedCrease.rotation.z = Math.PI * 1.06;
        closedCrease.visible = false;
        eyeAnchor.add(closedCrease);

        blinkUnits.push({ eyeContent, lidShield, closedCrease });

        const brow = new THREE.Mesh(
            createRoundedExtrudeGeo(THREE, style.gender === 'female' ? 0.090 : 0.106, style.gender === 'female' ? 0.019 : 0.027, 0.016, 0.010, 0.004),
            browMat
        );
        brow.position.set(sx * 0.108, 0.086, 0.238);
        brow.rotation.y = sx * 0.12;
        brow.rotation.z = sx < 0 ? 0.08 : -0.08;
        faceGroup.add(brow);

        const blush = new THREE.Mesh(new THREE.PlaneGeometry(0.098, 0.058), blushMat);
        blush.position.set(sx * 0.165, -0.072, 0.226);
        blush.rotation.y = sx * 0.34;
        faceGroup.add(blush);
    });

    const nose = new THREE.Mesh(new THREE.SphereGeometry(0.019, 14, 12), makeMat(THREE, 0xf2b290, 0.46, 0.02));
    nose.scale.set(1.18, 0.84, 0.85);
    nose.position.set(0, -0.044, 0.242);
    faceGroup.add(nose);

    const mouthGroup = new THREE.Group();
    mouthGroup.position.set(0, -0.108, 0.233);
    faceGroup.add(mouthGroup);

    const lipOutlineMat = makeMat(THREE, 0x992b2d, 0.34, 0.04);
    const mouthDarkMat = makeMat(THREE, 0x7b1826, 0.38, 0.02);
    const tongueMat = makeMat(THREE, 0xfb7185, 0.40, 0.02);

    if (style.openSmile) {
        const mouthShape = new THREE.Shape();
        mouthShape.moveTo(-0.046, 0.015);
        mouthShape.quadraticCurveTo(0, 0.004, 0.046, 0.015);
        mouthShape.quadraticCurveTo(0.038, -0.034, 0, -0.034);
        mouthShape.quadraticCurveTo(-0.038, -0.034, -0.046, 0.015);
        const mouthGeo = new THREE.ExtrudeGeometry(mouthShape, { depth: 0.011, bevelEnabled: true, bevelThickness: 0.003, bevelSize: 0.002, bevelSegments: 2 });
        mouthGeo.center();
        const cavity = new THREE.Mesh(mouthGeo, mouthDarkMat);
        mouthGroup.add(cavity);

        const tongue = new THREE.Mesh(new THREE.SphereGeometry(0.025, 14, 10), tongueMat);
        tongue.scale.set(1.26, 0.60, 0.48);
        tongue.position.set(0, -0.012, 0.007);
        mouthGroup.add(tongue);

        const upperLip = new THREE.Mesh(new THREE.TorusGeometry(0.046, 0.0048, 8, 16, Math.PI * 0.72), lipOutlineMat);
        upperLip.position.set(0, 0.038, 0.008);
        upperLip.rotation.z = Math.PI * 1.14;
        mouthGroup.add(upperLip);
    } else {
        const smileArc = new THREE.Mesh(new THREE.TorusGeometry(0.038, 0.0068, 8, 18, Math.PI * 0.76), lipOutlineMat);
        smileArc.position.set(0, 0.022, 0.008);
        smileArc.rotation.z = Math.PI * 1.12;
        mouthGroup.add(smileArc);
    }

    return { faceGroup, blinkUnits, mouthGroup };
}

function buildHandProp(THREE, kind) {
    const g = new THREE.Group();
    if (kind === 'tablet') {
        const frame = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.22, 0.16, 0.014, 0.018, 0.003), makeMat(THREE, 0x0f172a, 0.25, 0.45));
        const scr = new THREE.Mesh(new THREE.PlaneGeometry(0.19, 0.13), new THREE.MeshBasicMaterial({ color: 0x38bdf8 }));
        scr.position.z = 0.009;
        g.add(frame, scr);
    } else if (kind === 'clipboard') {
        const board = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.20, 0.25, 0.012, 0.015, 0.003), makeMat(THREE, 0x7c3aed, 0.4, 0.15));
        const paper = new THREE.Mesh(new THREE.BoxGeometry(0.17, 0.21, 0.006), makeMat(THREE, 0xffffff, 0.5, 0.02));
        paper.position.z = 0.008;
        const clip = new THREE.Mesh(new THREE.BoxGeometry(0.07, 0.024, 0.016), makeMat(THREE, 0xcbd5e1, 0.2, 0.7));
        clip.position.set(0, 0.115, 0.010);
        g.add(board, paper, clip);
    } else if (kind === 'pen') {
        const body = new THREE.Mesh(new THREE.CylinderGeometry(0.009, 0.009, 0.16, 10), makeMat(THREE, 0x1d4ed8, 0.3, 0.3));
        const tip = new THREE.Mesh(new THREE.ConeGeometry(0.009, 0.026, 10), makeMat(THREE, 0x0f172a, 0.3, 0.4));
        tip.position.y = -0.092;
        tip.rotation.x = Math.PI;
        g.add(body, tip);
    } else if (kind === 'flask') {
        const bulb = new THREE.Mesh(
            new THREE.ConeGeometry(0.055, 0.11, 14),
            new THREE.MeshPhysicalMaterial({ color: 0x38bdf8, emissive: 0x0ea5e9, emissiveIntensity: 0.7, roughness: 0.1, transparent: true, opacity: 0.85 })
        );
        const neck = new THREE.Mesh(new THREE.CylinderGeometry(0.016, 0.018, 0.055, 12), makeMat(THREE, 0xe0f2fe, 0.1, 0.1, { transparent: true, opacity: 0.7 }));
        neck.position.y = 0.072;
        g.add(bulb, neck);
    } else if (kind === 'folder') {
        const f = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.20, 0.15, 0.018, 0.014, 0.003), makeMat(THREE, 0x10b981, 0.45, 0.1));
        g.add(f);
    }
    return g;
}

export function createOrchestratorRobot(THREE) {
    const root = new THREE.Group();

    const whiteGloss = new THREE.MeshPhysicalMaterial({
        color: 0xffffff,
        roughness: 0.14,
        metalness: 0.10,
        clearcoat: 1.0,
        clearcoatRoughness: 0.08
    });
    const royalBlue = new THREE.MeshPhysicalMaterial({
        color: 0x1d4ed8,
        roughness: 0.22,
        metalness: 0.22,
        clearcoat: 0.78,
        clearcoatRoughness: 0.14
    });
    const brightBlue = new THREE.MeshPhysicalMaterial({
        color: 0x2563eb,
        roughness: 0.20,
        metalness: 0.24,
        clearcoat: 0.80
    });
    const darkJoint = makeMat(THREE, 0x1e293b, 0.34, 0.45);
    const cyanGlow = new THREE.MeshStandardMaterial({
        color: 0x7dd3fc,
        emissive: 0x00d4ff,
        emissiveIntensity: 2.0,
        roughness: 0.12
    });
    const visorBlack = new THREE.MeshPhysicalMaterial({
        color: 0x050b18,
        roughness: 0.04,
        metalness: 0.22,
        clearcoat: 1.0,
        clearcoatRoughness: 0.04
    });

    const hoverRoot = new THREE.Group();
    hoverRoot.position.y = 0.46;
    root.add(hoverRoot);

    const thrusterBase = new THREE.Mesh(new THREE.CylinderGeometry(0.18, 0.12, 0.14, 24), royalBlue);
    thrusterBase.position.y = 0.07;
    hoverRoot.add(thrusterBase);

    const thrusterGlow = new THREE.Mesh(new THREE.TorusGeometry(0.15, 0.022, 10, 32), cyanGlow);
    thrusterGlow.rotation.x = Math.PI / 2;
    thrusterGlow.position.y = 0.01;
    hoverRoot.add(thrusterGlow);

    const torsoGroup = new THREE.Group();
    torsoGroup.position.y = 0.16;
    hoverRoot.add(torsoGroup);

    const torsoShell = new THREE.Mesh(new THREE.SphereGeometry(0.38, 32, 26), whiteGloss);
    torsoShell.scale.set(1.04, 1.12, 0.86);
    torsoShell.position.y = 0.36;
    torsoShell.castShadow = true;
    torsoGroup.add(torsoShell);

    const waistRing = new THREE.Mesh(new THREE.CylinderGeometry(0.28, 0.22, 0.14, 28), royalBlue);
    waistRing.position.y = 0.06;
    torsoGroup.add(waistRing);

    const chestPlate = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.44, 0.32, 0.08, 0.10, 0.02), brightBlue);
    chestPlate.position.set(0, 0.40, 0.29);
    torsoGroup.add(chestPlate);

    const chestScreenTex = (() => {
        const c = document.createElement('canvas');
        c.width = 256;
        c.height = 128;
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#0c4a6e';
        ctx.fillRect(0, 0, 256, 128);
        ctx.fillStyle = '#38bdf8';
        ctx.fillRect(24, 34, 62, 18);
        ctx.fillRect(24, 64, 104, 14);
        ctx.fillStyle = '#e0f2fe';
        ctx.beginPath();
        ctx.arc(198, 64, 26, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = '#0284c7';
        ctx.beginPath();
        ctx.arc(198, 64, 14, 0, Math.PI * 2);
        ctx.fill();
        return applySrgbTexture(THREE, new THREE.CanvasTexture(c));
    })();
    const chestScreen = new THREE.Mesh(
        new THREE.PlaneGeometry(0.28, 0.14),
        new THREE.MeshBasicMaterial({ map: chestScreenTex })
    );
    chestScreen.position.set(0, 0.41, 0.352);
    torsoGroup.add(chestScreen);

    const bellyBadge = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.14, 0.065, 0.02, 0.02, 0.005), cyanGlow);
    bellyBadge.position.set(-0.08, 0.20, 0.31);
    torsoGroup.add(bellyBadge);

    const neck = new THREE.Mesh(new THREE.CylinderGeometry(0.11, 0.14, 0.12, 20), darkJoint);
    neck.position.y = 0.78;
    torsoGroup.add(neck);

    const headPivot = new THREE.Group();
    headPivot.position.set(0, 0.84, 0);
    torsoGroup.add(headPivot);

    const headShell = new THREE.Mesh(new THREE.SphereGeometry(0.42, 36, 28), whiteGloss);
    headShell.scale.set(1.16, 0.92, 0.98);
    headShell.position.y = 0.32;
    headShell.castShadow = true;
    headPivot.add(headShell);

    const topHalo = new THREE.Mesh(new THREE.TorusGeometry(0.11, 0.022, 12, 28), cyanGlow);
    topHalo.rotation.x = Math.PI / 2;
    topHalo.position.set(0, 0.70, 0);
    headPivot.add(topHalo);

    const visor = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.62, 0.36, 0.12, 0.14, 0.025), visorBlack);
    visor.position.set(0, 0.31, 0.34);
    headPivot.add(visor);

    const eyeGlowMat = new THREE.MeshBasicMaterial({ color: 0xe0f2fe });
    const eyes = [];
    [-0.145, 0.145].forEach((ex) => {
        const eye = new THREE.Mesh(new THREE.CapsuleGeometry(0.044, 0.055, 8, 14), eyeGlowMat);
        eye.position.set(ex, 0.34, 0.425);
        headPivot.add(eye);
        eyes.push(eye);
    });

    const smileMesh = new THREE.Mesh(
        new THREE.TorusGeometry(0.058, 0.011, 8, 20, Math.PI * 0.78),
        new THREE.MeshBasicMaterial({ color: 0x38bdf8 })
    );
    smileMesh.position.set(0, 0.255, 0.426);
    smileMesh.rotation.z = Math.PI * 1.11;
    headPivot.add(smileMesh);

    [-1, 1].forEach((sx) => {
        const earCup = new THREE.Mesh(new THREE.CylinderGeometry(0.17, 0.18, 0.10, 24), royalBlue);
        earCup.rotation.z = Math.PI / 2;
        earCup.position.set(sx * 0.47, 0.32, 0.0);
        earCup.castShadow = true;
        headPivot.add(earCup);

        const earRing = new THREE.Mesh(new THREE.TorusGeometry(0.10, 0.018, 10, 24), whiteGloss);
        earRing.rotation.y = Math.PI / 2;
        earRing.position.set(sx * 0.525, 0.32, 0.0);
        headPivot.add(earRing);

        const earCore = new THREE.Mesh(new THREE.CylinderGeometry(0.055, 0.055, 0.02, 16), cyanGlow);
        earCore.rotation.z = Math.PI / 2;
        earCore.position.set(sx * 0.528, 0.32, 0.0);
        headPivot.add(earCore);
    });

    const makeBotArm = (sideSign) => {
        const shoulder = new THREE.Group();
        shoulder.position.set(sideSign * 0.43, 0.56, 0.02);
        torsoGroup.add(shoulder);

        const sBall = new THREE.Mesh(new THREE.SphereGeometry(0.115, 18, 16), royalBlue);
        shoulder.add(sBall);

        const upper = new THREE.Mesh(new THREE.CapsuleGeometry(0.082, 0.18, 8, 16), whiteGloss);
        upper.position.y = -0.14;
        upper.castShadow = true;
        shoulder.add(upper);

        const elbow = new THREE.Group();
        elbow.position.set(0, -0.27, 0);
        shoulder.add(elbow);

        const eJoint = new THREE.Mesh(new THREE.SphereGeometry(0.072, 14, 12), darkJoint);
        elbow.add(eJoint);

        const fore = new THREE.Mesh(new THREE.CapsuleGeometry(0.086, 0.16, 8, 16), brightBlue);
        fore.position.y = -0.13;
        fore.castShadow = true;
        elbow.add(fore);

        const cuff = new THREE.Mesh(new THREE.CylinderGeometry(0.088, 0.076, 0.07, 16), whiteGloss);
        cuff.position.y = -0.24;
        elbow.add(cuff);

        const hand = new THREE.Group();
        hand.position.set(0, -0.30, 0);
        elbow.add(hand);

        const palm = new THREE.Mesh(new THREE.SphereGeometry(0.074, 14, 12), darkJoint);
        palm.scale.set(1.0, 0.9, 0.65);
        hand.add(palm);

        for (let f = -1; f <= 1; f++) {
            const finger = new THREE.Mesh(new THREE.CapsuleGeometry(0.016, 0.065, 4, 8), whiteGloss);
            finger.position.set(f * 0.036, -0.075, 0.01);
            hand.add(finger);
        }
        const thumb = new THREE.Mesh(new THREE.CapsuleGeometry(0.017, 0.050, 4, 8), whiteGloss);
        thumb.position.set(-sideSign * 0.068, -0.025, 0.025);
        thumb.rotation.z = sideSign * 0.72;
        hand.add(thumb);

        return { shoulder, elbow, hand };
    };

    const rightArm = makeBotArm(-1);
    const leftArm = makeBotArm(1);

    let blinkTimer = 1.6;
    let blinkProg = 0;

    return {
        root,
        hoverRoot,
        torsoGroup,
        headPivot,
        leftArm,
        rightArm,
        getRightHandPalmWorldPos(out = new THREE.Vector3()) {
            rightArm.hand.getWorldPosition(out);
            return out;
        },
        getHeadWorldPos(out = new THREE.Vector3()) {
            headPivot.getWorldPosition(out);
            return out;
        },
        update(dt, t, state = 'idle', lookYaw = 0) {
            blinkTimer -= dt;
            if (blinkTimer <= 0 && blinkProg === 0) {
                blinkProg = 0.001;
                blinkTimer = Math.random() < 0.25 ? 0.38 : (2.0 + Math.random() * 1.6);
            }
            let eyeScaleY = 1.0;
            if (blinkProg > 0) {
                blinkProg += dt * 7.2;
                const amt = blinkProg <= 0.85 ? (blinkProg / 0.85) : (blinkProg <= 1.2 ? 1.0 : Math.max(0, (2.05 - blinkProg) / 0.85));
                eyeScaleY = Math.max(0.06, 1.0 - amt * 0.94);
                if (blinkProg >= 2.05) blinkProg = 0;
            }
            eyes.forEach((e) => {
                e.scale.y = eyeScaleY;
                e.scale.x = 1.0 + (1.0 - eyeScaleY) * 0.28;
            });

            hoverRoot.position.y = 0.48 + Math.sin(t * 2.4) * 0.065;
            thrusterGlow.scale.setScalar(1.0 + Math.sin(t * 6.0) * 0.06);

            const s = String(state || 'idle').toLowerCase();
            const targetYaw = lookYaw || Math.sin(t * 0.55) * 0.24;
            torsoGroup.rotation.y += (targetYaw * 0.55 - torsoGroup.rotation.y) * Math.min(1, dt * 4.0);
            headPivot.rotation.y += (targetYaw * 0.45 - headPivot.rotation.y) * Math.min(1, dt * 5.0);
            headPivot.rotation.z = Math.sin(t * 1.1) * 0.05;

            if (s === 'working' || s === 'routing' || s === 'thinking' || s === 'searching') {
                rightArm.shoulder.rotation.set(-0.75, 0.15, -0.55 + Math.sin(t * 4.5) * 0.14);
                rightArm.elbow.rotation.set(-0.65, 0, -0.35);
                leftArm.shoulder.rotation.set(-0.75, -0.15, 0.55 + Math.cos(t * 4.5) * 0.14);
                leftArm.elbow.rotation.set(-0.65, 0, 0.35);
                smileMesh.scale.setScalar(1.08 + Math.sin(t * 8.0) * 0.08);
            } else if (s === 'collaborating' || s === 'success') {
                rightArm.shoulder.rotation.set(-0.35, 0.1, -1.05 + Math.sin(t * 6.5) * 0.22);
                rightArm.elbow.rotation.set(-0.45, 0, -0.85);
                leftArm.shoulder.rotation.set(-0.35, -0.1, 1.05 - Math.sin(t * 6.5) * 0.22);
                leftArm.elbow.rotation.set(-0.45, 0, 0.85);
                smileMesh.scale.setScalar(1.22);
            } else {
                rightArm.shoulder.rotation.set(-0.42, 0.18, -0.78 + Math.sin(t * 2.2) * 0.12);
                rightArm.elbow.rotation.set(-0.55 + Math.sin(t * 2.2) * 0.12, 0, -0.25);
                leftArm.shoulder.rotation.set(-0.22, -0.10, 0.42 + Math.sin(t * 1.8) * 0.08);
                leftArm.elbow.rotation.set(-0.48, 0, 0.22);
                smileMesh.scale.setScalar(1.0);
            }
        }
    };
}

export function createChibiHuman(THREE, personaKey = 'dean') {
    const style = PERSONA_STYLES[personaKey] || PERSONA_STYLES.dean;
    const root = new THREE.Group();

    const chair = buildErgonomicChair(THREE, style.pinColor);
    root.add(chair);

    const actor = new THREE.Group();
    root.add(actor);

    const bodyRoot = new THREE.Group();
    bodyRoot.position.set(0, 0.34, -0.02);
    actor.add(bodyRoot);

    const skinMat = new THREE.MeshStandardMaterial({
        color: style.skinColor,
        roughness: 0.50,
        metalness: 0.0
    });
    const pantsMat = makeMat(THREE, style.pantsColor, 0.44, 0.08);
    const jacketMat = new THREE.MeshPhysicalMaterial({
        color: style.jacketColor,
        roughness: 0.36,
        metalness: 0.08,
        sheen: 0.30,
        sheenColor: new THREE.Color(0xbae6fd)
    });
    const shirtMat = makeMat(THREE, style.shirtColor, 0.34, 0.04);
    const tieMat = makeMat(THREE, style.tieColor, 0.30, 0.15);
    const shoeMat = makeMat(THREE, style.shoeColor, 0.26, 0.22);
    const beltMat = makeMat(THREE, 0x111827, 0.34, 0.25);
    const buckleMat = makeMat(THREE, 0xfbbf24, 0.18, 0.80);

    const hipBase = new THREE.Mesh(new THREE.CylinderGeometry(0.162, 0.158, 0.10, 22), pantsMat);
    hipBase.position.set(0, 0.03, 0.0);
    bodyRoot.add(hipBase);

    if (style.outfit === 'female_blazer') {
        const skirt = new THREE.Mesh(new THREE.CylinderGeometry(0.156, 0.215, 0.21, 24), pantsMat);
        skirt.position.set(0, -0.025, 0.015);
        skirt.castShadow = true;
        bodyRoot.add(skirt);
        const skirtBelt = new THREE.Mesh(new THREE.CylinderGeometry(0.162, 0.162, 0.026, 22), beltMat);
        skirtBelt.position.set(0, 0.075, 0.01);
        bodyRoot.add(skirtBelt);
        const clasp = new THREE.Mesh(new THREE.BoxGeometry(0.038, 0.028, 0.018), buckleMat);
        clasp.position.set(0, 0.075, 0.172);
        bodyRoot.add(clasp);
    } else if (style.outfit !== 'hoodie') {
        const belt = new THREE.Mesh(new THREE.CylinderGeometry(0.170, 0.170, 0.028, 22), beltMat);
        belt.position.set(0, 0.078, 0.005);
        bodyRoot.add(belt);
        const buckle = new THREE.Mesh(new THREE.BoxGeometry(0.042, 0.030, 0.018), buckleMat);
        buckle.position.set(0, 0.078, 0.175);
        bodyRoot.add(buckle);
    }

    const useBareLegs = style.outfit === 'female_blazer';
    const makeLeg = (lx) => {
        const hipPivot = new THREE.Group();
        hipPivot.position.set(lx, 0.01, 0.0);
        bodyRoot.add(hipPivot);

        const thighMat = useBareLegs ? skinMat : pantsMat;
        const thigh = new THREE.Mesh(new THREE.CapsuleGeometry(useBareLegs ? 0.044 : 0.056, 0.135, 8, 12), thighMat);
        thigh.position.y = -0.095;
        thigh.castShadow = true;
        hipPivot.add(thigh);

        const kneePivot = new THREE.Group();
        kneePivot.position.set(0, -0.19, 0);
        hipPivot.add(kneePivot);

        const shin = new THREE.Mesh(new THREE.CapsuleGeometry(useBareLegs ? 0.038 : 0.048, 0.145, 8, 12), thighMat);
        shin.position.y = -0.095;
        shin.castShadow = true;
        kneePivot.add(shin);

        const shoe = new THREE.Mesh(new THREE.SphereGeometry(useBareLegs ? 0.048 : 0.056, 12, 10), shoeMat);
        shoe.scale.set(0.94, 0.62, 1.40);
        shoe.position.set(0, -0.205, 0.032);
        kneePivot.add(shoe);

        if (style.outfit === 'hoodie' || style.outfit === 'lab_coat') {
            const sole = new THREE.Mesh(new THREE.BoxGeometry(0.096, 0.020, 0.148), shirtMat);
            sole.position.set(0, -0.230, 0.032);
            kneePivot.add(sole);
        }

        return { hipPivot, kneePivot };
    };

    const leftLeg = makeLeg(-0.078);
    const rightLeg = makeLeg(0.078);

    const torsoPivot = new THREE.Group();
    torsoPivot.position.set(0, 0.05, 0);
    bodyRoot.add(torsoPivot);

    const torsoPlaceholder = new THREE.Mesh(new THREE.CylinderGeometry(0.145, 0.17, 0.30, 24), jacketMat);
    torsoPlaceholder.position.y = 0.16;
    torsoPlaceholder.castShadow = true;
    torsoPivot.add(torsoPlaceholder);

    loadFirstGeometry('chibi_suit_torso.glb').then((geo) => {
        if (!geo) return;
        torsoPivot.remove(torsoPlaceholder);
        const sculptedTorso = new THREE.Mesh(geo, jacketMat);
        if (style.gender === 'female') {
            sculptedTorso.scale.set(0.88, 0.88, 0.84);
        } else {
            sculptedTorso.scale.set(0.96, 0.90, 0.86);
        }
        sculptedTorso.castShadow = true;
        sculptedTorso.receiveShadow = true;
        torsoPivot.add(sculptedTorso);
    });

    if (style.outfit === 'suit_tie' || style.outfit === 'female_blazer' || style.outfit === 'lab_coat') {
        const lapelMat = style.outfit === 'lab_coat'
            ? makeMat(THREE, 0xe2e8f0, 0.36, 0.04)
            : makeMat(THREE, style.jacketColor, 0.30, 0.14);

        const shirtCore = new THREE.Mesh(
            createRoundedExtrudeGeo(THREE, style.outfit === 'lab_coat' ? 0.145 : 0.125, style.outfit === 'lab_coat' ? 0.24 : 0.185, 0.036, 0.02, 0.005),
            shirtMat
        );
        shirtCore.position.set(0, style.outfit === 'lab_coat' ? 0.19 : 0.245, style.outfit === 'lab_coat' ? 0.144 : 0.138);
        torsoPivot.add(shirtCore);

        [-1, 1].forEach((sx) => {
            const lapel = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.056, 0.175, 0.020, 0.012, 0.005), lapelMat);
            lapel.position.set(sx * 0.072, 0.215, 0.152);
            lapel.rotation.z = sx * -0.22;
            lapel.rotation.y = sx * 0.20;
            torsoPivot.add(lapel);

            const wing = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.056, 0.072, 0.020, 0.010, 0.004), shirtMat);
            wing.position.set(sx * 0.048, 0.296, 0.152);
            wing.rotation.z = sx < 0 ? 0.42 : -0.42;
            wing.rotation.y = sx < 0 ? -0.18 : 0.18;
            torsoPivot.add(wing);
        });

        if (style.outfit === 'suit_tie') {
            const tieKnot = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.046, 0.040, 0.028, 0.010, 0.005), tieMat);
            tieKnot.position.set(0, 0.280, 0.166);
            torsoPivot.add(tieKnot);
            const tieBlade = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.040, 0.155, 0.020, 0.009, 0.004), tieMat);
            tieBlade.position.set(0, 0.192, 0.168);
            torsoPivot.add(tieBlade);
            const pocketSq = new THREE.Mesh(
                createRoundedExtrudeGeo(THREE, 0.052, 0.022, 0.012, 0.005, 0.002),
                makeMat(THREE, style.pinColor, 0.26, 0.18)
            );
            pocketSq.position.set(0.095, 0.205, 0.156);
            pocketSq.rotation.y = 0.24;
            torsoPivot.add(pocketSq);
        } else if (style.outfit === 'female_blazer') {
            for (let p = -3; p <= 3; p++) {
                const a = p * 0.22;
                const pearl = new THREE.Mesh(new THREE.SphereGeometry(0.013, 10, 8), makeMat(THREE, 0xffffff, 0.16, 0.25));
                pearl.position.set(Math.sin(a) * 0.088, 0.278 - Math.cos(a) * 0.022, 0.148 + Math.cos(a) * 0.015);
                torsoPivot.add(pearl);
            }
            [0.105, 0.052].forEach((by) => {
                const goldBtn = new THREE.Mesh(new THREE.CylinderGeometry(0.014, 0.014, 0.012, 12), buckleMat);
                goldBtn.rotation.x = Math.PI / 2;
                goldBtn.position.set(0, by, 0.158);
                torsoPivot.add(goldBtn);
            });
        } else if (style.outfit === 'lab_coat') {
            const turtleNeck = new THREE.Mesh(new THREE.CylinderGeometry(0.070, 0.076, 0.055, 18), shirtMat);
            turtleNeck.position.set(0, 0.312, 0.01);
            torsoPivot.add(turtleNeck);
            const pocket = new THREE.Mesh(new THREE.BoxGeometry(0.072, 0.066, 0.014), makeMat(THREE, 0xe2e8f0, 0.42));
            pocket.position.set(0.092, 0.155, 0.160);
            pocket.rotation.y = 0.22;
            torsoPivot.add(pocket);
            const pen1 = new THREE.Mesh(new THREE.CylinderGeometry(0.006, 0.006, 0.048, 8), makeMat(THREE, 0x2563eb, 0.3));
            pen1.position.set(0.078, 0.196, 0.164);
            const pen2 = new THREE.Mesh(new THREE.CylinderGeometry(0.006, 0.006, 0.045, 8), makeMat(THREE, 0xef4444, 0.3));
            pen2.position.set(0.098, 0.195, 0.164);
            torsoPivot.add(pen1, pen2);
        }
    } else if (style.outfit === 'female_vest_pants') {
        const collar = new THREE.Mesh(new THREE.TorusGeometry(0.082, 0.024, 10, 20), shirtMat);
        collar.rotation.x = Math.PI / 2;
        collar.position.set(0, 0.308, 0.02);
        torsoPivot.add(collar);
        const bib = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.125, 0.185, 0.036, 0.02, 0.004), shirtMat);
        bib.position.set(0, 0.242, 0.136);
        torsoPivot.add(bib);
        const bow = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.088, 0.040, 0.024, 0.014, 0.004), tieMat);
        bow.position.set(0, 0.286, 0.162);
        torsoPivot.add(bow);
        [0.148, 0.098, 0.048].forEach((by) => {
            const gbtn = new THREE.Mesh(new THREE.CylinderGeometry(0.013, 0.013, 0.012, 12), buckleMat);
            gbtn.rotation.x = Math.PI / 2;
            gbtn.position.set(0, by, 0.158);
            torsoPivot.add(gbtn);
        });
    } else if (style.outfit === 'sweater_vest') {
        const vShirt = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.13, 0.14, 0.036, 0.02, 0.004), shirtMat);
        vShirt.position.set(0, 0.265, 0.142);
        torsoPivot.add(vShirt);
        [-1, 1].forEach((sx) => {
            const wing = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.056, 0.072, 0.020, 0.010, 0.004), shirtMat);
            wing.position.set(sx * 0.048, 0.296, 0.154);
            wing.rotation.z = sx < 0 ? 0.42 : -0.42;
            torsoPivot.add(wing);
        });
        const tieKnot = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.044, 0.038, 0.026, 0.009, 0.004), tieMat);
        tieKnot.position.set(0, 0.280, 0.166);
        torsoPivot.add(tieKnot);
        const tieBlade = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.036, 0.105, 0.018, 0.008, 0.003), tieMat);
        tieBlade.position.set(0, 0.215, 0.166);
        torsoPivot.add(tieBlade);
    } else if (style.outfit === 'hoodie') {
        const hoodCollar = new THREE.Mesh(new THREE.TorusGeometry(0.146, 0.052, 12, 24), jacketMat);
        hoodCollar.rotation.x = Math.PI / 2 - 0.18;
        hoodCollar.position.set(0, 0.305, -0.015);
        torsoPivot.add(hoodCollar);
        const teeHem = new THREE.Mesh(new THREE.CylinderGeometry(0.180, 0.184, 0.034, 24), shirtMat);
        teeHem.position.set(0, 0.012, 0);
        torsoPivot.add(teeHem);
        const pocket = new THREE.Mesh(createRoundedExtrudeGeo(THREE, 0.215, 0.095, 0.028, 0.026, 0.008), jacketMat);
        pocket.position.set(0, 0.082, 0.162);
        torsoPivot.add(pocket);
        [-0.038, 0.038].forEach((dx) => {
            const cord = new THREE.Mesh(new THREE.CylinderGeometry(0.007, 0.007, 0.130, 8), shirtMat);
            cord.position.set(dx, 0.215, 0.166);
            torsoPivot.add(cord);
        });
    }

    const neck = new THREE.Mesh(new THREE.CylinderGeometry(0.056, 0.064, 0.105, 18), skinMat);
    neck.position.y = 0.338;
    torsoPivot.add(neck);

    const makeArm = (sideSign) => {
        const shoulderPivot = new THREE.Group();
        shoulderPivot.position.set(sideSign * (style.gender === 'female' ? 0.174 : 0.188), 0.258, 0.012);
        torsoPivot.add(shoulderPivot);

        const sleeveMat = (style.outfit === 'female_vest_pants' || style.outfit === 'sweater_vest') ? shirtMat : jacketMat;
        const upperArm = new THREE.Mesh(new THREE.CapsuleGeometry(0.052, 0.105, 8, 16), sleeveMat);
        upperArm.position.y = -0.064;
        upperArm.castShadow = true;
        shoulderPivot.add(upperArm);

        const elbowPivot = new THREE.Group();
        elbowPivot.position.set(0, -0.125, 0);
        shoulderPivot.add(elbowPivot);

        const forearm = new THREE.Mesh(new THREE.CapsuleGeometry(0.046, 0.095, 8, 16), sleeveMat);
        forearm.position.y = -0.052;
        forearm.castShadow = true;
        elbowPivot.add(forearm);

        const cuff = new THREE.Mesh(new THREE.CylinderGeometry(0.049, 0.049, 0.028, 16), shirtMat);
        cuff.position.y = -0.106;
        elbowPivot.add(cuff);

        const handHolder = new THREE.Group();
        handHolder.position.set(0, -0.145, 0);
        elbowPivot.add(handHolder);

        const fallbackHand = new THREE.Mesh(new THREE.SphereGeometry(0.046, 14, 12), skinMat);
        handHolder.add(fallbackHand);

        loadFirstGeometry('chibi_hand_r.glb').then((geo) => {
            if (!geo) return;
            handHolder.remove(fallbackHand);
            const hm = new THREE.Mesh(geo, skinMat);
            hm.scale.setScalar(0.94);
            if (sideSign < 0) {
                hm.rotation.set(0, 0, Math.PI);
            } else {
                hm.rotation.set(0, Math.PI, Math.PI);
            }
            hm.castShadow = true;
            handHolder.add(hm);
        });

        return { shoulderPivot, elbowPivot, hand: handHolder };
    };

    const charRightArm = makeArm(-1);
    const charLeftArm = makeArm(1);

    let leftProp = null;
    let rightProp = null;
    if (style.jobAnim === 'executive_review') {
        leftProp = buildHandProp(THREE, 'tablet');
        leftProp.position.set(0, -0.05, 0.06);
        leftProp.rotation.x = 0.6;
        charLeftArm.hand.add(leftProp);
    } else if (style.jobAnim === 'quality_audit') {
        leftProp = buildHandProp(THREE, 'clipboard');
        leftProp.position.set(0.02, -0.05, 0.06);
        leftProp.rotation.set(0.7, -0.25, 0);
        charLeftArm.hand.add(leftProp);
        rightProp = buildHandProp(THREE, 'pen');
        rightProp.position.set(0.02, -0.05, 0.04);
        rightProp.rotation.set(0.9, 0, -0.4);
        charRightArm.hand.add(rightProp);
    } else if (style.jobAnim === 'lab_research') {
        leftProp = buildHandProp(THREE, 'flask');
        leftProp.position.set(0, -0.04, 0.05);
        leftProp.rotation.x = 1.4;
        charLeftArm.hand.add(leftProp);
    } else if (style.jobAnim === 'admin_dispatch') {
        leftProp = buildHandProp(THREE, 'folder');
        leftProp.position.set(0, -0.05, 0.05);
        leftProp.rotation.x = 0.8;
        charLeftArm.hand.add(leftProp);
    } else if (style.jobAnim === 'student_study') {
        rightProp = buildHandProp(THREE, 'pen');
        rightProp.position.set(0, -0.05, 0.04);
        rightProp.rotation.set(0.9, 0, -0.35);
        charRightArm.hand.add(rightProp);
    }

    const headPivot = new THREE.Group();
    headPivot.position.set(0, 0.362, 0.01);
    torsoPivot.add(headPivot);

    const headAnchor = new THREE.Group();
    headAnchor.position.set(0, 0.215, 0);
    headPivot.add(headAnchor);

    const fallbackHead = new THREE.Mesh(new THREE.SphereGeometry(0.245, 28, 22), skinMat);
    fallbackHead.scale.set(1.08, 0.90, 0.94);
    headAnchor.add(fallbackHead);

    loadFirstGeometry('chibi_head.glb').then((geo) => {
        if (!geo) return;
        headAnchor.remove(fallbackHead);
        const hMesh = new THREE.Mesh(geo, skinMat);
        hMesh.castShadow = true;
        hMesh.receiveShadow = true;
        headAnchor.add(hMesh);
    });

    const { faceGroup, blinkUnits, mouthGroup } = buildPhysical3DFace(THREE, style, skinMat);
    headAnchor.add(faceGroup);

    if (style.hairMode === 'procedural') {
        headAnchor.add(buildBespokeHair(THREE, style.hairStyle, style.hairBaseColor, style.hairSheen));
    } else {
        const hairMat = new THREE.MeshPhysicalMaterial({
            color: style.hairTint || 0xffffff,
            vertexColors: true,
            roughness: 0.34,
            metalness: 0.08,
            clearcoat: 0.24,
            clearcoatRoughness: 0.32,
            sheen: 0.45,
            sheenColor: new THREE.Color(style.hairSheen)
        });
        loadFirstGeometry(style.hairGlb).then((geo) => {
            if (!geo) return;
            const hairMesh = new THREE.Mesh(geo, hairMat);
            hairMesh.castShadow = true;
            hairMesh.receiveShadow = true;
            headAnchor.add(hairMesh);
        });
    }

    if (style.glasses && style.glasses !== 'none') {
        headAnchor.add(buildGlasses(THREE, style.glasses));
    }

    let blinkTimer = 1.4 + Math.random() * 2.5;
    let blinkProg = 0;
    let standBlend = 0;
    let walkPhase = 0;
    let locoRoute = null;
    let locoMode = 'sit';
    let locoTimer = 4.0 + Math.random() * 6.0;
    let wpIdx = 0;
    const seatOrigin = new THREE.Vector3(0, 0, 0);

    function lerpAngle(a, b, alpha) {
        let diff = ((b - a + Math.PI) % (Math.PI * 2)) - Math.PI;
        if (diff < -Math.PI) diff += Math.PI * 2;
        return a + diff * alpha;
    }

    return {
        root,
        actor,
        chair,
        torsoPivot,
        headPivot,
        leftArm: charLeftArm,
        rightArm: charRightArm,
        setActivityRoute(routeSpec) {
            locoRoute = routeSpec;
            locoMode = routeSpec.startStanding ? 'activity' : 'sit';
            locoTimer = routeSpec.initialDelay ?? (4.5 + Math.random() * 5.0);
            if (routeSpec.startStanding && routeSpec.waypoints?.length > 0) {
                const last = routeSpec.waypoints[routeSpec.waypoints.length - 1];
                actor.position.set(last.x, 0, last.z);
                actor.rotation.y = routeSpec.activityYaw ?? 0;
                standBlend = 1;
                wpIdx = routeSpec.waypoints.length - 1;
            }
        },
        getActorWorldPos(out = new THREE.Vector3()) {
            actor.getWorldPosition(out);
            return out;
        },
        getRightHandPalmWorldPos(out = new THREE.Vector3()) {
            charRightArm.hand.getWorldPosition(out);
            return out;
        },
        getHeadWorldPos(out = new THREE.Vector3()) {
            headAnchor.getWorldPosition(out);
            return out;
        },
        update(dt, t, state = 'idle', phaseShift = 0) {
            blinkTimer -= dt;
            if (blinkTimer <= 0 && blinkProg === 0) {
                blinkProg = 0.001;
                blinkTimer = Math.random() < 0.22 ? 0.36 : (1.8 + Math.random() * 1.8);
            }
            let blinkAmt = 0;
            if (blinkProg > 0) {
                blinkProg += dt * 7.6;
                if (blinkProg <= 0.85) {
                    blinkAmt = blinkProg / 0.85;
                } else if (blinkProg <= 1.20) {
                    blinkAmt = 1.0;
                } else {
                    blinkAmt = Math.max(0, (2.05 - blinkProg) / 0.85);
                }
                if (blinkProg >= 2.05) {
                    blinkProg = 0;
                    blinkAmt = 0;
                }
            }
            blinkUnits.forEach((bu) => {
                bu.eyeContent.scale.y = Math.max(0.05, 1.0 - blinkAmt * 0.95);
                bu.lidShield.visible = blinkAmt > 0.05;
                if (bu.lidShield.visible) {
                    bu.lidShield.scale.y = 0.10 + blinkAmt * 1.08;
                    bu.lidShield.position.y = 0.052 * (1.0 - blinkAmt);
                }
                bu.closedCrease.visible = blinkAmt > 0.42;
                if (bu.closedCrease.visible) {
                    bu.closedCrease.position.y = 0.020 - blinkAmt * 0.012;
                }
            });

            const s = String(state || 'idle').toLowerCase();
            const tp = t + phaseShift;
            mouthGroup.scale.y = 1.0;

            let isWalking = false;
            let isStandingActivity = false;

            if (locoRoute && Array.isArray(locoRoute.waypoints) && locoRoute.waypoints.length > 0) {
                const forceDesk = (s === 'working' || s === 'typing');
                if (forceDesk && locoMode !== 'sit') {
                    locoMode = 'walk_back';
                    wpIdx = Math.min(wpIdx, locoRoute.waypoints.length - 1);
                }

                if (locoMode === 'sit') {
                    standBlend = Math.max(0, standBlend - dt * 2.4);
                    actor.position.lerp(seatOrigin, Math.min(1, dt * 6.0));
                    actor.rotation.y = lerpAngle(actor.rotation.y, 0, Math.min(1, dt * 6.0));
                    if (!forceDesk) {
                        locoTimer -= dt;
                        if (locoTimer <= 0) {
                            locoMode = 'stand_up';
                            locoTimer = 0.55;
                        }
                    }
                } else if (locoMode === 'stand_up') {
                    standBlend = Math.min(1, standBlend + dt * 2.4);
                    locoTimer -= dt;
                    if (locoTimer <= 0) {
                        locoMode = 'walk_out';
                        wpIdx = 0;
                    }
                } else if (locoMode === 'walk_out' || locoMode === 'walk_back') {
                    standBlend = Math.min(1, standBlend + dt * 3.0);
                    const targetPt = locoMode === 'walk_out'
                        ? locoRoute.waypoints[wpIdx]
                        : (wpIdx >= 0 ? locoRoute.waypoints[wpIdx] : { x: 0, z: 0 });
                    const dx = targetPt.x - actor.position.x;
                    const dz = targetPt.z - actor.position.z;
                    const dist = Math.hypot(dx, dz);
                    const speed = 1.28;
                    if (dist > 0.08) {
                        isWalking = true;
                        const step = Math.min(dist, speed * dt);
                        actor.position.x += (dx / dist) * step;
                        actor.position.z += (dz / dist) * step;
                        const desiredYaw = Math.atan2(dx, dz);
                        actor.rotation.y = lerpAngle(actor.rotation.y, desiredYaw, Math.min(1, dt * 8.5));
                    } else {
                        if (locoMode === 'walk_out') {
                            wpIdx++;
                            if (wpIdx >= locoRoute.waypoints.length) {
                                locoMode = 'activity';
                                locoTimer = locoRoute.activityDuration ?? 7.5;
                                wpIdx = locoRoute.waypoints.length - 1;
                            }
                        } else {
                            wpIdx--;
                            if (wpIdx < -1) {
                                locoMode = 'sit';
                                locoTimer = locoRoute.sitDuration ?? 9.0;
                            }
                        }
                    }
                } else if (locoMode === 'activity') {
                    standBlend = Math.min(1, standBlend + dt * 2.5);
                    isStandingActivity = true;
                    actor.rotation.y = lerpAngle(actor.rotation.y, locoRoute.activityYaw ?? 0, Math.min(1, dt * 5.0));
                    locoTimer -= dt;
                    if (locoTimer <= 0) {
                        locoMode = 'walk_back';
                        wpIdx = locoRoute.waypoints.length - 2;
                    }
                }
            }

            if (isWalking) {
                if (leftProp) leftProp.visible = false;
                if (rightProp) rightProp.visible = false;

                walkPhase += dt * 7.4;
                const stride = Math.sin(walkPhase);
                bodyRoot.position.y = 0.44 + Math.abs(stride) * 0.028;
                leftLeg.hipPivot.rotation.x = stride * 0.48;
                leftLeg.kneePivot.rotation.x = Math.max(0, -stride) * 0.42;
                rightLeg.hipPivot.rotation.x = -stride * 0.48;
                rightLeg.kneePivot.rotation.x = Math.max(0, stride) * 0.42;

                torsoPivot.position.y = 0.05;
                torsoPivot.rotation.set(0.04, -stride * 0.08, stride * 0.022);
                headPivot.rotation.set(0.0, Math.sin(tp * 1.4) * 0.12, 0);

                charRightArm.shoulderPivot.rotation.set(stride * 0.46, 0, -0.055);
                charRightArm.elbowPivot.rotation.set(-0.14 - Math.max(0, -stride) * 0.28, 0, 0);
                charLeftArm.shoulderPivot.rotation.set(-stride * 0.46, 0, 0.055);
                charLeftArm.elbowPivot.rotation.set(-0.14 - Math.max(0, stride) * 0.28, 0, 0);
                return;
            }

            bodyRoot.position.y = 0.34 + standBlend * 0.10;
            const sitHip = -Math.PI * 0.48 * (1 - standBlend);
            const sitKnee = Math.PI * 0.48 * (1 - standBlend);
            leftLeg.hipPivot.rotation.x = sitHip;
            leftLeg.kneePivot.rotation.x = sitKnee;
            rightLeg.hipPivot.rotation.x = sitHip;
            rightLeg.kneePivot.rotation.x = sitKnee;

            torsoPivot.position.y = 0.05 + Math.sin(tp * 2.2) * 0.006;
            torsoPivot.rotation.y = 0;
            torsoPivot.rotation.z = 0;

            if (isStandingActivity) {
                if (leftProp) leftProp.visible = true;
                if (rightProp) rightProp.visible = true;

                const act = locoRoute?.activityType || 'confer';
                if (act === 'confer') {
                    torsoPivot.rotation.x = 0.02;
                    torsoPivot.rotation.y = Math.sin(tp * 1.1) * 0.12;
                    headPivot.rotation.set(-0.08 + Math.sin(tp * 2.4) * 0.05, Math.sin(tp * 1.5) * 0.16, 0);
                    charRightArm.shoulderPivot.rotation.set(-0.56 + Math.sin(tp * 3.0) * 0.16, 0.10, -0.14);
                    charRightArm.elbowPivot.rotation.set(-0.84 + Math.sin(tp * 3.6) * 0.16, 0, 0);
                    charLeftArm.shoulderPivot.rotation.set(leftProp ? -0.78 : -0.18, -0.08, 0.12);
                    charLeftArm.elbowPivot.rotation.set(leftProp ? -0.88 : -0.26, 0, 0);
                    mouthGroup.scale.y = 0.90 + Math.abs(Math.sin(tp * 7.0)) * 0.34;
                } else if (act === 'kiosk') {
                    torsoPivot.rotation.x = -0.02;
                    headPivot.rotation.set(-0.14 + Math.sin(tp * 1.8) * 0.04, Math.sin(tp * 1.2) * 0.18, 0);
                    charRightArm.shoulderPivot.rotation.set(-1.10 + Math.sin(tp * 2.2) * 0.12, 0.12, -0.12);
                    charRightArm.elbowPivot.rotation.set(-0.40 + Math.cos(tp * 2.2) * 0.10, 0, 0);
                    charLeftArm.shoulderPivot.rotation.set(-0.18, 0, 0.08);
                    charLeftArm.elbowPivot.rotation.set(-0.28, 0, 0);
                } else if (act === 'audit') {
                    torsoPivot.rotation.x = 0.05;
                    headPivot.rotation.set(0.10, Math.sin(tp * 1.4) * 0.24, 0);
                    charLeftArm.shoulderPivot.rotation.set(-0.88, -0.18, 0.12);
                    charLeftArm.elbowPivot.rotation.set(-0.98, 0, 0);
                    charRightArm.shoulderPivot.rotation.set(-0.86, 0.22, -0.10);
                    charRightArm.elbowPivot.rotation.set(-0.98 + Math.sin(tp * 7.0) * 0.14, 0, Math.cos(tp * 7.0) * 0.10);
                } else {
                    torsoPivot.rotation.x = 0.04;
                    headPivot.rotation.set(0.06, Math.sin(tp * 1.6) * 0.18, 0);
                    charRightArm.shoulderPivot.rotation.set(-0.72 + Math.sin(tp * 2.8) * 0.12, 0.08, -0.12);
                    charRightArm.elbowPivot.rotation.set(-0.78 + Math.cos(tp * 2.8) * 0.12, 0, 0);
                    charLeftArm.shoulderPivot.rotation.set(-0.62, 0, 0.12);
                    charLeftArm.elbowPivot.rotation.set(-0.70, 0, 0);
                }
                return;
            }

            if (leftProp) leftProp.visible = false;
            if (rightProp) rightProp.visible = false;

            if (standBlend > 0.25) {
                torsoPivot.rotation.x = 0.03;
                headPivot.rotation.set(0, 0, 0);
                charRightArm.shoulderPivot.rotation.set(-0.08, 0, -0.06);
                charRightArm.elbowPivot.rotation.set(-0.18, 0, 0);
                charLeftArm.shoulderPivot.rotation.set(-0.08, 0, 0.06);
                charLeftArm.elbowPivot.rotation.set(-0.18, 0, 0);
                return;
            }

            if (s === 'working' || s === 'typing') {
                torsoPivot.rotation.x = 0.09;
                headPivot.rotation.set(0.05 + Math.sin(tp * 4.5) * 0.025, Math.sin(tp * 1.8) * 0.10, 0);
                charRightArm.shoulderPivot.rotation.set(-0.56, 0.14, 0.10);
                charRightArm.elbowPivot.rotation.set(-0.98 + Math.sin(tp * 16.0) * 0.09, 0, 0);
                charLeftArm.shoulderPivot.rotation.set(-0.56, -0.14, -0.10);
                charLeftArm.elbowPivot.rotation.set(-0.98 + Math.cos(tp * 16.0) * 0.09, 0, 0);
                mouthGroup.scale.y = 0.9 + Math.abs(Math.sin(tp * 9.0)) * 0.32;
                return;
            }
            if (s === 'thinking' || s === 'routing') {
                torsoPivot.rotation.x = 0.04;
                headPivot.rotation.set(-0.04 + Math.sin(tp * 1.8) * 0.03, Math.sin(tp * 1.2) * 0.16, 0.06);
                charRightArm.shoulderPivot.rotation.set(-0.56, -0.10, -0.05);
                charRightArm.elbowPivot.rotation.set(-0.96 + Math.sin(tp * 2.4) * 0.04, 0, 0);
                charLeftArm.shoulderPivot.rotation.set(-0.54, -0.12, -0.08);
                charLeftArm.elbowPivot.rotation.set(-0.98, 0, 0);
                return;
            }
            if (s === 'collaborating' || s === 'success') {
                torsoPivot.rotation.x = 0.05;
                torsoPivot.rotation.y = Math.sin(tp * 1.6) * 0.10;
                headPivot.rotation.set(Math.sin(tp * 4.0) * 0.04, Math.sin(tp * 2.0) * 0.10, 0);
                charRightArm.shoulderPivot.rotation.set(-0.56, 0.14, 0.10);
                charRightArm.elbowPivot.rotation.set(-0.98 + Math.sin(tp * 12.0) * 0.08, 0, 0);
                charLeftArm.shoulderPivot.rotation.set(-0.56, -0.14, -0.10);
                charLeftArm.elbowPivot.rotation.set(-0.98 + Math.cos(tp * 12.0) * 0.08, 0, 0);
                mouthGroup.scale.y = 0.88 + Math.abs(Math.sin(tp * 10.0)) * 0.38;
                return;
            }

            const job = style.jobAnim;
            if (job === 'executive_review') {
                const mouseMode = Math.sin(tp * 0.65) > 0;
                torsoPivot.rotation.x = 0.07;
                torsoPivot.rotation.y = Math.sin(tp * 0.5) * 0.07;
                headPivot.rotation.set(0.03 + Math.sin(tp * 2.2) * 0.03, mouseMode ? -0.10 : 0.08, 0);
                charLeftArm.shoulderPivot.rotation.set(-0.55, -0.14, -0.10);
                charLeftArm.elbowPivot.rotation.set(-0.99 + (mouseMode ? 0 : Math.cos(tp * 11.0) * 0.07), 0, 0);
                charRightArm.shoulderPivot.rotation.set(-0.55, mouseMode ? -0.12 + Math.sin(tp * 2.1) * 0.07 : 0.14, mouseMode ? -0.05 : 0.10);
                charRightArm.elbowPivot.rotation.set(-0.98 + (mouseMode ? Math.sin(tp * 3.8) * 0.03 : Math.sin(tp * 11.0) * 0.07), 0, 0);
            } else if (job === 'academic_scheduling') {
                const checkMonitor = Math.sin(tp * 0.72) > 0.45;
                torsoPivot.rotation.x = 0.08;
                torsoPivot.rotation.y = checkMonitor ? -0.08 : Math.sin(tp * 0.8) * 0.04;
                headPivot.rotation.set(checkMonitor ? -0.03 : 0.06, checkMonitor ? -0.14 : Math.sin(tp * 1.6) * 0.10, 0);
                charRightArm.shoulderPivot.rotation.set(-0.56, checkMonitor ? -0.12 + Math.sin(tp * 2.6) * 0.06 : 0.14, checkMonitor ? -0.05 : 0.10);
                charRightArm.elbowPivot.rotation.set(-0.98 + (checkMonitor ? 0.02 : Math.sin(tp * 12.5) * 0.07), 0, 0);
                charLeftArm.shoulderPivot.rotation.set(-0.56, -0.14, -0.10);
                charLeftArm.elbowPivot.rotation.set(-0.98 + (checkMonitor ? 0 : Math.cos(tp * 12.5) * 0.07), 0, 0);
            } else if (job === 'admin_dispatch') {
                const checkDual = Math.sin(tp * 0.85) > 0.25;
                torsoPivot.rotation.x = 0.08;
                torsoPivot.rotation.y = checkDual ? 0.10 : -0.04;
                headPivot.rotation.set(0.04 + Math.sin(tp * 2.8) * 0.025, checkDual ? 0.18 : -0.08, 0);
                charLeftArm.shoulderPivot.rotation.set(-0.56, -0.14, -0.10);
                charLeftArm.elbowPivot.rotation.set(-0.98 + Math.cos(tp * 14.0) * 0.075, 0, 0);
                charRightArm.shoulderPivot.rotation.set(-0.56, 0.14, 0.10);
                charRightArm.elbowPivot.rotation.set(-0.98 + Math.sin(tp * 14.0) * 0.075, 0, 0);
            } else if (job === 'quality_audit') {
                const scrollMouse = Math.sin(tp * 0.7) > -0.1;
                torsoPivot.rotation.x = 0.07;
                torsoPivot.rotation.y = Math.sin(tp * 0.55) * 0.08;
                headPivot.rotation.set(0.04 + Math.sin(tp * 2.4) * 0.03, Math.sin(tp * 0.9) * 0.14, 0);
                charLeftArm.shoulderPivot.rotation.set(-0.55, -0.14, -0.10);
                charLeftArm.elbowPivot.rotation.set(-0.99 + (scrollMouse ? 0 : Math.cos(tp * 10.5) * 0.065), 0, 0);
                charRightArm.shoulderPivot.rotation.set(-0.55, scrollMouse ? -0.11 + Math.sin(tp * 2.4) * 0.08 : 0.14, scrollMouse ? -0.05 : 0.10);
                charRightArm.elbowPivot.rotation.set(-0.98 + (scrollMouse ? Math.sin(tp * 5.0) * 0.03 : Math.sin(tp * 10.5) * 0.065), 0, 0);
            } else if (job === 'lab_research') {
                const analyzeData = Math.sin(tp * 0.6) > 0.15;
                torsoPivot.rotation.x = 0.09;
                torsoPivot.rotation.y = analyzeData ? -0.10 : 0.06;
                headPivot.rotation.set(analyzeData ? 0.02 : 0.06, analyzeData ? -0.14 : 0.10, 0);
                charRightArm.shoulderPivot.rotation.set(-0.56, analyzeData ? -0.12 + Math.sin(tp * 2.8) * 0.06 : 0.14, analyzeData ? -0.05 : 0.10);
                charRightArm.elbowPivot.rotation.set(-0.98 + (analyzeData ? Math.sin(tp * 4.0) * 0.03 : Math.sin(tp * 12.0) * 0.07), 0, 0);
                charLeftArm.shoulderPivot.rotation.set(-0.56, -0.14, -0.10);
                charLeftArm.elbowPivot.rotation.set(-0.98 + Math.cos(tp * 12.0) * 0.065, 0, 0);
            } else {
                const mouseStudy = Math.sin(tp * 0.62) > 0.35;
                torsoPivot.rotation.x = 0.09;
                torsoPivot.rotation.y = Math.sin(tp * 0.7) * 0.07;
                headPivot.rotation.set(0.05, Math.sin(tp * 1.1) * 0.12, 0);
                charRightArm.shoulderPivot.rotation.set(-0.56, mouseStudy ? -0.12 + Math.sin(tp * 2.2) * 0.06 : 0.14, mouseStudy ? -0.05 : 0.10);
                charRightArm.elbowPivot.rotation.set(-0.98 + (mouseStudy ? Math.sin(tp * 4.2) * 0.03 : Math.sin(tp * 11.5) * 0.07), 0, 0);
                charLeftArm.shoulderPivot.rotation.set(-0.56, -0.14, -0.10);
                charLeftArm.elbowPivot.rotation.set(-0.98 + (mouseStudy ? 0 : Math.cos(tp * 11.5) * 0.07), 0, 0);
            }
        }
    };
}
