<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
        DB::statement('ALTER TABLE travelgo_contents ADD COLUMN location geography(Point, 4326)
            GENERATED ALWAYS AS (
                CASE WHEN latitude IS NOT NULL AND longitude IS NOT NULL
                    THEN ST_SetSRID(ST_MakePoint(longitude::double precision, latitude::double precision), 4326)::geography
                END
            ) STORED');
        DB::statement('CREATE INDEX travelgo_location_gist ON travelgo_contents USING gist (location) WHERE location IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE travelgo_contents DROP COLUMN location');
        }
    }
};
