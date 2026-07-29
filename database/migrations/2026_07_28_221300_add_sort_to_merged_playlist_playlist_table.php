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
        Schema::table('merged_playlist_playlist', function (Blueprint $table) {
            $table->unsignedInteger('sort')->nullable();
            $table->unique(
                ['merged_playlist_id', 'playlist_id'],
                'merged_playlist_playlist_unique',
            );
            $table->index(
                ['merged_playlist_id', 'sort'],
                'merged_playlist_playlist_sort_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merged_playlist_playlist', function (Blueprint $table) {
            $table->dropUnique('merged_playlist_playlist_unique');
            $table->dropIndex('merged_playlist_playlist_sort_index');
            $table->dropColumn('sort');
        });
    }
};
