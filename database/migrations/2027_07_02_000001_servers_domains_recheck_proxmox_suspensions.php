<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proxmox VPSs suspended before 0.6.12 still start when their node boots, and some may still run
 * if their stop failed. Flag them, so the nightly run suspends them once more: start on boot off,
 * and stopped for sure (Provisioner::recheckSuspensions). Each one gets seven nightly tries.
 */
return new class extends Migration
{
    private const FLAG = 'recheck_suspension';

    private const TRIES = 7;

    public function up(): void
    {
        if (! Schema::hasTable('services') || ! Schema::hasTable('products') || ! Schema::hasColumn('services', 'module_data')) {
            return;
        }

        $services = DB::table('services')
            ->join('products', 'products.id', '=', 'services.product_id')
            ->where('products.server_module', 'proxmox')
            ->where('services.status', 'suspended')
            ->whereNotNull('services.module_data')
            ->get(['services.id', 'services.module_data']);

        foreach ($services as $service) {
            $data = json_decode((string) $service->module_data, true);

            if (! is_array($data) || (int) ($data['vmid'] ?? 0) === 0 || isset($data[self::FLAG])) {
                continue;
            }

            DB::table('services')->where('id', $service->id)->update(['module_data' => json_encode([...$data, self::FLAG => self::TRIES])]);
        }
    }

    public function down(): void
    {
        // Nothing to undo: the flag only asks the nightly run to suspend these VPSs once more.
    }
};
