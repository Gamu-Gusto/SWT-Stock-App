<script setup lang="ts">
import { reactive, ref } from 'vue';
import { Plus } from 'lucide-vue-next';
import PageHeader from '@/components/ui/PageHeader.vue';
import AsyncState from '@/components/ui/AsyncState.vue';
import PaginationBar from '@/components/ui/PaginationBar.vue';
import ModalDialog from '@/components/ui/ModalDialog.vue';
import FormField from '@/components/ui/FormField.vue';
import { useList } from '@/composables/useList';
import { useSubmit } from '@/composables/useForm';
import { useAuth } from '@/stores/auth';
import { useLookups } from '@/stores/lookups';
import { post } from '@/api/http';
import { formatDate } from '@/utils/format';

const auth = useAuth();
const lookups = useLookups();
const list = useList<any>('/suppliers', { q: '', status: 'active' });
const open = ref(false);
const form = reactive({ company_name: '', contact_person: '', phone: '', email: '', address: '', notes: '' });
const submit = useSubmit();

async function save() {
    if (await submit.run(() => post('/suppliers', form), 'Supplier added.')) {
        open.value = false;
        Object.assign(form, { company_name: '', contact_person: '', phone: '', email: '', address: '', notes: '' });
        list.load();
        lookups.load(true);
    }
}
</script>

<template>
    <div>
        <PageHeader title="Suppliers" subtitle="Who supplies the tyres, and what they have delivered.">
            <template #actions><button v-if="auth.can('suppliers.manage')" type="button" class="btn btn-primary" @click="open = true"><Plus class="size-4" /> Add supplier</button></template>
        </PageHeader>
        <div class="mb-4 grid grid-cols-2 gap-3 sm:max-w-xl">
            <div><label class="field-label" for="sup-q">Search</label><input id="sup-q" v-model="list.filters.q" type="search" class="field" placeholder="Company or contact" /></div>
            <div><label class="field-label" for="sup-s">Status</label><select id="sup-s" v-model="list.filters.status" class="field"><option value="active">Active</option><option value="archived">Archived</option><option value="all">All</option></select></div>
        </div>
        <section class="panel overflow-hidden">
            <AsyncState :loading="list.loading.value && !list.items.value.length" :error="list.error.value" :empty="!list.items.value.length" empty-title="No suppliers" empty-text="Add the companies you buy tyres from." @retry="list.load()">
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>Company</th><th>Contact</th><th>Phone</th><th>E-mail</th><th class="num">Deliveries</th><th>Last delivery</th></tr></thead>
                        <tbody>
                            <tr v-for="s in list.items.value" :key="s.id">
                                <td><RouterLink :to="`/suppliers/${s.id}`" class="font-semibold hover:underline">{{ s.company_name }}</RouterLink><span v-if="s.status === 'archived'" class="ml-2 text-xs text-muted">archived</span></td>
                                <td>{{ s.contact_person ?? '–' }}</td>
                                <td>{{ s.phone ?? '–' }}</td>
                                <td>{{ s.email ?? '–' }}</td>
                                <td class="num">{{ s.receipts_count }}</td>
                                <td>{{ formatDate(s.receipts_max_delivery_date) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <PaginationBar :page="list.page.value" :last-page="list.lastPage.value" :total="list.total.value" @change="list.load" />
            </AsyncState>
        </section>

        <ModalDialog :open="open" title="Add supplier" @close="open = false">
            <form id="sup-form" class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
                <div class="sm:col-span-2"><FormField v-slot="{ id }" label="Company name" required :error="submit.fieldError('company_name')"><input :id="id" v-model="form.company_name" class="field" required /></FormField></div>
                <FormField v-slot="{ id }" label="Contact person"><input :id="id" v-model="form.contact_person" class="field" /></FormField>
                <FormField v-slot="{ id }" label="Telephone"><input :id="id" v-model="form.phone" type="tel" class="field" /></FormField>
                <FormField v-slot="{ id }" label="E-mail" :error="submit.fieldError('email')"><input :id="id" v-model="form.email" type="email" class="field" /></FormField>
                <FormField v-slot="{ id }" label="Physical address"><input :id="id" v-model="form.address" class="field" /></FormField>
                <div class="sm:col-span-2"><FormField v-slot="{ id }" label="Notes"><textarea :id="id" v-model="form.notes" rows="2" class="field" /></FormField></div>
            </form>
            <template #footer><button type="button" class="btn btn-secondary" @click="open = false">Cancel</button><button type="submit" form="sup-form" class="btn btn-primary" :disabled="submit.busy.value">Add supplier</button></template>
        </ModalDialog>
    </div>
</template>
