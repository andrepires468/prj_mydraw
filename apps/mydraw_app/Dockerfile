# ==============================================================================
# mydraw_app — produção (Vite build + Nginx na porta 80)
#
# Easypanel:
#   Caminho de Build: /apps/mydraw_app
#   Arquivo: Dockerfile
#   Porta do serviço: 80
#
# O Vite grava estas variáveis no bundle. Defina-as no serviço antes do build:
#   VITE_API_URL, VITE_API_VERSION, VITE_MINIO_PUBLIC_URL, VITE_GOOGLE_CLIENT_ID
# ==============================================================================
FROM node:20-alpine AS build

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .

ARG VITE_API_URL
ARG VITE_API_VERSION=v1
ARG VITE_MINIO_PUBLIC_URL
ARG VITE_GOOGLE_CLIENT_ID

ENV VITE_API_URL=$VITE_API_URL \
    VITE_API_VERSION=$VITE_API_VERSION \
    VITE_MINIO_PUBLIC_URL=$VITE_MINIO_PUBLIC_URL \
    VITE_GOOGLE_CLIENT_ID=$VITE_GOOGLE_CLIENT_ID \
    NODE_OPTIONS=--max-old-space-size=2048

RUN npm run build

FROM nginx:1.27-alpine

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 80
