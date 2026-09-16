<?php

use DreamFactory\Core\System\Components\AccessUsageRecorder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Last-used tracking for access audits.
 *
 * One row per subject (app / role / user), upserted by the RecordAccessUsage
 * middleware. On installs that already carry df-agents' agent_activity_ledger
 * (Silver+), its history seeds the table so an upgrade doesn't start from zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('access_usage')) {
            Schema::create('access_usage', function (Blueprint $t) {
                $t->increments('id');
                $t->string('subject_type', 16);
                $t->unsignedInteger('subject_id');
                // When tracking first saw this subject; never moved by later writes.
                $t->dateTime('first_seen_at')->nullable();
                $t->dateTime('last_used_at')->nullable();
                $t->dateTime('last_denied_at')->nullable();
                $t->string('last_service', 128)->nullable();
                $t->unsignedSmallInteger('last_status')->nullable();
                $t->unique(['subject_type', 'subject_id']);
                $t->index(['subject_type', 'last_used_at']);
            });
        }

        AccessUsageRecorder::backfillFromLedger();
    }

    public function down(): void
    {
        Schema::dropIfExists('access_usage');
    }
};
