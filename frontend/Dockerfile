FROM node:24-bookworm-slim AS development
WORKDIR /app
ENV NUXT_TELEMETRY_DISABLED=1
EXPOSE 3000
CMD ["node", "node_modules/nuxt/bin/nuxt.mjs", "dev", "--host", "0.0.0.0"]

FROM development AS build
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build

FROM node:24-bookworm-slim AS production
WORKDIR /app
ENV NODE_ENV=production HOST=0.0.0.0 PORT=3000
COPY --from=build --chown=node:node /app/.output ./.output
USER node
EXPOSE 3000
CMD ["node", ".output/server/index.mjs"]
