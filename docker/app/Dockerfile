# ==============================================================================
# mydraw_app — Vue 3 + Vite (Node 20)
# ==============================================================================
FROM node:20-alpine

RUN apk add --no-cache bash git

WORKDIR /app

ENV CHOKIDAR_USEPOLLING=true \
    WATCHPACK_POLLING=true

EXPOSE 5173

CMD ["sh", "-c", "if [ -f package.json ]; then npm install && npm run dev -- --host 0.0.0.0 --port 5173; else echo 'mydraw_app vazio — rode bash scripts/init.sh antes' && sleep infinity; fi"]
