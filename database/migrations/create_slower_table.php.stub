<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create(config('slower.resources.table_name'), function (Blueprint $table) {
            $table->id();
            $table->boolean('is_analyzed')->default(false)->index();
            $table->longtext('bindings');
            $table->longtext('sql');
            $table->float('time')->nullable()->index();
            $table->string('connection');
            $table->string('connection_name')->nullable();
            $table->longtext('raw_sql');
            $table->longtext('recommendation')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('slower.resources.table_name'));
    }
};
