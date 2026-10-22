<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Empresas já cadastradas passam a buscar CT-e também; novas recebem o estado ao serem criadas.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            insert into sync_states (company_id, tipo, ult_nsu, consecutive_656, consecutive_failures, created_at, updated_at)
            select c.id, 'cte', '000000000000000', 0, 0, now(), now()
            from companies c
            where not exists (select 1 from sync_states s where s.company_id = c.id and s.tipo = 'cte')
            SQL);
    }

    public function down(): void
    {
        DB::table('sync_states')->where('tipo', 'cte')->delete();
    }
};
