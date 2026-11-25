ARG POSTGRES_VERSION
FROM postgres:${POSTGRES_VERSION}

#####################################
# Set Timezone
#####################################

#ARG TZ=UTC
ARG TZ=Europe/Kiev
ENV TZ ${TZ}
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && \
    echo $TZ > /etc/timezone

RUN apt-get update \
    && apt-get install -y --no-install-recommends "postgresql-${PG_MAJOR}-postgis-3" \
    && rm -rf /var/lib/apt/lists/* \
    && printf '%s\n' 'CREATE EXTENSION IF NOT EXISTS postgis;' > /docker-entrypoint-initdb.d/20-postgis.sql

EXPOSE 5432
