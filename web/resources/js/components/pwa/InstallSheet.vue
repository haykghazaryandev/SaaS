<script setup lang="ts">
// iPhone / iPad Safari has no install prompt: the three taps to put the app on the home screen.
import Icon from '@/components/Icon.vue';
import { pwa } from '@/lib/pwa';

const close = () => (pwa.iosSheet = false);
</script>

<template>
    <div v-if="pwa.iosSheet" class="scrim" data-test="install-ios" @click.self="close">
        <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="install-ios-title">
            <div class="sheet-head">
                <h3 id="install-ios-title">{{ $t('pwa.install.ios.title') }}</h3>
                <button type="button" class="x" :aria-label="$t('common.close')" @click="close"><Icon name="x" size="sm" /></button>
            </div>
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
        </div>
    </div>
</template>

<style scoped>
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
