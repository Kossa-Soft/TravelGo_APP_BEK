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

EXPOSE 5432
