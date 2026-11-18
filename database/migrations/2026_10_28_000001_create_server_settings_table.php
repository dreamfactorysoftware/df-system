<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime overrides for an allow-listed set of server settings (log level first).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('server_settings')) {
            Schema::create('server_settings', function (Blueprint $t) {
                $t->increments('id');
                $t->string('name', 64)->unique();
                $t->text('value');
                $t->timestamp('created_date')->nullable();
                $t->timestamp('last_modified_date')->nullable();
                $t->unsignedInteger('created_by_id')->nullable();
                $t->unsignedInteger('last_modified_by_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('server_settings');
    }
};
