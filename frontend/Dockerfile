FROM node:22-alpine AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
# VITE_* are baked in at build time; pass them as build args (.env.production
# is excluded by .dockerignore).
ARG VITE_API_URL
ARG VITE_RECAPTCHA_SITE_KEY
ARG VITE_APP_NAME
ENV VITE_API_URL=$VITE_API_URL \
    VITE_RECAPTCHA_SITE_KEY=$VITE_RECAPTCHA_SITE_KEY \
    VITE_APP_NAME=$VITE_APP_NAME
RUN npm run build

FROM nginx:1.27-alpine
# host:port of the Laravel backend as reachable from this container.
ENV BACKEND_HOST=dictis-api:8080
COPY docker/default.conf.template /etc/nginx/templates/default.conf.template
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD wget -q -O /dev/null http://127.0.0.1/ || exit 1
