<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travelgo_contents', function (Blueprint $table): void {
            $table->uuid('id');
            $table->primary('id');
            $table->string('type', 12);
            $table->char('country_code', 2)->nullable()->unique();
            $table->foreignUuid('parent_id')->nullable()->constrained('travelgo_contents')->restrictOnDelete();
            $table->foreignUuid('region_id')->nullable()->constrained('travelgo_contents')->restrictOnDelete();
            $table->foreignUuid('start_place_id')->nullable()->constrained('travelgo_contents')->restrictOnDelete();
            $table->foreignUuid('end_place_id')->nullable()->constrained('travelgo_contents')->restrictOnDelete();
            $table->jsonb('name');
            $table->jsonb('data');
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 12)->default('draft');
            $table->timestampTz('publish_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->text('status_reason')->nullable();
            $table->timestampTz('status_changed_at')->nullable();
            $table->foreignId('status_changed_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['type', 'status', 'sort_order']);
            $table->index(['status', 'publish_at']);
            $table->index('parent_id');
            $table->index('region_id');
            $table->index('start_place_id');
            $table->index('end_place_id');
        });

        Schema::create('travelgo_route_stops', function (Blueprint $table): void {
            $table->foreignUuid('route_id')->constrained('travelgo_contents')->cascadeOnDelete();
            $table->foreignUuid('place_id')->constrained('travelgo_contents')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedSmallInteger('visit_minutes');
            $table->jsonb('note')->nullable();
            $table->primary(['route_id', 'position']);
            $table->index('place_id');
        });

        Schema::create('travelgo_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('content_id')->nullable();
            $table->foreignId('admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('actor', 255)->nullable();
            $table->string('type', 12);
            $table->string('action', 24);
            $table->jsonb('details');
            $table->timestampTz('created_at');
            $table->index(['content_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travelgo_audit_events');
        Schema::dropIfExists('travelgo_route_stops');
        Schema::dropIfExists('travelgo_contents');
    }
};
