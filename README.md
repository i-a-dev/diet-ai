# Diet AI (Web App)

React + TypeScript フロントエンドと PHP バックエンドで作成した、ダイエット記録アプリです。  
AIロジックはまだ実装しておらず、チャットは固定メッセージを返す構成です。

## 技術構成

- Frontend: React + TypeScript + Vite
- Backend: PHP 8.3 (Built-in server)
- Local 開発: Docker Compose

## 画面

- 相談する（チャット）
- 記録する（体重/食事/歩数/睡眠/メモ）
- 記録を見る（週次レポート）

## 起動方法

```bash
docker compose up --build
```

起動後:

- Frontend: `http://localhost:5173`
- Backend API: `http://localhost:8000/api`

## API エンドポイント

- `GET /api/records/daily`
- `GET /api/reports/weight-timeline`
- `GET /api/reports/metric-timeline`

## ディレクトリ構成

```text
.
├── backend
│   ├── public
│   │   └── index.php
│   └── src
│       ├── Http.php
│       └── WeightRepository.php
├── frontend
│   └── src
│       ├── api
│       ├── components
│       ├── features
│       └── types
└── docker-compose.yml
```
