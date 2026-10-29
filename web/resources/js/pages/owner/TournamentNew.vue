<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import TournamentForm from '@/components/owner/TournamentForm.vue';
import { useOwner } from '@/composables/useOwner';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { Link, router } from '@inertiajs/vue3';

// New tournament (the owner): its own page. Saved: opens the tournament's page; Cancel: back to the list.
const owner = useOwner();
</script>

<template>
    <OwnerLayout :title="$t('owner.club.new_tournament')">
        <Link class="backlink" :href="route('owner.tournaments')"><Icon name="back" size="sm" />{{ $t('owner.nav.tournaments') }}</Link>
        <div class="phead">
            <div>
                <div class="kicker">{{ owner.venue.name }}</div>
                <h1>{{ $t('owner.club.new_tournament') }}</h1>
            </div>
        </div>
        <section class="card panel" style="max-width: 760px">
            <TournamentForm @done="router.visit(route('owner.tournaments'))" />
        </section>
    </OwnerLayout>
</template>
