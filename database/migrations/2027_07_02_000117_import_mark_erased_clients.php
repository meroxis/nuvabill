<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clients erased before this version still had their import mappings, so another run of an import put
 * their name, phone, address and notes back, opened them again and created their erased tickets again.
 * Their mappings now become 'client_erased' ones, as erasing does from now on, and the import skips those
 * clients. What such a run already put back is removed again, the way erasing removes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('import_mappings') || ! Schema::hasTable('clients') || ! Schema::hasColumn('clients', 'erased_at')) {
            return;
        }

        $this->markErasedClients();
        $this->eraseAgain();
    }

    public function down(): void
    {
        // Which mapping each erased client had is not kept, and they must not be imported again anyway.
    }

    private function markErasedClients(): void
    {
        $entities = ['client', 'client_link', 'client_unproven'];
        $erased = DB::table('clients')->select('id')->whereNotNull('erased_at');

        $mappings = DB::table('import_mappings')
            ->whereIn('entity', $entities)
            ->whereIn('local_id', $erased)
            ->orderBy('id')
            ->get(['source', 'source_id', 'local_id'])
            ->unique(fn (object $mapping): string => $mapping->source.'|'.$mapping->source_id);

        foreach ($mappings as $mapping) {
            DB::table('import_mappings')->updateOrInsert(
                ['source' => $mapping->source, 'entity' => 'client_erased', 'source_id' => $mapping->source_id],
                ['local_id' => $mapping->local_id],
            );
        }

        DB::table('import_mappings')->whereIn('entity', $entities)->whereIn('local_id', $erased)->delete();
    }

    /**
     * Erases the imported clients again, as ClientPrivacy::erase() does. Staff cannot do it themselves: an
     * erased client shows "Personal data was erased" and has no Erase button. A client nothing came back to
     * is already in this state, so it stays as it is.
     */
    private function eraseAgain(): void
    {
        $erased = DB::table('clients')
            ->whereNotNull('erased_at')
            ->whereIn('id', DB::table('import_mappings')->select('local_id')->where('entity', 'client_erased'));
        $columns = array_flip(Schema::getColumnListing('clients'));

        foreach ((clone $erased)->orderBy('id')->pluck('id') as $id) {
            $fields = ['phone' => null, 'notes' => null, 'tags' => null, 'status' => 'closed'];

            // Invoices and payments keep the name, company, address and tax ID they show, as the law requires.
            $keepForInvoices = (Schema::hasTable('invoices') && DB::table('invoices')->where('client_id', $id)->where('status', '!=', 'draft')->exists())
                || (Schema::hasTable('transactions') && DB::table('transactions')->where('client_id', $id)->exists());

            if (! $keepForInvoices) {
                $fields += [
                    'first_name' => __('Erased'),
                    'last_name' => __('client #:id', ['id' => $id]),
                    'company_name' => null,
                    'address_1' => null,
                    'address_2' => null,
                    'city' => null,
                    'state' => null,
                    'postcode' => null,
                    'tax_id' => null,
                ];
            }

            DB::table('clients')->where('id', $id)->update(array_intersect_key($fields, $columns));
        }

        if (! Schema::hasTable('tickets') || ! Schema::hasTable('ticket_replies')) {
            return;
        }

        // The tickets an import created again. A ticket staff opened here has no mapping and stays.
        $tickets = DB::table('tickets')
            ->whereIn('client_id', (clone $erased)->select('id'))
            ->whereIn('id', DB::table('import_mappings')->select('local_id')->where('entity', 'ticket'))
            ->orderBy('id')
            ->pluck('id');

        foreach ($tickets->chunk(500) as $chunk) {
            DB::table('ticket_replies')->whereIn('ticket_id', $chunk->all())->delete();
            DB::table('tickets')->whereIn('id', $chunk->all())->delete();
        }
    }
};
