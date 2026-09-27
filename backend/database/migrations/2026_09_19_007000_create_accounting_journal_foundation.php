<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_journal_entries')) {
            Schema::create('accounting_journal_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->string('code', 70)->unique();
                $table->date('posting_date')->index();
                $table->string('source_type', 80);
                $table->unsignedBigInteger('source_id');
                $table->string('source_event', 80);
                $table->string('reference', 120)->nullable();
                $table->text('description');
                $table->string('status', 30)->default('POSTED')->index();
                $table->dateTime('posted_at');
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamps();
                $table->unique(['business_id', 'source_type', 'source_id', 'source_event'], 'acct_jrn_source_event_uq');
            });
        }

        if (! Schema::hasTable('accounting_journal_lines')) {
            Schema::create('accounting_journal_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('journal_entry_id')->constrained('accounting_journal_entries')->cascadeOnUpdate()->cascadeOnDelete();
                $table->unsignedSmallInteger('line_no');
                $table->string('account_key', 100);
                $table->string('account_code', 40)->nullable();
                $table->string('account_name', 160);
                $table->decimal('debit', 14, 2)->default(0);
                $table->decimal('credit', 14, 2)->default(0);
                $table->text('memo')->nullable();
                $table->timestamps();
                $table->unique(['journal_entry_id', 'line_no'], 'acct_jrn_line_no_uq');
                $table->index(['account_key', 'account_code'], 'acct_jrn_account_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_journal_lines');
        Schema::dropIfExists('accounting_journal_entries');
    }
};
