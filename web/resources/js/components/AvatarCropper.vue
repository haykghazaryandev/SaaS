<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

// Position a profile photo before uploading: drag to move (left/right/up/down), slider or wheel/pinch to zoom.
// Emits "done" with a square 512×512 JPEG of what is inside the circle.
// <AvatarCropper :file="file" @done="(blob) => …" @cancel="…" />
const props = defineProps<{ file: File; saving?: boolean }>();
const emit = defineEmits<{ done: [blob: Blob]; cancel: [] }>();

const VIEW = 280; // size of the circle on screen (px)
const OUT = 512; // size of the saved square (px)

const url = URL.createObjectURL(props.file);
const img = new Image();
const ready = ref(false);
const natW = ref(1);
const natH = ref(1);
const zoom = ref(1); // 1 = the photo just covers the circle
const x = ref(0); // offset of the photo centre from the circle centre (screen px)
const y = ref(0);

// scale so that the shorter side fills the circle at zoom 1
const base = computed(() => VIEW / Math.min(natW.value, natH.value));
const shownW = computed(() => natW.value * base.value * zoom.value);
const shownH = computed(() => natH.value * base.value * zoom.value);

// keep the circle always covered by the photo
function clamp() {
    const mx = Math.max(0, (shownW.value - VIEW) / 2);
    const my = Math.max(0, (shownH.value - VIEW) / 2);
    x.value = Math.min(mx, Math.max(-mx, x.value));
    y.value = Math.min(my, Math.max(-my, y.value));
}

img.onload = () => {
    natW.value = img.naturalWidth;
    natH.value = img.naturalHeight;
    ready.value = true;
};
img.src = url;

// ---------- dragging (mouse + touch, one or two fingers) ----------
const pointers = new Map<number, { x: number; y: number }>();
let pinchStart = 0;
let zoomStart = 1;
function down(e: PointerEvent) {
    (e.target as HTMLElement).setPointerCapture(e.pointerId);
    pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (pointers.size === 2) {
        const [a, b] = [...pointers.values()];
        pinchStart = Math.hypot(a.x - b.x, a.y - b.y);
        zoomStart = zoom.value;
    }
}
function move(e: PointerEvent) {
    const prev = pointers.get(e.pointerId);
    if (!prev) return;
    pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (pointers.size === 2) {
        const [a, b] = [...pointers.values()];
        const d = Math.hypot(a.x - b.x, a.y - b.y);
        if (pinchStart) setZoom((zoomStart * d) / pinchStart);
    } else {
        x.value += e.clientX - prev.x;
        y.value += e.clientY - prev.y;
        clamp();
    }
}
function up(e: PointerEvent) {
    pointers.delete(e.pointerId);
    if (pointers.size < 2) pinchStart = 0;
}
function setZoom(z: number) {
    zoom.value = Math.min(4, Math.max(1, z));
    clamp();
}
function wheel(e: WheelEvent) {
    e.preventDefault();
    setZoom(zoom.value * (e.deltaY < 0 ? 1.08 : 1 / 1.08));
}
// arrow keys move, +/- zoom (keyboard users)
function key(e: KeyboardEvent) {
    const step = 10;
    if (e.key === 'ArrowLeft') x.value += step;
    else if (e.key === 'ArrowRight') x.value -= step;
    else if (e.key === 'ArrowUp') y.value += step;
    else if (e.key === 'ArrowDown') y.value -= step;
    else if (e.key === '+' || e.key === '=') setZoom(zoom.value * 1.1);
    else if (e.key === '-') setZoom(zoom.value / 1.1);
    else return;
    e.preventDefault();
    clamp();
}
function reset() {
    zoom.value = 1;
    x.value = 0;
    y.value = 0;
}

// ---------- save: draw what is inside the circle into a square ----------
function save() {
    const c = document.createElement('canvas');
    c.width = OUT;
    c.height = OUT;
    const ctx = c.getContext('2d')!;
    const k = OUT / VIEW; // screen px → output px
    const w = shownW.value * k;
    const h = shownH.value * k;
    ctx.fillStyle = '#141915';
    ctx.fillRect(0, 0, OUT, OUT);
    ctx.drawImage(img, OUT / 2 + x.value * k - w / 2, OUT / 2 + y.value * k - h / 2, w, h);
    c.toBlob((b) => b && emit('done', b), 'image/jpeg', 0.9);
}

function onEsc(e: KeyboardEvent) {
    if (e.key === 'Escape') emit('cancel');
}
onMounted(() => document.addEventListener('keydown', onEsc));
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onEsc);
    URL.revokeObjectURL(url);
});
</script>

<template>
    <div class="scrim" @click.self="emit('cancel')">
        <div class="sheet crop" role="dialog" aria-modal="true" aria-labelledby="cropT">
            <div class="sheet-head">
                <h3 id="cropT">{{ $t('profile.photo.position') }}</h3>
                <button type="button" class="x" :aria-label="$t('player.close')" @click="emit('cancel')"><Icon name="x" /></button>
            </div>
            <p class="muted small">{{ $t('profile.photo.positionHint') }}</p>
            <div
                class="stage"
                data-noswipe
                :style="{ width: VIEW + 'px', height: VIEW + 'px' }"
                tabindex="0"
                :aria-label="$t('profile.photo.positionHint')"
                @pointerdown="down"
                @pointermove="move"
                @pointerup="up"
                @pointercancel="up"
                @wheel="wheel"
                @keydown="key"
            >
                <img
                    v-if="ready"
                    :src="url"
                    alt=""
                    draggable="false"
                    :style="{ width: shownW + 'px', height: shownH + 'px', transform: `translate(calc(-50% + ${x}px), calc(-50% + ${y}px))` }"
                />
                <span class="ring" aria-hidden="true"></span>
            </div>
            <div class="zoomrow">
                <Icon name="search" size="sm" />
                <input type="range" min="1" max="4" step="0.01" :value="zoom" :aria-label="$t('profile.photo.zoom')" @input="setZoom(+($event.target as HTMLInputElement).value)" />
                <button type="button" class="btn link sm" @click="reset">{{ $t('profile.photo.reset') }}</button>
            </div>
            <div class="acts">
                <button type="button" class="btn outline" @click="emit('cancel')">{{ $t('player.close') }}</button>
                <button type="button" class="btn" :disabled="!ready || saving" @click="save">{{ saving ? $t('profile.photo.uploading') : $t('profile.photo.save') }}</button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.crop { display: flex; flex-direction: column; gap: 12px; align-items: stretch; }
.small { font-size: 13px; margin: 0; }
.stage { position: relative; align-self: center; overflow: hidden; border-radius: 16px; background: #0b0f0c; touch-action: none; cursor: grab; user-select: none; outline: none; }
.stage:active { cursor: grabbing; }
.stage:focus-visible { box-shadow: 0 0 0 2px var(--accent); }
.stage img { position: absolute; left: 50%; top: 50%; max-width: none; pointer-events: none; }
/* darken everything outside the circle */
.ring { position: absolute; inset: 0; border-radius: 50%; box-shadow: 0 0 0 999px rgba(5, 8, 6, 0.62); outline: 2px solid rgba(255, 255, 255, 0.85); pointer-events: none; }
.zoomrow { display: flex; align-items: center; gap: 10px; color: var(--muted); }
.zoomrow input { flex: 1; accent-color: var(--accent); }
.acts { display: flex; gap: 8px; justify-content: flex-end; }
.acts .btn { flex: 1; }
</style>
