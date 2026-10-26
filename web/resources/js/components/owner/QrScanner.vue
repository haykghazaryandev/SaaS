<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import OwnerSheet from '@/components/owner/OwnerSheet.vue';
import { trans } from '@/lib/i18n';
import { tgApp } from '@/lib/telegram';
import { router } from '@inertiajs/vue3';
import jsQR from 'jsqr';
import { onBeforeUnmount, onMounted, ref } from 'vue';

// Scan a player's card QR and open their card. In the Telegram Mini App: Telegram's own scanner.
// In a browser: the back camera in this sheet (BarcodeDetector where the browser has it, else jsQR on video frames).
// The QR holds a link …/c/{code}; typing the code works too (a broken camera, a printed card).
const emit = defineEmits<{ close: [] }>();
const video = ref<HTMLVideoElement>();
const error = ref<string | null>(null);
const typed = ref('');
let stream: MediaStream | null = null;
let raf = 0;
let done = false;

/** the 20-letter card code from a scanned link or typed text */
const codeOf = (text: string) => /(?:\/c\/)?([a-z0-9]{20})\b/.exec(text.trim().toLowerCase())?.[1] ?? null;

function open(text: string): boolean {
    const code = codeOf(text);
    if (!code || done) return false;
    done = true;
    stop();
    router.visit(route('owner.cards.show', code));
    emit('close');
    return true;
}

function stop() {
    cancelAnimationFrame(raf);
    stream?.getTracks().forEach((t) => t.stop());
    stream = null;
}

onMounted(async () => {
    const tg = tgApp();
    if (tg?.showScanQrPopup) {
        tg.showScanQrPopup({ text: trans('owner.club.scan_hint') }, (text) => open(text));
        emit('close');
        return;
    }
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
    } catch {
        error.value = trans('owner.club.no_camera');
        return;
    }
    const v = video.value!;
    v.srcObject = stream;
    await v.play().catch(() => {});
    const Detector = (window as unknown as { BarcodeDetector?: new (o: { formats: string[] }) => { detect(src: CanvasImageSource): Promise<{ rawValue: string }[]> } }).BarcodeDetector;
    const detector = Detector ? new Detector({ formats: ['qr_code'] }) : null;
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    const tick = async () => {
        if (done || !stream) return;
        if (v.readyState >= 2) {
            if (detector) {
                const found = await detector.detect(v).catch(() => []);
                if (found[0] && open(found[0].rawValue)) return;
            } else if (ctx) {
                canvas.width = v.videoWidth;
                canvas.height = v.videoHeight;
                ctx.drawImage(v, 0, 0);
                const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
                const hit = jsQR(img.data, img.width, img.height);
                if (hit && open(hit.data)) return;
            }
        }
        raf = requestAnimationFrame(tick);
    };
    raf = requestAnimationFrame(tick);
});
onBeforeUnmount(stop);

function submitTyped() {
    if (!open(typed.value)) error.value = trans('owner.club.bad_code');
}
</script>

<template>
    <OwnerSheet :title="$t('owner.club.scan')" :sub="$t('owner.club.scan_hint')" @close="emit('close')">
        <div class="scanbox">
            <video ref="video" playsinline muted />
            <i class="frame" aria-hidden="true" />
        </div>
        <p v-if="error" class="err" style="margin: 10px 0 0">{{ error }}</p>
        <form class="row" style="gap: 8px; margin-top: 12px" @submit.prevent="submitTyped">
            <input v-model="typed" class="input mono" :placeholder="$t('owner.club.code_ph')" autocomplete="off" autocapitalize="off" />
            <button class="btn"><Icon name="arrow" size="sm" /></button>
        </form>
    </OwnerSheet>
</template>

<style scoped>
.scanbox { position: relative; aspect-ratio: 1; max-width: 360px; margin: 0 auto; border-radius: 18px; overflow: hidden; background: #000; }
.scanbox video { width: 100%; height: 100%; object-fit: cover; display: block; }
.scanbox .frame { position: absolute; inset: 18%; border: 3px solid var(--accent); border-radius: 16px; box-shadow: 0 0 0 999px rgba(0, 0, 0, .35); }
</style>
