FROM node:22-alpine

WORKDIR /app

COPY package.json package-lock.json* ./

RUN npm install

COPY . .

# Vite の環境変数はビルド時に JS へ埋め込まれる（実行時の変更は効かない）
ARG VITE_API_BASE_URL=/api
ENV VITE_API_BASE_URL=$VITE_API_BASE_URL

RUN npm run build

# 静的ファイルを配信するサーバーをインストール
RUN npm install -g serve

EXPOSE 5173

# 本番用コマンド
CMD ["serve", "-s", "dist", "-l", "5173"]
