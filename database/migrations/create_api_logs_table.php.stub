<?php

declare(strict_types=1);

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
        Schema::create('api_logs', function (Blueprint $table) {
            $table->id();

            // Request identification
            $table->string('request_id', 36)->unique()->index();

            // Request details
            $table->string('method', 10);
            $table->text('endpoint');
            $table->json('request_headers')->nullable();
            $table->json('request_body')->nullable();

            // Response details
            $table->integer('response_code');
            $table->json('response_headers')->nullable();
            $table->json('response_body')->nullable();
            $table->decimal('response_time_ms', 10, 2);

            // User and request metadata
            $table->string('user_identifier')->nullable()->index();
            $table->string('ip_address', 45)->nullable()->index(); // Supports IPv6
            $table->text('user_agent')->nullable();

            // Additional metadata
            $table->json('metadata')->nullable();

            // Timestamps
            $table->timestamp('created_at')->useCurrent()->index();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // Indexes for performance
            $table->index('method');
            $table->index('response_code');
            $table->index(['response_code', 'created_at']); // For retention cleanup
            $table->index(['user_identifier', 'created_at']); // For user-specific queries
            $table->index(['endpoint', 'method']); // For endpoint analysis
            $table->index('response_time_ms'); // For performance analysis
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_logs');
    }
};
