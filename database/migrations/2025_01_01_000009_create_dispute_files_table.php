<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispute_files', function (Blueprint $table) {
            $table->id();
            $table->integer('dispute_id')->nullable();
            $table->text('file_link')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_files');
    }
};
