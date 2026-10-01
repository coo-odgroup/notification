<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('notification_campaign_queue', function (Blueprint $table) {
            $table->timestamp('queued_at')
                ->nullable()
                ->after('scheduled_time');

            $table->timestamp('processing_at')
                ->nullable()
                ->after('queued_at');
        });
    }

    public function down()
    {
        Schema::table('notification_campaign_queue', function (Blueprint $table) {
            $table->dropColumn([
                'queued_at',
                'processing_at',
            ]);
        });
    }
};