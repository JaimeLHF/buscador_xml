<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Empresas já cadastradas passam a buscar CT-e também; novas recebem o estado ao serem criadas.
return new class extends Migration
{
    public function up(): void
    {
        $now = now()->toDateTimeString();

        DB::table('companies')
            ->select('id')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('sync_states')
                    ->whereColumn('sync_states.company_id', 'companies.id')
                    ->where('sync_states.tipo', 'cte');
            })
            ->orderBy('id')
            ->each(function ($company) use ($now) {
                DB::table('sync_states')->insert([
                    'company_id' => $company->id,
                    'tipo' => 'cte',
                    'ult_nsu' => '000000000000000',
                    'consecutive_656' => 0,
                    'consecutive_failures' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        DB::table('sync_states')->where('tipo', 'cte')->delete();
    }
};
