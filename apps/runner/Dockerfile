# syntax=docker/dockerfile:1
FROM node:24.14.0-bookworm-slim@sha256:4bd6219054c8bebcd26a66bfd8ca0bd6e1024b4b97474c59bb7ee3bbcbef4fe8 AS build
WORKDIR /build
COPY . .
RUN npm ci --ignore-scripts --workspace=@odvr/runner --workspace=@odvr/dispatcher --workspace=@odvr/shared --workspace=@odvr/schemas --include-workspace-root=false && npm run build && node infra/scripts/runtime-tree.mjs runner && npm ci --omit=dev --ignore-scripts --workspace=@odvr/runner --workspace=@odvr/shared --workspace=@odvr/schemas --include-workspace-root=false && node infra/scripts/runtime-dependencies.mjs
FROM mcr.microsoft.com/playwright:v1.64.0-noble@sha256:bc72a8df40831ded821e3cbc79c318c21b44d6e51dff9fd89ace7a8249c5b462
ARG GIT_REVISION
ARG LOCK_SHA256
LABEL org.opencontainers.image.title="odvr-runner" org.opencontainers.image.version="0.1.0" org.opencontainers.image.revision=$GIT_REVISION odvr.lock.sha256=$LOCK_SHA256 odvr.playwright.version="1.64.0" odvr.chromium.version="156.0.8078.4"
ENV NODE_ENV=production PLAYWRIGHT_BROWSERS_PATH=/ms-playwright
WORKDIR /app
COPY --from=build --chown=1000:1000 /runtime/ /app/
RUN test "$(node -p "require('playwright/package.json').version")" = 1.64.0 && rm -rf /ms-playwright/firefox-* /ms-playwright/webkit-* && mkdir -p /etc/odvr && chown 1000:1000 /etc/odvr
USER 1000:1000
ENTRYPOINT ["node", "apps/runner/dist/job.js"]
