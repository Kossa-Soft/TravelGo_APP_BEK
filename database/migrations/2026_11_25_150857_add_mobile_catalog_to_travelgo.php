<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travelgo_contents', function (Blueprint $table): void {
            $table->string('slug', 160)->nullable();
            $table->char('import_hash', 64)->nullable();
            $table->unique(['type', 'slug']);
        });

        Schema::create('travelgo_saved_routes', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('route_id')->constrained('travelgo_contents')->cascadeOnDelete();
            $table->timestampTz('created_at');
            $table->primary(['user_id', 'route_id']);
            $table->index(['user_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE travelgo_contents ADD COLUMN country_boundary geometry(MultiPolygon, 4326)');
            DB::statement('CREATE INDEX travelgo_country_boundary_gist ON travelgo_contents USING gist (country_boundary) WHERE country_boundary IS NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE travelgo_contents DROP COLUMN country_boundary');
        }
        Schema::dropIfExists('travelgo_saved_routes');
        Schema::table('travelgo_contents', function (Blueprint $table): void {
            $table->dropUnique(['type', 'slug']);
            $table->dropColumn(['slug', 'import_hash']);
        });
    }
};
