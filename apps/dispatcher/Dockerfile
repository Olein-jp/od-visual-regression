# syntax=docker/dockerfile:1
FROM node:24.14.0-bookworm-slim@sha256:4bd6219054c8bebcd26a66bfd8ca0bd6e1024b4b97474c59bb7ee3bbcbef4fe8 AS build
WORKDIR /build
COPY . .
RUN npm ci --ignore-scripts --workspace=@odvr/runner --workspace=@odvr/dispatcher --workspace=@odvr/shared --workspace=@odvr/schemas --include-workspace-root=false && npm run build && node infra/scripts/runtime-tree.mjs dispatcher && npm ci --omit=dev --ignore-scripts --workspace=@odvr/dispatcher --workspace=@odvr/runner --workspace=@odvr/shared --workspace=@odvr/schemas --include-workspace-root=false && node infra/scripts/runtime-dependencies.mjs
FROM node:24.14.0-bookworm-slim@sha256:4bd6219054c8bebcd26a66bfd8ca0bd6e1024b4b97474c59bb7ee3bbcbef4fe8
ARG GIT_REVISION
ARG LOCK_SHA256
LABEL org.opencontainers.image.title="odvr-dispatcher" org.opencontainers.image.version="0.1.0" org.opencontainers.image.revision=$GIT_REVISION odvr.lock.sha256=$LOCK_SHA256
ENV NODE_ENV=production PORT=8080
WORKDIR /app
COPY --from=build --chown=1000:1000 /runtime/ /app/
USER 1000:1000
ENTRYPOINT ["node", "apps/dispatcher/dist/index.js"]
