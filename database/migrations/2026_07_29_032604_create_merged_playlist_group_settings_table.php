<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('merged_playlist_group_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merged_playlist_id')
                ->constrained()
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('group_id')
                ->constrained()
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->unsignedInteger('sort');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(
                ['merged_playlist_id', 'group_id'],
                'merged_playlist_group_unique',
            );
            $table->index(
                ['merged_playlist_id', 'enabled', 'sort'],
                'merged_playlist_group_order_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merged_playlist_group_settings');
    }
};
