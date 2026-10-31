<script setup lang="ts">
// iPhone / iPad Safari has no install prompt: the three taps to put the app on the home screen. In another browser or
// an app's own browser (Chrome as the default after a QR scan, Instagram…): first "Open in Safari" (or copy the link).
import Icon from '@/components/Icon.vue';
import { pwa, safariUrl } from '@/lib/pwa';
import { ref } from 'vue';

const close = () => (pwa.iosSheet = false);
const copied = ref(false);
async function copy() {
    try {
        await navigator.clipboard.writeText(window.location.href);
        copied.value = true;
    } catch {
        // no clipboard: the person can still use "Open in Safari"
    }
}
</script>

<template>
    <div v-if="pwa.iosSheet" class="scrim" data-test="install-ios" @click.self="close">
        <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="install-ios-title">
            <div class="sheet-head">
                <h3 id="install-ios-title">{{ $t('pwa.install.ios.title') }}</h3>
                <button type="button" class="x" :aria-label="$t('common.close')" @click="close"><Icon name="x" size="sm" /></button>
            </div>
            <template v-if="pwa.iosChrome">
                <ol class="isteps">
                    <li>
                        <span class="n">1</span><span>{{ $t('pwa.install.ios.chrome_share') }}</span
                        ><Icon name="ios_share" class="si" />
                    </li>
                    <li>
                        <span class="n">2</span><span>{{ $t('pwa.install.ios.add') }}</span
                        ><Icon name="plus" class="si" />
                    </li>
                    <li>
                        <span class="n">3</span><span>{{ $t('pwa.install.ios.confirm') }}</span>
                    </li>
                </ol>
                <button type="button" class="btn block" @click="close">{{ $t('pwa.install.ios.done') }}</button>
                <a class="btn ghost block icopy" :href="safariUrl()" data-test="open-safari">{{ $t('pwa.install.ios.or_safari') }}</a>
            </template>
            <template v-else-if="pwa.iosOther">
                <p class="iother">{{ $t('pwa.install.ios.safari_text') }}</p>
                <a class="btn block" :href="safariUrl()" data-test="open-safari">{{ $t('pwa.install.ios.safari_open') }}</a>
                <button type="button" class="btn ghost block icopy" @click="copy">
                    {{ $t(copied ? 'pwa.install.ios.copied' : 'pwa.install.ios.copy_link') }}
                </button>
            </template>
            <template v-else>
                <ol class="isteps">
                    <li>
                        <span class="n">1</span><span>{{ $t('pwa.install.ios.share') }}</span
                        ><Icon name="ios_share" class="si" />
                    </li>
                    <li>
                        <span class="n">2</span><span>{{ $t('pwa.install.ios.add') }}</span
                        ><Icon name="plus" class="si" />
                    </li>
                    <li>
                        <span class="n">3</span><span>{{ $t('pwa.install.ios.confirm') }}</span>
                    </li>
                </ol>
                <p class="ipush">
                    <Icon name="bell" size="sm" /><span>{{ $t('pwa.install.ios.notifications') }}</span>
                </p>
                <button type="button" class="btn block" @click="close">{{ $t('pwa.install.ios.done') }}</button>
            </template>
        </div>
    </div>
</template>

<style scoped>
.iother {
    margin: 0 0 16px;
    font-size: 14.5px;
}
.icopy {
    margin-top: 8px;
}
.isteps {
    list-style: none;
    margin: 0 0 14px;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.isteps li {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    border-radius: 14px;
    background: var(--surface-2);
    font-size: 14.5px;
}
.isteps .n {
    flex: none;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: var(--surface-3);
    font-weight: 800;
    font-size: 13px;
}
.isteps .si {
    flex: none;
    margin-left: auto;
    color: var(--accent);
}
.ipush {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    margin: 0 0 16px;
    font-size: 13.5px;
    color: var(--muted);
}
.ipush .ico {
    flex: none;
    margin-top: 2px;
}
</style>
