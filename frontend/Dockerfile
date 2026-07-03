FROM node:22-alpine

WORKDIR /app

COPY package.json package-lock.json* ./

RUN npm install

COPY . .

# 本番用ビルド
RUN npm run build

# 静的ファイルを配信するサーバーをインストール
RUN npm install -g serve

EXPOSE 5173

# 本番用コマンド
CMD ["serve", "-s", "dist", "-l", "5173"]
