# Railway's Railpack Node builder runs `playwright install --with-deps` at
# build time, but its slim runtime image doesn't carry over the apt-installed
# shared libraries (libglib-2.0.so.0 etc.) from the build stage, so Chromium
# fails to even start. Playwright's official image ships Chromium with every
# system dependency already baked in, sidestepping that split entirely.
FROM mcr.microsoft.com/playwright:v1.63.0-jammy

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts

COPY . .

CMD ["node", "server.js"]
