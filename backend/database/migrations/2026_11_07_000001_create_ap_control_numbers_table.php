<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ap_control_numbers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->date('document_date');
            $table->unsignedSmallInteger('code_year');
            $table->unsignedInteger('sequence_number');
            $table->string('code', 35);
            $table->timestamps();
            $table->unique(['business_id', 'source_type', 'source_id'], 'ap_control_source_unique');
            $table->unique(['business_id', 'code_year', 'code'], 'ap_control_code_year_unique');
            $table->unique(['business_id', 'document_date', 'sequence_number'], 'ap_control_daily_seq_unique');
        });

        // Preserve historical source-document identity. Backfill all previously posted
        // unpaid obligations, including ones that have since been settled.
        $sources = collect();
        DB::table('expenses')->where('status', 'POSTED')->orderBy('id')
            ->get(['id', 'business_id', 'expense_date', 'payment_status', 'created_at'])
            ->each(function ($row) use ($sources) {
                if ($row->payment_status !== 'PAID') {
                    $sources->push(['business_id' => $row->business_id, 'source_type' => 'EXPENSE', 'source_id' => $row->id, 'date' => substr((string) $row->expense_date, 0, 10), 'created_at' => $row->created_at]);
                }
            });
        DB::table('inventory_receipts')->where('status', 'POSTED')->orderBy('id')
            ->get(['id', 'business_id', 'received_date', 'payment_status', 'created_at'])
            ->each(function ($row) use ($sources) {
                if ($row->payment_status !== 'PAID') {
                    $sources->push(['business_id' => $row->business_id, 'source_type' => 'INVENTORY_RECEIPT', 'source_id' => $row->id, 'date' => substr((string) $row->received_date, 0, 10), 'created_at' => $row->created_at]);
                }
            });
        $counts = [];
        foreach ($sources->sortBy(fn ($row) => sprintf('%010d-%s-%s-%010d', $row['business_id'], $row['date'], $row['created_at'] ?? '', $row['source_id'])) as $row) {
            if (! $row['date']) continue;
            $key = $row['business_id'].'|'.$row['date'];
            $number = $counts[$key] = ($counts[$key] ?? 0) + 1;
            DB::table('ap_control_numbers')->insert([
                'business_id' => $row['business_id'], 'source_type' => $row['source_type'],
                'source_id' => $row['source_id'], 'document_date' => $row['date'],
                'code_year' => (int) substr($row['date'], 0, 4),
                'sequence_number' => $number,
                'code' => 'AP-'.substr($row['date'], 5, 2).substr($row['date'], 8, 2).'-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ap_control_numbers');
    }
};
